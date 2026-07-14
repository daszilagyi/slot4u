<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Concerns\RecordsDelivery;
use App\Notifications\Concerns\TracksDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms a booking to the customer once it reaches `confirmed` (SLO-108). The
 * mail renders in the tenant's locale and links to the public confirmation page
 * (`/booked/{code}`), whose code is the customer's access key. Queued so a slow
 * mail transport never blocks the booking request.
 */
class BookingConfirmedNotification extends Notification implements RecordsDelivery, ShouldQueue
{
    use Queueable, TracksDelivery;

    public function __construct(private readonly Booking $booking)
    {
        // Render in the tenant's locale regardless of the queue worker's locale.
        $this->locale = $this->booking->tenant->locale;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenant = $this->booking->tenant;

        $mail = (new MailMessage)
            ->subject(__('app.mail.booking_confirmed.subject', ['tenant' => $tenant->name]))
            ->greeting(__('app.mail.booking_confirmed.greeting', ['name' => $notifiable->name]))
            ->line(__('app.mail.booking_confirmed.intro', ['tenant' => $tenant->name]))
            ->line(__('app.mail.booking_confirmed.code', ['code' => $this->booking->code]));

        if ($this->booking->service !== null) {
            $mail->line(__('app.mail.booking_confirmed.service', ['service' => $this->booking->service->name]));
        }

        if ($this->booking->starts_at !== null) {
            $when = $this->booking->starts_at->copy()->timezone($tenant->timezone)->format('Y-m-d H:i');
            $mail->line(__('app.mail.booking_confirmed.when', ['when' => $when]));
        }

        return $mail
            ->action(__('app.mail.booking_confirmed.action'), $this->confirmationUrl())
            ->line(__('app.mail.booking_confirmed.outro'));
    }

    /**
     * The public confirmation page on the tenant subdomain (`/booked/{code}`).
     */
    private function confirmationUrl(): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $host = $this->booking->tenant->slug.'.'.config('tenancy.central_domain');

        return sprintf('%s://%s/booked/%s', $scheme, $host, $this->booking->code);
    }
}
