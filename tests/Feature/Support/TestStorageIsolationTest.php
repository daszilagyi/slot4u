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
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The suite does not write to the real disk (SLO-227)
|--------------------------------------------------------------------------
|
| The queue is synchronous in tests, so a settled payment runs the real
| invoicing job and writes a real PDF. Until tests/Pest.php faked the disk
| globally, those PDFs landed in the developer's own storage/: 150 000 of them,
| and a Vite dev server watching every one arrive (SLO-225).
|
| No test here fakes a disk itself — that is the point. What is asserted is
| the global default every other test now inherits.
|
*/

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

it('puts a PDF issued by the real invoicing job on a faked disk, not the real one', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    Carbon::setTestNow('2026-09-01 12:00:00');

    $tenant = Tenant::factory()->active()->create(['slug' => 'isolation']);
    $tenant->invoicing = ['api_key' => 'secret-agent-key', 'seller_name' => 'Isolation Kft.'];
    $tenant->save();
    app(SetTenantFeature::class)($tenant, Feature::Invoicing, true);
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

    $pdf = (string) Invoice::withoutGlobalScopes()->sole()->pdf_path;
    $disk = Storage::disk((string) config('invoicing.disk'));

    // The job wrote the file (the premise)…
    $disk->assertExists($pdf);

    // …under the test root, and nowhere the developer's own storage would hold it.
    expect($disk->path($pdf))->toStartWith(storage_path('framework/testing/disks'))
        ->and(file_exists(storage_path('app/private/'.$pdf)))->toBeFalse();
});

it('fakes the public disk too', function () {
    // Logos and share images: written lazily by any test that renders a page.
    expect(Storage::disk('public')->path('og/x.png'))->toStartWith(storage_path('framework/testing/disks'));
});
