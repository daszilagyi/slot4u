<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Booking\ApproveBooking;
use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Booking\CompleteBooking;
use App\Actions\Booking\CreateBooking;
use App\Actions\Booking\RejectBooking;
use App\Actions\Customer\CreateCustomer;
use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\ScheduleExceptionType;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * „Lélekút Pszichológiai Rendelő" — the solo-practitioner persona (SLO-184,
 * docs/20 §2.1).
 *
 * The smallest viable business in the demo set, and the first one built on the
 * SLO-183 framework: one practitioner who is also the tenant admin, one room,
 * four services, a quarter of a year of history behind her and a fortnight of
 * bookable calendar ahead. It is what an individual therapist, coach or
 * dietitian is meant to recognise as their own practice.
 *
 * What it demonstrates, from the coverage matrix (docs/20 §2):
 * `duration_based` as the core, `no_time_slot` for a document order, and
 * `requires_approval` — a first consultation is screened before it is accepted,
 * so the demo has a live pending request and a rejected one to show.
 *
 * ## ⚠️ No health-related content, anywhere
 *
 * Not even fictionally, and not in a note field (docs/20 §2.1, §5.5). Anything
 * resembling a diagnosis, a symptom or a reason for attending is a special
 * category of personal data by association, and a sales demo that showed one —
 * however invented — would be advertising exactly the wrong instinct to the
 * sector most sensitive to it. The notes therefore come from
 * {@see self::NEUTRAL_NOTES}, a closed list of scheduling logistics, and a test
 * holds a keyword blocklist against every note the seed writes.
 */
final class PsychologistDemoPersona extends DemoPersona
{
    /** ~13 whole weeks of history (docs/20 §2.1: "~90 nap"). */
    private const HISTORY_DAYS = 91;

    /** How far the bookable calendar reaches ahead. */
    private const FUTURE_DAYS = 14;

    private const CUSTOMER_COUNT = 10;

    /** Every session is a 50-minute hour, which is what makes the hourly grid safe. */
    private const SESSION_MINUTES = 50;

    private const BUFFER_MINUTES = 10;

    /**
     * The consulting hours, as ISO weekday => the hours a session may start on
     * (docs/20 §2.1: Mon–Thu 9–17, Fri 9–13).
     *
     * Whole hours only, and that is load-bearing rather than tidy: a 50-minute
     * session plus a 10-minute buffer fills the hour exactly, so two bookings on
     * this grid can never collide — the seed does not have to reason about
     * overlap, and {@see CreateBooking}'s conflict check never has to reject one.
     *
     * @var array<int, list<int>>
     */
    private const HOURS = [
        1 => [9, 10, 11, 12, 13, 14, 15, 16],
        2 => [9, 10, 11, 12, 13, 14, 15, 16],
        3 => [9, 10, 11, 12, 13, 14, 15, 16],
        4 => [9, 10, 11, 12, 13, 14, 15, 16],
        5 => [9, 10, 11, 12],
    ];

    /**
     * The only things a booking note may say here. Scheduling logistics and
     * nothing else — see the class docblock.
     *
     * @var list<string>
     */
    private const NEUTRAL_NOTES = [
        'Kapucsengő: 12',
        'Első alkalom',
        'Átütemezést kért',
        'Számlát kér a nevére',
        'Telefonon egyeztetve',
        'A szomszéd utcában parkol',
    ];

    /**
     * Hours already spoken for, as "dayOffset:hour".
     *
     * The showcase bookings (the pending and the rejected request) are placed
     * first, on days the bulk generator also fills; without a shared occupancy
     * map the generator could pick the same hour and the create would fail on a
     * slot conflict — a seed that breaks on a date-dependent coincidence is the
     * worst kind, because it passes for weeks first.
     *
     * @var array<string, true>
     */
    private array $taken = [];

    public function slug(): string
    {
        return 'demo-pszichologus';
    }

    public function name(): string
    {
        return 'Lélekút Pszichológiai Rendelő';
    }

    /** The practice is one person; the admin login is hers (docs/20 §2.1). */
    public function adminName(): string
    {
        return 'dr. Vas Emese';
    }

