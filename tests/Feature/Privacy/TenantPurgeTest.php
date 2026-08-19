<?php

use App\Actions\Tenant\ChangeTenantStatus;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CommissionInvoice;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\PrivacyRequest;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestMessage;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Privacy\PurgeTenant;
use App\Services\Privacy\RetentionStep;
use App\Services\Privacy\RetentionSweep;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fixtures\PersonalDataSweep;

/**
 * The archived-tenant purge (SLO-160, docs/19 §7) — the retention counterpart to
 * the single-customer erasure.
 *
 * Two promises are tested here and they pull in opposite directions. After the
 * grace period **no personal data of the tenant's people may remain anywhere**,
 * proved by the same live-schema sweep the art. 17 erasure uses. And yet the
 * **issued invoices, the commission invoices and the turnover behind them must
 * survive**, because slot4u and the tenant both owe eight years of accounting
 * records (Szt. 169. §) and the turnover is what slot4u already billed on
 * (docs/10 §3.1). A purge that satisfied only one of the two would be a bug in
 * either direction.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

const PURGE_CUSTOMER_NAME = 'Kovács Anna';
const PURGE_CUSTOMER_EMAIL = 'anna@example.test';
const PURGE_CUSTOMER_PHONE = '+36301234567';
const PURGE_STAFF_NAME = 'Nagy Béla';
const PURGE_STAFF_EMAIL = 'bela@example.test';
const PURGE_GUEST_NAME = 'Szabó Csaba';
const PURGE_GUEST_EMAIL = 'csaba@example.test';
const PURGE_TENANT_PHONE = '+3612223333';

/**
 * A tenant archived `$archivedDaysAgo` days ago, with personal data spread
 * across every table that can hold it — including a guest booking belonging to
 * someone who never had an account, which a purge that only loops over `users`
 * would leave standing.
 *
 * @return array{Tenant, User}
 */
function purgeFixture(int $archivedDaysAgo = 91): array
{
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'settings' => ['phone' => PURGE_TENANT_PHONE, 'email' => 'iroda@acme.test', 'slot_interval_minutes' => 30],
        'invoicing' => ['provider' => 'szamlazzhu', 'api_key' => 'secret-agent-key'],
    ]);

    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    $customer = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => PURGE_CUSTOMER_NAME,
        'email' => PURGE_CUSTOMER_EMAIL,
        'phone' => PURGE_CUSTOMER_PHONE,
    ]);
    $customer->assignRole(Role::Customer->value);

    $employee = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => PURGE_STAFF_NAME,
        'email' => PURGE_STAFF_EMAIL,
    ]);
    $employee->assignRole(Role::TenantAdmin->value);

    $location = Location::factory()->forTenant($tenant)->create(['phone' => PURGE_TENANT_PHONE]);
    Staff::factory()->forTenant($tenant)->create([
        'user_id' => $employee->id,
        'name' => PURGE_STAFF_NAME,
        'bio' => PURGE_STAFF_NAME.' tíz éve dolgozik itt',
    ]);

    $service = Service::factory()->forTenant($tenant)->create();

    $booking = Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'status' => BookingStatus::Completed,
        'price_minor' => 1_500_000,
        'notes' => PURGE_CUSTOMER_NAME.' allergiás a levendulára',
    ]);

    // A guest who never registered: matched to no user row at all.
    Booking::factory()->forTenant($tenant)->create([
        'customer_id' => null,
        'service_id' => $service->id,
        'status' => BookingStatus::Completed,
        'price_minor' => 500_000,
        'guest_name' => PURGE_GUEST_NAME,
        'guest_email' => PURGE_GUEST_EMAIL,
    ]);

    $quote = QuoteRequest::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'guest_name' => PURGE_GUEST_NAME,
        'guest_email' => PURGE_GUEST_EMAIL,
        'internal_notes' => PURGE_CUSTOMER_NAME.' korábban lemondott',
        'parameters' => ['contact' => PURGE_GUEST_NAME],
    ]);
    QuoteRequestMessage::factory()->create([
        'quote_request_id' => $quote->id,
        'user_id' => $customer->id,
        'body' => 'Üdv, '.PURGE_CUSTOMER_NAME.' vagyok',
    ]);

    $event = Event::factory()->forTenant($tenant)->create(['service_id' => $service->id]);
    WaitlistEntry::factory()->forTenant($tenant)->forEvent($event)->create(['customer_id' => $customer->id]);

    NotificationLog::factory()->forTenant($tenant)->create(['recipient' => PURGE_CUSTOMER_EMAIL]);

    PrivacyRequest::factory()->forTenant($tenant)->create([
        'user_id' => $customer->id,
        'resolution_note' => PURGE_CUSTOMER_NAME.' jogvitája miatt elutasítva',
    ]);

    $payment = Payment::factory()->forBooking($booking)->paid()->create();
    Invoice::factory()->forPayment($payment)->issued()->create();
    // No forTenant() state on this factory: BelongsToTenant stamps the bound
    // tenant on create, which is the tenant set above.
    CommissionInvoice::factory()->create(['tenant_id' => $tenant->id]);

    expect($location->phone)->toBe(PURGE_TENANT_PHONE);

    // Archive it, then backdate the archive instant: `deleted_at` is what the
    // sweep measures the grace window from.
    app(ChangeTenantStatus::class)($tenant, TenantStatus::Archived);
    $tenant->forceFill(['deleted_at' => Carbon::now()->subDays($archivedDaysAgo)])->saveQuietly();

    // The sweep is a console job: no tenant is bound while it runs, and the
    // tenant global scope is silent there. Leaving one bound would test a
    // context the scheduler never has.
    app(TenantManager::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // withTrashed: the tenant is soft-deleted by now, so a plain fresh() is null.
    return [Tenant::withTrashed()->findOrFail($tenant->id), $customer];
}

