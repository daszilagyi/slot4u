<?php

use App\Actions\Commission\RecomputeTenantPeriod;
use App\Enums\CommissionInvoiceStatus;
use App\Enums\CommissionItemState;
use App\Enums\Feature;
use App\Enums\Role;
use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\BookingCommissionItem;
use App\Models\CommissionCorrection;
use App\Models\CommissionInvoice;
use App\Models\CommissionSetting;
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
 * Tenant commission transparency dashboard (SLO-70, docs/10 §9): the overview
 * figures, the itemised ledger (incl. the `removed` 24h+ cancellations), the
 * invoice list, the CSV export, tenant isolation and the N+1 guard (DoD).
 *
 * tenantHost() lives in tests/Pest.php.
 */

beforeEach(function () {
    // The period is a calendar month in the tenant's tz — freeze so "current
    // period" is deterministic and never straddles a month boundary mid-run.
    Carbon::setTestNow('2026-07-16 09:00:00');
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    // Keep the model boot hooks (unique booking code) but silence the lifecycle
    // listeners, so the factory bookings do not double-write the ledger.
    Event::fake([BookingCreated::class, BookingStatusChanged::class]);

    $this->setting = CommissionSetting::factory()->create([
        'free_threshold_minor' => 1_000_000,
        'rate_bps' => 100,
        'rate_with_integration_bps' => 150,
        'monthly_cap_minor' => 5_000_000,
        'currency' => 'HUF',
        'effective_from' => Carbon::parse('2026-01-01'),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function billingUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);

    return $user;
}

/** A ledger row whose booking belongs to the same tenant (so the code renders). */
function ledgerItem(Tenant $tenant, string $period, int $amount, CommissionItemState $state = CommissionItemState::Billable): BookingCommissionItem
{
    $booking = Booking::factory()->forTenant($tenant)->create(['price_minor' => $amount]);

    return BookingCommissionItem::factory()->create([
        'tenant_id' => $tenant->id,
        'booking_id' => $booking->id,
        'period' => $period,
        'amount_minor' => $amount,
        'rate_bps' => 100,
        'realized_at' => Carbon::parse($period.'-10 12:00:00'),
        'state' => $state,
        'settings_id' => test()->setting->id,
        'currency' => 'HUF',
    ]);
}

// --- Access control ---

it('renders the billing dashboard for a tenant admin', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Billing/Index')
            ->where('overview.period', '2026-07')
            ->where('overview.configured', true)
            ->has('items')
            ->has('invoices')
            ->has('periods'));
});

it('denies a manager without billing.view', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $manager = billingUser($tenant, Role::Manager);

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/billing'))
        ->assertForbidden();
});

// --- Overview figures (docs/10 §9) ---

it('reports the free allowance, accrued commission and cap remaining', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    // Threshold 10 000 Ft; 15 000 Ft turnover → 5 000 Ft billable base at 1% = 50 Ft.
    ledgerItem($tenant, '2026-07', 1_500_000);
    app(RecomputeTenantPeriod::class)($tenant->id, '2026-07');

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Billing/Index')
            ->where('overview.turnover_minor', 1_500_000)
            ->where('overview.billable_base_minor', 500_000)
            ->where('overview.commission_minor', 5_000)
            ->where('overview.free_threshold_minor', 1_000_000)
            ->where('overview.free_remaining_minor', 0)
            ->where('overview.threshold_reached', true)
            ->where('overview.monthly_cap_minor', 5_000_000)
            ->where('overview.cap_remaining_minor', 4_995_000)
            ->where('overview.cap_reached', false)
            ->where('overview.rate_bps', 100));
});

it('keeps the full free allowance and zero commission below the threshold', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    ledgerItem($tenant, '2026-07', 400_000);
    app(RecomputeTenantPeriod::class)($tenant->id, '2026-07');

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.commission_minor', 0)
            ->where('overview.free_remaining_minor', 600_000)
            ->where('overview.threshold_reached', false));
});

