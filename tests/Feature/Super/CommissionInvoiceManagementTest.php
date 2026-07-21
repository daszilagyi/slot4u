<?php

use App\Actions\Tenant\ChangeTenantStatus;
use App\Enums\BillingPeriodStatus;
use App\Enums\CommissionInvoiceStatus;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\CommissionInvoice;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Models\User;
use App\Notifications\CommissionInvoiceNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// superUrl(), tenantHost() and superAdmin() live in tests/Pest.php.

/*
 * Superadmin commission-invoice management (docs/10 §10, SLO-122): the
 * cross-tenant list plus settle / void / resend, each gated to outstanding
 * invoices, audited, and (for void/paid) tied to the non-payment reactivation
 * rule. Covers the access control and the storno/reactivation edge cases.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-07-05 09:00:00');
    $this->seed(PermissionSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** The newest audit entry for an action code, or null if it was never recorded. */
function invoiceAudit(string $action): ?AuditLog
{
    return AuditLog::query()->where('action', $action)->latest('id')->first();
}

/** An admin of the tenant, resolvable by the TenantAdminNotifier. */
function billingAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

/**
 * @param  array<string, mixed>  $extra
 */
function superInvoice(Tenant $tenant, CommissionInvoiceStatus $status, string $period = '2026-06', array $extra = []): CommissionInvoice
{
    TenantBillingPeriod::factory()->create([
        'tenant_id' => $tenant->id,
        'period' => $period,
        'status' => BillingPeriodStatus::Invoiced,
    ]);

    return CommissionInvoice::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'period' => $period,
        'status' => $status,
        'issued_at' => Carbon::now()->subDays(3),
        'due_at' => Carbon::now()->addDays(5),
    ], $extra));
}

// --- Access control ---

it('renders the invoice list for a superadmin', function () {
    superInvoice(Tenant::factory()->active()->create(), CommissionInvoiceStatus::Issued);

    $this->actingAs(superAdmin())
        ->get(superUrl('/commission-invoices'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Super/CommissionInvoices/Index')
            ->has('invoices.data', 1)
            ->where('grace_days', 14));
});

it('denies a tenant user', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)->get(superUrl('/commission-invoices'))->assertForbidden();
});

it('sends a guest to the login page', function () {
    $this->get(superUrl('/commission-invoices'))->assertRedirectContains('/login');
});

