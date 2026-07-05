<?php

namespace App\Services\Schedule;

use App\Tenancy\TenantManager;
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

        // M3 refines this to "bookings no longer covered by the new availability".
        // Column mapping mirrors docs/04: staff_id / room_id on the bookings table.
        $column = $schedulableType === 'room' ? 'room_id' : 'staff_id';

        // This raw query bypasses the BelongsToTenant global scope, so scope it
        // to the current tenant explicitly (defense-in-depth for the M3 engine).
        return DB::table('bookings')
            ->where('tenant_id', app(TenantManager::class)->id())
            ->where($column, $schedulableId)
            ->where('starts_at', '>', now())
            ->whereNotIn('status', ['canceled', 'no_show'])
            ->orderBy('starts_at')
            ->limit(50)
            ->get(['id', 'code', 'starts_at'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => $row->code,
                'starts_at' => (string) $row->starts_at,
            ])
            ->all();
    }
}
