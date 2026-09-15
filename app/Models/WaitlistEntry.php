<?php

namespace App\Models;

use App\Enums\WaitlistStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasGuestContact;
use App\Models\Concerns\HasPublicCode;
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
 * The waiter is either a customer account or an account-less guest whose
 * contact details live on the entry (SLO-228, as on bookings — SLO-128).
 *
 * @property int $id
 * @property string $code the public confirmation code (SLO-103), `/waitlisted/{code}`
 * @property int $tenant_id
 * @property int|null $event_id
 * @property int|null $service_id
 * @property int|null $customer_id
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property int $party_size
 * @property int $position
 * @property WaitlistStatus $status
 * @property Carbon|null $offered_until
 */
class WaitlistEntry extends Model
{
    /** @use HasFactory<WaitlistEntryFactory> */
    use BelongsToTenant, HasFactory, HasGuestContact, HasPublicCode;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'service_id',
        'customer_id',
        'guest_name',
        'guest_email',
        'guest_phone',
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
