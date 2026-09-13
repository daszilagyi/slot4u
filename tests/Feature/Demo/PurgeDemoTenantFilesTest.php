<?php

use App\Actions\Payment\SettleBookingPayment;
use App\Actions\Tenant\SetTenantFeature;
use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Demo\PurgeDemoTenant;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| A purged demo tenant takes its files with it (SLO-226)
|--------------------------------------------------------------------------
|
| Rows follow the tenant through cascading foreign keys; files follow nothing.
| Before this, every nightly `demo:reset` in production left the previous
| night's invoice PDFs on disk — ~700 for the fitness persona alone, on a host
| with an inode quota, and nothing anywhere to say so.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    Carbon::setTestNow('2026-09-01 12:00:00');
    Storage::fake((string) config('invoicing.disk'));
    Storage::fake('public');
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A demo tenant that invoices — through the sandbox issuer, as every demo does. */
function purgeFilesDemoTenant(string $slug): Tenant
{
    $tenant = Tenant::factory()->active()->create(['slug' => $slug, 'is_demo' => true]);

    app(SetTenantFeature::class)($tenant, Feature::Invoicing, true);

    return $tenant;
}

/** Settle one payment, so the real invoicing path writes a real PDF. */
function purgeFilesIssueInvoice(Tenant $tenant): Invoice
{
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    $service = Service::factory()->forTenant($tenant)->create();
    $booking = Booking::factory()->forTenant($tenant)->status(BookingStatus::PendingPayment)->create([
        'service_id' => $service->id,
        'price_minor' => 250000,
        'starts_at' => Carbon::parse('2026-09-10 08:00:00'),
        'ends_at' => Carbon::parse('2026-09-10 09:00:00'),
    ]);
    $payment = Payment::factory()->forBooking($booking)->create(['amount_minor' => 250000]);

    app(SettleBookingPayment::class)($payment);
    app(TenantManager::class)->forget();

    $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    // The premise: the file exists. Without it every assertion below is empty.
    Storage::disk((string) config('invoicing.disk'))->assertExists((string) $invoice->pdf_path);

    return $invoice;
}

it('deletes the purged tenant\'s invoices, logo and share image', function () {
    $tenant = purgeFilesDemoTenant('demo-purge');
    $invoice = purgeFilesIssueInvoice($tenant);

    Storage::disk('public')->put("tenants/{$tenant->id}/logo.png", 'png');
    Storage::disk('public')->put("og/{$tenant->id}-abc123.png", 'png');

    app(PurgeDemoTenant::class)($tenant);

    Storage::disk((string) config('invoicing.disk'))->assertMissing((string) $invoice->pdf_path);
    expect(Storage::disk((string) config('invoicing.disk'))->allFiles("tenants/{$tenant->id}"))->toBe([]);
    Storage::disk('public')->assertMissing("tenants/{$tenant->id}/logo.png");
    Storage::disk('public')->assertMissing("og/{$tenant->id}-abc123.png");
});

it('leaves every other tenant\'s files alone', function () {
    $purged = purgeFilesDemoTenant('demo-purge');
    $kept = purgeFilesDemoTenant('demo-kept');
    $keptInvoice = purgeFilesIssueInvoice($kept);
    purgeFilesIssueInvoice($purged);

    Storage::disk('public')->put("og/{$kept->id}-abc123.png", 'png');
    // `og/{id}0-…` is tenant 10× the id, not this one: the share image is matched
    // up to the dash, never as a bare prefix.
    Storage::disk('public')->put("og/{$purged->id}0-abc123.png", 'png');

    app(PurgeDemoTenant::class)($purged);

    Storage::disk((string) config('invoicing.disk'))->assertExists((string) $keptInvoice->pdf_path);
    Storage::disk('public')->assertExists("og/{$kept->id}-abc123.png");
    Storage::disk('public')->assertExists("og/{$purged->id}0-abc123.png");
});

