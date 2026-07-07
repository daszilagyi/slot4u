<?php

namespace App\Models;

use App\Actions\Quote\ChangeQuoteRequestStatus;
use App\Enums\QuoteRequestStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\QuoteRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A quote request in the ask-first booking mode (docs/02, docs/04 §6, SLO-27).
 * `status`, the quoted price fields and `booking_id` are driven by the Action
 * layer ({@see ChangeQuoteRequestStatus} and friends), never
 * mass-assigned from request input. Tenant-isolated via BelongsToTenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $service_id
 * @property int|null $customer_id
 * @property QuoteRequestStatus $status
 * @property array<string, mixed>|null $parameters
 * @property int|null $price_minor
 * @property string|null $currency
 * @property Carbon|null $valid_until
 * @property string|null $internal_notes
 * @property int|null $booking_id
 */
class QuoteRequest extends Model
{
    /** @use HasFactory<QuoteRequestFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'service_id',
        'customer_id',
        'parameters',
        'internal_notes',
    ];

    /**
     * A new request starts in the `new` state (docs/04 §6).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => QuoteRequestStatus::New->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteRequestStatus::class,
            'parameters' => 'array',
            'price_minor' => 'integer',
            'valid_until' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * The booking generated when the request was accepted (docs/04 §6).
     *
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return HasMany<QuoteRequestMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(QuoteRequestMessage::class);
    }
}