    /**
     * @return array<string, mixed>
     */
    public function profileSettings(): array
    {
        return [
            'description' => 'Egyszemélyes pszichológiai rendelő Budapest belvárosában. '
                .'Előzetes egyeztetés alapján, diszkrét környezetben. '
                .'Az első konzultációt egyeztetés után visszaigazolom.',
            'email' => 'rendelo@'.$this->slug().'.demo.slot4u.hu',
            'phone' => '+36 1 445 2210',
            'address_line' => 'Kossuth Lajos utca 14. II/3.',
            'address_city' => 'Budapest',
            'address_postal' => '1053',
            'opening_hours' => 'H–Cs 9:00–17:00 · P 9:00–13:00',
            'social' => ['website' => 'https://lelekut.demo.slot4u.hu'],
            // A day's notice to cancel online — long enough to be a real rule a
            // visitor can run into, short enough not to lock the demo calendar.
            'cancellation_deadline_hours' => 24,
        ];
    }

    protected function build(Tenant $tenant, User $admin, DemoDataFactory $data): void
    {
        // The models below are tenant-scoped; bind the tenant so BelongsToTenant
        // stamps and scopes them without every call passing an id.
        app(TenantManager::class)->set($tenant);

        try {
            $this->buildFor($admin, $data);
        } finally {
            app(TenantManager::class)->forget();
        }
    }

    private function buildFor(User $admin, DemoDataFactory $data): void
    {
        $this->taken = [];

        $location = Location::query()->create([
            'name' => 'Belvárosi rendelő',
            // `address` is an array cast — a flat string survives the save and
            // then silently fails the public page's is_array() check.
            'address' => [
                'line' => 'Kossuth Lajos utca 14. II/3.',
                'city' => 'Budapest',
                'postal_code' => '1053',
            ],
            'phone' => '+36 1 445 2210',
            'active' => true,
        ]);

        $room = Room::query()->create([
            'location_id' => $location->getKey(),
            'name' => 'Rendelő',
            'capacity' => 1,
            'active' => true,
        ]);

        $staff = new Staff([
            'name' => $this->adminName(),
            'title' => 'klinikai szakpszichológus',
            'color' => '#7c6cf0',
            'active' => true,
        ]);
        // The practitioner and the tenant admin are the same person (docs/20
        // §2.1). `user_id` is guarded — the invite flow owns it in production —
        // so it is set on the model rather than mass-assigned.
        $staff->user_id = $admin->getKey();
        $staff->save();
        $staff->locations()->sync([$location->getKey()]);

        $this->seedSchedules($location, $staff, $room);
        $dayOff = $this->seedDayOff($staff, $data);

        [$sessions, $document] = $this->seedServices($staff, $room);
        $customers = $this->seedCustomers($data);

        // Order matters: the showcase bookings claim their hours first, and the
        // bulk generators then work around them (see {@see self::$taken}).
        $this->seedApprovalShowcase($admin, $sessions['first'], $staff, $room, $customers, $data);
        $this->seedHistory($admin, $sessions, $staff, $room, $customers, $data);
        $this->seedUpcoming($sessions, $staff, $room, $customers, $dayOff, $data);
        $this->seedDocumentOrders($admin, $document, $customers, $data);
    }

    /**
     * Mon–Thu 09:00–17:00, Fri 09:00–13:00 — for the practitioner AND the room.
     *
     * The room gets the same bands rather than none, because the public calendar
     * lets a visitor filter by room: a room with no schedule has no free windows
     * at all, so that filter would answer "nothing available" on a demo whose
     * whole job is to look available.
     */
    private function seedSchedules(Location $location, Staff $staff, Room $room): void
    {
        foreach (self::HOURS as $weekday => $hours) {
            $closes = max($hours) + 1;

            foreach ([$staff, $room] as $resource) {
                $schedule = new Schedule([
                    'location_id' => $location->getKey(),
                    'day_of_week' => $weekday,
                    'start_time' => '09:00',
                    'end_time' => sprintf('%02d:00', $closes),
                ]);
                // schedulable_* are guarded (the morph is associated, never
                // mass-assigned) and NOT NULL, so they are set before the save.
                $schedule->schedulable()->associate($resource);
                $schedule->save();
            }
        }
    }

