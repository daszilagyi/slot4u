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
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
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
use Illuminate\Support\Str;

/**
 * „Premium Fitness Studio" — the flagship persona (SLO-187/SLO-188, docs/20 §2.3).
 *
 * The biggest of the four and the one the landing screenshots come from. This
 * class is the FIRST of its three parts (SLO-188): the tenant, its features,
 * two sites, the team, and the services that need no events and no money —
 * personal training and the two rentals. Group classes with their waitlist are
 * SLO-189; the payment and invoice history is SLO-190. Both extend this file
 * rather than replacing it.
 *
 * What it adds to the demo set that no other persona can:
 *
 * - **Two sites, and a trainer who works at both** (SLO-51). Ádám coaches in
 *   Buda in the mornings and in Pest in the afternoons, so the location filter
 *   on the public calendar has something real to filter and his diary proves a
 *   person cannot be in two places at once.
 * - **A rental with a duration the customer picks** (docs/04 §4) — the only
 *   free-range `resource_rental` in the set.
 *
 * ## Nothing here contends for the same resource, again on purpose
 *
 * Personal training books a trainer and NO room (it happens on the gym floor),
 * the sauna books the sauna, the PT box books the PT box. Besides keeping the
 * seed collision-free, it avoids a clash the engine would not catch: an
 * `event_based` sign-up claims capacity rather than locking its room
 * ({@see CreateBooking}), so a PT session pinned to the main hall would happily
 * be booked on top of SLO-189's spinning class.
 */
final class FitnessDemoPersona extends DemoPersona
{
    /** docs/20 §2.3: ~180 days of dense trade for the dashboard "wow" state. */
    private const HISTORY_DAYS = 180;

    private const FUTURE_DAYS = 21;

    private const CUSTOMER_COUNT = 60;

    private const PT_MINUTES = 60;

    private const SAUNA_MINUTES = 60;

    /** The PT box is let for as long as the customer wants, within these bounds. */
    private const BOX_MIN_MINUTES = 60;

    private const BOX_MAX_MINUTES = 180;

    private const PRIMARY_COLOR = '#e0592a';

    /**
     * Per-trainer hours as `[location key, ISO weekday, open, close]`.
     *
     * ⚠️ Ádám appears under BOTH sites, and his two shifts never overlap — the
     * whole point of the multi-location demo (SLO-51). A `Schedule` carries a
     * `location_id`, and `WorkingWindows::matchesLocation()` only offers a band
     * when the visitor asked for that site (or asked for none), so filtering the
     * public calendar to Pest correctly hides his Buda mornings.
     *
     * @var array<string, list<array{string, int, int, int}>>
     */
    private const SHIFTS = [
        'adam' => [
            ['buda', 1, 6, 12], ['buda', 3, 6, 12], ['buda', 5, 6, 12],
            ['pest', 2, 14, 20], ['pest', 4, 14, 20],
        ],
        'petra' => [
            ['buda', 1, 12, 20], ['buda', 2, 12, 20], ['buda', 3, 12, 20],
            ['buda', 4, 12, 20], ['buda', 5, 12, 20],
        ],
        'marcell' => [
            ['pest', 1, 8, 16], ['pest', 2, 8, 16], ['pest', 3, 8, 16],
            ['pest', 4, 8, 16], ['pest', 5, 8, 16],
        ],
        // The class instructors mostly matter to SLO-189, but they need hours
        // now so the team reads as a real roster rather than three names.
        'lena' => [['pest', 1, 17, 21], ['pest', 3, 17, 21], ['pest', 6, 9, 13]],
        'gergo' => [['buda', 2, 17, 21], ['buda', 4, 17, 21], ['buda', 6, 9, 13]],
        'nora' => [['buda', 1, 8, 16], ['buda', 2, 8, 16], ['buda', 3, 8, 16], ['buda', 4, 8, 16], ['buda', 5, 8, 16]],
    ];

    /** Who takes personal training bookings. */
    private const TRAINERS = ['adam', 'petra', 'marcell'];

    public function slug(): string
    {
        return 'demo-fitnesz';
    }

