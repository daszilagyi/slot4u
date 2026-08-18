<?php

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestMessage;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Privacy\AnonymizeCustomer;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * The erasure sweep (SLO-159, docs/19) — the headline acceptance criterion:
 * after a request is honoured, the personal data appears NOWHERE.
 *
 * Written as a sweep over the live database schema rather than a list of tables
 * somebody remembered: a new column holding a customer's name should fail this
 * test the day it lands, not the day a regulator asks. Every text-shaped column
 * in every table is searched for the erased name, email and phone.
 *
 * The other half of the criterion is what must SURVIVE. An erasure that took
 * the tenant's turnover with it would silently rewrite what the tenant owes
 * slot4u (docs/10 §3.1), and one that destroyed an issued invoice would break
 * an eight-year retention duty (Szt. 169. §) that art. 17 (3) (b) explicitly
 * exempts. Both are asserted here so a future "tidy-up" cannot quietly widen
 * the erasure into them.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

const ERASURE_NAME = 'Kovács Anna';
const ERASURE_EMAIL = 'anna@example.test';
const ERASURE_PHONE = '+36301234567';

/**
 * A tenant with one customer whose data is spread across every table that can
 * hold it. Returns [tenant, customer].
 *
 * @return array{Tenant, User}
 */
function erasureFixture(): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    $customer = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => ERASURE_NAME,
        'email' => ERASURE_EMAIL,
        'phone' => ERASURE_PHONE,
    ]);
    $customer->assignRole(Role::Customer->value);

    $service = Service::factory()->forTenant($tenant)->create();

    // Linked booking, with the free-text note a receptionist would write.
    $booking = Booking::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'status' => BookingStatus::Completed,
        'price_minor' => 1_500_000,
        'notes' => ERASURE_NAME.' allergiás a levendulára',
    ]);

    // Guest booking made with the same email but never linked to the account.
    Booking::factory()->forTenant($tenant)->create([
        'customer_id' => null,
        'service_id' => $service->id,
        'status' => BookingStatus::Completed,
        'price_minor' => 500_000,
        'guest_name' => ERASURE_NAME,
        'guest_email' => ERASURE_EMAIL,
        'guest_phone' => ERASURE_PHONE,
    ]);

    $quote = QuoteRequest::factory()->forTenant($tenant)->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'guest_name' => ERASURE_NAME,
        'guest_email' => ERASURE_EMAIL,
        'guest_phone' => ERASURE_PHONE,
        'parameters' => ['contact' => ERASURE_NAME, 'phone' => ERASURE_PHONE],
        'internal_notes' => ERASURE_NAME.' korábban lemondott',
    ]);

    QuoteRequestMessage::factory()->create([
        'quote_request_id' => $quote->id,
        'user_id' => $customer->id,
        'body' => 'Üdv, '.ERASURE_NAME.' vagyok, a telefonom '.ERASURE_PHONE,
    ]);

    $event = Event::factory()->forTenant($tenant)->create(['service_id' => $service->id]);
    WaitlistEntry::factory()->forTenant($tenant)->forEvent($event)->create([
        'customer_id' => $customer->id,
    ]);

    NotificationLog::factory()->forTenant($tenant)->create(['recipient' => ERASURE_EMAIL]);
    NotificationLog::factory()->forTenant($tenant)->create(['recipient' => ERASURE_PHONE, 'channel' => 'sms']);

    $payment = Payment::factory()->forBooking($booking)->paid()->create();
    Invoice::factory()->forPayment($payment)->issued()->create();

    return [$tenant, $customer];
}

/**
 * Every table/column pair in the live schema that could hold a string, searched
 * for `$needle`. Returns "table.column" for each hit.
 *
 * @return list<string>
 */
