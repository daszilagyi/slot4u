<?php

namespace App\Models;

use App\Enums\ScheduleExceptionType;
use App\Models\Concerns\BelongsToTenant;
use App\Support\ScheduleVisibility;
use Database\Factories\ScheduleExceptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A date-specific override to a resource's weekly schedule (docs/02, SLO-19):
 * a closure (holiday, leave) or an extra opening on a single calendar date.
 * Null start/end time means the whole day. Tenant-isolated via BelongsToTenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $schedulable_type
 * @property int $schedulable_id
 * @property Carbon $date
 * @property string|null $start_time
 * @property string|null $end_time
 * @property ScheduleExceptionType $type
 * @property string|null $note
 */
class ScheduleException extends Model
{
    /** @use HasFactory<ScheduleExceptionFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'start_time',
        'end_time',
        'type',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'type' => ScheduleExceptionType::class,
        ];
    }

    /**
     * The staff member or room this exception belongs to (morph map: staff|room).
     *
     * @return MorphTo<Model, $this>
     */
    public function schedulable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Bind {exception} route params through the actor's schedule scope so a
     * colleague's (or a room's) record 404s rather than 403s — the ownership
     * scope hides existence, exactly like a cross-tenant id (docs/01). The
     * BelongsToTenant global scope still supplies the tenant filter;
     * ScheduleVisibility adds the ownership one. The permission itself
     * (`schedule.manage`) is gated at the route and the policy.
     */
    public function resolveRouteBinding($value, $field = null): ScheduleException
    {
        $query = self::query()->where($field ?? $this->getRouteKeyName(), $value);

        $actor = Auth::user();
        if ($actor !== null) {
            ScheduleVisibility::apply($query, $actor);
        }

        return $query->firstOrFail();
    }
}
