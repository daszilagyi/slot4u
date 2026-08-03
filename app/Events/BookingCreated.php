<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A booking was created (docs/04). Listeners (confirmation email, statistics) hook
 * in, and the event also broadcasts to the tenant's private admin channel so a new
 * booking pops up live on the dashboard (docs/01 Realtime, SLO-117). The event is
 * dispatched from the booking engine, keeping it event-driven end to end.
 */
class BookingCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  bool  $notifyCustomer  whether the customer should be mailed about this
     *                                booking (docs/04 §2, SLO-44). Only an admin who
     *                                explicitly opted out of the notification while
     *                                moving a booking passes false — the broadcast to
     *                                the tenant's own staff is unaffected either way,
     *                                because the admin panel must still see the move.
     */
    public function __construct(
        public readonly Booking $booking,
        public readonly bool $notifyCustomer = true,
    ) {}

    /**
     * Private, per-tenant channel — only that tenant's staff may subscribe
     * (routes/channels.php), so the payload never crosses tenant boundaries.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('tenant.'.$this->booking->tenant_id.'.bookings');
    }

    public function broadcastAs(): string
    {
        return 'booking.created';
    }

    /**
     * A compact, safe summary for the live card — no internal notes or cross-tenant
     * data. Times go out as UTC ISO-8601; the dashboard renders them in the tenant
     * timezone (docs/01 §7). starts_at is null for the slot-less modes.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->booking->id,
            'code' => $this->booking->code,
            'customer_name' => $this->booking->customer?->name,
            'service_name' => $this->booking->service?->name,
            'starts_at' => $this->booking->starts_at?->toIso8601String(),
            'status' => $this->booking->status->value,
            'source' => $this->booking->source->value,
        ];
    }
}
