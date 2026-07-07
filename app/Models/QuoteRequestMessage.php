<?php

namespace App\Models;

use App\Actions\Quote\PostQuoteMessage;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\QuoteRequestMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single message in a quote request's conversation thread (docs/04 §6,
 * SLO-27). Appended through {@see PostQuoteMessage}.
 * Tenant-isolated via BelongsToTenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $quote_request_id
 * @property int|null $user_id
 * @property string $body
 */
class QuoteRequestMessage extends Model
{
    /** @use HasFactory<QuoteRequestMessageFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'quote_request_id',
        'user_id',
        'body',
    ];

    /**
     * @return BelongsTo<QuoteRequest, $this>
     */
    public function quoteRequest(): BelongsTo
    {
        return $this->belongsTo(QuoteRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