function erasureSweep(string $needle): array
{
    $hits = [];

    foreach (Schema::getTableListing() as $table) {
        // SQLite reports names schema-qualified ("main.bookings"), MariaDB bare.
        // Compare on the bare name or the skip list below never matches and the
        // sweep quietly searches the queue tables too — where a serialised job
        // payload legitimately holds a recipient address mid-flight.
        $bare = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        // Framework bookkeeping, not tenant data.
        if (in_array($bare, ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'], true)) {
            continue;
        }

        foreach (Schema::getColumns($table) as $column) {
            $type = strtolower((string) $column['type_name']);

            if (! in_array($type, ['varchar', 'text', 'char', 'json', 'longtext', 'mediumtext', 'blob'], true)) {
                continue;
            }

            $found = DB::table($table)
                ->where($column['name'], 'like', '%'.$needle.'%')
                ->exists();

            if ($found) {
                $hits[] = $table.'.'.$column['name'];
            }
        }
    }

    return $hits;
}

it('finds the personal data everywhere before the erasure', function () {
    erasureFixture();

    // Validates the sweep itself: if this were empty the "nothing found after"
    // assertion below would pass on a harness that searches nothing.
    expect(erasureSweep(ERASURE_NAME))->not->toBeEmpty()
        ->and(erasureSweep(ERASURE_EMAIL))->not->toBeEmpty()
        ->and(erasureSweep(ERASURE_PHONE))->not->toBeEmpty();
});

it('leaves no trace of the name, email or phone after the erasure', function () {
    [$tenant, $customer] = erasureFixture();

    app(AnonymizeCustomer::class)->erase($customer, $tenant);

    expect(erasureSweep(ERASURE_NAME))->toBe([])
        ->and(erasureSweep(ERASURE_EMAIL))->toBe([])
        ->and(erasureSweep(ERASURE_PHONE))->toBe([]);
});

it('keeps the booking history and the turnover the commission is based on', function () {
    [$tenant, $customer] = erasureFixture();

    $before = Booking::query()->where('tenant_id', $tenant->id)->sum('price_minor');
    $countBefore = Booking::query()->where('tenant_id', $tenant->id)->count();

    app(AnonymizeCustomer::class)->erase($customer, $tenant);

    $after = Booking::query()->where('tenant_id', $tenant->id)->sum('price_minor');

    expect(Booking::query()->where('tenant_id', $tenant->id)->count())->toBe($countBefore)
        // Turnover is the commission base (docs/10 §3.1) — an erasure that moved
        // it would rewrite what the tenant already owes slot4u.
        ->and($after)->toBe($before)
        ->and($after)->toBe(2_000_000);

    $booking = Booking::query()->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)->sole();

    expect($booking->status)->toBe(BookingStatus::Completed)
        ->and($booking->starts_at)->not->toBeNull()
        ->and($booking->service_id)->not->toBeNull()
        // The link survives so the history stays one person's, just an unnamed one.
        ->and($booking->customer_id)->toBe($customer->id);
});

it('keeps the issued invoice and its PDF, which the law requires', function () {
    [$tenant, $customer] = erasureFixture();

    $invoice = Invoice::query()->where('tenant_id', $tenant->id)->sole();
    $number = $invoice->number;
    $pdfPath = $invoice->pdf_path;

    app(AnonymizeCustomer::class)->erase($customer, $tenant);

    $invoice->refresh();

    // Art. 17 (3) (b): the erasure right yields to a legal retention duty
    // (Szt. 169. §, eight years). This is an exception on purpose, not an
    // oversight — widening the erasure into it would break the tenant's books.
    expect($invoice->number)->toBe($number)
        ->and($invoice->pdf_path)->toBe($pdfPath)
        ->and($invoice->amount_minor)->toBeGreaterThan(0);
});

it('erases the guest booking made with the same email', function () {
    [$tenant, $customer] = erasureFixture();

    app(AnonymizeCustomer::class)->erase($customer, $tenant);

    $guestBooking = Booking::query()
        ->where('tenant_id', $tenant->id)
        ->whereNull('customer_id')
        ->sole();

    expect($guestBooking->guest_name)->toBeNull()
        ->and($guestBooking->guest_email)->toBeNull()
        ->and($guestBooking->guest_phone)->toBeNull()
        // Still a booking that happened, for the tenant's own record.
        ->and($guestBooking->price_minor)->toBe(500_000);
});

