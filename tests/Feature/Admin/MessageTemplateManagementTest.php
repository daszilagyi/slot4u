<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\MessageTemplate;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// The tenant email-template editor (SLO-114). tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function templateUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);

    return $user;
}

it('lists the editable templates for a tenant admin', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = templateUser($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/settings/templates'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Templates/Index')
            ->has('templates', 7)
            ->where('templates.0.key', 'booking_confirmed')
            ->where('templates.0.override', null)
            ->has('templates.0.variables'));
});

it('upserts an override and the confirmation mail renders from it', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'locale' => 'hu', 'name' => 'Acme']);
    $admin = templateUser($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/templates/booking_confirmed'), [
            'subject' => 'Egyedi tárgy – :tenant',
            'body' => 'Kész a foglalásod, :name! Kód: :code.',
            'enabled' => true,
        ])
        ->assertRedirect();

    $template = MessageTemplate::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('key', NotificationType::BookingConfirmed->value)
        ->where('channel', NotificationChannel::Email->value)
        ->where('locale', 'hu')
        ->first();

    expect($template)->not->toBeNull()
        ->and($template->enabled)->toBeTrue()
        ->and($template->subject)->toBe('Egyedi tárgy – :tenant');

    // The override actually drives the outgoing mail (SLO-113 integration).
    app(TenantManager::class)->set($tenant);
    $customer = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Teszt Elek']);
    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Masszázs']);
    $booking = Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'code' => 'XYZ999',
    ]);

    $mail = (new BookingConfirmedNotification($booking, $tenant))->toMail($customer);

    expect($mail->subject)->toBe('Egyedi tárgy – Acme')
        ->and($mail->introLines)->toContain('Kész a foglalásod, Teszt Elek! Kód: XYZ999.');
});

it('updates an existing override instead of duplicating it', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = templateUser($tenant, Role::TenantAdmin);
    MessageTemplate::factory()->forTenant($tenant)->forType(NotificationType::BookingConfirmed)->create([
        'locale' => $tenant->locale,
        'subject' => 'Régi',
    ]);

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/templates/booking_confirmed'), [
            'subject' => 'Új tárgy',
            'body' => 'Új törzs',
            'enabled' => true,
        ])
        ->assertRedirect();

    $rows = MessageTemplate::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('key', NotificationType::BookingConfirmed->value)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->subject)->toBe('Új tárgy');
});

it('resets an override back to the default', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = templateUser($tenant, Role::TenantAdmin);
    MessageTemplate::factory()->forTenant($tenant)->forType(NotificationType::BookingConfirmed)->create([
        'locale' => $tenant->locale,
    ]);

    $this->actingAs($admin)
        ->delete(tenantHost('acme', '/settings/templates/booking_confirmed'))
        ->assertRedirect();

    expect(MessageTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('rejects an empty subject or body', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = templateUser($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/templates/booking_confirmed'), [
            'subject' => '',
            'body' => '',
            'enabled' => true,
        ])
        ->assertSessionHasErrors(['subject', 'body']);
});

it('404s an unknown or non-editable template key', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = templateUser($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/templates/payment_success'), [
            'subject' => 'x',
            'body' => 'y',
            'enabled' => true,
        ])
        ->assertNotFound();
});

it('forbids a manager from managing templates', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $manager = templateUser($tenant, Role::Manager);

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/settings/templates'))
        ->assertForbidden();
});

it('does not leak another tenant\'s override', function () {
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    MessageTemplate::factory()->forTenant($other)->forType(NotificationType::BookingConfirmed)->create([
        'locale' => $other->locale,
        'subject' => 'IDEGEN',
    ]);

    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = templateUser($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/settings/templates'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('templates.0.override', null));
});
