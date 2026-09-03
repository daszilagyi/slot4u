<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Booking\ApproveBooking;
use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Booking\CreateBooking;
use App\Actions\Customer\CreateCustomer;
use App\Actions\Quote\AcceptQuoteRequest;
use App\Actions\Quote\ChangeQuoteRequestStatus;
use App\Actions\Quote\CreateQuoteRequest;
use App\Actions\Quote\PostQuoteMessage;
use App\Actions\Quote\RejectQuoteRequest;
use App\Actions\Quote\SubmitQuote;
use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\QuoteRequestStatus;
use App\Models\Customer;
use App\Models\Location;
use App\Models\QuoteRequest;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * „Fényliget Rendezvényház" — the quote-driven persona (SLO-186, docs/20 §2.4).
 *
 * The proof that slot4u is not merely an appointment book. Nothing here is
 * booked off a calendar: a company asks, the venue works the enquiry up into a
 * price, and only an accepted quote becomes an engagement. It is the one
 * persona that demonstrates `quote_request` (docs/04 §6) end to end — every
 * status in the pipeline, with a real conversation on the enquiries that have
 * one — and the one that shows `resource_rental` combined with approval.
 *
 * It is also the opposite shape to the salon in the statistics: few bookings,
 * large amounts. A demo that only ever showed high-frequency low-ticket trade
 * would quietly tell every event venue, clinic and studio that the product is
 * not for them.
 *
 * ## The three services never contend for the same resource
 *
 * Deliberate, and it is what keeps the seed collision-free without a planner:
 * a site visit books the organiser and no room, a meeting-room rental books the
 * room and no staff (nobody staffs a meeting room), and an accepted quote
 * generates a booking with neither — quote bookings carry no time at all
 * (docs/04 §6), which is also why they land in the reports by `created_at`.
 */
final class VenueDemoPersona extends DemoPersona
{
    /** docs/20 §2.4: ~90 days of few-but-large engagements. */
    private const HISTORY_DAYS = 91;

    private const FUTURE_DAYS = 35;

    /** Companies on the books. Fewer than a salon's — this is a B2B roster. */
    private const CLIENT_COUNT = 30;

    /** The meeting room is let by the hour, on the hour. */
    private const MEETING_MINUTES = 60;

    private const SITE_VISIT_MINUTES = 45;

    /**
     * How long after acceptance a signed event is treated as having happened.
     *
     * A quote booking has no `starts_at` at all (docs/04 §6) — the event date
     * lives in the enquiry parameters as free text — so the acceptance is the
     * only instant the seed can age it from. Roughly a month is what the lead
     * time on a venue booking actually looks like.
     */
    private const SETTLED_AFTER_DAYS = 30;

    /**
     * What an enquiry asks for (docs/20 §2.4). These strings are the labels the
     * public form renders and the keys the answers are stored under
     * (`Service::quoteFields()` → `QuoteRequest::$parameters`), so they are the
     * schema of the whole mode for this tenant.
     *
     * @var list<string>
     */
    private const QUOTE_FIELDS = [
        'Tervezett dátum',
        'Várható létszám',
        'Esemény típusa',
        'Catering igény',
    ];

    /** @var list<string> */
    private const EVENT_TYPES = [
        'Céges évzáró', 'Konferencia', 'Termékbemutató', 'Esküvő',
        'Csapatépítő nap', 'Sajtótájékoztató', 'Díjátadó gála',
    ];

    /** @var list<string> */
    private const CATERING = [
        'Állófogadás, 3 fogás', 'Ültetett vacsora', 'Kávészünet és szendvics',
        'Nem kérünk cateringet, saját szolgáltatóval jövünk', 'Svédasztal',
    ];

    /** @var list<string> */
    private const COMPANIES = [
        'Duna Logisztika Kft.', 'Aurora Pharma Zrt.', 'Bakony Bau Kft.',
        'Széchenyi Consulting Kft.', 'NovaTech Hungary Kft.', 'Tisza Energia Zrt.',
        'Kékszalag Utazási Iroda', 'Pannon Agrár Zrt.', 'Vertigo Media Kft.',
        'Balaton Invest Kft.',
    ];

