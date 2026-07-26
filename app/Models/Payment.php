<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One customer checkout attempt on a booking (docs/02, SLO-130). The tenant's own
 * revenue — unrelated to the slot4u commission ledger, which bills on the booking
 * list price whether or not it was paid online (docs/10 §3).
 *
 * `status` and `paid_at` are written by the Action layer only (the gateway callback
 * or the expiry sweep), never mass-assigned. Tenant-isolated via BelongsToTenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property PaymentProvider $provider
 * @property string|null $provider_ref
 * @property int $amount_minor
 * @property string $currency
 * @property PaymentStatus $status
 * @property Carbon|null $paid_at
 * @property array<string, mixed>|null $payload
 */
class Payment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'booking_id',
        'provider',
        'provider_ref',
        'amount_minor',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'paid_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * A fresh attempt is open until the gateway reports back.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => PaymentStatus::Pending->value,
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Money returned against this payment (SLO-131). Several partial refunds may
     * stack; only a refused one does not count against the settled amount.
     *
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
