<?php

namespace App\Models;

use App\Enums\BookingMode;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant service in one of the five booking modes (docs/02, docs/04). The
 * mode-specific columns are only meaningful for their mode; per-mode free-form
 * config lives in `settings`. Tenant-isolated via BelongsToTenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $category_id
 * @property string $name
 * @property string|null $description
 * @property BookingMode $booking_mode
 * @property int|null $duration_minutes
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property int $price_minor
 * @property string $currency
 * @property int|null $capacity
 * @property bool $requires_staff
 * @property bool $requires_room
 * @property bool $requires_approval
 * @property bool $waitlist_enabled
 * @property bool $online_payment_required
 * @property array<string, mixed>|null $settings
 * @property bool $active
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * tenant_id is stamped by BelongsToTenant; the pivots are synced explicitly
     * in the Action layer, never mass-assigned here.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'description',
        'booking_mode',
        'duration_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'price_minor',
        'currency',
        'capacity',
        'requires_staff',
        'requires_room',
        'requires_approval',
        'waitlist_enabled',
        'online_payment_required',
        'settings',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'booking_mode' => BookingMode::class,
            'duration_minutes' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'price_minor' => 'integer',
            'capacity' => 'integer',
            'requires_staff' => 'boolean',
            'requires_room' => 'boolean',
            'requires_approval' => 'boolean',
            'waitlist_enabled' => 'boolean',
            'online_payment_required' => 'boolean',
            'settings' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    /**
     * Staff who may provide this service (docs/02 service_staff).
     *
     * @return BelongsToMany<Staff, $this>
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'service_staff');
    }

    /**
     * Rooms/resources where this service may be provided (docs/02 service_rooms).
     *
     * @return BelongsToMany<Room, $this>
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'service_rooms');
    }

    /**
     * The fulfilment type for a no_time_slot service (docs/04 §1): digital /
     * manual / downloadable. Lives in the free-form `settings` json (validated by
     * ServiceRequest); null when unset or not a no_time_slot service.
     */
    public function fulfillmentType(): ?string
    {
        $value = $this->settings['fulfillment_type'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The customer-facing form fields a quote_request service asks for (docs/04
     * §6), stored in `settings.quote_fields`. Empty when unset.
     *
     * @return list<string>
     */
    public function quoteFields(): array
    {
        $fields = $this->settings['quote_fields'] ?? [];

        return is_array($fields) ? array_values(array_filter($fields, 'is_string')) : [];
    }

    /**
     * The free-range rental duration bounds (docs/04 §4), or null for a fixed-duration
     * or non-rental service. Both bounds come from the `settings` json.
     *
     * @return array{min: int, max: int}|null
     */
    public function rentalDurationBounds(): ?array
    {
        if ($this->booking_mode !== BookingMode::ResourceRental || $this->duration_minutes !== null) {
            return null;
        }

        $min = $this->settings['min_duration_minutes'] ?? null;
        $max = $this->settings['max_duration_minutes'] ?? null;

        if ($min === null || $max === null) {
            return null;
        }

        return ['min' => (int) $min, 'max' => (int) $max];
    }

    /**
     * Whether the service has upcoming bookings that block hard deletion (it must
     * be inactivated instead). The bookings table arrives with the booking engine
     * (M3); until then a service can never have a booking, so this is false.
     */
    public function hasFutureBookings(): bool
    {
        if (! Schema::hasTable('bookings')) {
            return false;
        }

        // Status literals mirror docs/04; M3 replaces them with the BookingStatus enum.
        return DB::table('bookings')
            ->where('service_id', $this->id)
            ->where('starts_at', '>', now())
            ->whereNotIn('status', ['canceled', 'no_show'])
            ->exists();
    }
}