    /**
     * One day off ahead, so the calendar shows an exception overriding the
     * weekly pattern (docs/20 §2.1) rather than an unbroken grid.
     *
     * On the practitioner, not the room: she is the resource a session is booked
     * against, so hers is the absence a visitor actually sees.
     *
     * @return int the day offset that is closed
     */
    private function seedDayOff(Staff $staff, DemoDataFactory $data): int
    {
        $offset = 12;

        // Landing it on a weekend would make it invisible — the practice is
        // closed then anyway.
        while (! isset(self::HOURS[$data->today()->addDays($offset)->dayOfWeekIso])) {
            $offset++;
        }

        $exception = new ScheduleException([
            // Whole day: null start/end times mean a full closure (docs/02).
            'date' => $data->today()->addDays($offset)->toDateString(),
            'type' => ScheduleExceptionType::Off,
            'note' => 'Szabadnap',
        ]);
        $exception->schedulable()->associate($staff);
        $exception->save();

        return $offset;
    }

    /**
     * The four services of docs/20 §2.1.
     *
     * @return array{array{first: Service, individual: Service, online: Service}, Service}
     */
    private function seedServices(Staff $staff, Room $room): array
    {
        $consultations = ServiceCategory::query()->create(['name' => 'Konzultációk', 'sort_order' => 1]);
        $admin = ServiceCategory::query()->create(['name' => 'Adminisztráció', 'sort_order' => 2]);

        // ⚠️ requires_approval: the one thing this persona demonstrates that the
        // others do not. A first consultation arrives as `requested` and waits
        // for a decision (docs/04 §5) — which is also why the practice can show
        // a pending request and a rejected one in the admin UI.
        $first = $this->session($consultations, 'Első konzultáció', 22_000, [
            'description' => 'Első alkalom: megismerkedés és a további közös munka kereteinek egyeztetése. '
                .'Az időpontot egyeztetés után igazolom vissza.',
            'requires_approval' => true,
        ]);

        $individual = $this->session($consultations, 'Egyéni konzultáció', 18_000, [
            'description' => 'Ötven perces egyéni alkalom a belvárosi rendelőben.',
        ]);

        $online = $this->session($consultations, 'Online konzultáció', 16_000, [
            'description' => 'Ötven perces alkalom videóhívásban, a megbeszélt időpontban küldött linken.',
            // No travel to pad between two calls.
            'buffer_after_minutes' => 0,
        ]);

        // The no_time_slot demo (docs/04 §1): nothing to schedule, only something
        // to deliver. `manual` fulfilment keeps the order at `confirmed` until an
        // admin closes it, which is the queue the demo wants to show — `digital`
        // would auto-complete on creation and leave nothing on screen.
        $document = Service::query()->create([
            'category_id' => $admin->getKey(),
            'name' => 'Igazolás / dokumentum kérése',
            'description' => 'Korábbi alkalmakról szóló részvételi igazolás kiállítása, e-mailben küldve.',
            'booking_mode' => BookingMode::NoTimeSlot,
            'price_minor' => 5_000 * 100,
            'currency' => 'HUF',
            'settings' => ['fulfillment_type' => 'manual'],
            'active' => true,
        ]);

        foreach ([$first, $individual, $online] as $service) {
            // Without the service_staff pivot the availability engine has no
            // resource to build a grid from and the public page offers no slots
            // at all (AvailabilityService::primaryResources).
            $service->staff()->sync([$staff->getKey()]);
            $service->rooms()->sync([$room->getKey()]);
        }

        return [compact('first', 'individual', 'online'), $document];
    }