/** @return list<RetentionStep> */
function runSweep(): array
{
    return app(RetentionSweep::class)->run();
}

it('finds the personal data everywhere before the purge', function () {
    purgeFixture();

    // Validates the harness: without this, "nothing found after" would pass on
    // a sweep that searches nothing.
    expect(PersonalDataSweep::find(PURGE_CUSTOMER_NAME))->not->toBeEmpty()
        ->and(PersonalDataSweep::find(PURGE_GUEST_EMAIL))->not->toBeEmpty()
        ->and(PersonalDataSweep::find(PURGE_STAFF_NAME))->not->toBeEmpty()
        ->and(PersonalDataSweep::find(PURGE_TENANT_PHONE))->not->toBeEmpty();
});

it('leaves no trace of any person after the grace period', function () {
    purgeFixture();

    runSweep();

    expect(PersonalDataSweep::find(PURGE_CUSTOMER_NAME))->toBe([])
        ->and(PersonalDataSweep::find(PURGE_CUSTOMER_EMAIL))->toBe([])
        ->and(PersonalDataSweep::find(PURGE_CUSTOMER_PHONE))->toBe([])
        ->and(PersonalDataSweep::find(PURGE_STAFF_NAME))->toBe([])
        ->and(PersonalDataSweep::find(PURGE_STAFF_EMAIL))->toBe([])
        // The guest who never registered — the case a per-user loop would miss.
        ->and(PersonalDataSweep::find(PURGE_GUEST_NAME))->toBe([])
        ->and(PersonalDataSweep::find(PURGE_GUEST_EMAIL))->toBe([])
        ->and(PersonalDataSweep::find(PURGE_TENANT_PHONE))->toBe([]);
});

it('does not purge a tenant one day short of the window', function () {
    // 89 days: the boundary case the acceptance criterion names. A sweep that
    // used `<` on days rather than the archive instant would fire here.
    [$tenant] = purgeFixture(archivedDaysAgo: 89);

    runSweep();

    expect($tenant->fresh()?->purged_at)->toBeNull()
        ->and(PersonalDataSweep::find(PURGE_CUSTOMER_NAME))->not->toBeEmpty();
});

it('purges a tenant one day past the window', function () {
    [$tenant] = purgeFixture(archivedDaysAgo: 91);

    runSweep();

    expect($tenant->fresh()?->purged_at)->not->toBeNull();
});