    public function name(): string
    {
        return 'Premium Fitness Studio';
    }

    public function adminName(): string
    {
        return 'Bognár Tamás';
    }

    /**
     * @return array<string, mixed>
     */
    public function profileSettings(): array
    {
        return [
            'description' => 'Két budapesti stúdió, csoportórák, személyi edzés és szauna. '
                .'Foglalj online a saját edződhöz, vagy nézd meg a heti órarendet — '
                .'a helyszínt te választod.',
            'email' => 'studio@'.$this->slug().'.demo.slot4u.hu',
            'phone' => '+36 1 501 8820',
            'address_line' => 'Margit körút 12.',
            'address_city' => 'Budapest',
            'address_postal' => '1027',
            'opening_hours' => 'Buda: H–P 6:00–21:00, Szo 9:00–13:00 · Pest: H–P 8:00–21:00',
            'social' => [
                'website' => 'https://premiumfitness.demo.slot4u.hu',
                'instagram' => 'https://instagram.com/premiumfitness.demo',
            ],
            // A gym's diary is worked in half hours.
            'slot_interval_minutes' => 30,
        ];
    }

    protected function build(Tenant $tenant, User $admin, DemoDataFactory $data): void
    {
        app(TenantManager::class)->set($tenant);

        try {
            $this->buildFor($tenant, $data);
        } finally {
            app(TenantManager::class)->forget();
        }
    }

    private function buildFor(Tenant $tenant, DemoDataFactory $data): void
    {
        $this->enableFeatures($tenant);
        $this->seedDesk($tenant);

        $locations = [
            'buda' => Location::query()->create([
                'name' => 'Premium Fitness Buda',
                'address' => ['line' => 'Margit körút 12.', 'city' => 'Budapest', 'postal_code' => '1027'],
                'phone' => '+36 1 501 8820',
                'sort_order' => 1,
                'active' => true,
            ]),
            'pest' => Location::query()->create([
                'name' => 'Premium Fitness Pest',
                'address' => ['line' => 'Károly körút 3.', 'city' => 'Budapest', 'postal_code' => '1075'],
                'phone' => '+36 1 501 8821',
                'sort_order' => 2,
                'active' => true,
            ]),
        ];

        $rooms = [
            // Buda: the big floor and the sauna.
            'nagyterem' => $this->room($locations['buda'], 'Nagyterem', 24),
            'szauna' => $this->room($locations['buda'], 'Szauna', 6),
            // Pest: the small studio and the box an outside trainer can hire.
            'kisterem' => $this->room($locations['pest'], 'Kisterem', 16),
            'ptbox' => $this->room($locations['pest'], 'PT-box', 2),
        ];

        $team = $this->seedTeam($locations);

        // Only the two rentals need room hours: they ARE their room's diary.
        // The class rooms get theirs with the timetable in SLO-189.
        $this->schedule($locations['buda'], $rooms['szauna'], range(1, 7), 8, 21);
        $this->schedule($locations['pest'], $rooms['ptbox'], range(1, 6), 6, 22);

        [$personal, $sauna, $box] = $this->seedServices($team, $rooms);
        $customers = $this->seedCustomers($data);

        $this->seedPersonalTraining($personal, $team, $customers, $data);
        $this->seedRental($sauna, $rooms['szauna'], $customers, $data, self::SAUNA_MINUTES);
        $this->seedBoxRentals($box, $rooms['ptbox'], $customers, $data);
    }

    /**
     * The Max-package feature set (docs/20 §2.3) — as far as "everything on"
     * can honestly go.
     *
     * ⚠️ Deliberately not every flag. `feature_sms`, `feature_api`,
     * `feature_documents`, `feature_nlp_booking` and `feature_google_meet` have
     * no implementation behind them at all (zero references outside the enum),
     * so switching them on would put doors in the demo that open onto nothing —
     * worse in a sales conversation than not offering them. The four enabled
     * here are the ones that are off by default on the base plan AND built.
     */
    private function enableFeatures(Tenant $tenant): void
    {
        $tenant->branding = (new TenantBranding(primaryColor: self::PRIMARY_COLOR))->toArray();
        $tenant->save();

        foreach ([Feature::Branding, Feature::OnlinePayment, Feature::Invoicing, Feature::CustomDomain] as $feature) {
            TenantFeature::query()->create([
                'feature_code' => $feature->value,
                'enabled' => true,
            ]);
        }
    }

