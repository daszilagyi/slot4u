<?php

namespace App\Models;

use App\Actions\Message\SendMessage;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message in a tenant ↔ customer thread (SLO-36, docs/02). The thread is the
 * customer: every message carries `customer_id`, and `from_customer` says which
 * side wrote it. Written through {@see SendMessage}. Tenant-isolated via
 * BelongsToTenant.
 *
 * The quote-request conversation is NOT here — it keeps its own
 * `quote_request_messages` table (docs/02, SLO-27).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property int|null $sender_id
 * @property bool $from_customer
 * @property int|null $booking_id
 * @property string $body
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'sender_id',
        'from_customer',
        'booking_id',
        'body',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_customer' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
