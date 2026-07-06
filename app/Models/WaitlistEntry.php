<?php

namespace App\Models;

use App\Enums\WaitlistStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Booking\WaitlistService;
use Database\Factories\WaitlistEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer's place in the FIFO waitlist for a full event occurrence (docs/02,
 * docs/04 §3, SLO-25). `position`/`status`/`offered_until` are driven by the
 * action + {@see WaitlistService}, never mass-assigned from
 * request input. Tenant-isolated via BelongsToTenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $event_id
 * @property int|null $service_id
 * @property int $customer_id
 * @property int $party_size
 * @property int $position
 * @property WaitlistStatus $status
 * @property Carbon|null $offered_until
 */
class WaitlistEntry extends Model
{
    /** @use HasFactory<WaitlistEntryFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'service_id',
        'customer_id',
        'party_size',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'party_size' => 'integer',
            'position' => 'integer',
            'status' => WaitlistStatus::class,
            'offered_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
