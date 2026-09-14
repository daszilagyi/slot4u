<?php

use App\Enums\Feature;
use App\Enums\NotificationType;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Message;
use App\Models\NotificationLog;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Notifications\CustomerMessageNotification;
use App\Notifications\MessageReceivedNotification;
use App\Services\Notification\MessageTemplateCatalog;
use App\Services\Privacy\AnonymizeCustomer;
use App\Services\Privacy\PersonalDataExport;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// SLO-36: tenant ↔ customer messaging. tenantHost() lives in tests/Pest.php;
// BasePlanSeeder switches feature_messages on for the base plan.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    Carbon::setTestNow('2026-09-01 08:00:00');
    Notification::fake();
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function msgTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::factory()->active()->create(['slug' => $slug]);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

function msgUser(Tenant $tenant, Role $role, array $attributes = []): User
{
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create([...$attributes, 'tenant_id' => $tenant->id]);
    $user->assignRole($role->value);

    return $user;
}

/** An employee linked to a staff record, and a customer booked with that staff. */
function msgEmployeeWithCustomer(Tenant $tenant): array
{
    $employee = msgUser($tenant, Role::Employee);
    $staff = Staff::factory()->forTenant($tenant)->create(['user_id' => $employee->id]);
    $customer = msgUser($tenant, Role::Customer);
    $booking = Booking::factory()->forTenant($tenant)->create(['customer_id' => $customer->id, 'staff_id' => $staff->id]);

    return [$employee, $customer, $booking];
}

// --- Members area -----------------------------------------------------------

it('lets a customer write to the tenant and mails the staff who may answer', function () {
    $tenant = msgTenant();
    $admin = msgUser($tenant, Role::TenantAdmin);
    $customer = msgUser($tenant, Role::Customer, ['name' => 'Kovács Anna']);
    app(TenantManager::class)->forget();

    $this->actingAs($customer)
        ->post(tenantHost('acme', '/my/messages'), ['body' => 'Mit hozzak az első alkalomra?'])
        ->assertRedirect();

    $message = Message::withoutGlobalScopes()->sole();
    expect($message->tenant_id)->toBe($tenant->id)
        ->and($message->customer_id)->toBe($customer->id)
        ->and($message->sender_id)->toBe($customer->id)
        ->and($message->from_customer)->toBeTrue();

    Notification::assertSentTo($admin, CustomerMessageNotification::class);
});

it('shows the customer only their own thread and marks the replies read', function () {
    $tenant = msgTenant();
    $admin = msgUser($tenant, Role::TenantAdmin);
    $me = msgUser($tenant, Role::Customer);
    $other = msgUser($tenant, Role::Customer);

    $reply = Message::factory()->fromStaff($me, $admin)->create(['body' => 'Szia, várunk!']);
    Message::factory()->fromCustomer($other)->create(['body' => 'Idegen szál']);
    app(TenantManager::class)->forget();

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/messages'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/My/Messages')
            ->has('messages', 1)
            ->where('messages.0.id', $reply->id)
            ->where('messages.0.from_customer', false)
            // Opening the page read it, so the nav badge is already zero.
            ->where('messages_unread', 0));

    expect($reply->fresh()->read_at)->not->toBeNull();
});

it('counts a customer\'s unread replies in the shared prop', function () {
    $tenant = msgTenant();
    $admin = msgUser($tenant, Role::TenantAdmin);
    $me = msgUser($tenant, Role::Customer);
    Message::factory()->count(2)->fromStaff($me, $admin)->create();
    Message::factory()->fromCustomer($me)->create();
    app(TenantManager::class)->forget();

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/bookings'))
        ->assertInertia(fn (Assert $page) => $page->where('messages_unread', 2));
});

it('never shows a customer another tenant\'s messages', function () {
    $acme = msgTenant('acme');
    $me = msgUser($acme, Role::Customer);

    $other = msgTenant('other');
    $otherAdmin = msgUser($other, Role::TenantAdmin);
    $otherCustomer = msgUser($other, Role::Customer);
    Message::factory()->fromStaff($otherCustomer, $otherAdmin)->create();
    // Even a row forged onto my id under the other tenant stays invisible here.
    Message::factory()->create(['tenant_id' => $other->id, 'customer_id' => $me->id, 'from_customer' => false]);
    app(TenantManager::class)->forget();

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/messages'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('messages', 0));
});

