<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Booking\ApproveBooking;
use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Booking\CreateBooking;
use App\Actions\Booking\RejectBooking;
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
use App\Enums\Feature;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Enums\QuoteRequestStatus;
use App\Enums\Role;
use App\Enums\ScheduleExceptionType;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\MessageTemplate;
use App\Models\QuoteRequest;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Settings\TenantBranding;
use App\Tenancy\TenantManager;

/**
 * „Csavarkulcs Autószerviz" — the fifth persona (SLO-197, docs/22 §2).
 *
 * The first NON-WELLNESS vertical in the demo set, and the proof that the
 * booking engine is not a beauty-salon product with a generic name. What it
 * shows that the other four do not:
 *
 *  - `duration_based` + `requires_room` + `requires_staff` AT ONCE. The lift is
 *    the scarce resource, not the mechanic, and the visitor picks neither — the
 *    engine assigns a free bay under a lock (SLO-200). This persona is the
 *    reason that gap was found.
 *  - A Saturday where the staff rota and the room rota disagree: only the tyre
 *    fitter works, and only the tyre bay is open, so a wheel change is bookable
 *    and an oil change is not — from the same calendar.
 *  - An eight-hour "drop the car off" service that takes a bay for the day.
 *  - Approval, quote and plain booking inside ONE tenant (the venue is
 *    quote-led, the practice approval-led).
 *  - Seasonality the statistics module can actually show: a tyre-change spike
 *    fixed relative to the seed date, so it is visible whatever month it is.
 *
 * ⚠️ Every seeded instant must still be in the past — and every soft hold still
 * live — AT 03:00, because that is when `demo:reset` runs (SLO-191). Two bugs
 * in other personas came from getting that wrong.
 */
final class AutoServiceDemoPersona extends DemoPersona
{
    /** Anthracite: a workshop, not a salon. The whole point of the branding demo. */
    private const PRIMARY_COLOR = '#2f3640';

    private const HISTORY_DAYS = 180;

    private const FUTURE_DAYS = 14;

    private const CUSTOMER_COUNT = 40;

    /**
     * The tyre season, as an offset from the seed date rather than a month.
     *
     * A calendar month would put the spike wherever today happens to fall — some
     * days off the left edge of a six-month chart entirely. Anchored to `today`
     * it lands in the same place on the curve every night, which is what makes
     * it demoable: "look, October and April are our two weeks of chaos".
     */
    private const TYRE_PEAK_FROM = 150;

    private const TYRE_PEAK_TO = 120;

    /** Plausible Hungarian plates, both the old and the new format. */
    private const PLATE_LETTERS = ['ABC', 'KLM', 'NRT', 'PZS', 'GHF', 'MDX', 'AAFB', 'CDLM', 'NNPR'];

    /**
     * Car models. A brand name in a service note is a product category, not a
     * claim of partnership — but the workshop's OWN name has to be fictional,
     * which is why it is a made-up compound (docs/22 §2).
     *
     * @var list<string>
     */
    private const CARS = [
        'Skoda Octavia', 'Skoda Fabia', 'Toyota Corolla', 'Toyota Yaris',
        'Ford Focus', 'Ford Fiesta', 'Opel Astra', 'Opel Corsa',
        'Suzuki Swift', 'Suzuki Vitara', 'Volkswagen Golf', 'Volkswagen Passat',
    ];

    /** What an enquiry form asks for. Order matters — it is the field order on screen. */
    private const QUOTE_FIELDS = ['Rendszám', 'Márka és típus', 'Évjárat', 'Km-óra állása', 'Hibaleírás', 'Mikorra kellene'];

    /** @var list<string> */
    private const FAULTS = [
        'Fékezéskor rángat a kormány, és csikorgó hang hallatszik elöl.',
        'Hidegindításkor füstöl, aztán rendben megy. Kb. két hete kezdődött.',
        'Kigyulladt a motorhiba-lámpa, a teljesítmény visszaesett.',
        'A klíma nem hűt, csak fúj. Tavaly töltve volt.',
        'Kuplungnál szaggat, emelkedőn megcsúszik.',
        'Hátsó futóműből kopogó hang egyenetlen úton.',
    ];

    /** The two bays the workshop services share; the tyre bay stands apart. */
    private const WORKSHOP_BAYS = ['bay1', 'bay2'];

    public function slug(): string
    {
        return 'demo-autoszerviz';
    }

    public function name(): string
    {
        return 'Csavarkulcs Autószerviz';
    }

    public function adminName(): string
    {
        return 'Kovács Gábor';
    }