it('reports zeros for a period with no billable booking yet', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.turnover_minor', 0)
            ->where('overview.commission_minor', 0)
            ->where('overview.free_remaining_minor', 1_000_000)
            ->where('overview.status', 'open')
            ->where('items.total', 0));
});

it('shows the raised rate and names the integration behind it', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    // OnlinePayment is off by default on the base plan (docs/10 §5.6) — the
    // per-tenant override is what turns it on, and what raises the rate.
    TenantFeature::factory()->create([
        'tenant_id' => $tenant->id,
        'feature_code' => Feature::OnlinePayment->value,
        'enabled' => true,
    ]);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.rate_bps', 150)
            ->where('overview.raised_rate_bps', 150)
            ->where('overview.integration_codes', [Feature::OnlinePayment->value]));
});

it('reports an unconfigured pricing model rather than a zero threshold', function () {
    CommissionSetting::query()->delete();

    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.configured', false)
            ->where('overview.free_threshold_minor', null)
            ->where('overview.rate_bps', null));
});

// --- Ledger list ---

it('lists the removed 24h+ cancellations, flagged and outside the turnover', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    ledgerItem($tenant, '2026-07', 1_500_000);
    ledgerItem($tenant, '2026-07', 900_000, CommissionItemState::Removed);
    app(RecomputeTenantPeriod::class)($tenant->id, '2026-07');

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Both rows are visible — the tenant must see *why* one does not count…
            ->where('items.total', 2)
            // …but only the billable one moved the turnover.
            ->where('overview.turnover_minor', 1_500_000));
});

it('shows the booking code on each ledger row', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    $item = ledgerItem($tenant, '2026-07', 1_500_000);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.data.0.booking_code', $item->booking->code)
            ->where('items.data.0.state', 'billable')
            ->where('items.data.0.rate_bps', 100));
});

it('filters the ledger to the selected period', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    ledgerItem($tenant, '2026-07', 1_500_000);
    ledgerItem($tenant, '2026-06', 2_000_000);
    app(RecomputeTenantPeriod::class)($tenant->id, '2026-06');
    app(RecomputeTenantPeriod::class)($tenant->id, '2026-07');

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing?period=2026-06'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.period', '2026-06')
            ->where('items.total', 1)
            ->where('overview.turnover_minor', 2_000_000));
});

it('404s on a well-formed period the tenant has no row for', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing?period=2020-01'))
        ->assertNotFound();
});

it('rejects a malformed period', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing?period=2026-13'))
        ->assertSessionHasErrors('period');
});

// --- Invoices ---

it('lists the tenant commission invoices with net, VAT and gross', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    CommissionInvoice::factory()->create([
        'tenant_id' => $tenant->id,
        'period' => '2026-06',
        'commission_net_minor' => 100_000,
        'vat_bps' => 2700,
        'vat_minor' => 27_000,
        'total_gross_minor' => 127_000,
        'status' => CommissionInvoiceStatus::Issued,
        'pdf_path' => null,
    ]);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('invoices', 1)
            ->where('invoices.0.period', '2026-06')
            ->where('invoices.0.commission_net_minor', 100_000)
            ->where('invoices.0.vat_minor', 27_000)
            ->where('invoices.0.total_gross_minor', 127_000)
            ->where('invoices.0.status', 'issued')
            ->where('invoices.0.has_pdf', false));
});

it('shows the credits carried in from an already-invoiced period', function () {
    // docs/10 §8.2: the tenant must be able to see why this month's invoice is
    // lower than its own ledger — the credit is a line of its own, not a silent
    // adjustment of the accrued figure.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);
    $item = ledgerItem($tenant, '2026-07', 2_000_000);

    CommissionCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'booking_id' => $item->booking_id,
        'source_period' => '2026-06',
        'period' => '2026-07',
        'commission_delta_minor' => -4_000,
    ]);
    app(RecomputeTenantPeriod::class)($tenant->getKey(), '2026-07');

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('corrections', 1)
            ->where('corrections.0.source_period', '2026-06')
            ->where('corrections.0.type', 'booking_adjustment')
            ->where('corrections.0.commission_delta_minor', -4_000)
            ->where('overview.correction_minor', -4_000)
            // 1% of the 1 000 000 above the threshold = 10 000, less the credit.
            ->where('overview.commission_minor', 10_000)
            ->where('overview.net_payable_minor', 6_000));
});

