<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Booking\CreateBooking;
use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Location;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;

/**
 * The smallest persona that proves the framework works (SLO-183).
 *
 * Not one of the four sales personas — those are SLO-184 to SLO-190 and carry
 * the realistic content. This one exists so the framework has something to
 * build on the day it lands, and so every helper is exercised by something the
 * test suite runs: a seeded name, a relative future date, a backdated past
 * booking with a real status chain behind it.
 *
 * Kept deliberately thin. Every line here is a line the real personas will
 * write differently, and a rich smoke persona would only be a second thing to
 * keep in step with them.
 */
final class SmokeDemoPersona extends DemoPersona
{
    /** How far back the sample history reaches. */
    private const HISTORY_DAYS = 30;

    public function slug(): string
    {
        return 'demo-smoke';
    }

    public function name(): string
    {
        return 'Slot4u Demo Stúdió';
    }

    protected function build(Tenant $tenant, User $admin, DemoDataFactory $data): void
    {
        // The models below are tenant-scoped; bind the tenant so BelongsToTenant
        // stamps and scopes them without every call passing an id.
        app(TenantManager::class)->set($tenant);

        try {
            $this->buildFor($tenant, $data);
        } finally {
            app(TenantManager::class)->forget();
        }
    }

    private function buildFor(Tenant $tenant, DemoDataFactory $data): void
    {
        $location = Location::query()->create([
            'name' => 'Belvárosi stúdió',
            'address' => ['line' => 'Váci utca 1.', 'city' => 'Budapest', 'postal_code' => '1052'],
            'active' => true,
        ]);

        $room = Room::query()->create([
            'location_id' => $location->getKey(),
            'name' => 'Kezelő',
            'capacity' => 1,
            'active' => true,
        ]);

        $staff = Staff::query()->create([
            'name' => $data->faker()->name(),
            'title' => 'Terapeuta',
            'color' => '#14b8a6',
            'active' => true,
        ]);

        // Mon–Fri 09:00–17:00. The bookings below are placed inside this window,
        // which is what "times land on the schedule grid" means in practice
        // (docs/20 §3.2) — a booking outside its resource's hours would show up
        // in the calendar as an orphan the availability engine will not offer.
        foreach (range(1, 5) as $weekday) {
            $schedule = new Schedule([
                'location_id' => $location->getKey(),
                'day_of_week' => $weekday,
                'start_time' => '09:00',
                'end_time' => '17:00',
            ]);
            // schedulable_* are guarded (the morph is associated, never
            // mass-assigned), so they are set before the first save rather than
            // after — the columns are NOT NULL.
            $schedule->schedulable()->associate($staff);
            $schedule->save();
        }

        $category = ServiceCategory::query()->create(['name' => 'Kezelések']);

        $service = Service::query()->create([
            'category_id' => $category->getKey(),
            'name' => 'Konzultáció',
            'booking_mode' => BookingMode::DurationBased,
            'duration_minutes' => 60,
            'buffer_after_minutes' => 10,
            'price_minor' => 1_800_000,
            'currency' => 'HUF',
            'requires_staff' => true,
            'active' => true,
        ]);

        $this->seedHistory($service, $staff, $room, $data);
        $this->seedUpcoming($service, $staff, $room, $data);
    }

    /**
     * Past bookings, written as if they happened then (docs/20 §3.3).
     *
     * Each one is created through the real Action with the clock moved back, so
     * `created_at`, the `booking_status_history` chain and the transition
     * metadata all agree — and then walked confirmed → completed, because a
     * past appointment that is still "confirmed" is what an abandoned system
     * looks like.
     */
    private function seedHistory(Service $service, Staff $staff, Room $room, DemoDataFactory $data): void
    {
        $create = app(CreateBooking::class);
        $changeStatus = app(ChangeBookingStatus::class);

        for ($daysAgo = self::HISTORY_DAYS; $daysAgo >= 1; $daysAgo--) {
            $day = $data->today()->subDays($daysAgo);

            // Weekends are outside the seeded schedule.
            if ($day->isWeekend()) {
                continue;
            }

            $startsAt = $data->at(-$daysAgo, sprintf('%02d:00', $data->between(9, 15)));

            // Booked a couple of days before the appointment, the way a customer
            // does — not at the same instant it was attended.
            $bookedAt = $startsAt->copy()->subDays(2);

            $booking = $data->asOf($bookedAt, fn () => $create(
                $service,
                [
                    'staff_id' => $staff->getKey(),
                    'room_id' => $room->getKey(),
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->copy()->addMinutes((int) $service->duration_minutes),
                    'guest_name' => $data->faker()->name(),
                    'guest_email' => $data->faker()->userName().'@'.$this->slug().'.demo.slot4u.hu',
                    'price_minor' => $service->price_minor,
                    'currency' => $service->currency,
                    'source' => BookingSource::Online->value,
                ],
            ));

            // Completed just after it ended, again on the clock of the day.
            $data->asOf(
                $startsAt->copy()->addMinutes((int) $service->duration_minutes),
                fn () => $changeStatus($booking, BookingStatus::Completed),
            );
        }
    }

    /**
     * A partly booked fortnight ahead, so the public calendar has both taken and
     * free slots to show (docs/20 §1.3).
     */
    private function seedUpcoming(Service $service, Staff $staff, Room $room, DemoDataFactory $data): void
    {
        $create = app(CreateBooking::class);

        foreach (range(1, 14) as $daysAhead) {
            $day = $data->today()->addDays($daysAhead);

            if ($day->isWeekend()) {
                continue;
            }

            // Not every day: a fully booked calendar sells the product no better
            // than an empty one, and leaves a visitor nothing to click.
            if ($data->between(1, 3) === 1) {
                continue;
            }

            $startsAt = $data->at($daysAhead, sprintf('%02d:00', $data->between(9, 15)));

            $create(
                $service,
                [
                    'staff_id' => $staff->getKey(),
                    'room_id' => $room->getKey(),
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->copy()->addMinutes((int) $service->duration_minutes),
                    'guest_name' => $data->faker()->name(),
                    'guest_email' => $data->faker()->userName().'@'.$this->slug().'.demo.slot4u.hu',
                    'price_minor' => $service->price_minor,
                    'currency' => $service->currency,
                    'source' => BookingSource::Online->value,
                ],
            );
        }
    }
}
