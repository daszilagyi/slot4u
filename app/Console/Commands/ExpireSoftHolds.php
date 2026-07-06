<?php

namespace App\Console\Commands;

use App\Actions\Booking\ChangeBookingStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Releases requested (approval-pending) bookings whose soft-hold window has lapsed
 * (docs/04 §5, SLO-26): each is canceled through {@see ChangeBookingStatus} so the
 * slot frees, history + events fire, and an event seat is returned (SLO-25).
 * Scheduled (routes/console.php); idempotent — a resolved request no longer holds.
 */
class ExpireSoftHolds extends Command
{
    protected $signature = 'bookings:expire-soft-holds';

    protected $description = 'Cancel approval-pending bookings whose soft hold has expired.';

    public function handle(ChangeBookingStatus $changeStatus): int
    {
        // Tenant-less: scans all tenants. hold_expires_at was stamped per tenant at
        // creation, so a single deadline comparison is correct everywhere.
        $expired = Booking::query()
            ->where('status', BookingStatus::Requested->value)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->get();

        foreach ($expired as $booking) {
            $changeStatus($booking, BookingStatus::Canceled, null, __('app.booking.reason.hold_expired'));
        }

        $this->info("Released {$expired->count()} expired soft hold(s).");

        return self::SUCCESS;
    }
}
