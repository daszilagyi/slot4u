<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Booking\CreateBooking;
use App\Actions\Customer\CreateCustomer;
use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\MessageTemplate;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Settings\TenantBranding;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * „GlamZone Szépségszalon" — the multi-staff SME persona (SLO-185, docs/20 §2.2).
 *
 * The classic small business the product is aimed at: several people working
 * different shifts under one roof, a full catalogue, and half a year of dense
 * trade behind it. Where the psychologist persona (SLO-184) proves the engine
 * works for one person, this one is where the things that only exist with
 * *several* become demonstrable:
 *
 * - **staff choice, and "anyone"** — the hairdressing services are offered by
 *   both stylists, so a visitor can pick one or let the calendar pick
 *   (AvailabilityService unions the service's staff when none is pinned);
 * - **different shifts per person** — a morning stylist, an afternoon one, and
 *   a nail tech who is the only one working Saturdays, so the calendar's
 *   staff/room filters have something to actually filter;
 * - **the statistics module with real curves** — ~180 days of trade, returning
 *   customers, per-staff utilisation against per-staff schedules;
 * - **branding**, and the fact that it is a switch somebody has to throw;
 * - **the Manager role**, which is only a demo if someone can log in as one.
 *
 * ## ⚠️ Four chairs, four rooms — not the three the spec names
 *
 * docs/20 §2.2 asks for three rooms (Fodrász tér / Kozmetika / Körmös stúdió)
 * and four staff, with both stylists in the shared hairdressing area. The
 * booking engine cannot express that: a room is an EXCLUSIVE resource — the
 * conflict check in {@see CreateBooking} matches on `staff_id OR room_id`, so
 * two stylists working at the same time in one room is a double-booking, and
 * every second appointment in the salon would be refused.
 *
 * So the shared floor is modelled as what the engine understands it to be: two
 * chairs are two rooms. The three functional areas survive in the naming, and
 * the alternative — leaving `room_id` null on hairdressing — would have cost
 * the room utilisation report exactly the busiest half of the salon.
 */
final class SalonDemoPersona extends DemoPersona
{
    /** Half a year of trade — what the statistics module needs to show a curve (docs/20 §2.2). */
    private const HISTORY_DAYS = 180;

    private const FUTURE_DAYS = 21;

    /**
     * ⚠️ 180, not the 35 docs/20 §2.2 names — the spec's two numbers cannot both
     * be true.
     *
     * Half a year at the 6–10 appointments a day it also asks for is ~1200
     * bookings; spread over 35 people that is forty-odd visits each, a client in
     * the chair every four days. The customer list — which the demo opens — would
     * read as obviously generated, and "top customer" would mean nothing because
     * everyone would be one.
     *
     * The daily volume is the half worth keeping: it is what the statistics
     * module needs to draw six months of curve (§2.2 AC). So the roster grows to
     * match it, and lands where a real salon's book sits — most clients in a few
     * times a year, a core of regulars in monthly.
     */
    private const CUSTOMER_COUNT = 180;

    /** Appointments start on a quarter hour, the way a salon's book is written. */
    private const GRID_MINUTES = 15;

    /**
     * The salon's own colours (docs/20 §2.2). Rendered on the public page only
     * once `feature_branding` is switched on for the tenant — see
     * {@see self::enableBranding()}.
     */
    private const PRIMARY_COLOR = '#b0476b';

    /**
     * Per-staff hours, as ISO weekday => [open, close] in the tenant's local
     * wall clock. Deliberately all different (docs/20 §2.2): identical shifts
     * would make the calendar's staff filter a control with nothing to show.
     *
     * @var array<string, array<int, array{int, int}>>
     */
    private const SHIFTS = [
        // Morning stylist.
        'reka' => [1 => [9, 16], 2 => [9, 16], 3 => [9, 16], 4 => [9, 16], 5 => [9, 16]],
        // Afternoon stylist — the two overlap only in the middle of the day.
        'bence' => [1 => [12, 20], 2 => [12, 20], 3 => [12, 20], 4 => [12, 20], 5 => [12, 20]],
        'nora' => [1 => [10, 18], 2 => [10, 18], 3 => [10, 18], 4 => [10, 18]],
        // The only one who works Saturdays.
        'dorina' => [3 => [10, 18], 4 => [10, 18], 5 => [10, 18], 6 => [9, 14]],
    ];

    public function slug(): string
    {
        return 'demo-szepsegszalon';
    }

    public function name(): string
    {
        return 'GlamZone Szépségszalon';
    }

    public function adminName(): string
    {
        return 'Farkas Emília';
    }

    /**
     * @return array<string, mixed>
     */
    public function profileSettings(): array
    {
        return [
            'description' => 'Fodrászat, kozmetika és körömszalon egy helyen, a Bartók Béla úton. '
                .'Foglalj online a saját fodrászodhoz, vagy válaszd a legkorábbi szabad időpontot.',
            'email' => 'szalon@'.$this->slug().'.demo.slot4u.hu',
            'phone' => '+36 1 279 4180',
            'address_line' => 'Bartók Béla út 42. fszt. 2.',
            'address_city' => 'Budapest',
            'address_postal' => '1114',
            'opening_hours' => 'H–P 9:00–20:00 · Szo 9:00–14:00 (körmös)',
            'social' => [
                'website' => 'https://glamzone.demo.slot4u.hu',
                'instagram' => 'https://instagram.com/glamzone.demo',
                'facebook' => 'https://facebook.com/glamzone.demo',
            ],
        ];
    }

    protected function build(Tenant $tenant, User $admin, DemoDataFactory $data): void
    {
        app(TenantManager::class)->set($tenant);

        try {
            $this->buildFor($tenant, $admin, $data);
        } finally {
            app(TenantManager::class)->forget();
        }
    }

    private function buildFor(Tenant $tenant, User $owner, DemoDataFactory $data): void
    {
        $this->brand($tenant);

        // The receptionist. A Manager sees the diary and the customers but not
        // the settings or the billing (Role::Manager) — which is only a demo of
        // anything if there is an account to sign in with and find that out.
        $this->createStaffUser(
            $tenant,
            'Balogh Kata',
            'recepcio@'.$this->slug().'.demo.slot4u.hu',
            Role::Manager,
        );

        $location = Location::query()->create([
            'name' => 'GlamZone Szalon',
            'address' => [
                'line' => 'Bartók Béla út 42. fszt. 2.',
                'city' => 'Budapest',
                'postal_code' => '1114',
            ],
            'phone' => '+36 1 279 4180',
            'active' => true,
        ]);

        $staff = $this->seedTeam($location, $data);
        $services = $this->seedCatalogue($staff);
        $this->seedTemplate();

        $customers = $this->seedCustomers($data);

        $this->seedHistory($staff, $services, $customers, $data);
        $this->seedUpcoming($staff, $services, $customers, $data);
    }

    /**
     * The salon's colours — and the switch that makes them visible.
     *
     * `feature_branding` is OFF on the base plan by default
     * ({@see Feature::enabledByDefaultOnBase()}), so writing the `branding` JSON
     * alone would leave the demo looking exactly like every unbranded tenant.
     * The override row is the same one a superadmin writes, which means the
     * persona also demonstrates the per-tenant feature path rather than merely
     * depending on it.
     */
    private function brand(Tenant $tenant): void
    {
        $tenant->branding = (new TenantBranding(primaryColor: self::PRIMARY_COLOR))->toArray();
        $tenant->save();

        TenantFeature::query()->create([
            'feature_code' => Feature::Branding->value,
            'enabled' => true,
        ]);
    }

    /**
     * The four people and the room each of them works in.
     *
     * @return array<string, array{staff: Staff, room: Room}>
     */
    private function seedTeam(Location $location, DemoDataFactory $data): array
    {
        $people = [
            'reka' => ['Kovács Réka', 'senior fodrász', '#b0476b', 'Fodrász tér — 1. szék'],
            'bence' => ['Tóth Bence', 'fodrász', '#3f7fb5', 'Fodrász tér — 2. szék'],
            'nora' => ['Szabó Nóra', 'kozmetikus', '#4fa07a', 'Kozmetika'],
            'dorina' => ['Kiss Dorina', 'körömspecialista', '#c98b2e', 'Körmös stúdió'],
        ];

        $team = [];

        foreach ($people as $key => [$name, $title, $color, $roomName]) {
            $staff = Staff::query()->create([
                'name' => $name,
                'title' => $title,
                'color' => $color,
                'active' => true,
            ]);
            $staff->locations()->sync([$location->getKey()]);

            $room = Room::query()->create([
                'location_id' => $location->getKey(),
                'name' => $roomName,
                'capacity' => 1,
                'active' => true,
            ]);

            $this->seedShift($location, $staff, $room, self::SHIFTS[$key]);

            $team[$key] = ['staff' => $staff, 'room' => $room];
        }

        return $team;
    }

    /**
     * One person's weekly hours, mirrored onto their room.
     *
     * The room needs its own bands because the public calendar lets a visitor
     * filter by room, and a room with no schedule has no free windows to offer —
     * the filter would answer "nothing available" in a salon that is open.
     *
     * @param  array<int, array{int, int}>  $shift
     */
    private function seedShift(Location $location, Staff $staff, Room $room, array $shift): void
    {
        foreach ($shift as $weekday => [$open, $close]) {
            foreach ([$staff, $room] as $resource) {
                $schedule = new Schedule([
                    'location_id' => $location->getKey(),
                    'day_of_week' => $weekday,
                    'start_time' => sprintf('%02d:00', $open),
                    'end_time' => sprintf('%02d:00', $close),
                ]);
                // schedulable_* are guarded and NOT NULL — associated before the save.
                $schedule->schedulable()->associate($resource);
                $schedule->save();
            }
        }
    }

    /**
     * Ten services across the three areas (docs/20 §2.2), with genuinely
     * different lengths and buffers — a catalogue of identical 60-minute slots
     * would hide the thing the scheduler is actually good at.
     *
     * @param  array<string, array{staff: Staff, room: Room}>  $team
     * @return array<string, list<Service>> keyed by the staff key that provides them
     */
    private function seedCatalogue(array $team): array
    {
        $hair = ServiceCategory::query()->create(['name' => 'Fodrászat', 'sort_order' => 1]);
        $beauty = ServiceCategory::query()->create(['name' => 'Kozmetika', 'sort_order' => 2]);
        $nails = ServiceCategory::query()->create(['name' => 'Kéz és láb', 'sort_order' => 3]);

        // Both stylists provide every hairdressing service, which is what makes
        // "anyone" bookable: with no staff pinned, AvailabilityService offers
        // the union of their free windows (docs/20 §2.2).
        $stylists = [$team['reka']['staff']->getKey(), $team['bence']['staff']->getKey()];
        $chairs = [$team['reka']['room']->getKey(), $team['bence']['room']->getKey()];

        $hairServices = [
            $this->service($hair, 'Női hajvágás', 60, 10, 12_500),
            $this->service($hair, 'Festés + vágás', 120, 15, 24_000),
            $this->service($hair, 'Férfi hajvágás', 30, 5, 6_500),
            $this->service($hair, 'Melírozás', 150, 15, 29_000),
            $this->service($hair, 'Hajmosás + szárítás', 30, 5, 4_500),
        ];

        foreach ($hairServices as $service) {
            $service->staff()->sync($stylists);
            $service->rooms()->sync($chairs);
        }

        $beautyServices = [
            $this->service($beauty, 'Arckezelés', 75, 10, 15_000),
            $this->service($beauty, 'Szemöldök formázás és festés', 30, 5, 5_500),
        ];

        foreach ($beautyServices as $service) {
            $service->staff()->sync([$team['nora']['staff']->getKey()]);
            $service->rooms()->sync([$team['nora']['room']->getKey()]);
        }

        $nailServices = [
            $this->service($nails, 'Géllakk', 60, 10, 9_000),
            $this->service($nails, 'Műköröm építés', 90, 15, 14_000),
            $this->service($nails, 'Pedikűr', 60, 10, 11_000),
        ];

        foreach ($nailServices as $service) {
            $service->staff()->sync([$team['dorina']['staff']->getKey()]);
            $service->rooms()->sync([$team['dorina']['room']->getKey()]);
        }

        return [
            'reka' => $hairServices,
            'bence' => $hairServices,
            'nora' => $beautyServices,
            'dorina' => $nailServices,
        ];
    }

    private function service(ServiceCategory $category, string $name, int $minutes, int $buffer, int $priceHuf): Service
    {
        return Service::query()->create([
            'category_id' => $category->getKey(),
            'name' => $name,
            'booking_mode' => BookingMode::DurationBased,
            'duration_minutes' => $minutes,
            // Only an after-buffer: the tidy-up belongs to the appointment that
            // just ended, and a before-buffer as well would double-count it.
            'buffer_after_minutes' => $buffer,
            // Money in minor units (docs/01 §6) — fillér, never float.
            'price_minor' => $priceHuf * 100,
            'currency' => 'HUF',
            'requires_staff' => true,
            'active' => true,
        ]);
    }

    /**
     * The salon's own wording for the confirmation mail (docs/20 §2.2).
     *
     * A tenant override of the built-in template (SLO-113): proof in the demo
     * that the mail a customer receives is the salon's voice, not the
     * platform's.
     */
    private function seedTemplate(): void
    {
        MessageTemplate::query()->create([
            'key' => NotificationType::BookingConfirmed,
            'channel' => NotificationChannel::Email,
            'locale' => 'hu',
            'subject' => 'Szia {{customer_name}}! Foglalásod megvan a GlamZone-ban ✨',
            'body' => "Szia {{customer_name}}!\n\n"
                ."Várunk szeretettel a GlamZone-ban:\n\n"
                ."• Szolgáltatás: {{service_name}}\n"
                ."• Időpont: {{booking_date}} {{booking_time}}\n"
                ."• Kollégád: {{staff_name}}\n\n"
                ."Ha közbejön valami, a foglalásod oldalán egy kattintással le tudod mondani.\n\n"
                .'Puszi, a GlamZone csapata',
            'enabled' => true,
        ]);
    }

    /**
     * @return list<Customer>
     */
    private function seedCustomers(DemoDataFactory $data): array
    {
        $create = app(CreateCustomer::class);
        $customers = [];

        foreach (range(1, self::CUSTOMER_COUNT) as $index) {
            $name = $data->faker()->name();

            $customers[] = $create([
                // `users.email` is globally unique — the persona's own
                // (non-deliverable) domain plus an index keeps two clients who
                // happen to share a name, and every other persona, out of the way.
                'email' => Str::slug($name, '.').'.'.$index.'@'.$this->slug().'.demo.slot4u.hu',
                'name' => $name,
                'phone' => '+3630'.sprintf('%07d', $data->between(1_000_000, 9_999_999)),
            ]);
        }

        return $customers;
    }

    /**
     * Half a year of trade, written as if it happened then (docs/20 §3.3).
     *
     * @param  array<string, array{staff: Staff, room: Room}>  $team
     * @param  array<string, list<Service>>  $services
     * @param  list<Customer>  $customers
     */
    private function seedHistory(array $team, array $services, array $customers, DemoDataFactory $data): void
    {
        $changeStatus = app(ChangeBookingStatus::class);
        $cancel = app(CancelBooking::class);

        for ($daysAgo = self::HISTORY_DAYS; $daysAgo >= 1; $daysAgo--) {
            foreach ($this->dayPlan(-$daysAgo, $team, $services, $customers, $data) as [$booking, $startsAt, $endsAt]) {
                // Every past appointment reaches a terminal state: a diary full
                // of month-old "confirmed" rows is what a system nobody uses
                // looks like — and the revenue figures would count appointments
                // that may never have happened.
                match ($data->between(1, 25)) {
                    1 => $data->asOf(
                        $startsAt->copy()->subDay()->setTime(18, 30),
                        fn () => $cancel($booking, null, 'Az ügyfél lemondta.'),
                    ),
                    2 => $data->asOf($endsAt, fn () => $changeStatus($booking, BookingStatus::NoShow)),
                    default => $data->asOf($endsAt, fn () => $changeStatus($booking, BookingStatus::Completed)),
                };
            }
        }
    }

    /**
     * Three weeks of diary ahead, left partly free.
     *
     * @param  array<string, array{staff: Staff, room: Room}>  $team
     * @param  array<string, list<Service>>  $services
     * @param  list<Customer>  $customers
     */
    private function seedUpcoming(array $team, array $services, array $customers, DemoDataFactory $data): void
    {
        for ($daysAhead = 1; $daysAhead <= self::FUTURE_DAYS; $daysAhead++) {
            // Left `confirmed` — these have not happened yet.
            $this->dayPlan($daysAhead, $team, $services, $customers, $data);
        }
    }

    /**
     * One day's appointments across the whole team.
     *
     * Each person's diary is filled by walking their own shift from opening
     * time: place an appointment, advance the cursor past it and its buffer,
     * skip a gap, repeat until the shift runs out. Two properties come out of
     * that for free, and both matter more than they look:
     *
     * - **it cannot double-book.** The services here run 30 to 150 minutes with
     *   different buffers, so there is no tidy hourly grid to lean on the way
     *   the solo practice could (SLO-184). A cursor that only ever moves forward
     *   is the guarantee, rather than a conflict check that happens not to fire.
     * - **it leaves gaps.** A fully booked salon sells the product no better
     *   than an empty one, and leaves a visitor nothing to click.
     *
     * @param  array<string, array{staff: Staff, room: Room}>  $team
     * @param  array<string, list<Service>>  $services
     * @param  list<Customer>  $customers
     * @return list<array{Booking, Carbon, Carbon}> booking, start, end
     */
    private function dayPlan(int $dayOffset, array $team, array $services, array $customers, DemoDataFactory $data): array
    {
        $create = app(CreateBooking::class);
        $weekday = $data->today()->addDays($dayOffset)->dayOfWeekIso;
        $placed = [];

        foreach ($team as $key => $resources) {
            $shift = self::SHIFTS[$key][$weekday] ?? null;

            if ($shift === null) {
                continue;
            }

            [$open, $close] = $shift;
            $cursor = $open * 60;
            $closeMinutes = $close * 60;

            while ($cursor < $closeMinutes) {
                /** @var Service $service */
                $service = $data->oneOf($services[$key]);
                $length = (int) $service->duration_minutes;

                if ($cursor + $length > $closeMinutes) {
                    break;
                }

                $startsAt = $data->at($dayOffset, sprintf('%02d:%02d', intdiv($cursor, 60), $cursor % 60));
                $endsAt = $startsAt->copy()->addMinutes($length);

                // Booked days in advance, in the evening — when people book a
                // hairdresser — rather than at the moment of the cut.
                $bookedAt = $startsAt->copy()->subDays($data->between(2, 12))->setTime(20, 25);

                $booking = $data->asOf(
                    $bookedAt,
                    fn (): Booking => $create($service, [
                        'customer_id' => $this->pickCustomer($customers, $data)->getKey(),
                        'staff_id' => $resources['staff']->getKey(),
                        'room_id' => $resources['room']->getKey(),
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'source' => BookingSource::Online->value,
                    ], null, null, $data->notifiable($bookedAt)),
                );

                $placed[] = [$booking, $startsAt, $endsAt];

                // Past the appointment, its tidy-up buffer, and a gap — then
                // rounded up to the next quarter hour, because a salon's book is
                // written on quarter hours and 13:47 would read as generated.
                //
                // The gap is generous on purpose. Filling every shift end to end
                // would put the salon at ~11 appointments a day against the 6–10
                // the spec asks for (docs/20 §2.2), and — worse for a demo whose
                // public page is the thing to click — leave a visitor no free
                // slot to book into.
                $cursor += $length + (int) $service->buffer_after_minutes + $data->between(20, 240);
                $cursor = (int) ceil($cursor / self::GRID_MINUTES) * self::GRID_MINUTES;
            }
        }

        return $placed;
    }

    /**
     * A customer, weighted so some come back far more often than others.
     *
     * A flat draw would give everyone the same handful of visits, and the "top
     * customers by spend" panel — one of the things the statistics module exists
     * to show — would be a straight line. Real salons have regulars; the first
     * fifth of the list are them, and the spec asks for a top client with 15+
     * visits behind her (docs/20 §2.2).
     *
     * @param  list<Customer>  $customers
     */
    private function pickCustomer(array $customers, DemoDataFactory $data): Customer
    {
        // Two thirds of the trade goes to the first fifth of the list.
        $regulars = max(1, intdiv(count($customers), 5));

        return $data->between(1, 3) <= 2
            ? $customers[$data->between(0, $regulars - 1)]
            : $customers[$data->between(0, count($customers) - 1)];
    }
}
