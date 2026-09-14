<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Enums\BookingMode;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Services\Booking\AvailabilityService;
use App\Settings\TenantLanding;
use Illuminate\Support\Carbon;

/**
 * The live half of the glam landing (SLO-241, docs/26): the team cards and
 * "today's free times", built from the tenant's real staff and real schedule.
 *
 * The landing content only NAMES who and what to feature. A name that no longer
 * matches an active staff member or service simply drops out — the page never
 * shows a person who has left, or offers a time for a service that is gone.
 */
final class GlamLandingData
{
    /** How many team cards the page draws beside the "anyone" card. */
    public const TEAM_CARDS = 3;

    /** How many free start times the quick-booking strip offers. */
    public const QUICK_TIMES = 4;

    /** How far ahead the strip looks for a day that still has room. */
    public const QUICK_DAYS = 7;

    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * @return array{team: list<array{id: int, name: string, title: string|null, skills: string|null, photo: string|null}>, quick: array<string, mixed>|null}
     */
    public function for(Tenant $tenant, TenantLanding $landing): array
    {
        return [
            'team' => $this->team($landing),
            'quick' => $this->quick($tenant, $landing),
        ];
    }

    /**
     * @return list<array{id: int, name: string, title: string|null, skills: string|null, photo: string|null}>
     */
    private function team(TenantLanding $landing): array
    {
        if ($landing->team === []) {
            return [];
        }

        $staff = Staff::query()
            ->where('active', true)
            ->whereIn('name', array_column($landing->team, 'name'))
            ->get(['id', 'name', 'title'])
            ->keyBy('name');

        $cards = [];

        foreach ($landing->team as $member) {
            $record = $staff->get($member['name']);

            if ($record === null) {
                continue;
            }

            $cards[] = [
                'id' => $record->id,
                'name' => $record->name,
                'title' => $record->title,
                'skills' => $member['skills'],
                'photo' => $member['photo'],
            ];
        }

        return array_slice($cards, 0, self::TEAM_CARDS);
    }

    /**
     * The first day, from today, that still has a free start time for the
     * featured quick service, with its first few times.
     *
     * @return array{service_id: int, service_name: string, duration_minutes: int|null, date: string, is_today: bool, is_tomorrow: bool, times: list<string>}|null
     */
    private function quick(Tenant $tenant, TenantLanding $landing): ?array
    {
        if ($landing->quickService === null) {
            return null;
        }

        $service = Service::query()
            ->where('active', true)
            ->where('name', $landing->quickService)
            ->where('booking_mode', BookingMode::DurationBased->value)
            ->first();

        if ($service === null) {
            return null;
        }

        $now = Carbon::now();
        $today = $now->copy()->timezone($tenant->timezone)->startOfDay();
        $slots = $this->availability->slotsForRange($service, $today, $today->copy()->addDays(self::QUICK_DAYS - 1));

        $days = [];

        foreach ($slots as $slot) {
            if ($slot->start->lte($now)) {
                continue;
            }

            $local = $slot->start->copy()->timezone($tenant->timezone);
            $days[$local->toDateString()][$local->format('H:i')] = true;
        }

        ksort($days);
        $date = array_key_first($days);

        if ($date === null) {
            return null;
        }

        $times = array_keys($days[$date]);
        sort($times);

        return [
            'service_id' => $service->id,
            'service_name' => $service->name,
            'duration_minutes' => $service->duration_minutes,
            'date' => $date,
            'is_today' => $date === $today->toDateString(),
            'is_tomorrow' => $date === $today->copy()->addDay()->toDateString(),
            'times' => array_slice($times, 0, self::QUICK_TIMES),
        ];
    }
}
