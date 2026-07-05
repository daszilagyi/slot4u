<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An announced occurrence of an event_based service (docs/02, docs/04 §3, SLO-20):
 * a group class / workshop with a fixed start/end and capacity. Recurring
 * announcements generate one row per week sharing a `series_id`. Tenant-isolated
 * via BelongsToTenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $service_id
 * @property int|null $staff_id
 * @property int|null $room_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $capacity
 * @property int $booked_count
 * @property bool $waitlist_enabled
 * @property EventStatus $status
 * @property array<string, mixed>|null $recurrence_rule
 * @property string|null $series_id
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * tenant_id is stamped by BelongsToTenant; series_id / booked_count / status
     * are managed by the Action layer, never mass-assigned from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'service_id',
        'staff_id',
        'room_id',
        'starts_at',
        'ends_at',
        'capacity',
        'waitlist_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'booked_count' => 'integer',
            'waitlist_enabled' => 'boolean',
            'status' => EventStatus::class,
            'recurrence_rule' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
