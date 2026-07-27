<?php

use App\Enums\BillingPeriodStatus;
use App\Enums\CommissionInvoiceStatus;
use App\Models\CommissionInvoice;
use App\Models\CommissionSetting;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// superUrl() and superAdmin() live in tests/Pest.php.

/*
 * Superadmin global commission statistics (SLO-123, docs/10 §10): the platform
 * "how's the business" dashboard — monthly revenue (MRR proxy), the activation
 * funnel, top tenants by turnover, and overdue-invoice suspension risk. Covers
 * the access control, the aggregate numbers, and the below-threshold / cap /
 * overdue edge cases.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-07-15 09:00:00'); // current period = 2026-07 (UTC)
    // A platform pricing model in force so the figures are "configured".
    CommissionSetting::factory()->create([
        'free_threshold_minor' => 1_000_000,
        'effective_from' => Carbon::now()->subMonth(),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A billing-period aggregate row for a tenant in the July period under test. */
function statsPeriodRow(Tenant $tenant, array $attributes): TenantBillingPeriod
{
    return TenantBillingPeriod::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'period' => '2026-07',
    ], $attributes));
}

it('shows the aggregate revenue, funnel and top tenants to a superadmin', function () {
    // A: above threshold, still open. B: turnover but below threshold (stuck).
    // C: biggest earner, capped, already paid. D: trial tenant with no turnover.
    $a = Tenant::factory()->active()->create(['name' => 'Alpha']);
    $b = Tenant::factory()->active()->create(['name' => 'Bravo']);
    $c = Tenant::factory()->active()->create(['name' => 'Cimba']);
    Tenant::factory()->trial()->create(['name' => 'Delta']);

    statsPeriodRow($a, [
        'turnover_minor' => 3_000_000,
        'commission_minor' => 20_000,
        'status' => BillingPeriodStatus::Open,
    ]);
    statsPeriodRow($b, [
        'turnover_minor' => 500_000,
        'commission_minor' => 0,
        'status' => BillingPeriodStatus::Open,
    ]);
    statsPeriodRow($c, [
        'turnover_minor' => 8_000_000,
        'commission_minor' => 50_000,
        'cap_reached' => true,
        'status' => BillingPeriodStatus::Paid,
    ]);

    $this->actingAs(superAdmin())
        ->get(superUrl('/?period=2026-07'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Super/Dashboard')
            ->where('statistics.period', '2026-07')
            ->where('statistics.configured', true)
            ->where('statistics.free_threshold_minor', 1_000_000)
            // Revenue: MRR proxy = sum of commission across the month. With no
            // credits anywhere, the gross accrual and the billable net coincide.
            ->where('statistics.commission_accrued_minor', 70_000)
            ->where('statistics.correction_total_minor', 0)
            ->where('statistics.commission_net_minor', 70_000)
            ->where('statistics.turnover_total_minor', 11_500_000)
            ->where('statistics.commission_open_minor', 20_000)
            ->where('statistics.commission_paid_minor', 50_000)
            // Funnel: 4 operational tenants, 3 with turnover, 2 paying, 1 stuck.
            ->where('statistics.active_tenants', 4)
            ->where('statistics.tenants_with_turnover', 3)
            ->where('statistics.paying_tenants', 2)
            ->where('statistics.stuck_tenants', 1)
            ->where('statistics.cap_reached_tenants', 1)
            // Top tenants ordered by turnover desc.
            ->has('statistics.top_tenants', 3)
            ->where('statistics.top_tenants.0.tenant_id', $c->id)
            ->where('statistics.top_tenants.0.cap_reached', true)
            ->where('statistics.top_tenants.2.tenant_id', $b->id)
            ->where('statistics.overdue_count', 0),
        );
});

/*
 * Credits (SLO-127, docs/10 §8.2): a closed period's later change is credited in
 * the current one, so the platform's revenue is the netted figure — the gross
 * accrual on its own overstates it.
 */

it('nets the credits off the monthly revenue and reports them separately', function () {
    $credited = Tenant::factory()->active()->create(['name' => 'Credited']);
    $clean = Tenant::factory()->active()->create(['name' => 'Clean']);

    statsPeriodRow($credited, [
        'turnover_minor' => 4_000_000,
        'commission_minor' => 30_000,
        'correction_minor' => -12_000, // June's refund credited into July
        'status' => BillingPeriodStatus::Open,
    ]);
    statsPeriodRow($clean, [
        'turnover_minor' => 2_000_000,
        'commission_minor' => 10_000,
        'status' => BillingPeriodStatus::Open,
    ]);

    $this->actingAs(superAdmin())
        ->get(superUrl('/?period=2026-07'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Gross accrual is unchanged; the net is what we can actually bill.
            ->where('statistics.commission_accrued_minor', 40_000)
            ->where('statistics.correction_total_minor', -12_000)
            ->where('statistics.commission_net_minor', 28_000)
            // The lifecycle split reconciles with the headline, not with the gross.
            ->where('statistics.commission_open_minor', 28_000)
            // Both still owe something, so both are paying.
            ->where('statistics.paying_tenants', 2)
            // The top table nets per tenant too, and names the credit.
            ->where('statistics.top_tenants.0.tenant_id', $credited->id)
            ->where('statistics.top_tenants.0.commission_minor', 30_000)
            ->where('statistics.top_tenants.0.correction_minor', -12_000)
            ->where('statistics.top_tenants.0.net_minor', 18_000),
        );
});

it('treats a fully credited month as not paying and never as negative revenue', function () {
    // The credit exceeds the month's own commission: nothing is billable, and the
    // remainder carries over to the next period (§6.5) rather than showing here
    // as negative revenue.
    $tenant = Tenant::factory()->active()->create();

    statsPeriodRow($tenant, [
        'turnover_minor' => 3_000_000,
        'commission_minor' => 8_000,
        'correction_minor' => -20_000,
        'status' => BillingPeriodStatus::Open,
    ]);

    $this->actingAs(superAdmin())
        ->get(superUrl('/?period=2026-07'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.commission_accrued_minor', 8_000)
            ->where('statistics.correction_total_minor', -20_000)
            ->where('statistics.commission_net_minor', 0)
            ->where('statistics.commission_open_minor', 0)
            // Has turnover but owes nothing → not paying, counted as stuck.
            ->where('statistics.tenants_with_turnover', 1)
            ->where('statistics.paying_tenants', 0)
            ->where('statistics.stuck_tenants', 1)
            ->where('statistics.top_tenants.0.net_minor', 0),
        );
});

it('keeps a voided period out of the revenue and out of the paying count', function () {
    // A stornoed invoice voids the period (§6.5) while its accrual stays on the
    // row — billing it again would double-charge a cancelled month.
    $tenant = Tenant::factory()->active()->create();

    statsPeriodRow($tenant, [
        'turnover_minor' => 5_000_000,
        'commission_minor' => 25_000,
        'status' => BillingPeriodStatus::Void,
    ]);

    $this->actingAs(superAdmin())
        ->get(superUrl('/?period=2026-07'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.commission_accrued_minor', 25_000)
            ->where('statistics.commission_net_minor', 0)
            ->where('statistics.paying_tenants', 0)
            ->where('statistics.top_tenants.0.net_minor', 0),
        );
});

it('lists overdue invoices as suspension risk with a countdown', function () {
    $tenant = Tenant::factory()->active()->create();

    TenantBillingPeriod::factory()->create([
        'tenant_id' => $tenant->id,
        'period' => '2026-06',
        'status' => BillingPeriodStatus::Overdue,
    ]);
    $invoice = CommissionInvoice::factory()->create([
        'tenant_id' => $tenant->id,
        'period' => '2026-06',
        'status' => CommissionInvoiceStatus::Overdue,
        'issued_at' => Carbon::now()->subDays(11),
        'due_at' => Carbon::now()->subDays(3), // suspend at due + 14 grace = +11 days
    ]);

    $this->actingAs(superAdmin())
        ->get(superUrl('/'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.overdue_count', 1)
            ->where('statistics.overdue_total_minor', $invoice->total_gross_minor)
            ->has('statistics.overdue_invoices', 1)
            ->where('statistics.overdue_invoices.0.invoice_id', $invoice->id)
            ->where('statistics.overdue_invoices.0.days_until_suspension', 11)
            ->where('grace_days', 14),
        );
});

it('defaults to the current period and reports zeros when it is empty', function () {
    $this->actingAs(superAdmin())
        ->get(superUrl('/'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.period', '2026-07')
            ->where('statistics.configured', true)
            ->where('statistics.commission_accrued_minor', 0)
            ->where('statistics.correction_total_minor', 0)
            ->where('statistics.commission_net_minor', 0)
            ->where('statistics.turnover_total_minor', 0)
            ->where('statistics.tenants_with_turnover', 0)
            ->has('statistics.top_tenants', 0),
        );
});

it('reports not-configured when no commission settings are effective', function () {
    CommissionSetting::query()->delete();

    $this->actingAs(superAdmin())
        ->get(superUrl('/'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('statistics.configured', false)
            ->where('statistics.free_threshold_minor', null),
        );
});

it('forbids a tenant user from the superadmin dashboard', function () {
    $tenant = Tenant::factory()->active()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->get(superUrl('/'))
        ->assertForbidden();
});

it('redirects a guest to login', function () {
    $this->get(superUrl('/'))->assertRedirectContains('/login');
});
