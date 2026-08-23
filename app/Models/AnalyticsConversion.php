<?php

namespace App\Models;

use App\Enums\ConversionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A server-side conversion event owed to an ad platform (SLO-173).
 *
 * Created when a public booking is made by a visitor who allowed marketing, and
 * sent when that booking becomes a sale. The row is the durable half of the
 * pair: the browser's Pixel event may never fire (adblock, ITP, a closed tab),
 * this one does not depend on the visitor still being there.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property string $provider
 * @property string $event_name
 * @property string $event_id the booking code — the browser event's `eventID` too
 * @property ConversionStatus $status
 * @property int $attempts
 * @property string|null $fbp cleared once sent
 * @property string|null $fbc cleared once sent
 * @property string|null $event_source_url cleared once sent
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 */
class AnalyticsConversion extends Model
{
    use BelongsToTenant;

    public const PROVIDER_META = 'meta';

    public const EVENT_PURCHASE = 'Purchase';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'booking_id',
        'provider',
        'event_name',
        'event_id',
        'status',
        'fbp',
        'fbc',
        'event_source_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConversionStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Forget the browser identifiers. Called after a successful send: they exist
     * to attribute one conversion, and once it is attributed they are personal
     * data with no remaining purpose.
     */
    public function forgetBrowserIdentifiers(): void
    {
        $this->forceFill([
            'fbp' => null,
            'fbc' => null,
            'event_source_url' => null,
        ]);
    }
}