    /**
     * One 50-minute consultation service.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function session(ServiceCategory $category, string $name, int $priceHuf, array $overrides = []): Service
    {
        return Service::query()->create([
            'category_id' => $category->getKey(),
            'name' => $name,
            'booking_mode' => BookingMode::DurationBased,
            'duration_minutes' => self::SESSION_MINUTES,
            'buffer_after_minutes' => self::BUFFER_MINUTES,
            // Money is stored in minor units (docs/01 §6) — fillér, never float.
            'price_minor' => $priceHuf * 100,
            'currency' => 'HUF',
            'requires_staff' => true,
            'active' => true,
            ...$overrides,
        ]);
    }

    /**
     * The practice's ten clients (docs/20 §2.1).
     *
     * Real customer accounts rather than guest bookings: a returning client is
     * what makes the admin's customer list, and the "previous appointments" view
     * on it, worth opening in a demo.
     *
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
                // `users.email` is globally unique, so the address carries both
                // the persona's own (non-deliverable) domain and an index — two
                // clients who happen to share a name must not collide, and a
                // nightly reset must not trip over another persona's inbox.
                'email' => Str::slug($name, '.').'.'.$index.'@'.$this->slug().'.demo.slot4u.hu',
                'phone' => '+3630'.sprintf('%07d', $data->between(1_000_000, 9_999_999)),
            ]);
        }

        return $customers;
    }

    /**
     * The two approval-flow bookings the demo is meant to open on (docs/20 §2.1):
     * one request still waiting for a decision, one that was turned down.
     *
     * The pending one is booked yesterday, not three weeks ago, and that is
     * deliberate: a `requested` booking soft-holds its slot only for the tenant's
     * approval window (48h by default), after which the sweep expires it. A
     * request seeded further back would be a pending approval that quietly
     * cancels itself the first time the scheduler runs on staging.
     *
     * @param  list<Customer>  $customers
     */
    private function seedApprovalShowcase(User $admin, Service $first, Staff $staff, Room $room, array $customers, DemoDataFactory $data): void
    {
        $pendingDay = $this->nextOpenDay(6, $data);
        $pendingHour = $this->claim($pendingDay, self::HOURS[$data->today()->addDays($pendingDay)->dayOfWeekIso]);

        $this->book(
            $first,
            $customers[0],
            $staff,
            $room,
            $data->at($pendingDay, sprintf('%02d:00', $pendingHour)),
            $data->at(-1, '20:10'),
            'Első alkalom',
            $data,
        );
        // Left at `requested` on purpose — this is the pending decision the
        // admin's approval screen exists for.

        $rejectedDay = $this->previousOpenDay(-4, $data);
        $rejectedHour = $this->claim($rejectedDay, self::HOURS[$data->today()->addDays($rejectedDay)->dayOfWeekIso]);

        $rejected = $this->book(
            $first,
            $customers[1],
            $staff,
            $room,
            $data->at($rejectedDay, sprintf('%02d:00', $rejectedHour)),
            $data->at($rejectedDay - 4, '21:40'),
            null,
            $data,
        );

        $data->asOf($data->at($rejectedDay - 3, '08:30'), fn () => app(RejectBooking::class)(
            $rejected,
            $admin,
            'Ebben az idősávban nem tudok időpontot adni. Kérlek válassz másik napot a naptárból.',
        ));
    }

