<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceProvider;
use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The invoice a TENANT issues to ITS customer for a settled payment (docs/02,
 * SLO-133) — not to be confused with {@see CommissionInvoice}, which is slot4u's
 * own monthly invoice to the tenant (docs/10 §4).
 *
 * One row per payment (unique `payment_id`), created pending and filled in by the
 * queued issuer. A full refund voids it in place (`status = storno`) so a payment
 * always has exactly one invoice row. Tenant-isolated via BelongsToTenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property int $payment_id
 * @property InvoiceProvider $provider
 * @property string|null $provider_ref
 * @property string|null $number
 * @property int $amount_minor
 * @property string $currency
 * @property InvoiceStatus $status
 * @property string|null $pdf_path
 * @property Carbon|null $issued_at
 * @property string|null $storno_number
 * @property string|null $storno_pdf_path
 * @property Carbon|null $stornoed_at
 * @property string|null $error
 */
class Invoice extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'booking_id',
        'payment_id',
        'provider',
        'amount_minor',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => InvoiceProvider::class,
            'status' => InvoiceStatus::class,
            'amount_minor' => 'integer',
            'issued_at' => 'datetime',
            'stornoed_at' => 'datetime',
        ];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => InvoiceStatus::Pending->value,
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * The document to hand out today: the storno once it exists, the original
     * otherwise. Null while nothing has been issued yet.
     */
    public function downloadablePath(): ?string
    {
        return $this->storno_pdf_path ?? $this->pdf_path;
    }
}