it('filters the list by status', function () {
    superInvoice(Tenant::factory()->active()->create(), CommissionInvoiceStatus::Issued, '2026-05');
    superInvoice(Tenant::factory()->active()->create(), CommissionInvoiceStatus::Paid, '2026-06');

    $this->actingAs(superAdmin())
        ->get(superUrl('/commission-invoices?status=paid'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.status', 'paid'));
});

// --- Mark paid ---

it('marks an outstanding invoice paid, settles the period, and audits it', function () {
    $tenant = Tenant::factory()->active()->create();
    $invoice = superInvoice($tenant, CommissionInvoiceStatus::Issued);

    $this->actingAs(superAdmin())
        ->post(superUrl("/commission-invoices/{$invoice->id}/mark-paid"), ['method' => 'bank_transfer'])
        ->assertRedirect();

    expect($invoice->fresh()->status)->toBe(CommissionInvoiceStatus::Paid)
        ->and($invoice->fresh()->paid_method)->toBe('bank_transfer');
    expect(TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->status)
        ->toBe(BillingPeriodStatus::Paid);
    expect(invoiceAudit('commission.invoice_paid'))->not->toBeNull();
});

it('refuses to mark an already-paid invoice paid again', function () {
    $tenant = Tenant::factory()->active()->create();
    $invoice = superInvoice($tenant, CommissionInvoiceStatus::Paid);

    $this->actingAs(superAdmin())
        ->from(superUrl('/commission-invoices'))
        ->post(superUrl("/commission-invoices/{$invoice->id}/mark-paid"))
        ->assertSessionHasErrors('invoice');

    expect(invoiceAudit('commission.invoice_paid'))->toBeNull();
});

// --- Void ---

it('voids an outstanding invoice and its period, and audits it', function () {
    $tenant = Tenant::factory()->active()->create();
    $invoice = superInvoice($tenant, CommissionInvoiceStatus::Issued);

    $this->actingAs(superAdmin())
        ->post(superUrl("/commission-invoices/{$invoice->id}/void"), ['reason' => 'raised in error'])
        ->assertRedirect();

    expect($invoice->fresh()->status)->toBe(CommissionInvoiceStatus::Void);
    expect(TenantBillingPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole()->status)
        ->toBe(BillingPeriodStatus::Void);

    $audit = invoiceAudit('commission.invoice_voided');
    expect($audit)->not->toBeNull()
        ->and($audit->new_values['reason'])->toBe('raised in error');
});

it('voiding an overdue invoice lifts a non-payment suspension once cleared', function () {
    $tenant = Tenant::factory()->active()->create();
    $invoice = superInvoice($tenant, CommissionInvoiceStatus::Overdue, extra: ['due_at' => Carbon::now()->subDays(20)]);
    app(ChangeTenantStatus::class)($tenant, TenantStatus::Suspended);

    $this->actingAs(superAdmin())
        ->post(superUrl("/commission-invoices/{$invoice->id}/void"))
        ->assertRedirect();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('voiding a non-overdue invoice does not lift a manual suspension', function () {
    $tenant = Tenant::factory()->active()->create();
    $invoice = superInvoice($tenant, CommissionInvoiceStatus::Issued);
    app(ChangeTenantStatus::class)($tenant, TenantStatus::Suspended);

    $this->actingAs(superAdmin())
        ->post(superUrl("/commission-invoices/{$invoice->id}/void"))
        ->assertRedirect();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended);
});

it('refuses to void a paid invoice', function () {
    $tenant = Tenant::factory()->active()->create();
    $invoice = superInvoice($tenant, CommissionInvoiceStatus::Paid);

    $this->actingAs(superAdmin())
        ->from(superUrl('/commission-invoices'))
        ->post(superUrl("/commission-invoices/{$invoice->id}/void"))
        ->assertSessionHasErrors('invoice');

    expect($invoice->fresh()->status)->toBe(CommissionInvoiceStatus::Paid);
});

// --- Resend ---

it('resends an outstanding invoice to the tenant admins and audits it', function () {
    Notification::fake();
    $tenant = Tenant::factory()->active()->create();
    $admin = billingAdmin($tenant);
    $invoice = superInvoice($tenant, CommissionInvoiceStatus::Overdue);

    $this->actingAs(superAdmin())
        ->post(superUrl("/commission-invoices/{$invoice->id}/resend"))
        ->assertRedirect();

    Notification::assertSentTo($admin, CommissionInvoiceNotification::class);
    expect(invoiceAudit('commission.invoice_resent'))->not->toBeNull();
});

it('refuses to resend a voided invoice', function () {
    Notification::fake();
    $tenant = Tenant::factory()->active()->create();
    billingAdmin($tenant);
    $invoice = superInvoice($tenant, CommissionInvoiceStatus::Void);

    $this->actingAs(superAdmin())
        ->from(superUrl('/commission-invoices'))
        ->post(superUrl("/commission-invoices/{$invoice->id}/resend"))
        ->assertSessionHasErrors('invoice');

    Notification::assertNothingSent();
});

// --- Dunning countdown ---

it('surfaces the dunning countdown for an overdue invoice', function () {
    $tenant = Tenant::factory()->active()->create();
    // Due 10 days ago → 10 days overdue, 4 days until suspension (14-day grace).
    superInvoice($tenant, CommissionInvoiceStatus::Overdue, extra: ['due_at' => Carbon::now()->subDays(10)]);

    $this->actingAs(superAdmin())
        ->get(superUrl('/commission-invoices'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoices.data.0.dunning.days_overdue', 10)
            ->where('invoices.data.0.dunning.days_until_suspension', 4));
});