it('deletes the waitlist places so no offer can reach the erased account', function () {
    [$tenant, $customer] = erasureFixture();

    expect(WaitlistEntry::query()->where('customer_id', $customer->id)->count())->toBe(1);

    app(AnonymizeCustomer::class)->erase($customer, $tenant);

    expect(WaitlistEntry::query()->where('customer_id', $customer->id)->count())->toBe(0);
});

it('redacts the send ledger without dropping its rows', function () {
    [$tenant, $customer] = erasureFixture();

    $before = NotificationLog::query()->where('tenant_id', $tenant->id)->count();

    app(AnonymizeCustomer::class)->erase($customer, $tenant);

    // The rows carry the dedupe keys that make sends exactly-once; deleting them
    // could resurrect a notification for a person who just asked to be erased.
    expect(NotificationLog::query()->where('tenant_id', $tenant->id)->count())->toBe($before)
        ->and(NotificationLog::query()->where('recipient', 'redacted')->count())->toBe(2);
});

it('leaves the account unusable', function () {
    [$tenant, $customer] = erasureFixture();
    $hashBefore = $customer->password;

    app(AnonymizeCustomer::class)->erase($customer, $tenant);
    $customer->refresh();

    expect($customer->anonymized_at)->not->toBeNull()
        ->and($customer->email)->toBe('anonymized-'.$customer->id.'@invalid')
        ->and($customer->phone)->toBeNull()
        ->and($customer->email_verified_at)->toBeNull()
        ->and($customer->remember_token)->toBeNull()
        // A known-to-nobody credential, so the row cannot be logged into.
        ->and($customer->password)->not->toBe($hashBefore);
});

it('names the erased customer from the tenant locale, not a hardcoded string', function () {
    [$tenant, $customer] = erasureFixture();

    app(AnonymizeCustomer::class)->erase($customer, $tenant);

    // The stored placeholder comes from lang/hu/app.php, so the admin lists that
    // render it raw stay translated without every one of them being retrofitted.
    expect($customer->fresh()->name)->toBe(trans('app.privacy.erased_customer', [], $tenant->locale));
});

it('is idempotent so a retry cannot double-apply', function () {
    [$tenant, $customer] = erasureFixture();

    app(AnonymizeCustomer::class)->erase($customer, $tenant);
    $emailAfterFirst = $customer->fresh()->email;

    app(AnonymizeCustomer::class)->erase($customer->fresh(), $tenant);

    expect($customer->fresh()->email)->toBe($emailAfterFirst);
});

it('does not touch another tenant records for the same person', function () {
    [$tenant, $customer] = erasureFixture();

    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->set($other);
    app(PermissionRegistrar::class)->setPermissionsTeamId($other->getKey());
    $otherService = Service::factory()->forTenant($other)->create();
    $otherBooking = Booking::factory()->forTenant($other)->create([
        'customer_id' => null,
        'service_id' => $otherService->id,
        'guest_name' => ERASURE_NAME,
        'guest_email' => ERASURE_EMAIL,
    ]);

    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    app(AnonymizeCustomer::class)->erase($customer, $tenant);

    // Separate controllers, separate records: erasing at one tenant must not
    // reach into another's books, and the subject has to ask each of them.
    expect($otherBooking->fresh()->guest_email)->toBe(ERASURE_EMAIL);
});

it('deletes the database sessions of the erased account', function () {
    [$tenant, $customer] = erasureFixture();

    // The suite runs on the array session driver, so the branch is exercised by
    // pointing the config at the table the production config uses.
    config()->set('session.driver', 'database');
    config()->set('session.table', 'sessions');

    DB::table('sessions')->insert([
        'id' => 'session-under-test',
        'user_id' => $customer->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'pest',
        'payload' => 'x',
        'last_activity' => time(),
    ]);

    app(AnonymizeCustomer::class)->erase($customer, $tenant);

    // An erasure that left a live session would leave the account reachable.
    expect(DB::table('sessions')->where('user_id', $customer->id)->exists())->toBeFalse();
});