    public function slug(): string
    {
        return 'demo-rendezvenyhaz';
    }

    public function name(): string
    {
        return 'Fényliget Rendezvényház';
    }

    public function adminName(): string
    {
        return 'Halász Villő';
    }

    /**
     * @return array<string, mixed>
     */
    public function profileSettings(): array
    {
        return [
            'description' => 'Rendezvényhelyszín a Balaton északi partján: 120 fős nagyterem, '
                .'panorámás terasz és tárgyaló. Céges rendezvények, konferenciák és esküvők '
                .'helyszíne — kérj ajánlatot, és három munkanapon belül válaszolunk.',
            'email' => 'rendezveny@'.$this->slug().'.demo.slot4u.hu',
            'phone' => '+36 87 342 900',
            'address_line' => 'Zákonyi Ferenc utca 4.',
            'address_city' => 'Balatonfüred',
            'address_postal' => '8230',
            'opening_hours' => 'Iroda: H–P 9:00–17:00 · Rendezvények: naponta 8:00–23:00',
            'social' => ['website' => 'https://fenyliget.demo.slot4u.hu'],
        ];
    }

    protected function build(Tenant $tenant, User $admin, DemoDataFactory $data): void
    {
        app(TenantManager::class)->set($tenant);

        try {
            $this->buildFor($admin, $data);
        } finally {
            app(TenantManager::class)->forget();
        }
    }

