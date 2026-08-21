<?php

use App\Enums\BookingMode;
use App\Enums\ConsentContext;
use App\Models\LegalConsent;
use App\Models\LegalDocument;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Privacy\PersonalDataExport;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Consent recorded at the entry points (SLO-161, GDPR art. 7(1))
|--------------------------------------------------------------------------
|
| The controller has to be able to demonstrate that the subject consented. The
| risk this file guards is not that acceptance is impossible — it is that ONE of
| the seven ways into this product quietly stops recording, which looks exactly
| like six that work.
|
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
 * A bookable duration_based service on `acme` with a Monday band, mirroring
 * GuestBookingTest so the slot below is real.
 *
 * @return array{0: Tenant, 1: Service, 2: Staff}
 */
function consentFlowService(): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
        'active' => true,
    ]);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach($staff->id);

    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay(Carbon::parse('2026-09-07')->isoWeekday(), '09:00', '17:00')
        ->create();

    return [$tenant, $service, $staff];
}

/** The tenant's privacy notice, in force. */
function consentDocument(Tenant $tenant): LegalDocument
{
    return LegalDocument::factory()->forTenant($tenant)->privacy()->create();
}

/**
 * @return array<string, mixed>
 */
function consentPayload(Service $service, Staff $staff, LegalDocument $document, array $overrides = []): array
{
    return array_merge([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07T07:00:00Z',
        'ends_at' => '2026-09-07T08:00:00Z',
        'name' => 'Teszt Vendég',
        'email' => 'guest@example.test',
        'phone' => '+3611234567',
        'accepted_legal' => true,
        'legal_document_ids' => [$document->id],
    ], $overrides);
}

function consents(Tenant $tenant)
{
    return LegalConsent::withoutGlobalScopes()->where('tenant_id', $tenant->id);
}

it('records a guest booking acceptance against the email, with the version and the ip', function () {
    // A true guest: the address already belongs to an account somewhere else on
    // the platform, so ResolvePublicContact refuses to claim it and the booking
    // carries the contact on the row instead (SLO-128). There is no user row to
    // point the consent at — the whole reason this table is not keyed on one.
    [$tenant, $service, $staff] = consentFlowService();
    $document = consentDocument($tenant);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    User::factory()->create(['tenant_id' => $other->id, 'email' => 'guest@example.test']);

    $this->post(tenantHost('acme', '/book'), consentPayload($service, $staff, $document))
        ->assertRedirect();

    $consent = consents($tenant)->sole();

    expect($consent->legal_document_id)->toBe($document->id)
        ->and($consent->context)->toBe(ConsentContext::Booking)
        ->and($consent->user_id)->toBeNull()
        ->and($consent->email)->toBe('guest@example.test')
        ->and($consent->accepted_at)->not->toBeNull()
        ->and($consent->ip_address)->not->toBeNull();
});

it('refuses a booking that did not accept, and creates nothing', function () {
    [$tenant, $service, $staff] = consentFlowService();
    $document = consentDocument($tenant);

    $this->from(tenantHost('acme', '/book'))
        ->post(tenantHost('acme', '/book'), consentPayload($service, $staff, $document, [
            'accepted_legal' => false,
        ]))
        ->assertSessionHasErrors('accepted_legal');

    expect(consents($tenant)->count())->toBe(0);
});

it('refuses a submission that accepted a version no longer in force', function () {
    // The race this exists for: the form was rendered against v1, the tenant
    // published v2 while it sat open, and the visitor ticked a box describing a
    // text that is no longer the one in force. Recording either version would be
    // a false record — so the submission is refused and the form re-shown.
    [$tenant, $service, $staff] = consentFlowService();
    $old = LegalDocument::factory()->forTenant($tenant)->privacy()
        ->version('1.0')->effectiveAt(now()->subMonth())->create();
    LegalDocument::factory()->forTenant($tenant)->privacy()
        ->version('2.0')->effectiveAt(now()->subMinute())->create();

    $this->from(tenantHost('acme', '/book'))
        ->post(tenantHost('acme', '/book'), consentPayload($service, $staff, $old))
        ->assertSessionHasErrors('accepted_legal');

    expect(consents($tenant)->count())->toBe(0);
});

it('asks for nothing when the tenant has published nothing', function () {
    // A tenant that has not written a privacy notice must not have its booking
    // page refuse every visitor over a setting it does not know exists.
    [$tenant, $service, $staff] = consentFlowService();

    $payload = consentPayload($service, $staff, LegalDocument::factory()->forTenant($tenant)->make());
    unset($payload['accepted_legal'], $payload['legal_document_ids']);

    $this->post(tenantHost('acme', '/book'), $payload)->assertRedirect();

    expect(consents($tenant)->count())->toBe(0);
});

it('ignores a draft version that has not come into force yet', function () {
    [$tenant, $service, $staff] = consentFlowService();
    $inForce = LegalDocument::factory()->forTenant($tenant)->privacy()
        ->version('1.0')->effectiveAt(now()->subDay())->create();
    LegalDocument::factory()->forTenant($tenant)->privacy()
        ->version('2.0')->draft()->create();

    $this->post(tenantHost('acme', '/book'), consentPayload($service, $staff, $inForce))
        ->assertRedirect();

    expect(consents($tenant)->sole()->legal_document_id)->toBe($inForce->id);
});