it('refuses a booking that is not the customer\'s own', function () {
    $tenant = msgTenant();
    $me = msgUser($tenant, Role::Customer);
    $other = msgUser($tenant, Role::Customer);
    $foreign = Booking::factory()->forTenant($tenant)->create(['customer_id' => $other->id]);
    app(TenantManager::class)->forget();

    $this->actingAs($me)
        ->post(tenantHost('acme', '/my/messages'), ['body' => 'Kérdés', 'booking_id' => $foreign->id])
        ->assertSessionHasErrors('booking_id');

    expect(Message::withoutGlobalScopes()->count())->toBe(0);
});

it('attaches the customer\'s own booking to the message', function () {
    $tenant = msgTenant();
    $me = msgUser($tenant, Role::Customer);
    $booking = Booking::factory()->forTenant($tenant)->create(['customer_id' => $me->id]);
    app(TenantManager::class)->forget();

    $this->actingAs($me)
        ->post(tenantHost('acme', '/my/messages'), ['body' => 'Kérdés', 'booking_id' => $booking->id])
        ->assertSessionHasNoErrors();

    expect(Message::withoutGlobalScopes()->sole()->booking_id)->toBe($booking->id);
});

it('keeps staff out of the members-area thread', function () {
    $tenant = msgTenant();
    $admin = msgUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $response = $this->actingAs($admin)->get(tenantHost('acme', '/my/messages'));

    expect($response->status())->not->toBe(200);
});

it('closes both sides when the tenant has messaging switched off', function () {
    $tenant = msgTenant();
    TenantFeature::factory()->create(['tenant_id' => $tenant->id, 'feature_code' => Feature::Messages, 'enabled' => false]);
    $admin = msgUser($tenant, Role::TenantAdmin);
    $customer = msgUser($tenant, Role::Customer);
    app(TenantManager::class)->forget();

    $this->actingAs($customer)->get(tenantHost('acme', '/my/messages'))->assertForbidden();
    $this->actingAs($customer)
        ->get(tenantHost('acme', '/my/bookings'))
        ->assertInertia(fn (Assert $page) => $page->where('messages_unread', null));

    $this->flushSession();
    $this->actingAs($admin)->get(tenantHost('acme', '/messages'))->assertForbidden();
});

// --- Admin inbox ------------------------------------------------------------

it('lists threads newest first with their unread counts', function () {
    $tenant = msgTenant();
    $admin = msgUser($tenant, Role::TenantAdmin);
    $anna = msgUser($tenant, Role::Customer, ['name' => 'Anna']);
    $bela = msgUser($tenant, Role::Customer, ['name' => 'Béla']);

    Message::factory()->count(2)->fromCustomer($anna)->create();
    Message::factory()->fromStaff($bela, $admin)->create();
    Message::factory()->fromCustomer($bela)->create(['body' => 'Legutóbbi']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/messages'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Messages/Index')
            ->has('threads.data', 2)
            ->where('threads.data.0.customer_name', 'Béla')
            ->where('threads.data.0.preview', 'Legutóbbi')
            ->where('threads.data.0.unread', 1)
            ->where('threads.data.1.customer_name', 'Anna')
            ->where('threads.data.1.unread', 2)
            ->where('messages_unread', 3));
});

it('shows an employee only the threads of their own customers', function () {
    $tenant = msgTenant();
    [$employee, $mine] = msgEmployeeWithCustomer($tenant);
    $theirs = msgUser($tenant, Role::Customer);
    Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $theirs->id,
        'staff_id' => Staff::factory()->forTenant($tenant)->create()->id,
    ]);

    Message::factory()->fromCustomer($mine)->create();
    Message::factory()->count(3)->fromCustomer($theirs)->create();
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->get(tenantHost('acme', '/messages'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('threads.data', 1)
            ->where('threads.data.0.customer_id', $mine->id)
            ->where('messages_unread', 1));
});

it('404s an employee opening or answering a colleague\'s customer', function () {
    $tenant = msgTenant();
    [$employee] = msgEmployeeWithCustomer($tenant);
    $theirs = msgUser($tenant, Role::Customer);
    Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $theirs->id,
        'staff_id' => Staff::factory()->forTenant($tenant)->create()->id,
    ]);
    app(TenantManager::class)->forget();

    $this->actingAs($employee)->get(tenantHost('acme', "/messages/{$theirs->id}"))->assertNotFound();
    $this->actingAs($employee)
        ->post(tenantHost('acme', "/messages/{$theirs->id}"), ['body' => 'Szia'])
        ->assertNotFound();

    expect(Message::withoutGlobalScopes()->count())->toBe(0);
});

