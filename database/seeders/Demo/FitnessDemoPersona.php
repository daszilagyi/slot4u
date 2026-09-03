<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Booking\CreateBooking;
use App\Actions\Customer\CreateCustomer;
use App\Actions\Event\CreateEvent;
use App\Actions\Waitlist\JoinWaitlist;
use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Location;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Services\Booking\WaitlistService;
use App\Settings\TenantBranding;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    /** How far the published timetable reaches ahead (docs/20 §2.3). */
    private const CLASS_FUTURE_DAYS = 14;

    /**
     * How far back the timetable runs.
     *
     * Shorter than the 180 days of personal training and rentals above, and
     * deliberately: a class is one row per attendee, so six months of a
     * fifteen-a-week timetable is ~3,400 sign-ups — each created AND settled
     * through the real actions (docs/20 §3.3). The nightly reset and every test
     * that seeds this persona pay for all of them. The dashboard's six-month
     * curve comes from the 1/3 services either way; the classes make the recent
     * months busy, which is where anyone actually looks.
     */
    private const CLASS_HISTORY_DAYS = 90;

    /**
     * How far back the classes are busy. Older occurrences are seeded lightly —
     * see {@see self::seedAttendance()} for why that is the story as well as the
     * budget.
     */
    private const BUSY_WINDOW_DAYS = 45;

    private const CUSTOMER_COUNT = 60;

    private const PT_MINUTES = 60;

    private const SAUNA_MINUTES = 60;

    /** The PT box is let for as long as the customer wants, within these bounds. */
    private const BOX_MIN_MINUTES = 60;

    private const BOX_MAX_MINUTES = 180;

    private const PRIMARY_COLOR = '#e0592a';

    /** Every class costs the same — a gym sells a drop-in, not a price list. */
    private const CLASS_PRICE_HUF = 3_900;

    /**
     * The weekly timetable (docs/20 §2.3: ~15 a week), as
     * `[service key, instructor key, room key, ISO weekday, start, minutes, capacity]`.
     *
     * ⚠️ Every class sits inside its instructor's shift from
     * {@see self::SHIFTS}. Nothing enforces that — an event is announced, not
     * generated from availability — which is exactly why it has to be got right
     * here: a timetable that has the yoga teacher on the floor at an hour her
     * own diary says she is not working reads as broken to anyone who opens
     * both screens.
     *
     * @var list<array{string, string, string, int, string, int, int}>
     */
    private const CLASSES = [
        // Buda, the big floor.
        ['functional', 'adam', 'nagyterem', 1, '06:30', 50, 16],
        ['functional', 'petra', 'nagyterem', 1, '18:00', 45, 16],
        ['functional', 'nora', 'nagyterem', 2, '09:00', 50, 14],
        ['spinning', 'gergo', 'nagyterem', 2, '18:00', 45, 14],
        ['functional', 'adam', 'nagyterem', 3, '06:30', 50, 16],
        ['core', 'petra', 'nagyterem', 3, '18:30', 50, 12],
        ['spinning', 'gergo', 'nagyterem', 4, '18:00', 45, 14],
        ['functional', 'adam', 'nagyterem', 5, '06:30', 50, 16],
        ['functional', 'petra', 'nagyterem', 5, '17:00', 45, 16],
        ['spinning', 'gergo', 'nagyterem', 6, '09:30', 45, 14],
        // Pest, the studio.
        ['hatha', 'lena', 'kisterem', 1, '17:30', 60, 12],
        ['pilates', 'marcell', 'kisterem', 2, '12:15', 50, 10],
        ['hatha', 'lena', 'kisterem', 3, '17:30', 60, 12],
        ['vinyasa', 'lena', 'kisterem', 3, '19:00', 60, 12],
        ['hatha', 'lena', 'kisterem', 6, '10:00', 60, 12],
    ];

    /**
     * The class catalogue. An `Event` carries no name of its own — it points at
     * a service — so "Spinning" and "Hatha jóga" are separate services, which
     * is also how the public page lists them.
     *
     * @var array<string, string>
     */
    private const CLASS_SERVICES = [
        'functional' => 'Funkcionális edzés',
        'spinning' => 'Spinning',
        'hatha' => 'Hatha jóga',
        'vinyasa' => 'Vinyásza jóga',
        'pilates' => 'Pilates',
        'core' => 'Core & Stretch',
    ];

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

    /** How many people at the tail of the roster are kept for waitlist queues. */
    private const QUEUE_POOL = 12;

    /**
     * Occurrences the waitlist scenarios have already dealt with, so the bulk
     * attendance pass leaves them exactly as it found them.
     *
     * @var array<int, true>
     */
    private array $claimedEvents = [];

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

        // SLO-189: the timetable and everything that hangs off a full class.
        $classes = $this->seedClassCatalogue();
        $this->seedTimetable($classes, $team, $rooms, $data);
        // Order matters: the waitlist scenarios need classes whose sign-ups have
        // NOT been settled yet — a booking already walked to `completed` cannot
        // be cancelled, and cancelling is what frees the seat the queue is for.
        $this->seedWaitlists($customers, $data);
        $this->seedAttendance($customers, $data);
    }

    /**
     * One `event_based` service per kind of class.
     *
     * An {@see Event} has no name of its own — it points at a service — so
     * "Spinning" and "Hatha jóga" have to BE services. That is also how the
     * public page lists them, which is the right answer anyway.
     *
     * @return array<string, Service>
     */
    private function seedClassCatalogue(): array
    {
        $category = ServiceCategory::query()->create(['name' => 'Csoportórák', 'sort_order' => 0]);
        $services = [];

        foreach (self::CLASS_SERVICES as $key => $name) {
            $services[$key] = Service::query()->create([
                'category_id' => $category->getKey(),
                'name' => $name,
                'description' => 'Csoportóra a heti órarend szerint. Ha betelt, felkerülhetsz a '
                    .'várólistára — ha valaki lemondja, e-mailben szólunk és tiéd a hely.',
                'booking_mode' => BookingMode::EventBased,
                // The default advertised size; each occurrence carries its own.
                'capacity' => 14,
                'price_minor' => self::CLASS_PRICE_HUF * 100,
                'currency' => 'HUF',
                'requires_staff' => true,
                // ⚠️ Without this the waitlist cannot be joined at all
                // (JoinWaitlist refuses), and the full classes below would be a
                // dead end rather than the demo's best moment.
                'waitlist_enabled' => true,
                'active' => true,
            ]);
        }

        return $services;
    }

    /**
     * The recurring weekly timetable, published as real series.
     *
     * Each line of {@see self::CLASSES} becomes ONE call to {@see CreateEvent}
     * with weekly recurrence, so the occurrences share a `series_id` and a
     * recurrence rule exactly as an admin announcing a term would produce —
     * rather than a few hundred unrelated rows that merely look like a
     * timetable.
     *
     * Announced on the clock of the day the term started: a studio publishes its
     * timetable in advance, and `created_at` on six months of classes all
     * reading "today" is the tell that data was generated.
     *
     * @param  array<string, Service>  $classes
     * @param  array<string, Staff>  $team
     * @param  array<string, Room>  $rooms
     */
    private function seedTimetable(array $classes, array $team, array $rooms, DemoDataFactory $data): void
    {
        $create = app(CreateEvent::class);
        $publishedAt = $data->at(-self::CLASS_HISTORY_DAYS - 7, '10:00');
        $until = $data->today()->addDays(self::CLASS_FUTURE_DAYS);

        foreach (self::CLASSES as [$serviceKey, $staffKey, $roomKey, $weekday, $time, $minutes, $capacity]) {
            $first = $this->firstOccurrence($weekday, $time, $data);
            $ends = $first->copy()->addMinutes($minutes);

            $data->asOf($publishedAt, fn () => $create([
                'service_id' => $classes[$serviceKey]->getKey(),
                'staff_id' => $team[$staffKey]->getKey(),
                'room_id' => $rooms[$roomKey]->getKey(),
                // CreateEvent parses these as UTC and works in tenant-local time
                // from there, which is what keeps a 06:30 class at 06:30 across
                // the October clock change.
                'starts_at' => $first->toDateTimeString(),
                'ends_at' => $ends->toDateTimeString(),
                'capacity' => $capacity,
                'waitlist_enabled' => true,
                'repeat_weekly' => true,
                'repeat_until' => $until->toDateString(),
            ]));
        }
    }

    /**
     * The first occurrence of a weekly class: the earliest date on `$weekday` at
     * or after the start of the history window.
     */
    private function firstOccurrence(int $weekday, string $time, DemoDataFactory $data): Carbon
    {
        $offset = -self::CLASS_HISTORY_DAYS;

        while ($data->today()->addDays($offset)->dayOfWeekIso !== $weekday) {
            $offset++;
        }

        return $data->at($offset, $time);
    }

    /**
     * Sign-ups for every class that has already happened, plus a partly filled
     * fortnight ahead.
     *
     * @param  list<Customer>  $customers
     */
    private function seedAttendance(array $customers, DemoDataFactory $data): void
    {
        $busyFrom = $data->today()->subDays(self::BUSY_WINDOW_DAYS);

        foreach ($this->classOccurrences() as $event) {
            if (isset($this->claimedEvents[$event->getKey()])) {
                // A waitlist scenario already staged this one down to the seat.
                continue;
            }

            // A studio that filled up over the last few months, rather than one
            // that has been equally busy since the beginning of time. Two
            // reasons, and the second is the honest one:
            //
            // 1. It is a better story. "The classes have been filling up" is
            //    what a growing gym looks like, and the dashboard's default
            //    thirty-day window — the first thing anyone opens — sits
            //    squarely in the busy part.
            // 2. It is what makes the seed affordable. Six months of full
            //    classes is ~3,400 sign-ups, every one of them created AND
            //    settled through the real actions (docs/20 §3.3); the nightly
            //    reset and the test suite both pay for each one.
            $band = $event->starts_at->gte($busyFrom)
                ? [40, 85]   // recent: half full to nearly sold out
                : [15, 35];  // the early months: a handful of regulars

            $seats = max(1, (int) round($event->capacity * $data->between(...$band) / 100));

            $this->fill($event, $seats, $customers, $data);
        }
    }

    /**
     * Put `$seats` people into an event, and settle the past ones.
     *
     * @param  list<Customer>  $customers
     * @return list<Booking>
     */
    private function fill(Event $event, int $seats, array $customers, DemoDataFactory $data, int $partySize = 1, bool $settle = true): array
    {
        $create = app(CreateBooking::class);
        $changeStatus = app(ChangeBookingStatus::class);
        $service = $event->service;
        $made = [];

        // ⚠️ Draw WITHOUT replacement rather than skipping duplicates. One place
        // per person is right — a second sign-up is refused on the public page —
        // but skipping a repeat draw silently produced fewer sign-ups than asked
        // for, so a class told to sell out never actually reached capacity and
        // every waitlist in the persona came out empty.
        $pool = $customers;

        for ($i = 0; $i < $seats && $pool !== []; $i++) {
            $index = $data->between(0, count($pool) - 1);
            $customer = $pool[$index];
            array_splice($pool, $index, 1);

            $bookedAt = $event->starts_at->copy()->subDays($data->between(1, 10))->setTime(21, 5);

            $booking = $data->asOf($bookedAt, fn (): Booking => $create($service, [
                'customer_id' => $customer->getKey(),
                'event_id' => $event->getKey(),
                'party_size' => $partySize,
                'source' => BookingSource::Online->value,
            ], null, null, $data->notifiable($bookedAt)));

            $made[] = $booking;

            if (! $settle || $event->ends_at->isFuture()) {
                continue;
            }

            // Attended, or did not turn up — the two things that happen to a
            // class booking once the class is over.
            $data->asOf(
                $event->ends_at,
                fn () => $changeStatus($booking, $data->between(1, 12) === 1 ? BookingStatus::NoShow : BookingStatus::Completed),
            );
        }

        return $made;
    }

    /**
     * The full classes and the queues behind them (docs/20 §2.3, docs/04 §3).
     *
     * This is the persona's best moment in a sales call, and it needs every
     * waitlist state on screen at once — which means driving them the way the
     * product drives them rather than writing the rows:
     *
     * - `waiting`   — joined a class that is genuinely at capacity;
     * - `offered`   — somebody cancelled and the seat was handed to the next in
     *                 the queue, with the offer window still open;
     * - `converted` — the person who was offered a seat took it;
     * - `expired`   — the window lapsed and the offer rolled on.
     *
     * ⚠️ The offer window is the reason the cancellation that creates it happens
     * a couple of hours ago rather than last week. An offer only stands for the
     * tenant's `waitlist_offer_hours` (24 by default), and `offered_until` in
     * the past is not an offer anybody can accept — the nightly expiry sweep
     * would clear it, and the demo would lose the state overnight (SLO-25).
     *
     * @param  list<Customer>  $customers
     */
    private function seedWaitlists(array $customers, DemoDataFactory $data): void
    {
        $join = app(JoinWaitlist::class);
        $cancel = app(CancelBooking::class);
        $waitlist = app(WaitlistService::class);

        $upcoming = $this->classOccurrences()
            ->filter(fn (Event $e): bool => $e->starts_at->isFuture())
            ->values();

        // --- two future classes, sold out, with people waiting --------------
        foreach ([0, 1] as $nth) {
            $event = $upcoming->get($nth);

            if ($event === null) {
                continue;
            }

            $this->sellOut($event, $customers, $data);
            $this->queue($join, $event, $customers, $data, $data->between(2, 4));
        }

        // --- a live offer ----------------------------------------------------
        $offerEvent = $upcoming->get(2);

        if ($offerEvent !== null) {
            $this->sellOut($offerEvent, $customers, $data);
            $this->queue($join, $offerEvent, $customers, $data, 3);

            // A seat frees a couple of hours ago; ChangeBookingStatus hands it
            // to the head of the queue, so the offer window is still open now.
            $seat = Booking::query()
                ->where('event_id', $offerEvent->getKey())
                ->whereIn('status', BookingStatus::occupyingValues())
                ->first();

            if ($seat !== null) {
                $data->asOf(Carbon::now()->subHours(2), fn () => $cancel($seat, null, 'Közbejött, sajnos nem tudok menni.'));
            }
        }

        // --- one that was taken, and one that lapsed ------------------------
        $past = $this->classOccurrences()
            ->filter(fn (Event $e): bool => $e->starts_at->isPast() && $e->starts_at->gt($data->today()->subDays(30)))
            ->values();

        $this->seedConvertedOffer($past->get(0), $join, $cancel, $customers, $data);
        $this->seedExpiredOffer($past->get(1), $join, $cancel, $waitlist, $customers, $data);

        // --- somebody bringing a friend --------------------------------------
        $pair = $upcoming->get(3);

        if ($pair !== null) {
            // party_size 2 on a class with room for it: the atomic capacity
            // claim takes two seats at once (docs/04 §3). Not claimed — the
            // bulk pass should still fill the rest of this class normally.
            $this->fill($pair, 1, $customers, $data, partySize: 2);
        }
    }

    /**
     * Book a class right up to its capacity, whatever it already holds.
     *
     * @param  list<Customer>  $customers
     */
    private function sellOut(Event $event, array $customers, DemoDataFactory $data, bool $settle = true): void
    {
        $this->claimedEvents[$event->getKey()] = true;

        $event->refresh();
        $remaining = $event->capacity - $event->booked_count;

        if ($remaining > 0) {
            // Seats come from the front of the roster, queue places from the
            // back (see self::queue) — so nobody is ever asked to wait for a
            // class they are already booked on, which JoinWaitlist refuses.
            $this->fill($event, $remaining, $this->seatPool($customers), $data, settle: $settle);
        }

        $event->refresh();
    }

    /**
     * The part of the roster that takes seats on a sold-out class.
     *
     * @param  list<Customer>  $customers
     * @return list<Customer>
     */
    private function seatPool(array $customers): array
    {
        return array_slice($customers, 0, max(1, count($customers) - self::QUEUE_POOL));
    }

    /**
     * Close out a class that has already happened: everyone still holding a
     * seat either attended or did not turn up.
     *
     * Used by the two past waitlist scenarios, which have to leave their
     * sign-ups open long enough to cancel one of them.
     */
    private function settleEvent(Event $event, DemoDataFactory $data): void
    {
        if ($event->ends_at->isFuture()) {
            return;
        }

        $changeStatus = app(ChangeBookingStatus::class);

        $open = Booking::query()
            ->where('event_id', $event->getKey())
            ->whereIn('status', BookingStatus::occupyingValues())
            ->get();

        foreach ($open as $booking) {
            $data->asOf(
                $event->ends_at,
                fn () => $changeStatus($booking, $data->between(1, 12) === 1 ? BookingStatus::NoShow : BookingStatus::Completed),
            );
        }
    }

    /**
     * Put `$count` people in the queue for a class that is already full.
     *
     * @param  list<Customer>  $customers
     * @return list<int> the customer ids queued, in order
     */
    private function queue(JoinWaitlist $join, Event $event, array $customers, DemoDataFactory $data, int $count): array
    {
        $event->refresh();

        if ($event->booked_count < $event->capacity) {
            // JoinWaitlist refuses a class that is not full, and rightly so.
            return [];
        }

        $queued = [];
        // The tail of the roster, which self::seatPool() deliberately never
        // hands a seat to.
        $waiters = array_slice($customers, -self::QUEUE_POOL);

        for ($i = 0; $i < $count && $i < count($waiters); $i++) {
            $customer = $waiters[$i];

            if (Booking::query()
                ->where('event_id', $event->getKey())
                ->where('customer_id', $customer->getKey())
                ->whereIn('status', BookingStatus::occupyingValues())
                ->exists()) {
                continue;
            }

            $joinedAt = $event->starts_at->copy()->subDays($data->between(1, 4))->setTime(8, 30);
            $entry = $data->asOf($joinedAt, fn () => $join($event, $customer->getKey()));
            $queued[] = (int) $entry->customer_id;
        }

        return $queued;
    }

    /**
     * A queue that ended the way everyone hopes: a seat freed, the next person
     * was offered it, and they took it.
     *
     * @param  list<Customer>  $customers
     */
    private function seedConvertedOffer(?Event $event, JoinWaitlist $join, CancelBooking $cancel, array $customers, DemoDataFactory $data): void
    {
        if ($event === null) {
            return;
        }

        $this->sellOut($event, $customers, $data, settle: false);
        $queued = $this->queue($join, $event, $customers, $data, 2);

        if ($queued === []) {
            $this->settleEvent($event, $data);

            return;
        }

        $seat = Booking::query()
            ->where('event_id', $event->getKey())
            ->whereIn('status', BookingStatus::occupyingValues())
            ->first();

        if ($seat === null) {
            return;
        }

        $freedAt = $event->starts_at->copy()->subDays(2)->setTime(12, 0);
        $data->asOf($freedAt, fn () => $cancel($seat, null, 'Lemondta, a hely a várólistára ment.'));

        // The person at the head of the queue takes the seat. CreateBooking's
        // event path closes their waitlist entry as `converted` on the way
        // through (docs/04 §3) — the seed does not touch the status itself.
        $data->asOf($freedAt->copy()->addHours(3), fn () => app(CreateBooking::class)($event->service, [
            'customer_id' => $queued[0],
            'event_id' => $event->getKey(),
            'party_size' => 1,
            'source' => BookingSource::Online->value,
        ], null, null, false));

        $this->settleEvent($event, $data);
    }

    /**
     * And a queue that did not: the offer sat unanswered until its window
     * closed, and rolled on to the next person.
     *
     * @param  list<Customer>  $customers
     */
    private function seedExpiredOffer(?Event $event, JoinWaitlist $join, CancelBooking $cancel, WaitlistService $waitlist, array $customers, DemoDataFactory $data): void
    {
        if ($event === null) {
            return;
        }

        $this->sellOut($event, $customers, $data, settle: false);
        $queued = $this->queue($join, $event, $customers, $data, 2);

        if (count($queued) < 2) {
            $this->settleEvent($event, $data);

            return;
        }

        $seat = Booking::query()
            ->where('event_id', $event->getKey())
            ->whereIn('status', BookingStatus::occupyingValues())
            ->first();

        if ($seat === null) {
            return;
        }

        $freedAt = $event->starts_at->copy()->subDays(4)->setTime(9, 0);
        $data->asOf($freedAt, fn () => $cancel($seat, null, 'Lemondta, a hely a várólistára ment.'));

        // Nobody answered. The sweep that runs hourly in production closes the
        // window and chains to the next waiter — run here on the clock of the
        // day it would have fired, so the history reads correctly.
        $data->asOf(
            $freedAt->copy()->addHours(30),
            fn () => $waitlist->expireDueOffers(Carbon::now()),
        );

        $this->settleEvent($event, $data);
    }

    /**
     * Every occurrence of the timetable, oldest first.
     *
     * @return Collection<int, Event>
     */
    private function classOccurrences()
    {
        // Eager: the seed reads `$event->service` for every occurrence, and
        // lazy loading is disabled in this application (Model::preventLazyLoading).
        return Event::query()->with('service')->orderBy('starts_at')->get();
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
