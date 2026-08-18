<?php

use App\Enums\AuditAction;
use App\Enums\PrivacyRequestStatus;
use App\Enums\PrivacyRequestType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\PrivacyRequest;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
 * Members area — the customer's own data-protection page (SLO-159, docs/19).
 *
 * The two rights are served differently and the tests follow that split: the
 * export must answer on the spot with everything, the erasure must only ever
 * *record* an obligation, because slot4u is the processor and the tenant is the
 * controller.
 */

// tenantHost() lives in tests/Pest.php.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function privacyTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::factory()->active()->create(['slug' => $slug]);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

function privacyCustomer(Tenant $tenant, array $attributes = []): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create([...$attributes, 'tenant_id' => $tenant->id]);
    $user->assignRole(Role::Customer->value);

    return $user;
}

it('renders the privacy page with the customer request history', function () {
    $tenant = privacyTenant();
    $me = privacyCustomer($tenant);

    PrivacyRequest::factory()->forTenant($tenant)->create(['user_id' => $me->id]);

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/My/Privacy')
            ->has('requests', 1)
            ->where('requests.0.type', 'erasure')
            ->where('requests.0.status', 'pending')
            ->where('has_pending_erasure', true)
            ->where('anonymized', false));
});

it('downloads the personal data export as a JSON attachment', function () {
    $tenant = privacyTenant();
    $me = privacyCustomer($tenant, ['name' => 'Kovács Anna', 'email' => 'anna@example.test', 'phone' => '+36301234567']);

    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Masszázs']);
    Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $me->id,
        'service_id' => $service->id,
        'notes' => 'Hátfájásra panaszkodik',
    ]);

    $response = $this->actingAs($me)->get(tenantHost('acme', '/my/privacy/export'));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('attachment')
        // The file must not sit in a shared cache — it is a complete personal
        // data set behind a session cookie.
        ->and($response->headers->get('cache-control'))->toContain('no-store');

    $payload = $response->json();

    expect($payload['subject']['name'])->toBe('Kovács Anna')
        ->and($payload['subject']['email'])->toBe('anna@example.test')
        ->and($payload['subject']['phone'])->toBe('+36301234567')
        ->and($payload['bookings'])->toHaveCount(1)
        ->and($payload['bookings'][0]['service'])->toBe('Masszázs')
        ->and($payload['bookings'][0]['notes'])->toBe('Hátfájásra panaszkodik');
});

it('exports every section the app can hold data in', function () {
    $tenant = privacyTenant();
    $me = privacyCustomer($tenant);

    $payload = $this->actingAs($me)
        ->get(tenantHost('acme', '/my/privacy/export'))
        ->json();

    // A section silently dropped from the builder is the failure mode that
    // matters here: the file would look complete and be short one table.
    expect(array_keys($payload))->toEqualCanonicalizing([
        'generated_at',
        'subject',
        'bookings',
        'quote_requests',
        'waitlist_entries',
        'payments',
        'invoices',
        'notifications',
        'privacy_requests',
    ]);
});

it('includes guest bookings made with the customer email', function () {
    $tenant = privacyTenant();
    $me = privacyCustomer($tenant, ['email' => 'anna@example.test']);
    $service = Service::factory()->forTenant($tenant)->create();

    // Never linked to the account — but it is still her data (art. 15 does not
    // care which column holds it).
    Booking::factory()->forTenant($tenant)->create([
        'customer_id' => null,
        'service_id' => $service->id,
        'guest_name' => 'Kovács Anna',
        'guest_email' => 'anna@example.test',
    ]);

    $payload = $this->actingAs($me)
        ->get(tenantHost('acme', '/my/privacy/export'))
        ->json();

    expect($payload['bookings'])->toHaveCount(1)
        ->and($payload['bookings'][0]['guest_email'])->toBe('anna@example.test');
});