it('404s a customer of another tenant', function () {
    msgTenant('acme');
    $admin = msgUser(Tenant::where('slug', 'acme')->sole(), Role::TenantAdmin);
    $other = msgTenant('other');
    $foreign = msgUser($other, Role::Customer);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)->get(tenantHost('acme', "/messages/{$foreign->id}"))->assertNotFound();
});

it('refuses an employee pinning a colleague\'s booking to the thread', function () {
    $tenant = msgTenant();
    [$employee, $customer] = msgEmployeeWithCustomer($tenant);
    $colleagues = Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'staff_id' => Staff::factory()->forTenant($tenant)->create()->id,
    ]);
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->post(tenantHost('acme', "/messages/{$customer->id}"), ['body' => 'Szia', 'booking_id' => $colleagues->id])
        ->assertSessionHasErrors('booking_id');
});

it('hides a colleague\'s booking code from an employee reading the thread', function () {
    $tenant = msgTenant();
    [$employee, $customer, $ownBooking] = msgEmployeeWithCustomer($tenant);
    $colleagues = Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'staff_id' => Staff::factory()->forTenant($tenant)->create()->id,
    ]);
    Message::factory()->fromCustomer($customer)->create(['booking_id' => $colleagues->id]);
    Message::factory()->fromCustomer($customer)->create(['booking_id' => $ownBooking->id]);
    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->get(tenantHost('acme', "/messages/{$customer->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('messages.0.booking_code', null)
            ->where('messages.1.booking_code', $ownBooking->code));
});

it('keeps another tenant\'s threads out of the inbox and the badge', function () {
    $other = msgTenant('other');
    $otherCustomer = msgUser($other, Role::Customer);
    Message::factory()->count(4)->fromCustomer($otherCustomer)->create();

    $tenant = msgTenant('acme');
    $admin = msgUser($tenant, Role::TenantAdmin);
    $mine = msgUser($tenant, Role::Customer);
    Message::factory()->fromCustomer($mine)->create();
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/messages'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('threads.data', 1)
            ->where('threads.data.0.customer_id', $mine->id)
            ->where('messages_unread', 1));
});

it('refuses a staff reply pinning another tenant\'s booking', function () {
    $other = msgTenant('other');
    $foreignBooking = Booking::factory()->forTenant($other)->create();

    $tenant = msgTenant('acme');
    $admin = msgUser($tenant, Role::TenantAdmin);
    $customer = msgUser($tenant, Role::Customer);
    $foreignBooking->forceFill(['customer_id' => $customer->id])->saveQuietly();
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', "/messages/{$customer->id}"), ['body' => 'Szia', 'booking_id' => $foreignBooking->id])
        ->assertSessionHasErrors('booking_id');
});

it('marks the customer\'s messages read when staff open the thread', function () {
    $tenant = msgTenant();
    $admin = msgUser($tenant, Role::TenantAdmin);
    $customer = msgUser($tenant, Role::Customer);
    $incoming = Message::factory()->fromCustomer($customer)->create();
    $outgoing = Message::factory()->fromStaff($customer, $admin)->create();
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/messages/{$customer->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Messages/Show')
            ->has('messages', 2)
            ->where('messages_unread', 0));

    expect($incoming->fresh()->read_at)->not->toBeNull()
        // Staff opening the thread says nothing about whether the CUSTOMER read.
        ->and($outgoing->fresh()->read_at)->toBeNull();
});

it('mails the customer about a reply, once per unread burst', function () {
    $tenant = msgTenant();
    $admin = msgUser($tenant, Role::TenantAdmin);
    $customer = msgUser($tenant, Role::Customer);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)->post(tenantHost('acme', "/messages/{$customer->id}"), ['body' => 'Első'])->assertRedirect();
    $this->actingAs($admin)->post(tenantHost('acme', "/messages/{$customer->id}"), ['body' => 'Második'])->assertRedirect();

    Notification::assertSentToTimes($customer, MessageReceivedNotification::class, 1);
    expect(NotificationLog::withoutGlobalScopes()->where('type', NotificationType::MessageReceived->value)->count())->toBe(1);

    // Once the customer has read the thread, the next reply mails again.
    Message::withoutGlobalScopes()->update(['read_at' => Carbon::now()]);
    $this->actingAs($admin)->post(tenantHost('acme', "/messages/{$customer->id}"), ['body' => 'Harmadik'])->assertRedirect();

    Notification::assertSentToTimes($customer, MessageReceivedNotification::class, 2);
});

