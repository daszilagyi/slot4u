<?php

use App\Actions\Tenant\ChangeTenantStatus;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

/*
 * The tenant's own data export (SLO-160, docs/19 §7.4).
 *
 * Not a data-subject export — this is the *controller's* copy, the thing that
 * makes the 90-day retention window fair: a tenant that is leaving can take its
 * bookings, customers, services and invoices with it before the purge removes
 * them.
 *
 * Two properties matter beyond "the file downloads". It must contain the
 * tenant's own data and **only** the tenant's own data, and it must not contain
 * the invoicing provider API key — the model decrypts that column on
 * serialisation, so a plain toArray() would have written the credential
 * straight into a file destined for someone's downloads folder.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/**
 * A tenant with one staff user, one customer and one booking.
 *
 * @return array{Tenant, User, User}
 */
function exportFixture(string $slug = 'acme', string $role = Role::TenantAdmin->value): array
{
    $tenant = Tenant::factory()->active()->create([
        'slug' => $slug,
        'invoicing' => ['provider' => 'szamlazzhu', 'api_key' => 'secret-agent-key'],
    ]);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    $staff = User::factory()->create(['tenant_id' => $tenant->id]);
    $staff->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    $customer = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Kovács Anna '.$slug,
        'email' => 'anna@'.$slug.'.test',
    ]);
    $customer->assignRole(Role::Customer->value);

    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Hajvágás '.$slug]);
    Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'status' => BookingStatus::Completed,
        'price_minor' => 750_000,
    ]);

    return [$tenant, $staff, $customer];
}

/** @return array<string, mixed> */
function decodeExport(TestResponse $response): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('serves the tenant its own complete data set as valid JSON', function () {
    [, $staff] = exportFixture();

    $response = $this->actingAs($staff)
        ->get(tenantHost('acme', '/settings/privacy/export'))
        ->assertOk()
        ->assertHeader('content-type', 'application/json');

    // Streamed section by section from a cursor, so "is it still valid JSON"
    // is a real question and not a formality.
    $payload = decodeExport($response);

    expect($payload['tenant']['slug'])->toBe('acme')
        ->and($payload['services'][0]['name'])->toBe('Hajvágás acme')
        ->and($payload['customers'])->toHaveCount(2)
        ->and($payload['bookings'])->toHaveCount(1)
        ->and($payload['bookings'][0]['price_minor'])->toBe(750_000)
        ->and($payload)->toHaveKeys(['locations', 'rooms', 'staff', 'invoices', 'commission_invoices']);
});

it('never writes the invoicing provider credential into the file', function () {
    [, $staff] = exportFixture();

    $response = $this->actingAs($staff)->get(tenantHost('acme', '/settings/privacy/export'))->assertOk();

    // The `invoicing` column is cast `encrypted:array`, so serialising the
    // tenant model wholesale would decrypt the API key into the download.
    expect($response->streamedContent())->not->toContain('secret-agent-key')
        ->and($response->streamedContent())->not->toContain('api_key');
});

it('never writes a password hash into the file', function () {
    [, $staff] = exportFixture();

    $response = $this->actingAs($staff)->get(tenantHost('acme', '/settings/privacy/export'))->assertOk();

    $payload = decodeExport($response);

    expect($payload['customers'][0])->not->toHaveKey('password')
        ->and($payload['customers'][0])->not->toHaveKey('remember_token');
});

it('contains nothing belonging to another tenant', function () {
    [, $staff] = exportFixture('acme');
    exportFixture('other');

    // The fixture above rebinds the tenant; put the caller's back.
    app(TenantManager::class)->forget();

    $response = $this->actingAs($staff)->get(tenantHost('acme', '/settings/privacy/export'))->assertOk();

    expect($response->streamedContent())->toContain('Kovács Anna acme')
        ->and($response->streamedContent())->not->toContain('Kovács Anna other')
        ->and($response->streamedContent())->not->toContain('Hajvágás other');
});

it('refuses a staff member without privacy.manage', function () {
    // `employee` is a staff role but not the data-protection contact: the
    // export is a complete disclosure of the tenant's records.
    [, $staff] = exportFixture('acme', Role::Employee->value);

    $this->actingAs($staff)
        ->get(tenantHost('acme', '/settings/privacy/export'))
        ->assertForbidden();
});

it('refuses a customer', function () {
    [,, $customer] = exportFixture();

    $this->actingAs($customer)
        ->get(tenantHost('acme', '/settings/privacy/export'))
        ->assertForbidden();
});

it('redirects a guest to login', function () {
    exportFixture();

    $this->get(tenantHost('acme', '/settings/privacy/export'))
        ->assertRedirect();
});

it('records the export in the audit trail', function () {
    [$tenant, $staff] = exportFixture();

    $this->actingAs($staff)->get(tenantHost('acme', '/settings/privacy/export'))->assertOk();

    // Handing out a complete data set is a disclosure, so it is logged for the
    // same reason the customer export is (SLO-159).
    $entry = AuditLog::query()->where('action', AuditAction::TenantDataExported->value)->sole();

    expect($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->user_id)->toBe($staff->id);
});

it('lets a superadmin export an archived tenant during the grace window', function () {
    [$tenant] = exportFixture();

    app(ChangeTenantStatus::class)($tenant, TenantStatus::Archived);
    app(TenantManager::class)->forget();

    // The route the archive notice points at: an archived tenant's own
    // subdomain 404s, so slot4u serves the file on request.
    $response = $this->actingAs(superAdmin())
        ->get(superUrl('/tenants/'.$tenant->id.'/export'))
        ->assertOk();

    expect(decodeExport($response)['tenant']['slug'])->toBe('acme');
});