    private function buildFor(User $admin, DemoDataFactory $data): void
    {
        $location = Location::query()->create([
            'name' => 'Fényliget Rendezvényház',
            'address' => [
                'line' => 'Zákonyi Ferenc utca 4.',
                'city' => 'Balatonfüred',
                'postal_code' => '8230',
            ],
            'phone' => '+36 87 342 900',
            'active' => true,
        ]);

        $hall = $this->room($location, 'Nagyterem', 120);
        $terrace = $this->room($location, 'Panoráma terasz', 60);
        $meetingRoom = $this->room($location, 'Tárgyaló', 20);

        // The organiser is the admin (docs/20 §2.4: two staff, one admin) — she
        // is who a site visit is booked with, so her account and her calendar
        // have to be the same person.
        $organiser = new Staff([
            'name' => $this->adminName(),
            'title' => 'rendezvényszervező',
            'color' => '#2f7f8f',
            'active' => true,
        ]);
        $organiser->user_id = $admin->getKey();
        $organiser->save();
        $organiser->locations()->sync([$location->getKey()]);

        $caretaker = Staff::query()->create([
            'name' => 'Németh Zsolt',
            'title' => 'gondnok',
            'color' => '#8a7f5c',
            'active' => true,
        ]);
        $caretaker->locations()->sync([$location->getKey()]);

        // The office keeps office hours; the venue itself is open long days
        // because that is when events actually run.
        $this->schedule($location, $organiser, range(1, 5), '09:00', '17:00');
        $this->schedule($location, $caretaker, range(1, 6), '08:00', '20:00');
        $this->schedule($location, $meetingRoom, range(1, 5), '08:00', '18:00');
        $this->schedule($location, $hall, range(1, 7), '08:00', '23:00');
        $this->schedule($location, $terrace, range(1, 7), '08:00', '23:00');

        [$enquiry, $meeting, $visit] = $this->seedServices($organiser, $meetingRoom);

        $clients = $this->seedClients($data);

        $this->seedQuotePipeline($admin, $enquiry, $clients, $data);
        $this->seedMeetingRentals($admin, $meeting, $meetingRoom, $clients, $data);
        $this->seedSiteVisits($admin, $visit, $organiser, $clients, $data);
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
     * @param  list<int>  $weekdays
     */
    private function schedule(Location $location, Staff|Room $resource, array $weekdays, string $open, string $close): void
    {
        foreach ($weekdays as $weekday) {
            $schedule = new Schedule([
                'location_id' => $location->getKey(),
                'day_of_week' => $weekday,
                'start_time' => $open,
                'end_time' => $close,
            ]);
            // schedulable_* are guarded and NOT NULL — associated before the save.
            $schedule->schedulable()->associate($resource);
            $schedule->save();
        }
    }

    /**
     * @return array{Service, Service, Service} enquiry, meeting room, site visit
     */
    private function seedServices(Staff $organiser, Room $meetingRoom): array
    {
        $events = ServiceCategory::query()->create(['name' => 'Rendezvények', 'sort_order' => 1]);
        $office = ServiceCategory::query()->create(['name' => 'Iroda és tárgyaló', 'sort_order' => 2]);

        // The sixth mode (docs/04 §6). No price: an event this size does not
        // have a list price, which is the entire reason the mode exists.
        $enquiry = Service::query()->create([
            'category_id' => $events->getKey(),
            'name' => 'Rendezvény ajánlatkérés',
            'description' => 'Mondd el, mit tervezel, és három munkanapon belül személyre szabott '
                .'árajánlatot küldünk a termekre, a technikára és a cateringre.',
            'booking_mode' => BookingMode::QuoteRequest,
            'price_minor' => 0,
            'currency' => 'HUF',
            'settings' => ['quote_fields' => self::QUOTE_FIELDS],
            'active' => true,
        ]);

        // Resource rental AND approval — the combination docs/20 §2.4 asks for,
        // and the only place in the demo set where the two meet.
        //
        // Fixed at one hour rather than a free range: `price_minor` is a flat
        // per-booking snapshot (CreateBooking), so a four-hour let priced from a
        // free-duration service would still bill 15 000 Ft. An hourly rate is
        // only truthful here if the booking IS an hour. Duration-scaled pricing
        // is SLO-193's ground.
        $meeting = Service::query()->create([
            'category_id' => $office->getKey(),
            'name' => 'Tárgyalóbérlés (óradíj)',
            'description' => 'A 20 fős tárgyaló bérlése óradíjban, projektorral és flipcharttal. '
                .'A foglalást kollégánk visszaigazolja.',
            'booking_mode' => BookingMode::ResourceRental,
            'duration_minutes' => self::MEETING_MINUTES,
            'price_minor' => 15_000 * 100,
            'currency' => 'HUF',
            'requires_room' => true,
            'requires_approval' => true,
            'active' => true,
        ]);
        $meeting->rooms()->sync([$meetingRoom->getKey()]);

        // Free, and that is the point: the walk-through is what turns an enquiry
        // into a signed event, so charging for it would be charging for your own
        // sales call.
        $visit = Service::query()->create([
            'category_id' => $events->getKey(),
            'name' => 'Helyszínbejárás',
            'description' => 'Kötetlen helyszínbejárás a rendezvényszervezővel, 45 perc, díjmentes.',
            'booking_mode' => BookingMode::DurationBased,
            'duration_minutes' => self::SITE_VISIT_MINUTES,
            'buffer_after_minutes' => 15,
            'price_minor' => 0,
            'currency' => 'HUF',
            'requires_staff' => true,
            'active' => true,
        ]);
        $visit->staff()->sync([$organiser->getKey()]);

        return [$enquiry, $meeting, $visit];
    }

    /**
     * The contact people. An event is booked by a person at a company, so the
     * account is the person and the company travels in the enquiry parameters —
     * which is also what makes the quote screen read like a real one.
     *
     * @return list<Customer>
     */
    private function seedClients(DemoDataFactory $data): array
    {
        $create = app(CreateCustomer::class);
        $clients = [];

        foreach (range(1, self::CLIENT_COUNT) as $index) {
            $name = $data->faker()->name();

            $clients[] = $create([
                'name' => $name,
                'email' => Str::slug($name, '.').'.'.$index.'@'.$this->slug().'.demo.slot4u.hu',
                'phone' => '+3630'.sprintf('%07d', $data->between(1_000_000, 9_999_999)),
            ]);
        }

        return $clients;
    }

    /**
     * The quote pipeline, with every status represented (docs/20 §2.4).
     *
     * Old enquiries are walked to a terminal state — accepted (generating the
     * engagement and its revenue) or rejected. The most recent weeks are left
     * deliberately mid-flight, one per stage, because the pipeline is what the
     * admin screen is FOR: a demo whose enquiry list is entirely closed shows a
     * feature with nothing to do.
     *
     * @param  list<Customer>  $clients
     */
    private function seedQuotePipeline(User $admin, Service $enquiry, array $clients, DemoDataFactory $data): void
    {
        $create = app(CreateQuoteRequest::class);
        $submit = app(SubmitQuote::class);
        $accept = app(AcceptQuoteRequest::class);
        $reject = app(RejectQuoteRequest::class);
        $progress = app(ChangeQuoteRequestStatus::class);
        $message = app(PostQuoteMessage::class);
        $complete = app(ChangeBookingStatus::class);

        // Two to four enquiries a week over the history window (docs/20 §2.4) —
        // except the freshest week, which is fixed at three so there is exactly
        // one enquiry sitting at each open stage. Leaving that to the dice meant
        // a week that happened to draw two produced no `quoted` request at all,
        // and the pipeline demo lost a status.
        for ($week = 13; $week >= 0; $week--) {
            $count = $week === 0 ? 3 : $data->between(2, 4);

            foreach (range(1, $count) as $nth) {
                // ⚠️ At least one whole day back, never "earlier today".
                //
                // An office-hours instant on today's date is in the FUTURE when
                // the clock says 03:00 — which is precisely when the nightly
                // `demo:reset` runs (SLO-191). The enquiry was previously
                // skipped in that case, and since the freshest week supplies one
                // request per open stage, a skip silently cost the demo a whole
                // pipeline status. It failed roughly one run in three, on a
                // schedule nobody watches.
                $daysBack = ($week * 7) + $data->between(1, 6);
                $askedAt = $data->at(-$daysBack, sprintf('%02d:%02d', $data->between(8, 17), $data->oneOf([5, 20, 40])));

                $client = $clients[$data->between(0, count($clients) - 1)];
                $request = $data->asOf($askedAt, fn (): QuoteRequest => $create($enquiry, [
                    'customer_id' => $client->getKey(),
                    'parameters' => $this->parameters($data),
                ]));

                // The freshest week is the live pipeline: leave one enquiry at
                // each stage rather than closing everything.
                if ($week === 0) {
                    $this->leaveMidFlight($request, $nth, $admin, $askedAt, $submit, $progress, $message, $data);

                    continue;
                }

                // Everything older is decided. Quoted within a few days of the
                // ask — the venue promises three working days on its own page.
                $quotedAt = $askedAt->copy()->addDays($data->between(1, 4))->setTime(11, 15);
                $price = $data->between(35, 240) * 10_000;

                $data->asOf($quotedAt, fn () => $submit(
                    $request,
                    $price * 100,
                    'HUF',
                    $quotedAt->copy()->addDays(21),
                    'Ajánlat kiküldve, 21 napig érvényes.',
                    $admin,
                ));

                $decidedAt = $quotedAt->copy()->addDays($data->between(2, 10))->setTime(14, 40);

                // Most quotes are won; a real pipeline loses some.
                if ($data->between(1, 4) === 1) {
                    $data->asOf($decidedAt, fn () => $reject($request, $admin));

                    continue;
                }

                $data->asOf($decidedAt, fn () => $accept($request, $admin));

                // An engagement whose event has since happened is completed. A
                // quote booking carries no date of its own (docs/04 §6), so the
                // acceptance is what ages it — and a venue with thirty signed
                // events that never conclude reads as a system nobody closes out.
                if ($decidedAt->lt($data->today()->subDays(self::SETTLED_AFTER_DAYS))) {
                    $heldAt = $decidedAt->copy()->addDays(self::SETTLED_AFTER_DAYS);
                    $booking = $request->fresh()?->booking;

                    if ($booking !== null) {
                        $data->asOf($heldAt, fn () => $complete($booking, BookingStatus::Completed, $admin));
                    }
                }

                // A conversation on the enquiries that had one (docs/20 §2.4).
                if ($data->between(1, 3) === 1) {
                    $this->converse($message, $request, $client, $admin, $quotedAt, $data);
                }
            }
        }
    }

    /**
     * Leave one enquiry at each open stage, so every status in the pipeline is
     * on screen at once.
     */
    private function leaveMidFlight(
        QuoteRequest $request,
        int $nth,
        User $admin,
        Carbon $askedAt,
        SubmitQuote $submit,
        ChangeQuoteRequestStatus $progress,
        PostQuoteMessage $message,
        DemoDataFactory $data,
    ): void {
        match ($nth) {
            // 1 → left `new`: an enquiry nobody has touched yet.
            1 => null,
            2 => $data->asOf(
                $askedAt->copy()->addDay()->setTime(9, 30),
                fn () => $progress($request, QuoteRequestStatus::InProgress, $admin),
            ),
            // Quoted and waiting on the customer — with the offer still valid,
            // because an expired quote on the demo's front page is a dead end.
            default => $data->asOf($askedAt->copy()->addDay()->setTime(16, 5), fn () => $submit(
                $request,
                $data->between(60, 180) * 10_000 * 100,
                'HUF',
                $data->today()->addDays(18),
                'Ajánlat kiküldve, visszajelzést várunk.',
                $admin,
            )),
        };

        if ($nth === 2) {
            // The in-progress one is where a question is still open — the
            // clearest thing to show on the messaging tab.
            $data->asOf($askedAt->copy()->addDay()->setTime(9, 35), fn () => $message(
                $request,
                'Köszönjük az érdeklődést! Pontosítanád, hogy a létszámban benne vannak-e a kísérők? '
                .'A terasz 60 főig ültethető, a nagyterem 120-ig.',
                $admin,
            ));
        }
    }

    private function converse(PostQuoteMessage $message, QuoteRequest $request, Customer $client, User $admin, Carbon $quotedAt, DemoDataFactory $data): void
    {
        $data->asOf($quotedAt->copy()->addHours(3), fn () => $message(
            $request,
            'Köszönjük az ajánlatot! A technikát ti biztosítjátok, vagy külön kell hangosítást hoznunk?',
            $client,
        ));

        $data->asOf($quotedAt->copy()->addHours(5), fn () => $message(
            $request,
            'A nagyteremben a hangosítás és a projektor az árban van, külön hangtechnikust nem kell hoznotok.',
            $admin,
        ));
    }

    /**
     * @return array<string, string>
     */
    private function parameters(DemoDataFactory $data): array
    {
        return array_combine(self::QUOTE_FIELDS, [
            $data->today()->addDays($data->between(20, 160))->format('Y-m-d'),
            (string) ($data->between(3, 24) * 5).' fő',
            $data->oneOf(self::EVENT_TYPES).' — '.$data->oneOf(self::COMPANIES),
            $data->oneOf(self::CATERING),
        ]);
    }

    /**
     * Meeting-room lets: an approval flow on a resource rather than a person.
     *
     * One is left `requested` on purpose and booked yesterday, because a
     * `requested` booking only soft-holds its slot for the tenant's approval
     * window (48h by default). Seeded further back it would be a pending
     * decision that the expiry sweep cancels the first time the scheduler runs
     * on staging (SLO-26), and the demo would lose it overnight.
     *
     * @param  list<Customer>  $clients
     */
    private function seedMeetingRentals(User $admin, Service $meeting, Room $room, array $clients, DemoDataFactory $data): void
    {
        $create = app(CreateBooking::class);
        $approve = app(ApproveBooking::class);
        $changeStatus = app(ChangeBookingStatus::class);

        $let = function (int $dayOffset, int $hour, ?Carbon $bookedAt = null) use ($create, $meeting, $room, $clients, $data) {
            $startsAt = $data->at($dayOffset, sprintf('%02d:00', $hour));
            $bookedAt ??= $startsAt->copy()->subDays($data->between(1, 6))->setTime(10, 10);

            return [$data->asOf($bookedAt, fn () => $create($meeting, [
                'customer_id' => $clients[$data->between(0, count($clients) - 1)]->getKey(),
                // Nobody staffs a meeting room — the ROOM is the resource here
                // (docs/04 §4), which is also why these never contend with a
                // site visit for the organiser's time.
                'room_id' => $room->getKey(),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes(self::MEETING_MINUTES),
                'source' => BookingSource::Online->value,
            ], null, null, $data->notifiable($bookedAt))), $startsAt, $bookedAt];
        };

        // Roughly two lets a week, on the hour so they cannot overlap.
        for ($daysAgo = self::HISTORY_DAYS; $daysAgo >= 2; $daysAgo--) {
            $day = $data->today()->subDays($daysAgo);

            if ($day->isWeekend() || $data->between(1, 3) !== 1) {
                continue;
            }

            [$booking, $startsAt, $bookedAt] = $let(-$daysAgo, $data->between(8, 16));

            $data->asOf($bookedAt->copy()->addHours(2), fn () => $approve($booking, $admin));
            $data->asOf(
                $startsAt->copy()->addMinutes(self::MEETING_MINUTES),
                fn () => $changeStatus($booking, BookingStatus::Completed, $admin),
            );
        }

        // Ahead: a few approved lets, and exactly one still awaiting a decision.
        for ($daysAhead = 2; $daysAhead <= self::FUTURE_DAYS; $daysAhead++) {
            $day = $data->today()->addDays($daysAhead);

            if ($day->isWeekend() || $data->between(1, 4) !== 1) {
                continue;
            }

            [$booking, , $bookedAt] = $let($daysAhead, $data->between(8, 16));
            $data->asOf($bookedAt->copy()->addHours(3), fn () => $approve($booking, $admin));
        }

        // ⚠️ Booked YESTERDAY, explicitly — not "a few days before a let that is
        // itself days away". A `requested` booking holds its slot only for the
        // tenant's approval window (48h), so a randomly drawn lead time can put
        // `hold_expires_at` in the past before the seed has even finished: the
        // request is then one sweep away from cancelling itself, and the demo
        // loses the pending decision it exists to show (SLO-26).
        $pendingDay = $this->nextWeekday(4, $data);
        [$pending] = $let($pendingDay, 17, $data->at(-1, '09:20'));
        // Left `requested`: this is the soft hold the AC asks to see.
        unset($pending);
    }

    /**
     * Free walk-throughs with the organiser — the step between an enquiry and a
     * signed event.
     *
     * @param  list<Customer>  $clients
     */
    private function seedSiteVisits(User $admin, Service $visit, Staff $organiser, array $clients, DemoDataFactory $data): void
    {
        $create = app(CreateBooking::class);
        $changeStatus = app(ChangeBookingStatus::class);

        $book = function (int $dayOffset, int $hour) use ($create, $visit, $organiser, $clients, $data) {
            $startsAt = $data->at($dayOffset, sprintf('%02d:00', $hour));
            $bookedAt = $startsAt->copy()->subDays($data->between(2, 8))->setTime(15, 45);

            return [$data->asOf($bookedAt, fn () => $create($visit, [
                'customer_id' => $clients[$data->between(0, count($clients) - 1)]->getKey(),
                'staff_id' => $organiser->getKey(),
                // No room: the walk-through is of the whole house.
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes(self::SITE_VISIT_MINUTES),
                'source' => BookingSource::Online->value,
            ], null, null, $data->notifiable($bookedAt))), $startsAt];
        };

        for ($daysAgo = self::HISTORY_DAYS; $daysAgo >= 1; $daysAgo--) {
            $day = $data->today()->subDays($daysAgo);

            if ($day->isWeekend() || $data->between(1, 5) !== 1) {
                continue;
            }

            [$booking, $startsAt] = $book(-$daysAgo, $data->between(9, 15));

            $data->asOf(
                $startsAt->copy()->addMinutes(self::SITE_VISIT_MINUTES),
                fn () => $changeStatus($booking, BookingStatus::Completed, $admin),
            );
        }

        for ($daysAhead = 1; $daysAhead <= 21; $daysAhead++) {
            $day = $data->today()->addDays($daysAhead);

            if ($day->isWeekend() || $data->between(1, 4) !== 1) {
                continue;
            }

            $book($daysAhead, $data->between(9, 15));
        }
    }

    /** The first weekday at or after `$offset`, read off the tenant's own clock. */
    private function nextWeekday(int $offset, DemoDataFactory $data): int
    {
        while ($data->today()->addDays($offset)->isWeekend()) {
            $offset++;
        }

        return $offset;
    }
}