    /**
     * @return array<string, mixed>
     */
    public function profileSettings(): array
    {
        return [
            'description' => 'Független autószerviz és gumisműhely: olajcsere, diagnosztika, fék, '
                .'klíma, gumiszerelés és műszaki vizsga felkészítés. Három állás, három szerelő — '
                .'foglalj időpontot online, a kulcsot a pultnál add le. Az árak munkadíjak, '
                .'alkatrész nélkül.',
            'email' => 'szerviz@'.$this->slug().'.demo.slot4u.hu',
            'phone' => '+36 1 555 0142',
            'address_line' => 'Ipari park 12/B',
            'address_city' => 'Budapest',
            'address_postal' => '1163',
            'opening_hours' => 'H–P 8:00–17:00 · Szombat: csak gumisszerviz, 8:00–12:00',
            // The workshop's own cancellation window is generous: a car that is
            // not going to arrive is better said out loud than left as a no-show.
            'cancellation_deadline_hours' => 4,
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

    private function buildFor(Tenant $tenant, User $admin, DemoDataFactory $data): void
    {
        $this->enableBranding($tenant);

        $location = Location::query()->create([
            'name' => 'Csavarkulcs Autószerviz — Ipari park',
            'address' => [
                'line' => 'Ipari park 12/B',
                'city' => 'Budapest',
                'postal_code' => '1163',
            ],
            'phone' => '+36 1 555 0142',
            'active' => true,
        ]);

        $bays = $this->seedBays($location);
        $team = $this->seedTeam($tenant, $location, $admin);

        $this->seedSchedules($location, $team, $bays);
        $this->seedExceptions($team, $data);

        $services = $this->seedServices($team, $bays);
        $customers = $this->seedCustomers($data);

        $this->seedHistory($admin, $services, $team, $customers, $data);
        $this->seedFuture($admin, $services, $team, $customers, $data);
        $this->seedQuotePipeline($admin, $services['quote'], $customers, $data);
        $this->seedTemplate();
    }

    /**
     * Branding is off by default on the base plan, so writing the JSON alone
     * would leave the workshop looking like every other unbranded tenant — and
     * the point here is that a garage does NOT look like a salon.
     */
    private function enableBranding(Tenant $tenant): void
    {
        $tenant->branding = (new TenantBranding(primaryColor: self::PRIMARY_COLOR))->toArray();
        $tenant->save();

        TenantFeature::query()->create([
            'feature_code' => Feature::Branding->value,
            'enabled' => true,
        ]);
    }

    /**
     * Three bays, typed as such.
     *
     * ⚠️ `capacity: 1` on every one, and that is the load-bearing detail: a bay
     * holds one car, so two bookings in the same hour need two bays. That is
     * what the assignment in CreateBooking is counting.
     *
     * @return array<string, Room>
     */
    private function seedBays(Location $location): array
    {
        $bay = fn (string $name, string $description): Room => Room::query()->create([
            'location_id' => $location->getKey(),
            'name' => $name,
            // ⚠️ Left as the default `room` type. A service bay is neither a room
            // nor equipment, but adding a third RoomType case would reach the
            // admin UI, its validation and the i18n labels — scope this issue
            // does not own. docs/22 §2 allows naming it in the description
            // instead, which is what the names below do.
            'capacity' => 1,
            'description' => $description,
            'active' => true,
        ]);

        return [
            'bay1' => $bay('1-es állás (emelő)', 'Emelős állás — nagyobb munkák, műszaki vizsga.'),
            'bay2' => $bay('2-es állás (emelő)', 'Emelős állás — gyorsszerviz, fék, klíma.'),
            'tyre' => $bay('Gumis állás', 'Gumiszerelés és centrírozás. Szombaton is nyitva.'),
        ];
    }

    /**
     * Three mechanics and two logins.
     *
     * The owner is both: his staff row carries `user_id`, so the calendar he
     * books against and the account he logs in with are the same person. The
     * service adviser is a Manager — she takes bookings, approves and quotes,
     * but cannot reach settings or billing, which is the only way the permission
     * matrix is demonstrable (docs/03).
     *
     * @return array<string, Staff>
     */
    private function seedTeam(Tenant $tenant, Location $location, User $admin): array
    {
        $mechanic = function (string $name, string $title, string $color, ?User $user = null) use ($location): Staff {
            $staff = new Staff([
                'name' => $name,
                'title' => $title,
                'color' => $color,
                'active' => true,
            ]);

            if ($user !== null) {
                $staff->user_id = $user->getKey();
            }

            $staff->save();
            $staff->locations()->sync([$location->getKey()]);

            return $staff;
        };

        $this->createStaffUser($tenant, 'Szabó Kinga', 'kinga@'.$this->slug().'.demo.slot4u.hu', Role::Manager);

        return [
            'gabor' => $mechanic($this->adminName(), 'szerelő, tulajdonos', '#c0620f', $admin),
            'attila' => $mechanic('Németh Attila', 'szerelő', '#4a6572', null),
            'zsolt' => $mechanic('Balogh Zsolt', 'gumis, gyorsszerviz', '#7a8b3f', null),
        ];
    }

    /**
     * ⚠️ THE rota that makes this persona worth building.
     *
     * Weekdays everyone is in and every bay is open. On Saturday **only the tyre
     * fitter works, and only the tyre bay is open** — so the two rotas have to
     * agree before a slot exists. A wheel change is bookable; an oil change,
     * whose mechanics are off and whose bays are shut, is not. Both halves are
     * needed: staff-only filtering would still offer the bay-less slot, and
     * bay-only filtering would offer a slot with nobody to work it.
     *
     * @param  array<string, Staff>  $team
     * @param  array<string, Room>  $bays
     */
    private function seedSchedules(Location $location, array $team, array $bays): void
    {
        $band = function (Staff|Room $resource, array $weekdays, string $open, string $close) use ($location): void {
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
        };

        foreach ($team as $staff) {
            $band($staff, range(1, 5), '08:00', '17:00');
        }

        foreach ($bays as $bay) {
            $band($bay, range(1, 5), '08:00', '17:00');
        }

        // Saturday: the tyre fitter and his bay, nobody else.
        $band($team['zsolt'], [6], '08:00', '12:00');
        $band($bays['tyre'], [6], '08:00', '12:00');
    }

    /**
     * One holiday behind and one week off ahead — so the calendar shows an
     * exception overriding the weekly pattern, in both directions.
     *
     * @param  array<string, Staff>  $team
     */
    private function seedExceptions(array $team, DemoDataFactory $data): void
    {
        $closed = function (Staff $staff, int $dayOffset) use ($data): void {
            $exception = new ScheduleException([
                'date' => $data->today()->copy()->addDays($dayOffset)->toDateString(),
                'type' => ScheduleExceptionType::Off,
                'note' => $dayOffset < 0 ? 'Ünnepnap — zárva' : 'Szabadság',
            ]);
            $exception->schedulable()->associate($staff);
            $exception->save();
        };

        // Attila takes two days off next week; the shop stays open around him,
        // which is what makes the absence visible rather than a closed day.
        $closed($team['attila'], $this->nextWeekday(6, $data));
        $closed($team['attila'], $this->nextWeekday(7, $data));

        // A holiday behind: everyone off, one day.
        foreach ($team as $staff) {
            $closed($staff, -$this->nextWeekdayBack(21, $data));
        }
    }

    /**
     * The catalogue (docs/22 §2).
     *
     * ⚠️ Every timed service is `requires_staff` AND `requires_room`. That pair
     * is the persona's reason to exist, and until SLO-200 it was a pair the
     * engine only half-honoured.
     *
     * @param  array<string, Staff>  $team
     * @param  array<string, Room>  $bays
     * @return array<string, Service>
     */
    private function seedServices(array $team, array $bays): array
    {
        $quick = ServiceCategory::query()->create(['name' => 'Gyorsszerviz', 'sort_order' => 1]);
        $tyre = ServiceCategory::query()->create(['name' => 'Gumi', 'sort_order' => 2]);
        $brakes = ServiceCategory::query()->create(['name' => 'Fék és futómű', 'sort_order' => 3]);
        $major = ServiceCategory::query()->create(['name' => 'Nagyobb munkák', 'sort_order' => 4]);

        // The note the whole catalogue needs, said once per service rather than
        // buried in a policy page: a quoted price a customer reads as "all in"
        // and then doubles at the counter is how a workshop loses somebody.
        $labourOnly = ' Az ár munkadíj, alkatrész nélkül.';

        $timed = function (
            ServiceCategory $category,
            string $name,
            string $description,
            int $minutes,
            int $buffer,
            int $price,
            array $staff,
            array $rooms,
            bool $approval = false,
        ) use ($team, $bays, $labourOnly): Service {
            $service = Service::query()->create([
                'category_id' => $category->getKey(),
                'name' => $name,
                'description' => $description.$labourOnly,
                'booking_mode' => BookingMode::DurationBased,
                'duration_minutes' => $minutes,
                'buffer_after_minutes' => $buffer,
                'price_minor' => $price * 100,
                'currency' => 'HUF',
                'requires_staff' => true,
                'requires_room' => true,
                'requires_approval' => $approval,
                // Until structured fields land (SLO-199) the plate lives in the
                // notes box — so the box has to ask for it (SLO-197).
                'settings' => ['notes_hint' => 'Kérjük, add meg a rendszámot és az autó típusát.'],
                'active' => true,
            ]);

            // ⚠️ Both pivots, always. Without service_staff the public page
            // offers nothing; without service_rooms there is no bay to assign
            // and `requires_room` refuses every slot.
            $service->staff()->sync(array_map(fn (string $key): int => $team[$key]->getKey(), $staff));
            $service->rooms()->sync(array_map(fn (string $key): int => $bays[$key]->getKey(), $rooms));

            return $service;
        };

        $services = [
            'oil' => $timed($quick, 'Olajcsere szűrővel',
                'Motorolaj és olajszűrő csere, folyadékszintek ellenőrzése.',
                60, 15, 9_900, ['gabor', 'attila'], self::WORKSHOP_BAYS),

            'diag' => $timed($quick, 'Hibakód-olvasás, diagnosztika',
                'Hibakódok kiolvasása és értékelése, írásos összefoglalóval.',
                30, 10, 6_000, ['gabor', 'attila'], self::WORKSHOP_BAYS),

            'ac' => $timed($quick, 'Klímatöltés + fertőtlenítés',
                'Klímarendszer töltése, tömítettség-vizsgálat és fertőtlenítés.',
                45, 15, 15_000, ['attila'], ['bay2']),

            'wheels' => $timed($tyre, 'Kerékcsere (szezonális, 4 kerék)',
                'Négy komplett kerék cseréje, nyomásellenőrzéssel. Szombaton is.',
                45, 15, 8_500, ['zsolt', 'attila'], ['tyre']),

            'tyres' => $timed($tyre, 'Gumiszerelés felniről + centrírozás',
                'Gumiabroncs le- és felszerelése felniről, centrírozással.',
                60, 15, 12_000, ['zsolt'], ['tyre']),

            'brakes' => $timed($brakes, 'Fékbetét csere (első tengely)',
                'Első fékbetétek cseréje, féktárcsa-állapot ellenőrzésével.',
                90, 15, 14_000, ['gabor', 'attila'], self::WORKSHOP_BAYS),

            // ⚠️ Eight hours: a "drop the car off" booking that takes a bay for
            // the whole day. The one service in the demo set that does.
            'mot' => $timed($major, 'Műszaki vizsga felkészítés + vizsgáztatás',
                'Egész napos leadás: átvizsgálás, felkészítés és vizsgáztatás. '
                .'Az autót reggel hozd be, délután veheted át.',
                480, 0, 25_000, ['gabor'], ['bay1'], approval: true),

            'timing' => $timed($major, 'Vezérműszíj csere',
                'Vezérműszíj és feszítőgörgő cseréje gyári előírás szerint.',
                240, 30, 45_000, ['gabor'], ['bay1'], approval: true),
        ];

        // The sixth mode (docs/04 §6): a repair nobody can price from a name.
        $services['quote'] = Service::query()->create([
            'category_id' => $major->getKey(),
            'name' => 'Ajánlatkérés nagyobb javításra',
            'description' => 'Írd le a hibát, és két munkanapon belül árajánlatot küldünk. '
                .'A rendszámot és a km-órát is kérjük, hogy pontosan tudjunk kalkulálni.',
            'booking_mode' => BookingMode::QuoteRequest,
            'price_minor' => 0,
            'currency' => 'HUF',
            'settings' => ['quote_fields' => self::QUOTE_FIELDS],
            'active' => true,
        ]);

        return $services;
    }

    /**
     * @return list<Customer>
     */
    private function seedCustomers(DemoDataFactory $data): array
    {
        $create = app(CreateCustomer::class);
        $faker = $data->faker();
        $customers = [];

        for ($i = 0; $i < self::CUSTOMER_COUNT; $i++) {
            $name = $faker->name();
            $customers[] = $create([
                'name' => $name,
                'email' => 'ugyfel'.($i + 1).'@'.$this->slug().'.demo.slot4u.hu',
                'phone' => '+3630'.$data->between(1_000_000, 9_999_999),
            ]);
        }

        return $customers;
    }

    /**
     * Six months of trade, in two lanes that never contend for a bay.
     *
     * ⚠️ The lanes are the design. Gábor and Attila work the two workshop bays;
     * Zsolt works the tyre bay alone. Two concurrent workshop jobs fit the two
     * bays exactly, and the tyre lane is disjoint from both — so the seed can
     * leave the bay choice to CreateBooking (which is the point: the assignment
     * is demonstrated, not bypassed) without ever running out of bays.
     *
     * The wheel change is seeded on Zsolt only, even though Attila is qualified
     * for it: two people in one tyre bay is the one collision this layout can
     * produce. He stays on the service so "anyone" is still a real choice on the
     * public page.
     *
     * @param  array<string, Service>  $services
     * @param  array<string, Staff>  $team
     * @param  list<Customer>  $customers
     */
    private function seedHistory(User $admin, array $services, array $team, array $customers, DemoDataFactory $data): void
    {
        $changeStatus = app(ChangeBookingStatus::class);

        for ($daysAgo = self::HISTORY_DAYS; $daysAgo >= 1; $daysAgo--) {
            $day = $data->today()->copy()->subDays($daysAgo);

            if ($day->isSunday()) {
                continue;
            }

            $saturday = $day->isSaturday();
            $peak = $daysAgo <= self::TYRE_PEAK_FROM && $daysAgo >= self::TYRE_PEAK_TO;

            // The workshop lane: closed on Saturdays, so those days are the tyre
            // bay alone — which is exactly what the rota says.
            if (! $saturday) {
                foreach (['gabor', 'attila'] as $who) {
                    $this->fillLane($admin, $services, $team[$who], $who, $customers, $data, -$daysAgo, $changeStatus);
                }
            }

            $this->fillTyreLane($admin, $services, $team['zsolt'], $customers, $data, -$daysAgo, $peak, $saturday, $changeStatus);
        }
    }

    /**
     * One mechanic's day in the workshop lane: two or three jobs, back to back,
     * each starting after the previous one has finished with its buffer.
     *
     * @param  array<string, Service>  $services
     * @param  list<Customer>  $customers
     */
    private function fillLane(
        User $admin,
        array $services,
        Staff $staff,
        string $who,
        array $customers,
        DemoDataFactory $data,
        int $dayOffset,
        ChangeBookingStatus $changeStatus,
    ): void {
        // Gábor also does the long jobs; Attila keeps the quick lane moving.
        $menu = $who === 'gabor'
            ? ['oil', 'diag', 'brakes', 'timing', 'oil', 'diag']
            : ['oil', 'diag', 'ac', 'brakes', 'oil', 'ac'];

        $minute = 0;                       // minutes past 08:00
        $jobs = $data->between(2, 3);

        for ($i = 0; $i < $jobs; $i++) {
            $service = $services[$data->oneOf($menu)];
            $length = (int) $service->duration_minutes + $service->buffer_after_minutes;

            // 17:00 is the close; a job that would run past it is not started.
            if ($minute + (int) $service->duration_minutes > 9 * 60) {
                return;
            }

            $this->book($admin, $service, $staff, $customers, $data, $dayOffset, $minute, $changeStatus);
            $minute += $length;
        }
    }

    /**
     * The tyre bay: busier in season, and the only lane that works Saturdays.
     *
     * @param  array<string, Service>  $services
     * @param  list<Customer>  $customers
     */
    private function fillTyreLane(
        User $admin,
        array $services,
        Staff $zsolt,
        array $customers,
        DemoDataFactory $data,
        int $dayOffset,
        bool $peak,
        bool $saturday,
        ChangeBookingStatus $changeStatus,
    ): void {
        // ⚠️ The seasonality. Three times the volume in the peak window, which is
        // what puts a visible spike on the six-month chart — and it is anchored
        // to the seed date, so it is there whatever today's month happens to be.
        $jobs = $peak ? $data->between(4, 5) : $data->between(1, 2);

        // Saturday is a half day: 08:00–12:00.
        $closes = $saturday ? 4 * 60 : 9 * 60;
        $minute = 0;

        for ($i = 0; $i < $jobs; $i++) {
            // In season it is almost all wheel changes; out of season the fitter
            // does the slower job of stripping tyres off rims too.
            $service = $peak || $data->between(1, 3) !== 1 ? $services['wheels'] : $services['tyres'];
            $length = (int) $service->duration_minutes + $service->buffer_after_minutes;

            if ($minute + (int) $service->duration_minutes > $closes) {
                return;
            }

            $this->book($admin, $service, $zsolt, $customers, $data, $dayOffset, $minute, $changeStatus);
            $minute += $length;
        }
    }

    /**
     * One booking, taken through the real action and walked to its end state.
     *
     * ⚠️ The bay is deliberately NOT passed. `requires_room` is on, so
     * CreateBooking assigns a free one under its lock (SLO-200) — the seed
     * exercises the assignment rather than working around it, and a demo built
     * on a path nobody else takes proves nothing.
     *
     * @param  list<Customer>  $customers
     */
    private function book(
        User $admin,
        Service $service,
        Staff $staff,
        array $customers,
        DemoDataFactory $data,
        int $dayOffset,
        int $minutesPastOpening,
        ChangeBookingStatus $changeStatus,
    ): Booking {
        $startsAt = $data->at($dayOffset, '08:00')->addMinutes($minutesPastOpening);
        $endsAt = $startsAt->copy()->addMinutes((int) $service->duration_minutes);

        // Booked a few days ahead, in the evening — when somebody actually sits
        // down to sort the car out.
        $bookedAt = $startsAt->copy()->subDays($data->between(1, 8))->setTime(19, 25);

        // ⚠️ ...but never in the future. A job a fortnight out would otherwise be
        // "booked" next week, giving the row a `created_at` that has not happened
        // yet — a booking that does not exist at 03:00, when the reset runs and
        // the demo is judged. Pushed into the past instead of skipped: skipping
        // is what silently cost other personas whole scenes (SLO-191).
        if ($bookedAt->gte($data->today())) {
            $bookedAt = $data->at(-$data->between(1, 5), '19:25');
        }

        $customer = $customers[$data->between(0, count($customers) - 1)];

        $booking = $data->asOf($bookedAt, fn (): Booking => app(CreateBooking::class)($service, [
            'customer_id' => $customer->getKey(),
            'staff_id' => $staff->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'notes' => $this->vehicleNote($data),
            'source' => BookingSource::Online->value,
        ], null, null, $data->notifiable($bookedAt)));

        // A job that needs approving was approved — by the service adviser, the
        // same afternoon. Every one of them: the ONE request left pending is the
        // MOT in seedDecisions, on purpose. Without this, every timing-belt job
        // in six months of history sits `requested` for ever, which is not a
        // workshop, it is a workshop that never answers its email.
        if ($booking->status === BookingStatus::Requested) {
            $data->asOf(
                $bookedAt->copy()->addHours(2),
                fn () => app(ApproveBooking::class)($booking, $admin),
            );
            $booking->refresh();
        }

        // ⚠️ Terminality is decided by the clock, not the day offset: a job
        // seeded for 08:00 today is already over by the time an afternoon reset
        // finishes, and would otherwise sit `confirmed` in the past.
        if ($endsAt->isFuture() || $booking->status !== BookingStatus::Confirmed) {
            return $booking;
        }

        // A handful never turned up. Everything else was done.
        $status = $data->between(1, 40) === 1 ? BookingStatus::NoShow : BookingStatus::Completed;

        $data->asOf($endsAt->copy()->addMinutes(20), fn () => $changeStatus($booking, $status, $admin));

        return $booking;
    }

    /**
     * The vehicle, in the notes box, in one consistent shape (docs/22 §3).
     *
     * A stop-gap and openly so: the registration belongs in a field of its own,
     * which is SLO-199. What makes it usable meanwhile is that every booking
     * writes the SAME shape, so a receptionist scanning the calendar always
     * finds the plate in the same place.
     */
    private function vehicleNote(DemoDataFactory $data): string
    {
        $plate = $data->oneOf(self::PLATE_LETTERS).'-'.$data->between(100, 999);
        $year = $data->between(2008, 2023);
        $km = $data->between(45, 320) * 1_000;

        return sprintf(
            'Rendszám: %s · %s (%d) · %s km',
            $plate,
            $data->oneOf(self::CARS),
            $year,
            number_format($km, 0, ',', ' '),
        );
    }

    /**
     * The fortnight ahead: a partly booked calendar, plus one of every state a
     * service adviser has to deal with.
     *
     * @param  array<string, Service>  $services
     * @param  array<string, Staff>  $team
     * @param  list<Customer>  $customers
     */
    private function seedFuture(User $admin, array $services, array $team, array $customers, DemoDataFactory $data): void
    {
        $changeStatus = app(ChangeBookingStatus::class);

        // ⚠️ The set-pieces come FIRST and claim their days; the filler below
        // then works around them. An eight-hour MOT holds Gábor's entire day, so
        // a filler job dropped on top of it collides — and a seed that throws
        // half-way leaves the persona missing, which is exactly the failure the
        // nightly reset alerts on (SLO-191).
        $claimed = $this->seedDecisions($admin, $services, $team, $customers, $data, $changeStatus);

        for ($daysAhead = 1; $daysAhead <= self::FUTURE_DAYS; $daysAhead++) {
            $day = $data->today()->copy()->addDays($daysAhead);

            if ($day->isSunday()) {
                continue;
            }

            if (! $day->isSaturday()) {
                foreach (['gabor', 'attila'] as $who) {
                    if (in_array($daysAhead, $claimed[$who] ?? [], true)) {
                        continue;
                    }

                    $this->fillLane($admin, $services, $team[$who], $who, $customers, $data, $daysAhead, $changeStatus);
                }
            }

            $this->fillTyreLane($admin, $services, $team['zsolt'], $customers, $data, $daysAhead, false, $day->isSaturday(), $changeStatus);
        }
    }

    /**
     * The states the future calendar needs but random filling will not produce.
     *
     * @param  array<string, Service>  $services
     * @param  array<string, Staff>  $team
     * @param  list<Customer>  $customers
     * @return array<string, list<int>> day offsets these scenes claimed, per mechanic
     */
    private function seedDecisions(
        User $admin,
        array $services,
        array $team,
        array $customers,
        DemoDataFactory $data,
        ChangeBookingStatus $changeStatus,
    ): array {
        $create = app(CreateBooking::class);
        $reject = app(RejectBooking::class);

        // ⚠️ Booked YESTERDAY, explicitly. A `requested` booking soft-holds its
        // slot only for the approval window (48h), so a randomly drawn lead time
        // can put `hold_expires_at` in the past before the seed even finishes —
        // and the pending decision the demo exists to show is one sweep away
        // from cancelling itself (SLO-26).
        $motDay = $this->nextWeekday(5, $data);
        $motStart = $data->at($motDay, '08:00');

        $data->asOf($data->at(-1, '09:20'), fn (): Booking => $create($services['mot'], [
            'customer_id' => $customers[0]->getKey(),
            'staff_id' => $team['gabor']->getKey(),
            'starts_at' => $motStart,
            'ends_at' => $motStart->copy()->addMinutes(480),
            'notes' => $this->vehicleNote($data),
            'source' => BookingSource::Online->value,
        ], null, null, false));

        // A second request, refused with a reason a customer can act on — the
        // half of the approval flow a demo usually forgets to show.
        $refusedDay = $this->nextWeekday(9, $data);
        $refusedStart = $data->at($refusedDay, '08:00');

        $refused = $data->asOf($data->at(-1, '10:05'), fn (): Booking => $create($services['mot'], [
            'customer_id' => $customers[1]->getKey(),
            'staff_id' => $team['gabor']->getKey(),
            'starts_at' => $refusedStart,
            'ends_at' => $refusedStart->copy()->addMinutes(480),
            'notes' => $this->vehicleNote($data),
            'source' => BookingSource::Online->value,
        ], null, null, false));

        $data->asOf($data->at(-1, '11:40'), fn () => $reject(
            $refused,
            $admin,
            'Erre a napra nincs szabad vizsgaidőpont a vizsgaállomáson. Javasolt időpont: két nappal később, ugyanebben az időben.',
        ));

        // Two cancellations ahead — somebody's plans changed, which is ordinary
        // and should be visible as such.
        $cancelledDays = [];

        foreach ([3, 8] as $offset) {
            $day = $this->nextWeekday($offset, $data);
            $startsAt = $data->at($day, '15:00');

            $booking = $data->asOf($data->at(-2, '18:30'), fn (): Booking => $create($services['diag'], [
                'customer_id' => $customers[$data->between(2, 9)]->getKey(),
                'staff_id' => $team['attila']->getKey(),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes(30),
                'notes' => $this->vehicleNote($data),
                'source' => BookingSource::Online->value,
            ], null, null, false));

            $data->asOf($data->at(-1, '08:45'), fn () => $changeStatus(
                $booking,
                BookingStatus::Canceled,
                $admin,
                'Az ügyfél telefonon lemondta.',
            ));

            $cancelledDays[] = $day;
        }

        // Even the cancelled and rejected days are reported: they are free again
        // by the end, but they were occupied while these scenes ran, and the
        // filler runs after.
        return [
            'gabor' => [$motDay, $refusedDay],
            'attila' => $cancelledDays,
        ];
    }

    /**
     * Enquiries in every state, so the quote board is never half empty.
     *
     * @param  list<Customer>  $customers
     */
    private function seedQuotePipeline(User $admin, Service $enquiry, array $customers, DemoDataFactory $data): void
    {
        $create = app(CreateQuoteRequest::class);
        $submit = app(SubmitQuote::class);
        $accept = app(AcceptQuoteRequest::class);
        $reject = app(RejectQuoteRequest::class);
        $progress = app(ChangeQuoteRequestStatus::class);
        $message = app(PostQuoteMessage::class);

        // Settled history first: enquiries that were quoted and decided.
        for ($week = 10; $week >= 1; $week--) {
            foreach (range(1, $data->between(1, 2)) as $ignored) {
                $askedAt = $data->at(-(($week * 7) + $data->between(1, 6)), sprintf('%02d:%02d', $data->between(8, 17), $data->oneOf([10, 35, 50])));
                $customer = $customers[$data->between(0, count($customers) - 1)];

                $request = $data->asOf($askedAt, fn (): QuoteRequest => $create($enquiry, [
                    'customer_id' => $customer->getKey(),
                    'parameters' => $this->quoteParameters($data),
                ]));

                $quotedAt = $askedAt->copy()->addDays($data->between(1, 2))->setTime(16, 20);

                $data->asOf($quotedAt, fn () => $submit(
                    $request,
                    $data->between(8, 45) * 10_000 * 100,
                    'HUF',
                    $quotedAt->copy()->addDays(14),
                    'Az ajánlat a munkadíjra és a beépítendő alkatrészekre vonatkozik, 14 napig érvényes.',
                    $admin,
                ));

                $decidedAt = $quotedAt->copy()->addDays($data->between(1, 5))->setTime(9, 15);

                // A workshop loses some quotes to the shop down the road.
                $data->between(1, 3) === 1
                    ? $data->asOf($decidedAt, fn () => $reject($request, $admin))
                    : $data->asOf($decidedAt, fn () => $accept($request, $admin));
            }
        }

        // ...and the live board: one enquiry at each open stage, fixed rather
        // than drawn, because "every status visible" must not depend on dice.
        $this->seedOpenEnquiries($admin, $enquiry, $customers, $data, $create, $submit, $progress, $message);
    }

    /**
     * @param  list<Customer>  $customers
     */
    private function seedOpenEnquiries(
        User $admin,
        Service $enquiry,
        array $customers,
        DemoDataFactory $data,
        CreateQuoteRequest $create,
        SubmitQuote $submit,
        ChangeQuoteRequestStatus $progress,
        PostQuoteMessage $message,
    ): void {
        // Untouched: came in yesterday evening.
        $data->asOf($data->at(-1, '17:40'), fn (): QuoteRequest => $create($enquiry, [
            'customer_id' => $customers[3]->getKey(),
            'parameters' => $this->quoteParameters($data),
        ]));

        // In progress, with a question waiting on the customer — the clearest
        // thing to have on the messaging tab.
        $askedAt = $data->at(-2, '14:10');
        $inProgress = $data->asOf($askedAt, fn (): QuoteRequest => $create($enquiry, [
            'customer_id' => $customers[4]->getKey(),
            'parameters' => $this->quoteParameters($data),
        ]));

        $data->asOf($data->at(-1, '08:30'), fn () => $progress($inProgress, QuoteRequestStatus::InProgress, $admin));
        $data->asOf($data->at(-1, '08:35'), fn () => $message(
            $inProgress,
            'Köszönjük a megkeresést! Tud küldeni egy fotót a hibáról, illetve tudja, mikor volt utoljára szervizelve az autó?',
            $admin,
        ));
        $data->asOf($data->at(-1, '12:15'), fn () => $message(
            $inProgress,
            'Persze, holnap csatolom a képet. Legutóbb tavaly tavasszal volt szervizen, azóta kb. 18 000 km-t ment.',
            $customers[4],
        ));

        // Quoted and waiting on an answer — with the offer still valid, because
        // an expired quote on the demo's front page is a dead end.
        $quotedAsk = $data->at(-3, '09:50');
        $quoted = $data->asOf($quotedAsk, fn (): QuoteRequest => $create($enquiry, [
            'customer_id' => $customers[5]->getKey(),
            'parameters' => $this->quoteParameters($data),
        ]));

        $data->asOf($data->at(-1, '15:05'), fn () => $submit(
            $quoted,
            285_000 * 100,
            'HUF',
            $data->today()->copy()->addDays(12),
            'Vezérműszíj-készlet cseréje vízpumpával, munkadíjjal együtt. Az ajánlat 12 napig érvényes.',
            $admin,
        ));
    }

    /**
     * @return array<string, string>
     */
    private function quoteParameters(DemoDataFactory $data): array
    {
        return array_combine(self::QUOTE_FIELDS, [
            $data->oneOf(self::PLATE_LETTERS).'-'.$data->between(100, 999),
            $data->oneOf(self::CARS),
            (string) $data->between(2008, 2022),
            number_format($data->between(45, 320) * 1_000, 0, ',', ' ').' km',
            $data->oneOf(self::FAULTS),
            $data->oneOf(['Amint lehet', 'Jövő héten', 'Két héten belül', 'Rugalmas vagyok']),
        ]);
    }

    /**
     * The workshop's own voice on the confirmation mail: what to bring, where to
     * leave the key, and the price caveat said once more — because that is the
     * question the counter gets asked most.
     */
    private function seedTemplate(): void
    {
        MessageTemplate::query()->create([
            'key' => NotificationType::BookingConfirmed,
            'channel' => NotificationChannel::Email,
            'locale' => 'hu',
            'subject' => 'Foglalásod megvan — Csavarkulcs Autószerviz',
            'body' => "Kedves {{customer_name}}!\n\n"
                ."Az időpontodat rögzítettük:\n\n"
                ."• Munka: {{service_name}}\n"
                ."• Időpont: {{booking_date}} {{booking_time}}\n"
                ."• Szerelő: {{staff_name}}\n\n"
                .'Az autót a foglalt időpontra hozd be, a kulcsot a pultnál add le. '
                .'A munkadíj alkatrész nélkül értendő — ha menet közben kiderül, hogy alkatrész is kell, '
                ."előtte felhívunk.\n\n"
                .'Csavarkulcs Autószerviz',
            'enabled' => true,
        ]);
    }

    /** The offset of the next weekday at or after `$offset` days from today. */
    private function nextWeekday(int $offset, DemoDataFactory $data): int
    {
        while ($data->today()->copy()->addDays($offset)->isWeekend()) {
            $offset++;
        }

        return $offset;
    }

    /** The same, backwards: the offset of the nearest weekday at least `$offset` days ago. */
    private function nextWeekdayBack(int $offset, DemoDataFactory $data): int
    {
        while ($data->today()->copy()->subDays($offset)->isWeekend()) {
            $offset++;
        }

        return $offset;
    }
}
