<?php

namespace App\Services\Schedule;

use App\Enums\SchedulableType;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finds future bookings that a schedule change would leave outside the resource's
 * availability (docs/04 edge case: "staff munkarend-módosítás meglévő jövőbeli
 * foglalásokkal — figyelmeztetés, nem törlés"). The result is a warning list the
 * UI shows on save; nothing is deleted automatically.
 *
 * The bookings table arrives with the booking engine (M3). Until then a resource
 * can have no bookings, so this returns an empty list — the same forward-looking
 * guard used by Staff/Room/Service::hasFutureBookings().
 */
class FutureScheduleConflicts
{
    /**
     * @return list<array{id: int, code: string|null, starts_at: string}>
     */
    public function forSchedulable(string $schedulableType, int $schedulableId): array
    {
        if (! Schema::hasTable('bookings')) {
            return [];
        }

        // Column mapping mirrors docs/04: staff_id / room_id on the bookings table.
        $column = (SchedulableType::tryFrom($schedulableType) ?? SchedulableType::Staff)->bookingColumn();

        $tenant = app(TenantManager::class)->current();
        $timezone = $tenant !== null ? $tenant->timezone : (string) config('app.timezone');

        // This raw query bypasses the BelongsToTenant global scope, so scope it
        // to the current tenant explicitly.
        return DB::table('bookings')
            ->where('tenant_id', $tenant?->getKey())
            ->where($column, $schedulableId)
            ->where('starts_at', '>', now())
            ->whereNotIn('status', ['canceled', 'no_show'])
            ->orderBy('starts_at')
            ->limit(50)
            ->get(['id', 'code', 'starts_at'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => $row->code,
                // Stored in UTC; the admin reads it in the tenant's time (docs/01 §7).
                // The raw column went out as-is and showed a Budapest admin a
                // booking two hours early in summer (SLO-81).
                'starts_at' => Carbon::parse((string) $row->starts_at, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i'),
            ])
            ->all();
    }
}
