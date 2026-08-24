<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\ScheduleVisibility;
use Database\Factories\ScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A recurring weekly availability band for a bookable resource (docs/02, SLO-19):
 * a staff member or a room is available on `day_of_week` from `start_time` to
 * `end_time`, optionally scoped to a location and a validity window.
 *
 * Times are wall-clock local values in the tenant timezone — they describe a
 * recurring pattern, not an instant. The availability engine (M3) materialises
 * them into concrete UTC slots per date and handles DST there (docs/01 §7).
 * Tenant-isolated via BelongsToTenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $schedulable_type
 * @property int $schedulable_id
 * @property int|null $location_id
 * @property int $day_of_week
 * @property string $start_time
 * @property string $end_time
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 */
class Schedule extends Model
{
    /** @use HasFactory<ScheduleFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * tenant_id is stamped by BelongsToTenant; the schedulable is associated
     * explicitly in the Action layer, never mass-assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'location_id',
        'day_of_week',
        'start_time',
        'end_time',
        'valid_from',
        'valid_until',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            // Keep dates as plain Y-m-d strings (no time/tz drift on a wall-clock date).
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
        ];
    }

    /**
     * The staff member or room this band belongs to (morph map: staff|room).
     *
     * @return MorphTo<Model, $this>
     */
    public function schedulable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Bind {schedule} route params through the actor's schedule scope so a
     * colleague's (or a room's) record 404s rather than 403s — the ownership
     * scope hides existence, exactly like a cross-tenant id (docs/01). The
     * BelongsToTenant global scope still supplies the tenant filter;
     * ScheduleVisibility adds the ownership one. The permission itself
     * (`schedule.manage`) is gated at the route and the policy.
     */
    public function resolveRouteBinding($value, $field = null): Schedule
    {
        $query = self::query()->where($field ?? $this->getRouteKeyName(), $value);

        $actor = Auth::user();
        if ($actor !== null) {
            ScheduleVisibility::apply($query, $actor);
        }

        return $query->firstOrFail();
    }
}