it('never exports another tenant customer data', function () {
    $mine = privacyTenant('acme');
    $me = privacyCustomer($mine, ['email' => 'anna@example.test']);

    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->set($other);
    app(PermissionRegistrar::class)->setPermissionsTeamId($other->getKey());
    $otherService = Service::factory()->forTenant($other)->create();
    // Same person, other controller: a booking under the same email at a
    // different tenant must not appear — merging the two would disclose to each
    // tenant that the other exists.
    Booking::factory()->forTenant($other)->create([
        'customer_id' => null,
        'service_id' => $otherService->id,
        'guest_email' => 'anna@example.test',
        'notes' => 'Másik tenant adata',
    ]);

    app(TenantManager::class)->set($mine);
    app(PermissionRegistrar::class)->setPermissionsTeamId($mine->getKey());

    $payload = $this->actingAs($me)
        ->get(tenantHost('acme', '/my/privacy/export'))
        ->json();

    expect($payload['bookings'])->toBeEmpty();
});

it('records the export in the register and the audit trail', function () {
    $tenant = privacyTenant();
    $me = privacyCustomer($tenant);

    $this->actingAs($me)->get(tenantHost('acme', '/my/privacy/export'))->assertOk();

    $request = PrivacyRequest::query()->where('user_id', $me->id)->sole();

    expect($request->type)->toBe(PrivacyRequestType::Export)
        // Born completed: the download already happened, so a pending row would
        // describe an obligation that no longer exists.
        ->and($request->status)->toBe(PrivacyRequestStatus::Completed)
        ->and($request->resolved_at)->not->toBeNull();

    expect(AuditLog::query()->where('action', AuditAction::PrivacyDataExported->value)->exists())->toBeTrue();
});

it('records an erasure request as pending', function () {
    $tenant = privacyTenant();
    $me = privacyCustomer($tenant);

    $this->actingAs($me)
        ->from(tenantHost('acme', '/my/privacy'))
        ->post(tenantHost('acme', '/my/privacy/erasure'))
        ->assertRedirect(tenantHost('acme', '/my/privacy'));

    $request = PrivacyRequest::query()->where('user_id', $me->id)->sole();

    expect($request->type)->toBe(PrivacyRequestType::Erasure)
        ->and($request->status)->toBe(PrivacyRequestStatus::Pending);

    // Recording the request must not erase anything — the tenant decides.
    $me->refresh();
    expect($me->anonymized_at)->toBeNull();

    expect(AuditLog::query()->where('action', AuditAction::PrivacyErasureRequested->value)->exists())->toBeTrue();
});

it('does not queue a second erasure request while one is pending', function () {
    $tenant = privacyTenant();
    $me = privacyCustomer($tenant);

    $this->actingAs($me)->post(tenantHost('acme', '/my/privacy/erasure'));
    $this->actingAs($me)->post(tenantHost('acme', '/my/privacy/erasure'));

    expect(PrivacyRequest::query()->where('user_id', $me->id)->count())->toBe(1);
});

it('ignores an erasure request from an already anonymized account', function () {
    $tenant = privacyTenant();
    $me = privacyCustomer($tenant);
    $me->forceFill(['anonymized_at' => now()])->save();

    $this->actingAs($me)->post(tenantHost('acme', '/my/privacy/erasure'));

    expect(PrivacyRequest::query()->where('user_id', $me->id)->count())->toBe(0);
});

it('keeps staff out of the members privacy page', function () {
    $tenant = privacyTenant();
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $staff = User::factory()->create(['tenant_id' => $tenant->id]);
    $staff->assignRole(Role::TenantAdmin->value);

    // ensure.customer walls the /my group off from the admin panel's users.
    $this->actingAs($staff)
        ->get(tenantHost('acme', '/my/privacy'))
        ->assertForbidden();
});

it('requires authentication for the export', function () {
    privacyTenant();

    $this->get(tenantHost('acme', '/my/privacy/export'))->assertRedirect();
});
