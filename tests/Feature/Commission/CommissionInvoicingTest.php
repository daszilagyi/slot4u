<?php

use App\Actions\Commission\GenerateCommissionInvoice;
use App\Actions\Commission\MarkCommissionInvoicePaid;
use App\Actions\Commission\RecomputeTenantPeriod;
use App\Actions\Tenant\ChangeTenantStatus;
use App\Enums\BillingPeriodStatus;
use App\Enums\CommissionInvoiceStatus;
use App\Enums\CommissionItemState;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Models\BookingCommissionItem;
use App\Models\CommissionInvoice;
use App\Models\CommissionSetting;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Models\User;
use App\Notifications\CommissionInvoiceNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/*
 * Monthly commission invoicing + dunning (docs/10 §6.5, §6.6, §7). Covers §12/20
 * (VAT integer arithmetic), the zero-commission void, idempotency, the tenant-admin
 * invoice email, period-close scheduling, overdue dunning + grace suspension, and
 * §12/17 (a suspended tenant's period still computes).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-07-05 09:00:00');
    $this->seed(PermissionSeeder::class);
    // The ledger listener would double-write when the factory creates a Booking;
    // silence only the lifecycle events (keep the model boot hooks).
    Event::fake([BookingCreated::class, BookingStatusChanged::class]);
    $this->setting = CommissionSetting::factory()->create([
        'free_threshold_minor' => 0,
        'rate_bps' => 100,
        'rate_with_integration_bps' => 150,
        'monthly_cap_minor' => null,
        'currency' => 'HUF',
        'effective_from' => Carbon::parse('2026-01-01'),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function billable(Tenant $tenant, string $period, int $amount): BookingCommissionItem
{
    return BookingCommissionItem::factory()->create([
        'tenant_id' => $tenant->id,
        'period' => $period,
        'amount_minor' => $amount,
        'rate_bps' => 100,
        'realized_at' => Carbon::parse($period.'-10 12:00:00'),
        'state' => CommissionItemState::Billable,
        'settings_id' => test()->setting->id,
    ]);
}

function tenantAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);

    return $user;
}

it('issues an invoice with net commission plus 27% VAT (integer arithmetic)', function () {
    $tenant = Tenant::factory()->active()->create();
    billable($tenant, '2026-06', 100000); // 1% of 100000 = 1000 net

    $invoice = app(GenerateCommissionInvoice::class)($tenant->id, '2026-06');

    expect($invoice)->not->toBeNull()
        ->and($invoice->commission_net_minor)->toBe(1000)
        ->and($invoice->vat_bps)->toBe(2700)
        ->and($invoice->vat_minor)->toBe(270)           // intdiv(1000*2700, 10000)
        ->and($invoice->total_gross_minor)->toBe(1270)
        ->and($invoice->status)->toBe(CommissionInvoiceStatus::Issued)
        ->and($invoice->currency)->toBe('HUF')
        ->and($invoice->due_at->toDateString())->toBe('2026-07-13'); // issued 07-05 + 8 days

    $period = TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($period->status)->toBe(BillingPeriodStatus::Invoiced)
        ->and($period->invoice_id)->toBe($invoice->id);
});

it('floors the VAT to integer minor units', function () {
    $tenant = Tenant::factory()->active()->create();
    billable($tenant, '2026-06', 33300); // 1% = 333 net; VAT intdiv(333*2700,10000)=89

    $invoice = app(GenerateCommissionInvoice::class)($tenant->id, '2026-06');

    expect($invoice->commission_net_minor)->toBe(333)
        ->and($invoice->vat_minor)->toBe(89)
        ->and($invoice->total_gross_minor)->toBe(422);
});

it('voids a zero-commission period without issuing an invoice', function () {
    $tenant = Tenant::factory()->active()->create();
    // No billable items → nothing owed.

    $invoice = app(GenerateCommissionInvoice::class)($tenant->id, '2026-06');

    expect($invoice)->toBeNull()
        ->and(CommissionInvoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists())->toBeFalse();

    $period = TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($period->status)->toBe(BillingPeriodStatus::Void);
});

it('is idempotent per tenant and period', function () {
    $tenant = Tenant::factory()->active()->create();
    billable($tenant, '2026-06', 100000);

    $first = app(GenerateCommissionInvoice::class)($tenant->id, '2026-06');
    $second = app(GenerateCommissionInvoice::class)($tenant->id, '2026-06');

    expect($second->id)->toBe($first->id)
        ->and(CommissionInvoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('emails the tenant admins the issued invoice, not other tenants', function () {
    Notification::fake();
    $tenant = Tenant::factory()->active()->create();
    $admin = tenantAdmin($tenant);
    $otherAdmin = tenantAdmin(Tenant::factory()->active()->create());
    billable($tenant, '2026-06', 100000);

    app(GenerateCommissionInvoice::class)($tenant->id, '2026-06');

    Notification::assertSentTo($admin, CommissionInvoiceNotification::class);
    Notification::assertNotSentTo($otherAdmin, CommissionInvoiceNotification::class);
});

it('marks an invoice paid and settles its period', function () {
    $tenant = Tenant::factory()->active()->create();
    billable($tenant, '2026-06', 100000);
    $invoice = app(GenerateCommissionInvoice::class)($tenant->id, '2026-06');

    $paid = app(MarkCommissionInvoicePaid::class)($invoice, 'bank_transfer');

    expect($paid->status)->toBe(CommissionInvoiceStatus::Paid)
        ->and($paid->paid_at)->not->toBeNull()
        ->and($paid->paid_method)->toBe('bank_transfer');
    expect(TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->status)
        ->toBe(BillingPeriodStatus::Paid);
});

it('reactivates a tenant suspended for non-payment once it settles up', function () {
    $tenant = Tenant::factory()->active()->create();
    billable($tenant, '2026-06', 100000);
    $invoice = app(GenerateCommissionInvoice::class)($tenant->id, '2026-06');
    app(ChangeTenantStatus::class)($tenant, TenantStatus::Suspended);

    app(MarkCommissionInvoicePaid::class)($invoice);

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('closes only periods whose grace window has passed', function () {
    // June period: grace (2nd of July) has passed at now = 2026-07-05 → closes.
    $june = Tenant::factory()->active()->create();
    billable($june, '2026-06', 100000);
    // July period: grace (2nd of August) has not passed → stays open.
    $july = Tenant::factory()->active()->create();
    billable($july, '2026-07', 100000);
    // Seed the aggregates so the command has open periods to scan.
    app(RecomputeTenantPeriod::class)($june->id, '2026-06');
    app(RecomputeTenantPeriod::class)($july->id, '2026-07');

    $this->artisan('billing:close-periods')->assertSuccessful();

    expect(TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $june->id)->sole()->status)
        ->toBe(BillingPeriodStatus::Invoiced)
        ->and(TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $july->id)->sole()->status)
        ->toBe(BillingPeriodStatus::Open);
});

it('flags overdue invoices and reminds the tenant', function () {
    Notification::fake();
    $tenant = Tenant::factory()->active()->create();
    $admin = tenantAdmin($tenant);
    $invoice = CommissionInvoice::factory()->create([
        'tenant_id' => $tenant->id,
        'period' => '2026-06',
        'status' => CommissionInvoiceStatus::Issued,
        'issued_at' => Carbon::parse('2026-06-20'),
        'due_at' => Carbon::parse('2026-06-28'), // past due at now = 2026-07-05
    ]);

    $this->artisan('billing:dunning-sweep')->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(CommissionInvoiceStatus::Overdue);
    Notification::assertSentTo($admin, CommissionInvoiceNotification::class);
});

it('suspends a tenant whose invoice stays overdue beyond the grace window', function () {
    $tenant = Tenant::factory()->active()->create();
    CommissionInvoice::factory()->create([
        'tenant_id' => $tenant->id,
        'period' => '2026-05',
        'status' => CommissionInvoiceStatus::Overdue,
        'issued_at' => Carbon::parse('2026-06-01'),
        'due_at' => Carbon::parse('2026-06-09'), // > 14 days before now = 2026-07-05
    ]);

    $this->artisan('billing:dunning-sweep')->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended);
});

it('does not suspend a tenant still within the grace window', function () {
    $tenant = Tenant::factory()->active()->create();
    CommissionInvoice::factory()->create([
        'tenant_id' => $tenant->id,
        'period' => '2026-06',
        'status' => CommissionInvoiceStatus::Overdue,
        'issued_at' => Carbon::parse('2026-06-20'),
        'due_at' => Carbon::parse('2026-06-28'), // only ~7 days overdue at now = 2026-07-05
    ]);

    $this->artisan('billing:dunning-sweep')->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('still computes the period of a suspended tenant (§12/17)', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
    billable($tenant, '2026-06', 100000);

    $period = app(RecomputeTenantPeriod::class)($tenant->id, '2026-06');

    expect($period)->not->toBeNull()
        ->and($period->turnover_minor)->toBe(100000)
        ->and($period->commission_minor)->toBe(1000);
});
