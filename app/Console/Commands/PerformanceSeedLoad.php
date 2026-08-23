<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds a tenant big enough for {@see PerformanceProbe} to mean something
 * (SLO-176, docs/17 §10).
 *
 * A measurement on the demo tenant — two staff, four bookings — says nothing
 * about the shape that actually hurts: a salon with a full diary and two years
 * of history. Without a fixture, "the availability endpoint is the most
 * expensive path" stays an assertion, and a cache built on an assertion is a
 * correctness risk taken for no measured gain.
 *
 * ⚠️ REFUSES TO RUN IN PRODUCTION, and that is not a formality. It writes
 * thousands of bookings against a tenant; on the live host that would be a
 * tenant's diary filled with rows their customers never made, and their
 * commission base along with it (docs/10 §3.1).
 */
class PerformanceSeedLoad extends Command
{
    protected $signature = 'perf:seed-load
        {--tenant= : Tenant slug to load up (default: acme)}
        {--staff=6 : Staff members, each with a weekly schedule}
        {--bookings=5000 : Bookings spread over the window}
        {--days=365 : Window, in days, centred on today}';

    protected $description = 'Seed a realistically busy tenant for perf:probe (never in production)';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->components->error(
                'perf:seed-load refuses to run in production — it would write thousands of bookings into a real tenant.'
            );

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', (string) ($this->option('tenant') ?: 'acme'))->first();

        if (! $tenant instanceof Tenant) {
            $this->components->error('Tenant not found. Pass --tenant=<slug>.');

            return self::FAILURE;
        }

        app(TenantManager::class)->set($tenant);

        $service = $this->slotService($tenant);
        $staff = $this->ensureStaff($tenant, $service, (int) $this->option('staff'));
        $made = $this->seedBookings($tenant, $service, $staff, (int) $this->option('bookings'), (int) $this->option('days'));

        $this->components->info(sprintf(
            '%s: %d staff, %d new bookings (%d total) on service "%s".',
            $tenant->slug,
            count($staff),
            $made,
            Booking::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->count(),
            $service->name,
        ));

        $this->line('');
        $this->components->info('Now: php artisan perf:probe --tenant='.$tenant->slug);

        return self::SUCCESS;
    }

    private function slotService(Tenant $tenant): Service
    {
        $service = Service::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('booking_mode', BookingMode::DurationBased->value)
            ->first();

        if ($service instanceof Service) {
            return $service;
        }

        return Service::factory()->forTenant($tenant)->create([
            'name' => 'Perf teszt szolgáltatás',
            'booking_mode' => BookingMode::DurationBased,
            'duration_minutes' => 60,
            'active' => true,
        ]);
    }

    /**
     * @return list<Staff>
     */
    private function ensureStaff(Tenant $tenant, Service $service, int $wanted): array
    {
        $existing = Staff::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->orderBy('id')
            ->get()
            ->all();

        for ($i = count($existing); $i < $wanted; $i++) {
            $existing[] = Staff::factory()->forTenant($tenant)->create([
                'name' => 'Perf Munkatárs '.($i + 1),
            ]);
        }

        $staff = array_slice($existing, 0, $wanted);

        foreach ($staff as $member) {
            $service->staff()->syncWithoutDetaching([$member->getKey()]);

            // Mon–Fri, 09:00–17:00. The grid has to have somewhere to put slots,
            // or the picker returns an empty list quickly and measures nothing.
            for ($day = 1; $day <= 5; $day++) {
                $exists = Schedule::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('schedulable_type', 'staff')
                    ->where('schedulable_id', $member->getKey())
                    ->where('day_of_week', $day)
                    ->exists();

                if (! $exists) {
                    Schedule::factory()->forTenant($tenant)->forSchedulable($member)
                        ->onDay($day, '09:00', '17:00')
                        ->create();
                }
            }
        }

        return $staff;
    }

    /**
     * @param  list<Staff>  $staff
     */
    private function seedBookings(Tenant $tenant, Service $service, array $staff, int $count, int $days): int
    {
        if ($count <= 0 || $staff === []) {
            return 0;
        }

        $start = Carbon::now($tenant->timezone)->startOfDay()->subDays(intdiv($days, 2));
        $rows = [];
        $now = Carbon::now();

        for ($i = 0; $i < $count; $i++) {
            $member = $staff[$i % count($staff)];
            // Deterministic spread rather than random: a fixture whose shape
            // changes between runs makes two measurements incomparable, which is
            // the one thing a benchmark fixture must not do.
            $dayOffset = intdiv($i, max(1, intdiv($count, $days)));
            $hour = 9 + ($i % 8);
            $startsAt = $start->copy()->addDays($dayOffset)->setTime($hour, 0)->utc();

            $rows[] = [
                'tenant_id' => $tenant->getKey(),
                'code' => 'PERF'.Str::upper(Str::random(6)).$i,
                'customer_id' => null,
                'guest_name' => 'Perf Vendég',
                'guest_email' => 'perf'.$i.'@example.test',
                'service_id' => $service->getKey(),
                'booking_mode' => BookingMode::DurationBased->value,
                'staff_id' => $member->getKey(),
                'starts_at' => $startsAt->toDateTimeString(),
                'ends_at' => $startsAt->copy()->addHour()->toDateTimeString(),
                'status' => BookingStatus::Confirmed->value,
                'party_size' => 1,
                'price_minor' => 500000,
                'currency' => 'HUF',
                'source' => 'admin',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Bulk insert, bypassing the model: the `created` hook would raise
        // BookingCreated five thousand times — five thousand notifications and
        // five thousand commission ledger writes, none of which is the thing
        // being measured.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('bookings')->insert($chunk);
        }

        return $count;
    }
}