it('never shows another tenant credits', function () {
    $acme = Tenant::factory()->active()->create(['slug' => 'acme']);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $admin = billingUser($acme, Role::TenantAdmin);

    CommissionCorrection::factory()->create([
        'tenant_id' => $other->id,
        'booking_id' => null,
        'period' => '2026-07',
        'commission_delta_minor' => -9_999,
    ]);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('corrections', 0));
});

it('404s the invoice PDF while no provider has stored one', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    $invoice = CommissionInvoice::factory()->create([
        'tenant_id' => $tenant->id,
        'pdf_path' => null,
    ]);

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/billing/invoices/{$invoice->id}/pdf"))
        ->assertNotFound();
});

// --- Tenant isolation (DoD) ---

it('never shows another tenant ledger, invoice or period', function () {
    $acme = Tenant::factory()->active()->create(['slug' => 'acme']);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $admin = billingUser($acme, Role::TenantAdmin);

    ledgerItem($other, '2026-07', 4_000_000);
    ledgerItem($other, '2026-05', 4_000_000);
    app(RecomputeTenantPeriod::class)($other->id, '2026-07');
    app(RecomputeTenantPeriod::class)($other->id, '2026-05');
    CommissionInvoice::factory()->create(['tenant_id' => $other->id, 'period' => '2026-05']);

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.total', 0)
            ->where('overview.turnover_minor', 0)
            ->has('invoices', 0)
            // The other tenant's 2026-05 period must not even be selectable.
            ->where('periods', ['2026-07']));
});

it('404s an export of a period only another tenant has', function () {
    $acme = Tenant::factory()->active()->create(['slug' => 'acme']);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $admin = billingUser($acme, Role::TenantAdmin);

    ledgerItem($other, '2026-05', 4_000_000);
    app(RecomputeTenantPeriod::class)($other->id, '2026-05');

    // The period exists — for someone else. Unknown identifier, not a 403:
    // the response must not distinguish it from a period nobody has.
    $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing/export?period=2026-05'))
        ->assertNotFound();
});

it('404s a cross-tenant invoice PDF rather than 403', function () {
    $acme = Tenant::factory()->active()->create(['slug' => 'acme']);
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    $admin = billingUser($acme, Role::TenantAdmin);

    $invoice = CommissionInvoice::factory()->create([
        'tenant_id' => $other->id,
        'pdf_path' => 'invoices/other.pdf',
    ]);

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/billing/invoices/{$invoice->id}/pdf"))
        ->assertNotFound();
});

// --- Export ---

it('exports the selected period ledger as CSV', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    $item = ledgerItem($tenant, '2026-07', 1_500_000);

    $response = $this->actingAs($admin)
        ->get(tenantHost('acme', '/billing/export?period=2026-07'))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toContain($item->booking->code)
        // 15 000,00 Ft as minor units → plain decimal, integer arithmetic.
        ->toContain('15000,00')
        ->toContain('1,00%');
});

it('does not let a manager export the ledger', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $manager = billingUser($tenant, Role::Manager);

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/billing/export?period=2026-07'))
        ->assertForbidden();
});

// --- N+1 (DoD) ---

it('keeps the ledger query count constant as rows are added', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $admin = billingUser($tenant, Role::TenantAdmin);

    $measure = function () use ($admin) {
        // Re-warm the permission cache first so the counts are comparable.
        $this->actingAs($admin)->get(tenantHost('acme', '/billing'))->assertOk();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(tenantHost('acme', '/billing'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    };

    ledgerItem($tenant, '2026-07', 100_000);
    $few = $measure();

    ledgerItem($tenant, '2026-07', 100_000);
    ledgerItem($tenant, '2026-07', 100_000);
    ledgerItem($tenant, '2026-07', 100_000);
    $many = $measure();

    // The booking per row is eager-loaded → constant regardless of row count.
    expect($many)->toBe($few);
});