it('keeps the files when the purge is rolled back', function () {
    $tenant = purgeFilesDemoTenant('demo-purge');
    $invoice = purgeFilesIssueInvoice($tenant);

    // A file delete cannot be undone. If the rows come back, the PDFs they
    // point at have to still be there.
    try {
        DB::transaction(function () use ($tenant): void {
            app(PurgeDemoTenant::class)($tenant);

            throw new RuntimeException('the caller failed after the purge');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(Tenant::withoutGlobalScopes()->whereKey($tenant->id)->exists())->toBeTrue();
    Storage::disk((string) config('invoicing.disk'))->assertExists((string) $invoice->pdf_path);
});

describe('tenants:prune-orphaned-files', function () {
    it('lists the files of tenants that no longer exist, and deletes nothing without --force', function () {
        $alive = purgeFilesDemoTenant('demo-alive');
        $aliveInvoice = purgeFilesIssueInvoice($alive);
        Storage::disk((string) config('invoicing.disk'))->put('tenants/9999/invoices/szamla-1-x.pdf', '%PDF-');

        $this->artisan('tenants:prune-orphaned-files')
            ->expectsOutputToContain('tenant 9999: 1 file(s)')
            ->assertSuccessful();

        Storage::disk((string) config('invoicing.disk'))->assertExists('tenants/9999/invoices/szamla-1-x.pdf');
        Storage::disk((string) config('invoicing.disk'))->assertExists((string) $aliveInvoice->pdf_path);
    });

    it('deletes them with --force, and only them', function () {
        $alive = purgeFilesDemoTenant('demo-alive');
        $aliveInvoice = purgeFilesIssueInvoice($alive);
        Storage::disk((string) config('invoicing.disk'))->put('tenants/9999/invoices/szamla-1-x.pdf', '%PDF-');
        Storage::disk('public')->put('og/9999-abc.png', 'png');

        $this->artisan('tenants:prune-orphaned-files', ['--force' => true])->assertSuccessful();

        Storage::disk((string) config('invoicing.disk'))->assertMissing('tenants/9999/invoices/szamla-1-x.pdf');
        Storage::disk('public')->assertMissing('og/9999-abc.png');
        Storage::disk((string) config('invoicing.disk'))->assertExists((string) $aliveInvoice->pdf_path);
    });

    it('treats an archived tenant as existing — its invoices are kept for eight years', function () {
        // A logo, not an invoice: no row points at it, so the only thing that
        // can keep it is counting the trashed tenant as existing.
        $archived = purgeFilesDemoTenant('demo-archived');
        Storage::disk('public')->put("tenants/{$archived->id}/logo.png", 'png');
        $archived->delete();

        $this->artisan('tenants:prune-orphaned-files', ['--force' => true])
            ->expectsOutputToContain('No orphaned tenant files.')
            ->assertSuccessful();

        Storage::disk('public')->assertExists("tenants/{$archived->id}/logo.png");
    });

    it('never deletes a directory an invoice row still points into', function () {
        // Cannot arise through the cascading keys — which is why the command
        // spends a query making sure rather than trusting that.
        $alive = purgeFilesDemoTenant('demo-alive');
        $invoice = purgeFilesIssueInvoice($alive);
        Storage::disk((string) config('invoicing.disk'))->put('tenants/9999/invoices/szamla-1-x.pdf', '%PDF-');
        $invoice->forceFill(['pdf_path' => 'tenants/9999/invoices/szamla-1-x.pdf'])->saveQuietly();

        $this->artisan('tenants:prune-orphaned-files', ['--force' => true])->assertSuccessful();

        Storage::disk((string) config('invoicing.disk'))->assertExists('tenants/9999/invoices/szamla-1-x.pdf');
    });

    it('refuses to run against an empty tenants table', function () {
        // A wrong database would make every directory look orphaned.
        Storage::disk((string) config('invoicing.disk'))->put('tenants/1/invoices/szamla-1-x.pdf', '%PDF-');

        $this->artisan('tenants:prune-orphaned-files', ['--force' => true])->assertFailed();

        Storage::disk((string) config('invoicing.disk'))->assertExists('tenants/1/invoices/szamla-1-x.pdf');
    });
});