it('records an acceptance against the user when the booking produced one', function () {
    // An unclaimed address becomes a customer of this tenant (SLO-128), so the
    // consent has a user row to name. One handle, not two: the user row already
    // carries the address, and a second copy would be a second thing to keep in
    // step through an erasure.
    [$tenant, $service, $staff] = consentFlowService();
    $document = consentDocument($tenant);

    $this->post(tenantHost('acme', '/book'), consentPayload($service, $staff, $document, [
        'email' => 'brand-new@example.test',
    ]))->assertRedirect();

    $consent = consents($tenant)->sole();
    $customer = User::query()->where('email', 'brand-new@example.test')->sole();

    expect($consent->user_id)->toBe($customer->id)
        ->and($consent->email)->toBeNull();
});

it('records one acceptance per booking rather than deduplicating', function () {
    // Consent is an act. Collapsing a customer's second booking onto the
    // acceptance they gave at the first would throw away the second's timestamp
    // and circumstances — the evidence of an act that did happen.
    [$tenant, $service, $staff] = consentFlowService();
    $document = consentDocument($tenant);

    $this->post(tenantHost('acme', '/book'), consentPayload($service, $staff, $document))
        ->assertRedirect();
    $this->post(tenantHost('acme', '/book'), consentPayload($service, $staff, $document, [
        'starts_at' => '2026-09-07T08:00:00Z',
        'ends_at' => '2026-09-07T09:00:00Z',
    ]))->assertRedirect();

    expect(consents($tenant)->count())->toBe(2);
});

it('records an acceptance for every document in force, not just the first', function () {
    [$tenant, $service, $staff] = consentFlowService();
    $privacy = LegalDocument::factory()->forTenant($tenant)->privacy()->create();
    $terms = LegalDocument::factory()->forTenant($tenant)->terms()->create();

    $this->post(tenantHost('acme', '/book'), consentPayload($service, $staff, $privacy, [
        'legal_document_ids' => [$privacy->id, $terms->id],
    ]))->assertRedirect();

    expect(consents($tenant)->pluck('legal_document_id')->sort()->values()->all())
        ->toBe(collect([$privacy->id, $terms->id])->sort()->values()->all());
});

it('records the acceptance a customer gives when registering', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $document = consentDocument($tenant);

    $this->post(tenantHost('acme', '/register'), [
        'name' => 'Új Ügyfél',
        'email' => 'new@example.test',
        'phone' => '+3611234567',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'accepted_legal' => true,
        'legal_document_ids' => [$document->id],
    ])->assertRedirect();

    $consent = consents($tenant)->sole();
    $user = User::query()->where('email', 'new@example.test')->sole();

    expect($consent->context)->toBe(ConsentContext::CustomerRegistration)
        ->and($consent->user_id)->toBe($user->id);
});

it('refuses a customer registration without acceptance, and creates no user', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    consentDocument($tenant);

    $this->from(tenantHost('acme', '/register'))
        ->post(tenantHost('acme', '/register'), [
            'name' => 'Új Ügyfél',
            'email' => 'new@example.test',
            'phone' => '+3611234567',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertSessionHasErrors('accepted_legal');

    expect(User::query()->where('email', 'new@example.test')->exists())->toBeFalse();
});

it('records the platform acceptance a company gives when signing up, inside the same transaction', function () {
    $terms = LegalDocument::factory()->platform()->terms()->create();
    $privacy = LegalDocument::factory()->platform()->privacy()->create();

    $this->post('http://'.config('tenancy.central_domain').'/register', [
        'company_name' => 'Teszt Kft.',
        'slug' => 'teszt-kft',
        'name' => 'Cég Admin',
        'email' => 'admin@teszt.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'accepted_legal' => true,
        'legal_document_ids' => [$terms->id, $privacy->id],
    ])->assertRedirect();

    $tenant = Tenant::query()->where('slug', 'teszt-kft')->sole();

    // The acceptance belongs to the tenant it created, even though the document
    // is the platform's: it is that tenant's own evidence.
    expect(consents($tenant)->count())->toBe(2)
        ->and(consents($tenant)->first()->context)->toBe(ConsentContext::TenantRegistration);
});

it('creates no tenant at all when the sign-up did not accept', function () {
    LegalDocument::factory()->platform()->terms()->create();

    $this->from('http://'.config('tenancy.central_domain').'/register')
        ->post('http://'.config('tenancy.central_domain').'/register', [
            'company_name' => 'Teszt Kft.',
            'slug' => 'teszt-kft',
            'name' => 'Cég Admin',
            'email' => 'admin@teszt.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertSessionHasErrors('accepted_legal');

    expect(Tenant::withTrashed()->where('slug', 'teszt-kft')->exists())->toBeFalse();
});

it('includes the acceptances in the customer own data export (art. 15)', function () {
    // The evidence used to justify processing someone's data is data about them.
    [$tenant, $service, $staff] = consentFlowService();
    $document = consentDocument($tenant);

    $this->post(tenantHost('acme', '/book'), consentPayload($service, $staff, $document, [
        'email' => 'exporter@example.test',
    ]))->assertRedirect();

    $customer = User::query()->where('email', 'exporter@example.test')->sole();
    app(TenantManager::class)->set($tenant);

    $export = app(PersonalDataExport::class)->for($customer);

    expect($export['consents'])->toHaveCount(1)
        ->and($export['consents'][0]['document_version'])->toBe($document->version)
        ->and($export['consents'][0]['context'])->toBe('booking');
});
