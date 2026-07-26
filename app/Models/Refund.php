<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefundStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money returned to a customer against a {@see Payment} (docs/02, SLO-131).
 * Recorded as a pending intent when the booking is cancelled and settled by the
 * queued gateway call. Tenant-isolated via BelongsToTenant.
 *
 * A refund never touches the commission ledger: slot4u bills on the booking's list
 * price whether or not the tenant handed the money back (docs/10 §3) — a
 * commission-free cancellation is decided by the 24h rule, not by the refund.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $payment_id
 * @property int $amount_minor
 * @property string $currency
 * @property RefundStatus $status
 * @property string|null $reason
 * @property string|null $provider_ref
 * @property Carbon|null $refunded_at
 */
class Refund extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_id',
        'amount_minor',
        'currency',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => RefundStatus::class,
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * A fresh refund is an obligation until the gateway confirms it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => RefundStatus::Pending->value,
    ];

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