    /**
     * A quarter of a year of appointments behind the practice (docs/20 §2.1:
     * 8–12 a week), written as if they had happened then (§3.3).
     *
     * A client's first appointment is always the screened one, so the approval
     * chain (requested → approved → confirmed → completed) is not a special case
     * bolted onto the demo but the ordinary way every client entered it.
     *
     * @param  array{first: Service, individual: Service, online: Service}  $sessions
     * @param  list<Customer>  $customers
     */
    private function seedHistory(User $admin, array $sessions, Staff $staff, Room $room, array $customers, DemoDataFactory $data): void
    {
        $approve = app(ApproveBooking::class);
        $changeStatus = app(ChangeBookingStatus::class);
        $cancel = app(CancelBooking::class);

        /** @var array<int, true> $seen */
        $seen = [];

        for ($daysAgo = self::HISTORY_DAYS; $daysAgo >= 1; $daysAgo--) {
            $offset = -$daysAgo;
            $hours = self::HOURS[$data->today()->addDays($offset)->dayOfWeekIso] ?? null;

            if ($hours === null) {
                continue;
            }

            // Four to twelve full days a week, which lands the week's total in
            // the 8–12 the spec asks for without a weekly bookkeeping pass.
            $count = count($hours) === 4 ? $data->between(0, 2) : $data->between(1, 3);

            foreach ($this->pickHours($offset, $hours, $count, $data) as $hour) {
                $customer = $customers[$data->between(0, count($customers) - 1)];
                $isFirstVisit = ! isset($seen[$customer->getKey()]);
                $seen[$customer->getKey()] = true;

                $service = $isFirstVisit
                    ? $sessions['first']
                    // Most sessions are in person; online is the exception, the
                    // way it is in a practice with a consulting room.
                    : $data->oneOf([$sessions['individual'], $sessions['individual'], $sessions['individual'], $sessions['online']]);

                $startsAt = $data->at($offset, sprintf('%02d:00', $hour));

                // Booked a few days ahead, the way a client does — not at the
                // instant the appointment began.
                $bookedAt = $startsAt->copy()->subDays($data->between(2, 9))->setTime(19, 5);

                $booking = $this->book(
                    $service,
                    $customer,
                    $staff,
                    $room,
                    $startsAt,
                    $bookedAt,
                    $data->between(1, 4) === 1 ? $data->oneOf(self::NEUTRAL_NOTES) : null,
                    $data,
                );

                if ($isFirstVisit) {
                    // Screened the morning after it arrived — inside the 48-hour
                    // approval hold, not days after it would have lapsed. A
                    // request approved past its own hold is a state the sweep
                    // makes impossible in production (SLO-26), so seeding one
                    // would be fiction the rest of the system disagrees with.
                    $data->asOf(
                        $bookedAt->copy()->addDay()->setTime(8, 40),
                        fn () => $approve($booking, $admin),
                    );
                }

                $ended = $startsAt->copy()->addMinutes(self::SESSION_MINUTES);

                // A practice that never has a cancellation or a missed session
                // is not a practice anybody recognises — but a past appointment
                // still sitting at `confirmed` is what an abandoned system looks
                // like, so every one of them reaches a terminal state.
                match ($data->between(1, 20)) {
                    1 => $data->asOf(
                        $startsAt->copy()->subDay()->setTime(17, 15),
                        fn () => $cancel($booking, $customer, 'Az ügyfél lemondta.'),
                    ),
                    2 => $data->asOf($ended, fn () => $changeStatus($booking, BookingStatus::NoShow, $admin)),
                    default => $data->asOf($ended, fn () => $changeStatus($booking, BookingStatus::Completed, $admin)),
                };
            }
        }
    }

    /**
     * A partly booked fortnight ahead (docs/20 §1.3, §2.1).
     *
     * Partly is the point: a full calendar sells the product no better than an
     * empty one and leaves a visitor nothing to click, which on the demo's
     * public page is the only thing there is to do.
     *
     * @param  array{first: Service, individual: Service, online: Service}  $sessions
     * @param  list<Customer>  $customers
     */
    private function seedUpcoming(array $sessions, Staff $staff, Room $room, array $customers, int $dayOff, DemoDataFactory $data): void
    {
        for ($daysAhead = 1; $daysAhead <= self::FUTURE_DAYS; $daysAhead++) {
            if ($daysAhead === $dayOff) {
                // The exception closed this day; a booking on it would be an
                // orphan the availability engine never offers.
                continue;
            }

            $hours = self::HOURS[$data->today()->addDays($daysAhead)->dayOfWeekIso] ?? null;

            if ($hours === null) {
                continue;
            }

            // The same daily load as the history, so the fortnight ahead looks
            // like the quarter behind it — and still leaves three quarters of
            // every day free to click.
            $count = count($hours) === 4 ? $data->between(0, 2) : $data->between(1, 3);

            foreach ($this->pickHours($daysAhead, $hours, $count, $data) as $hour) {
                $customer = $customers[$data->between(0, count($customers) - 1)];
                $startsAt = $data->at($daysAhead, sprintf('%02d:00', $hour));

                $this->book(
                    // Only returning clients ahead: a first consultation would
                    // arrive as `requested`, and a calendar of unanswered
                    // requests would contradict the one pending decision the
                    // approval screen is meant to show.
                    $data->oneOf([$sessions['individual'], $sessions['individual'], $sessions['online']]),
                    $customer,
                    $staff,
                    $room,
                    $startsAt,
                    $data->at(-$data->between(1, 5), '18:20'),
                    $data->between(1, 4) === 1 ? $data->oneOf(self::NEUTRAL_NOTES) : null,
                    $data,
                );
            }
        }
    }

