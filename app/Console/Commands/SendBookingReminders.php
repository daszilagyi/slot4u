<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\NotificationType;
use App\Models\Booking;
use App\Notifications\BookingReminderNotification;
use App\Services\Notification\CustomerNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Reminds customers of the bookings starting within the next 24 hours (docs/04,
 * SLO-35/SLO-110). Scheduled hourly (routes/console.php).
 *
 * Tenant-less: it scans every tenant's bookings, and resolves each booking's own
 * tenant to decide whether (and in which language/timezone) to mail — see
 * {@see CustomerNotifier}.
 *
 * Exactly-once is the `notifications_log` claim (`booking:{id}:reminder_24h`), not
 * the schedule: an hourly run re-selects the same booking every hour until it
 * starts, and the dedup key makes every run after the first a no-op. That is also
 * what makes the job self-healing — a missed or failed run is simply caught by the
 * next one, instead of silently dropping the reminder.
 *
 * DST: the window is a fixed 24-hour duration measured on the stored UTC instants,
 * so it never gains or loses an hour when a tenant's wall clock shifts (docs/01 §7);
 * only the rendering of the time is tenant-local.
 */
class SendBookingReminders extends Command
{
    protected $signature = 'bookings:remind';

    protected $description = 'Email customers a reminder of their bookings starting within 24 hours.';

    public function handle(CustomerNotifier $notifier): int
    {
        $now = Carbon::now();
        $sent = 0;

        Booking::query()
            ->where('status', BookingStatus::Confirmed->value)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->copy()->addDay())
            // The mail names the service and greets the customer — load both per chunk
            // instead of per booking. (The tenant is memoised in CustomerNotifier: a
            // chunk is typically many bookings of a few tenants.)
            ->with(['service', 'customer'])
            // A day's worth of bookings across every tenant is unbounded — walk it in
            // chunks rather than holding it all in the worker's memory.
            ->chunkById(200, function ($bookings) use ($notifier, &$sent): void {
                foreach ($bookings as $booking) {
                    // Booked less than 24 hours ahead: the customer chose this slot
                    // minutes or hours ago and is not owed a "24h reminder" for it —
                    // their confirmation mail already carries the time.
                    if ($booking->created_at !== null && $booking->created_at->gt($booking->starts_at->copy()->subDay())) {
                        continue;
                    }

                    $tenant = $notifier->operationalTenant($booking);

                    if ($tenant === null) {
                        continue;
                    }

                    $log = $notifier->sendToCustomer(
                        tenant: $tenant,
                        type: NotificationType::Reminder24h,
                        dedupeKey: 'booking:'.$booking->getKey().':reminder_24h',
                        customer: $booking->customer,
                        notification: new BookingReminderNotification($booking, $tenant),
                    );

                    if ($log !== null) {
                        $sent++;
                    }
                }
            });

        $this->info("Sent {$sent} booking reminder(s).");

        return self::SUCCESS;
    }
}
