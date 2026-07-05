<?php

namespace App\Models;

use App\Enums\ScheduleExceptionType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ScheduleExceptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

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
}
