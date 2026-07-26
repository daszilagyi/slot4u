<?php

use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
 * Members area — "my payments" (SLO-132). A payment has no customer of its own, so
 * the list is self-scoped through the booking's customer_id: another customer's
 * (and another tenant's) payments must never appear, and a guest booking has no
 * owner to show it to.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    Carbon::setTestNow('2026-09-01 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** Active tenant with the online-payment integration on, current + team context. */
function myPayTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::factory()->active()->create(['slug' => $slug]);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    TenantFeature::factory()->create([
        'tenant_id' => $tenant->id,
        'feature_code' => Feature::OnlinePayment,
        'enabled' => true,
    ]);

    return $tenant;
}

function myPayCustomer(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::Customer->value);

    return $user;
}

/** A settled payment on a booking of `$customer` (or a guest booking when null). */
function myPayPayment(Tenant $tenant, ?User $customer, int $amountMinor = 100000, array $paymentState = []): Payment
{
    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Uszodabérlet']);

    $booking = Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'service_id' => $service->id,
        'customer_id' => $customer?->getKey(),
        'price_minor' => $amountMinor,
        'starts_at' => Carbon::parse('2026-09-10 08:00:00'),
        'ends_at' => Carbon::parse('2026-09-10 09:00:00'),
    ]);

    return Payment::factory()->forBooking($booking)->paid()->create([
        'amount_minor' => $amountMinor,
        ...$paymentState,
    ]);
}

it('lists only the customer\'s own payments, newest first', function () {
    $tenant = myPayTenant();
    $me = myPayCustomer($tenant);
    $other = myPayCustomer($tenant);

    $older = myPayPayment($tenant, $me, 50000);
    $newer = myPayPayment($tenant, $me, 70000);
    myPayPayment($tenant, $other, 90000);
    // A guest booking has no account behind it — it belongs to nobody's list.
    myPayPayment($tenant, null, 30000);

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/payments'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/My/Payments')
            ->has('payments', 2)
            ->where('payments.0.id', $newer->id)
            ->where('payments.0.amount_minor', 70000)
            ->where('payments.0.service_name', 'Uszodabérlet')
            ->where('payments.0.status', PaymentStatus::Paid->value)
            ->where('payments.0.paid_local', '2026-09-01 14:00')
            ->where('payments.0.booking_starts_local', '2026-09-10 10:00')
            ->where('payments.1.id', $older->id)
        );
});

it('shows the refunds that are on their way or arrived, and hides refused ones', function () {
    $tenant = myPayTenant();
    $me = myPayCustomer($tenant);
    $payment = myPayPayment($tenant, $me, 100000);

    Refund::factory()->forPayment($payment)->completed()->create(['amount_minor' => 40000]);
    Refund::factory()->forPayment($payment)->create(['amount_minor' => 10000]); // pending
    // A refused refund is the tenant's problem to retry — never promised to the customer.
    Refund::factory()->forPayment($payment)->failed()->create(['amount_minor' => 90000]);

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/payments'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('payments.0.refunds', 2)
            ->where('payments.0.refunds.0.amount_minor', 40000)
            ->where('payments.0.refunds.0.status', RefundStatus::Completed->value)
            ->where('payments.0.refunds.1.status', RefundStatus::Pending->value)
        );
});

it('lists a failed attempt too, so the customer can see why nothing went through', function () {
    $tenant = myPayTenant();
    $me = myPayCustomer($tenant);
    myPayPayment($tenant, $me, 25000, ['status' => PaymentStatus::Failed, 'paid_at' => null]);

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/payments'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('payments', 1)
            ->where('payments.0.status', PaymentStatus::Failed->value)
            ->where('payments.0.paid_local', null)
            ->where('payments.0.created_local', '2026-09-01 14:00')
        );
});

it('never shows another tenant\'s payments', function () {
    $tenant = myPayTenant();
    $me = myPayCustomer($tenant);
    myPayPayment($tenant, $me, 100000);

    $other = myPayTenant('other');
    // Same person, an account at the other tenant: that payment stays over there.
    $meThere = myPayCustomer($other);
    myPayPayment($other, $meThere, 777000);

    app(TenantManager::class)->set($tenant);

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/payments'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('payments', 1)
            ->where('payments.0.amount_minor', 100000)
        );
});

it('loads the list without an N+1 over the payments', function () {
    $tenant = myPayTenant();
    $me = myPayCustomer($tenant);

    foreach (range(1, 6) as $i) {
        $payment = myPayPayment($tenant, $me, 10000 * $i);
        Refund::factory()->forPayment($payment)->completed()->create(['amount_minor' => 1000]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($me)->get(tenantHost('acme', '/my/payments'))->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // Each relation is loaded once for the whole list, not once per payment. The
    // `from "payments"` exclusion skips the list query itself, whose ownership
    // filter carries a `bookings` subselect.
    $loads = fn (string $table): int => $queries
        ->filter(fn (string $sql): bool => str_contains($sql, "from \"{$table}\"") && ! str_contains($sql, 'from "payments"'))
        ->count();

    expect($loads('bookings'))->toBe(1)
        ->and($loads('services'))->toBe(1)
        ->and($loads('refunds'))->toBe(1);
});

it('403s the payments section when the integration is off', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->set($tenant);
    $me = myPayCustomer($tenant);

    // feature_online_payment is off by default on the base plan.
    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/payments'))
        ->assertForbidden();
});

it('forbids a staff user from the payments section', function () {
    $tenant = myPayTenant();
    $staff = User::factory()->create(['tenant_id' => $tenant->id]);
    $staff->assignRole(Role::TenantAdmin->value);

    $this->actingAs($staff)
        ->get(tenantHost('acme', '/my/payments'))
        ->assertForbidden();
});

it('redirects a guest from the payments section to login', function () {
    myPayTenant();

    $this->get(tenantHost('acme', '/my/payments'))->assertRedirectContains('/login');
});