it('keeps the turnover the commission was billed on', function () {
    [$tenant] = purgeFixture();

    $before = Booking::query()->where('tenant_id', $tenant->id)->sum('price_minor');

    runSweep();

    // slot4u already invoiced this tenant on this turnover (docs/10 §3.1). A
    // purge that moved it would rewrite the platform's own revenue history.
    expect(Booking::query()->where('tenant_id', $tenant->id)->sum('price_minor'))
        ->toBe($before)
        ->toBe(2_000_000)
        ->and(Booking::query()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

it('keeps the issued invoices and the commission invoices the law requires', function () {
    [$tenant] = purgeFixture();

    $invoice = Invoice::query()->where('tenant_id', $tenant->id)->sole();
    $number = $invoice->number;
    $pdfPath = $invoice->pdf_path;

    runSweep();

    $invoice->refresh();

    // Art. 17 (3) (b) yields to the eight-year accounting duty (Szt. 169. §) —
    // for the tenant's own invoices and for slot4u's invoices to the tenant.
    expect($invoice->number)->toBe($number)
        ->and($invoice->pdf_path)->toBe($pdfPath)
        ->and(CommissionInvoice::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('keeps the tenant row and its name so the invoices still name a counterparty', function () {
    [$tenant] = purgeFixture();

    $name = $tenant->name;

    runSweep();

    $purged = Tenant::withTrashed()->find($tenant->id);

    expect($purged)->not->toBeNull()
        ->and($purged?->name)->toBe($name)
        ->and($purged?->trashed())->toBeTrue();
});

it('clears the invoicing provider credential', function () {
    [$tenant] = purgeFixture();

    expect($tenant->invoicing)->not->toBeNull();

    runSweep();

    // A credential belonging to a company that has left has no reason to sit in
    // the database, encrypted or not.
    expect(Tenant::withTrashed()->find($tenant->id)?->invoicing)->toBeNull();
});

it('keeps the booking-rule settings while dropping the contact block', function () {
    [$tenant] = purgeFixture();

    runSweep();

    $settings = Tenant::withTrashed()->find($tenant->id)?->settings ?? [];

    expect($settings)->not->toHaveKey('phone')
        ->and($settings)->not->toHaveKey('email')
        // Configuration that identifies nobody; dropping it would leave the
        // JSON in a shape TenantSettings::fromArray has to guess at.
        ->and($settings['slot_interval_minutes'] ?? null)->toBe(30);
});

it('redacts the send ledger without dropping its rows', function () {
    [$tenant] = purgeFixture();

    $before = NotificationLog::query()->where('tenant_id', $tenant->id)->count();

    runSweep();

    // The rows carry the dedupe keys that make sends exactly-once.
    expect(NotificationLog::query()->where('tenant_id', $tenant->id)->count())->toBe($before)
        ->and(NotificationLog::query()->where('tenant_id', $tenant->id)->where('recipient', 'redacted')->count())
        ->toBe($before);
});

it('deletes the waitlist places so no offer can reach anyone', function () {
    [$tenant] = purgeFixture();

    runSweep();

    expect(WaitlistEntry::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('does not purge a tenant that was restored before the sweep ran', function () {
    [$tenant] = purgeFixture();

    // The race the acceptance criterion names: the tenant comes back to life
    // between being archived and the nightly sweep reaching it.
    app(ChangeTenantStatus::class)($tenant, TenantStatus::Active);

    runSweep();

    $restored = Tenant::query()->find($tenant->id);

    expect($restored)->not->toBeNull()
        ->and($restored?->purged_at)->toBeNull()
        ->and(PersonalDataSweep::find(PURGE_CUSTOMER_NAME))->not->toBeEmpty();
});

/*
 * The two guards below are deliberately redundant: the sweep filters the
 * candidate list, and PurgeTenant re-checks under the row lock. The sweep tests
 * above can only ever prove that *one* of them held, so each is exercised
 * directly here — otherwise removing either would go unnoticed.
 */
it('refuses at lock time to purge a tenant that is not yet past the cutoff', function () {
    [$tenant] = purgeFixture(archivedDaysAgo: 91);

    // A cutoff older than the archive instant: what the re-check sees when the
    // candidate list was built against a different moment.
    $purged = app(PurgeTenant::class)
        ->purge($tenant, Carbon::now()->subDays(200));

    expect($purged)->toBeFalse()
        ->and(Tenant::withTrashed()->find($tenant->id)?->purged_at)->toBeNull();
});

it('refuses at lock time to purge a tenant restored since the candidate list was built', function () {
    [$tenant] = purgeFixture(archivedDaysAgo: 91);

    $cutoff = Carbon::now()->subDays(90);

    // The restore lands between the sweep selecting the tenant and reaching it.
    app(ChangeTenantStatus::class)($tenant, TenantStatus::Active);

    $purged = app(PurgeTenant::class)->purge($tenant, $cutoff);

    expect($purged)->toBeFalse()
        ->and(PersonalDataSweep::find(PURGE_CUSTOMER_NAME))->not->toBeEmpty();
});

it('does not touch another tenant', function () {
    purgeFixture();

    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->set($other);
    app(PermissionRegistrar::class)->setPermissionsTeamId($other->getKey());

    $service = Service::factory()->forTenant($other)->create();
    $booking = Booking::factory()->forTenant($other)->create([
        'customer_id' => null,
        'service_id' => $service->id,
        'guest_name' => 'Idegen Ilona',
        'guest_email' => 'ilona@other.test',
    ]);

    app(TenantManager::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    runSweep();

    expect($booking->fresh()?->guest_email)->toBe('ilona@other.test');
});

it('is idempotent so a second run changes nothing', function () {
    [$tenant] = purgeFixture();

    runSweep();
    $purgedAt = Tenant::withTrashed()->find($tenant->id)?->purged_at;

    runSweep();

    // The second pass must skip the tenant entirely — not re-run and re-stamp,
    // which would make an interrupted sweep indistinguishable from a finished one.
    expect(Tenant::withTrashed()->find($tenant->id)?->purged_at?->toIso8601String())
        ->toBe($purgedAt?->toIso8601String())
        ->and(AuditLog::query()->where('action', AuditAction::TenantPurged->value)->count())->toBe(1);
});

it('records the purge in the audit trail without copying what it erased', function () {
    purgeFixture();

    runSweep();

    $entry = AuditLog::query()->where('action', AuditAction::TenantPurged->value)->sole();

    // An audit row holding the purged values would defeat the purge.
    expect(json_encode($entry->new_values))->not->toContain(PURGE_CUSTOMER_NAME)
        ->and(json_encode($entry->old_values))->not->toContain(PURGE_CUSTOMER_EMAIL);
});