    /**
     * Three `no_time_slot` orders (docs/04 §1): two already delivered, one still
     * waiting for the admin to close it — so the fulfilment queue in the demo is
     * not empty.
     *
     * @param  list<Customer>  $customers
     */
    private function seedDocumentOrders(User $admin, Service $document, array $customers, DemoDataFactory $data): void
    {
        $create = app(CreateBooking::class);
        $complete = app(CompleteBooking::class);

        foreach ([[-38, true], [-16, true], [-2, false]] as [$offset, $fulfilled]) {
            $orderedAt = $data->at($offset, '11:25');
            $customer = $customers[$data->between(0, count($customers) - 1)];

            $order = $data->asOf($orderedAt, fn (): Booking => $create($document, [
                'customer_id' => $customer->getKey(),
                'notes' => 'A korábbi alkalmakról kérek részvételi igazolást.',
                'source' => BookingSource::Online->value,
            ]));

            if ($fulfilled) {
                $data->asOf(
                    $orderedAt->copy()->addDays(2)->setTime(9, 15),
                    fn () => $complete($order, $admin),
                );
            }
        }
    }

    /**
     * Create one time-slotted booking through the real Action, on the clock of
     * the day it was booked (docs/20 §3.3).
     */
    private function book(Service $service, Customer $customer, Staff $staff, Room $room, Carbon $startsAt, Carbon $bookedAt, ?string $note, DemoDataFactory $data): Booking
    {
        return $data->asOf($bookedAt, fn (): Booking => app(CreateBooking::class)(
            $service,
            [
                'customer_id' => $customer->getKey(),
                'staff_id' => $staff->getKey(),
                'room_id' => $room->getKey(),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes(self::SESSION_MINUTES),
                'notes' => $note,
                // `online` rather than `admin`: the admin source skips the
                // approval gate (CreateBooking::initialStatus), and the approval
                // flow is the whole reason this persona exists.
                'source' => BookingSource::Online->value,
            ],
        ));
    }

    /**
     * `$count` free hours on a day, ascending, marked as taken.
     *
     * @param  list<int>  $hours
     * @return list<int>
     */
    private function pickHours(int $dayOffset, array $hours, int $count, DemoDataFactory $data): array
    {
        $pool = array_values(array_filter($hours, fn (int $hour): bool => ! isset($this->taken[$dayOffset.':'.$hour])));
        $picked = [];

        for ($i = 0; $i < $count && $pool !== []; $i++) {
            $index = $data->between(0, count($pool) - 1);
            $picked[] = $pool[$index];
            $this->taken[$dayOffset.':'.$pool[$index]] = true;
            array_splice($pool, $index, 1);
        }

        sort($picked);

        return $picked;
    }

    /**
     * The first free hour of a day, marked as taken.
     *
     * @param  list<int>  $hours
     */
    private function claim(int $dayOffset, array $hours): int
    {
        foreach ($hours as $hour) {
            if (! isset($this->taken[$dayOffset.':'.$hour])) {
                $this->taken[$dayOffset.':'.$hour] = true;

                return $hour;
            }
        }

        // Unreachable while the showcase runs before the bulk generators, which
        // is the order buildFor() fixes; loud rather than silently double-booked
        // if that ever changes.
        throw new RuntimeException("No free consulting hour left on day offset {$dayOffset}.");
    }

    /**
     * The first open day at or after `$offset`, skipping the weekend.
     *
     * Offsets are read off the factory's pinned "today" in the tenant's own
     * timezone — `Carbon::today()` would answer in the process default and, for
     * a couple of hours each night, name a different weekday than the calendar
     * the demo is being seeded for.
     */
    private function nextOpenDay(int $offset, DemoDataFactory $data): int
    {
        while (! isset(self::HOURS[$data->today()->addDays($offset)->dayOfWeekIso])) {
            $offset++;
        }

        return $offset;
    }

    /** The last open day at or before `$offset`, skipping the weekend. */
    private function previousOpenDay(int $offset, DemoDataFactory $data): int
    {
        while (! isset(self::HOURS[$data->today()->addDays($offset)->dayOfWeekIso])) {
            $offset--;
        }

        return $offset;
    }
}