it('mails only the staff who can see the customer', function () {
    $tenant = msgTenant();
    $admin = msgUser($tenant, Role::TenantAdmin);
    [$ownEmployee, $customer] = msgEmployeeWithCustomer($tenant);
    $otherEmployee = msgUser($tenant, Role::Employee);
    Staff::factory()->forTenant($tenant)->create(['user_id' => $otherEmployee->id]);
    app(TenantManager::class)->forget();

    $this->actingAs($customer)->post(tenantHost('acme', '/my/messages'), ['body' => 'Szia'])->assertRedirect();

    Notification::assertSentTo($admin, CustomerMessageNotification::class);
    Notification::assertSentTo($ownEmployee, CustomerMessageNotification::class);
    Notification::assertNotSentTo($otherEmployee, CustomerMessageNotification::class);
    Notification::assertNotSentTo($customer, CustomerMessageNotification::class);
});

it('keeps the message text out of both mails', function () {
    $tenant = msgTenant();
    $admin = msgUser($tenant, Role::TenantAdmin);
    $customer = msgUser($tenant, Role::Customer);
    $secret = 'Szorongásos tüneteim vannak';
    $message = Message::factory()->fromCustomer($customer)->create(['body' => $secret]);

    $toCustomer = (new MessageReceivedNotification($tenant))->toMail($customer)->render();
    $toStaff = (new CustomerMessageNotification($message, $customer->name, $tenant))->toMail($admin)->render();

    expect((string) $toCustomer)->not->toContain($secret)->toContain('/my/messages')
        ->and((string) $toStaff)->not->toContain($secret)
        ->toContain(route('tenant.messages.show', ['tenant' => $tenant->slug, 'customer' => $customer->id]));
});

// --- Tenancy, templates, privacy ---------------------------------------------

it('isolates messages by tenant', function () {
    $acme = msgTenant('acme');
    $acmeCustomer = msgUser($acme, Role::Customer);
    Message::factory()->fromCustomer($acmeCustomer)->create();

    $other = msgTenant('other');
    $otherCustomer = msgUser($other, Role::Customer);
    Message::factory()->fromCustomer($otherCustomer)->create();

    app(TenantManager::class)->set($acme);
    expect(Message::query()->pluck('customer_id')->all())->toBe([$acmeCustomer->id]);

    // A new message is stamped with the ambient tenant, whatever the caller passes.
    $forged = Message::query()->create([
        'customer_id' => $acmeCustomer->id,
        'from_customer' => true,
        'body' => 'x',
    ] + ['tenant_id' => $other->id]);
    expect($forged->tenant_id)->toBe($acme->id);
});

it('offers the reply mail as an editable template', function () {
    $catalog = app(MessageTemplateCatalog::class);

    expect($catalog->isEditable(NotificationType::MessageReceived))->toBeTrue()
        ->and($catalog->variables(NotificationType::MessageReceived))->toContain('name', 'tenant');
});

it('exports the thread and erases only the customer\'s own words', function () {
    $tenant = msgTenant();
    $admin = msgUser($tenant, Role::TenantAdmin);
    $customer = msgUser($tenant, Role::Customer);
    $mine = Message::factory()->fromCustomer($customer)->create(['body' => 'Az én szavaim']);
    $reply = Message::factory()->fromStaff($customer, $admin)->create(['body' => 'A cég válasza']);

    $export = app(PersonalDataExport::class)->for($customer);
    expect($export['messages'])->toHaveCount(2)
        ->and($export['messages'][0])->toMatchArray(['from_customer' => true, 'body' => 'Az én szavaim']);

    app(AnonymizeCustomer::class)->erase($customer, $tenant);

    expect($mine->fresh()->body)->toBe(__('app.privacy.erased_message'))
        ->and($reply->fresh()->body)->toBe('A cég válasza');
});
