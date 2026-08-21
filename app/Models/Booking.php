<?php

namespace App\Models;

use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Events\BookingCreated;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasGuestContact;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A booking in any of the six modes (docs/02, docs/04) — the single row the whole
 * booking engine turns around. `booking_mode` is a snapshot taken at creation so a
 * later service mode change never rewrites history. Its lifecycle is the shared
 * {@see BookingStatus} state machine; every status lands in booking_status_history.
 * Tenant-isolated via BelongsToTenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property int|null $customer_id
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property int $service_id
 * @property BookingMode $booking_mode
 * @property int|null $staff_id
 * @property int|null $room_id
 * @property int|null $event_id
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property BookingStatus $status
 * @property int $party_size
 * @property int $price_minor
 * @property string $currency
 * @property string|null $notes
 * @property BookingSource $source
 * @property Carbon|null $canceled_at
 * @property string|null $cancel_reason
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $hold_expires_at
 * @property string|null $reject_reason
 * @property int|null $rescheduled_from_id
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use BelongsToTenant, HasFactory, HasGuestContact;

    /**
     * Transient (never persisted, never an attribute — it is a declared property, so
     * Eloquent's magic setter never sees it): whether the customer should be mailed
     * about this booking. The CreateBooking action sets it before the insert so the
     * `created` hook can carry it into {@see BookingCreated}, the same way
     * `rescheduled_from_id` has to be set before the insert (SLO-44, docs/04 §2).
     */
    public bool $notifyCustomer = true;

    /**
     * status, code, canceled_at, the approved_* fields, hold_expires_at,
     * reject_reason and rescheduled_from_id are managed by the Action layer
     * (ChangeBookingStatus / CreateBooking), never mass-assigned from request
     * input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'guest_name',
        'guest_email',
        'guest_phone',
        'wants_invoice',
        'billing_name',
        'billing_tax_number',
        'billing_country_code',
        'billing_post_code',
        'billing_city',
        'billing_address',
        'service_id',
        'booking_mode',
        'staff_id',
        'room_id',
        'event_id',
        'starts_at',
        'ends_at',
        'party_size',
        'price_minor',
        'currency',
        'notes',
        'source',
    ];

    /**
     * A non-approval booking defaults to confirmed (docs/04); the create flow
     * (SLO-24) overrides this with the mode's initial status when needed.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => BookingStatus::Confirmed->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'booking_mode' => BookingMode::class,
            'status' => BookingStatus::class,
            'source' => BookingSource::class,
            'party_size' => 'integer',
            'wants_invoice' => 'boolean',
            'price_minor' => 'integer',
            'canceled_at' => 'datetime',
            'approved_at' => 'datetime',
            'hold_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Assign a public, non-guessable code before insert (status defaults via
        // $attributes / the create flow).
        static::creating(function (Booking $booking): void {
            if (blank($booking->code)) {
                $booking->code = self::generateUniqueCode();
            }
        });

        // The initial status is the first history entry (from null); fire the
        // domain event so M5 listeners (email, Reverb) can hook in later.
        static::created(function (Booking $booking): void {
            $booking->statusHistory()->create([
                'from_status' => null,
                'to_status' => $booking->status->value,
                'actor_id' => Auth::id(),
            ]);

            BookingCreated::dispatch($booking, $booking->notifyCustomer);
        });
    }

    /** Code alphabet without visually ambiguous characters (no 0/O/1/I/L). */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * A short, non-guessable public code (no ambiguous characters), unique across
     * the platform since it appears in customer-facing URLs. Retries on the (very
     * unlikely) collision; the DB unique index is the final backstop.
     */
    public static function generateUniqueCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;

        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, $max)];
            }
        } while (self::withoutGlobalScopes()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Whether `$now` is inside the tenant's online cancellation deadline before
     * the booking starts (docs/04 §5): within the window, online cancellation is
     * refused (an admin may still cancel). Timeless bookings are never in-window.
     */
    public function isWithinCancellationDeadline(int $deadlineHours, ?Carbon $now = null): bool
    {
        if ($this->starts_at === null) {
            return false;
        }

        $now ??= Carbon::now();

        return $now->gte($this->starts_at->copy()->subHours($deadlineHours));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
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

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The (now canceled) booking this one replaces, when it was created by a
     * reschedule (docs/04 §2, SLO-109). Set by the CreateBooking action on the
     * reschedule path only — a fresh booking has none.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'rescheduled_from_id');
    }

    /**
     * @return HasMany<BookingStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class);
    }
}