    /**
     * The three people who run the place (docs/20 §2.3), on three different
     * roles — which is the only way the permission matrix is demonstrable.
     */
    private function seedDesk(Tenant $tenant): void
    {
        $this->createStaffUser($tenant, 'Halmi Judit', 'manager@'.$this->slug().'.demo.slot4u.hu', Role::Manager);
        $this->createStaffUser($tenant, 'Fekete Nóra', 'recepcio@'.$this->slug().'.demo.slot4u.hu', Role::Employee);
    }

    private function room(Location $location, string $name, int $capacity): Room
    {
        return Room::query()->create([
            'location_id' => $location->getKey(),
            'name' => $name,
            'capacity' => $capacity,
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, Location>  $locations
     * @return array<string, Staff>
     */
    private function seedTeam(array $locations): array
    {
        $people = [
            'adam' => ['Barta Ádám', 'személyi edző', '#e0592a'],
            'petra' => ['Szűcs Petra', 'személyi edző, funkcionális', '#2f8fbf'],
            'marcell' => ['Deák Marcell', 'személyi edző', '#5b9e4f'],
            'lena' => ['Vámos Léna', 'jógaoktató', '#9a6fbf'],
            'gergo' => ['Rózsa Gergő', 'spinning oktató', '#c9a227'],
            'nora' => ['Fekete Nóra', 'recepció, csoportos edző', '#6b7280'],
        ];

        $team = [];

        foreach ($people as $key => [$name, $title, $color]) {
            $staff = Staff::query()->create([
                'name' => $name,
                'title' => $title,
                'color' => $color,
                'active' => true,
            ]);

            $shifts = self::SHIFTS[$key];
            $sites = array_values(array_unique(array_map(static fn (array $s): string => $s[0], $shifts)));

            // staff_locations is what marks a trainer as working at a site at
            // all (SLO-51); the schedules below say WHEN.
            $staff->locations()->sync(array_map(static fn (string $k): int => $locations[$k]->getKey(), $sites));

            foreach ($shifts as [$site, $weekday, $open, $close]) {
                $this->schedule($locations[$site], $staff, [$weekday], $open, $close);
            }

            $team[$key] = $staff;
        }

        return $team;
    }

    /**
     * @param  list<int>  $weekdays
     */
    private function schedule(Location $location, Staff|Room $resource, array $weekdays, int $open, int $close): void
    {
        foreach ($weekdays as $weekday) {
            $schedule = new Schedule([
                // The band belongs to a site, which is what makes the public
                // location filter mean anything (WorkingWindows::matchesLocation).
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

    /**
     * @param  array<string, Staff>  $team
     * @param  array<string, Room>  $rooms
     * @return array{Service, Service, Service} personal training, sauna, PT box
     */
    private function seedServices(array $team, array $rooms): array
    {
        $training = ServiceCategory::query()->create(['name' => 'Edzés', 'sort_order' => 1]);
        $rental = ServiceCategory::query()->create(['name' => 'Bérlés', 'sort_order' => 2]);

        // No room: personal training happens on the gym floor. Pinning it to a
        // hall would also put it in the way of SLO-189's classes — an
        // event_based sign-up claims capacity rather than locking the room, so
        // the engine would not catch the clash.
        $personal = Service::query()->create([
            'category_id' => $training->getKey(),
            'name' => 'Személyi edzés',
            'description' => 'Hatvan perc egy az egyben az edződdel, mindkét stúdióban. '
                .'Válaszd ki az edzőt és a helyszínt — a szabad időpontokat aszerint mutatjuk.',
            'booking_mode' => BookingMode::DurationBased,
            'duration_minutes' => self::PT_MINUTES,
            'buffer_after_minutes' => 15,
            'price_minor' => 14_000 * 100,
            'currency' => 'HUF',
            'requires_staff' => true,
            'active' => true,
        ]);
        $personal->staff()->sync(array_map(
            static fn (string $key): int => $team[$key]->getKey(),
            self::TRAINERS,
        ));

        $sauna = Service::query()->create([
            'category_id' => $rental->getKey(),
            'name' => 'Szaunabérlés',
            'description' => 'A budai szauna privát bérlése egy órára, akár hat főig.',
            'booking_mode' => BookingMode::ResourceRental,
            'duration_minutes' => self::SAUNA_MINUTES,
            'price_minor' => 8_000 * 100,
            'currency' => 'HUF',
            'requires_room' => true,
            'active' => true,
        ]);
        $sauna->rooms()->sync([$rooms['szauna']->getKey()]);

        // ⚠️ The free-range rental (docs/04 §4) — the only one in the demo set,
        // and the reason it is priced as a BLOCK rather than by the hour.
        //
        // `price_minor` is a flat per-booking snapshot (CreateBooking), so an
        // hourly rate here would charge the same for one hour as for three and
        // read as a bug to the one visitor who tries both. A session fee for a
        // slot you size yourself is a real pricing model, and it is one the
        // engine states truthfully. Per-hour pricing needs SLO-193.
        $box = Service::query()->create([
            'category_id' => $rental->getKey(),
            'name' => 'PT-box bérlés (alkalmi díj)',
            'description' => 'Külső edzőknek: a pesti PT-box bérlése egy és három óra között '
                .'szabadon választható időtartamra, egységes alkalmi díjjal.',
            'booking_mode' => BookingMode::ResourceRental,
            // null duration = the customer picks, within the bounds below.
            'duration_minutes' => null,
            'price_minor' => 18_000 * 100,
            'currency' => 'HUF',
            'requires_room' => true,
            'settings' => [
                'min_duration_minutes' => self::BOX_MIN_MINUTES,
                'max_duration_minutes' => self::BOX_MAX_MINUTES,
            ],
            'active' => true,
        ]);
        $box->rooms()->sync([$rooms['ptbox']->getKey()]);

        return [$personal, $sauna, $box];
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
                'name' => $name,
                'email' => Str::slug($name, '.').'.'.$index.'@'.$this->slug().'.demo.slot4u.hu',
                'phone' => '+3630'.sprintf('%07d', $data->between(1_000_000, 9_999_999)),
            ]);
        }

        return $customers;
    }

    /**
     * Personal training, placed inside each trainer's own shift for that day —
     * including Ádám's, whose shift is at a different site depending on the
     * weekday.
     *
     * @param  array<string, Staff>  $team
     * @param  list<Customer>  $customers
     */
    private function seedPersonalTraining(Service $service, array $team, array $customers, DemoDataFactory $data): void
    {
        foreach ($this->dayRange() as $offset) {
            $weekday = $data->today()->addDays($offset)->dayOfWeekIso;

            foreach (self::TRAINERS as $key) {
                foreach (self::SHIFTS[$key] as [, $shiftDay, $open, $close]) {
                    if ($shiftDay !== $weekday) {
                        continue;
                    }

                    // One or two sessions in the shift, on the hour so two can
                    // never overlap (60 minutes + a 15 minute buffer fits).
                    foreach ($this->pickHours($open, $close - 1, $data->between(0, 2), $data) as $hour) {
                        $this->place($service, $team[$key], null, $offset, $hour, self::PT_MINUTES, $customers, $data);
                    }
                }
            }
        }
    }

    /**
     * A fixed-length room rental — the sauna.
     *
     * @param  list<Customer>  $customers
     */
    private function seedRental(Service $service, Room $room, array $customers, DemoDataFactory $data, int $minutes): void
    {
        foreach ($this->dayRange() as $offset) {
            foreach ($this->pickHours(9, 20, $data->between(0, 2), $data) as $hour) {
                $this->place($service, null, $room, $offset, $hour, $minutes, $customers, $data);
            }
        }
    }

    /**
     * The PT box, where the customer chose the length.
     *
     * A forward-only cursor rather than an hour grid: the lets are 60 to 180
     * minutes, so there is no fixed slot they all fit in, and a cursor that only
     * moves past the end of what it just placed cannot double-book.
     *
     * @param  list<Customer>  $customers
     */
    private function seedBoxRentals(Service $service, Room $room, array $customers, DemoDataFactory $data): void
    {
        foreach ($this->dayRange() as $offset) {
            if ($data->today()->addDays($offset)->dayOfWeekIso === 7) {
                continue;
            }

            $cursor = 6 * 60;
            $close = 22 * 60;

            while ($cursor < $close) {
                // Long gaps: an outside trainer hires the box a couple of times
                // a week, not back to back all day. Tuned deliberately — at a
                // shorter gap the box ended up the busiest service in the gym,
                // outnumbering personal training, which is not what a studio's
                // diary looks like.
                $cursor += $data->between(240, 700);
                $length = $data->oneOf([60, 90, 120, 180]);

                if ($cursor + $length > $close) {
                    break;
                }

                $cursor = (int) ceil($cursor / 30) * 30;

                if ($cursor + $length > $close) {
                    break;
                }

                $this->place($service, null, $room, $offset, intdiv($cursor, 60), $length, $customers, $data, $cursor % 60);
                $cursor += $length;
            }
        }
    }

    /**
     * Create one booking and walk it to a terminal state if it is in the past.
     *
     * @param  list<Customer>  $customers
     */
    private function place(Service $service, ?Staff $staff, ?Room $room, int $offset, int $hour, int $minutes, array $customers, DemoDataFactory $data, int $minute = 0): void
    {
        $startsAt = $data->at($offset, sprintf('%02d:%02d', $hour, $minute));
        $endsAt = $startsAt->copy()->addMinutes($minutes);
        $bookedAt = $startsAt->copy()->subDays($data->between(1, 9))->setTime(19, 40);

        $booking = $data->asOf($bookedAt, fn (): Booking => app(CreateBooking::class)($service, [
            'customer_id' => $customers[$data->between(0, count($customers) - 1)]->getKey(),
            'staff_id' => $staff?->getKey(),
            'room_id' => $room?->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'source' => BookingSource::Online->value,
        ], null, null, $data->notifiable($bookedAt)));

        // Terminality is decided by the clock, not by the day offset: a session
        // seeded for 07:00 today is already over by the time an afternoon
        // `demo:reset` finishes, and would otherwise sit at `confirmed` in the
        // past — the exact state every other persona is careful not to leave.
        if ($endsAt->isFuture()) {
            return;
        }

        // Everything in the past reaches a terminal state — a gym's diary full
        // of month-old "confirmed" sessions is what a dead system looks like,
        // and it would count revenue for training nobody attended.
        $changeStatus = app(ChangeBookingStatus::class);

        match ($data->between(1, 20)) {
            1 => $data->asOf(
                $startsAt->copy()->subDay()->setTime(20, 0),
                fn () => app(CancelBooking::class)($booking, null, 'Az ügyfél lemondta.'),
            ),
            2 => $data->asOf($endsAt, fn () => $changeStatus($booking, BookingStatus::NoShow)),
            default => $data->asOf($endsAt, fn () => $changeStatus($booking, BookingStatus::Completed)),
        };
    }

    /**
     * Day offsets from the far past to the near future, oldest first — so every
     * booking is created after the one before it.
     *
     * @return list<int>
     */
    private function dayRange(): array
    {
        return range(-self::HISTORY_DAYS, self::FUTURE_DAYS);
    }

    /**
     * `$count` distinct whole hours in `[$from, $to]`, ascending.
     *
     * @return list<int>
     */
    private function pickHours(int $from, int $to, int $count, DemoDataFactory $data): array
    {
        $pool = range($from, max($from, $to));
        $picked = [];

        for ($i = 0; $i < $count && $pool !== []; $i++) {
            $index = $data->between(0, count($pool) - 1);
            $picked[] = $pool[$index];
            array_splice($pool, $index, 1);
        }

        sort($picked);

        return $picked;
    }
}
