<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Booking;
use App\Models\Tenant;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\GuestRecipient;
use App\Services\Notification\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Sends one real customer-facing email to an arbitrary address so the domain's
 * deliverability can be scored from the outside (SLO-169,
 * docs/17-monitoring-es-riasztas.md §9).
 *
 * Why a command rather than a tinker one-liner on prod: the score is only worth
 * anything if it is measured on a message the system actually sends — same
 * mailer, same From/Reply-To, same rendered HTML, same tenant template. The
 * obvious way to produce one by hand is to make a booking in production, which
 * writes customer rows and fires the whole event chain for a test. This builds an
 * **unsaved** Booking instead and sends the notification directly, so the message
 * on the wire is the genuine article while nothing at all is persisted: no
 * booking, no customer, and no `notifications_log` claim (that row is created by
 * {@see Notifier}, which this path deliberately
 * bypasses).
 *
 * Sent with `sendNow` on purpose. The notification is ShouldQueue, and on the
 * host the queue is drained by a once-a-minute cron — a delivery test whose
 * failure surfaces a minute later in a worker log, rather than in this command's
 * exit code, is not a test you can stand in front of and read.
 */
class MailDeliverabilityTest extends Command
{
    protected $signature = 'mail:deliverability-test
        {recipient : Address to send to — for a score, the throwaway address from mail-tester.com}
        {--tenant= : Slug of the tenant whose branding and template should render (default: the first operational tenant)}';

    protected $description = 'Send one genuine booking-confirmation email to an address, to measure SPF/DKIM/DMARC and spam score';

    public function handle(): int
    {
        $recipient = (string) $this->argument('recipient');

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Not an email address: '.$recipient);

            return self::FAILURE;
        }

        $tenant = $this->resolveTenant();

        if ($tenant === null) {
            $this->error($this->option('tenant') !== null
                ? 'No operational tenant with slug: '.$this->option('tenant')
                : 'No operational tenant to send as — the mail renders in a tenant\'s name.');

            return self::FAILURE;
        }

        Notification::sendNow(
            new GuestRecipient($recipient, __('app.mail.deliverability_test.recipient_name')),
            new BookingConfirmedNotification($this->sampleBooking(), $tenant),
        );

        $this->info('Sent to '.$recipient.'.');
        $this->line('  tenant   '.$tenant->slug);
        $this->line('  mailer   '.config('mail.default').' via '.config('mail.mailers.smtp.host'));
        $this->line('  from     '.config('mail.from.address'));
        $this->line('Nothing was persisted. Read the score on the mail-tester page of that address.');

        return self::SUCCESS;
    }

    /**
     * The tenant to speak for. Archived tenants are soft-deleted and suspended
     * ones are not sending mail in the first place, so neither may stand in for
     * the platform in a measurement of what customers actually receive.
     */
    private function resolveTenant(): ?Tenant
    {
        $slug = $this->option('tenant');

        return Tenant::query()
            ->when(is_string($slug) && $slug !== '', fn ($query) => $query->where('slug', $slug))
            ->whereIn('status', array_map(
                fn (TenantStatus $status): string => $status->value,
                array_filter(TenantStatus::cases(), fn (TenantStatus $status): bool => $status->isOperational()),
            ))
            ->orderBy('id')
            ->first();
    }

    /**
     * A booking that exists only for the duration of this send. It is never saved,
     * so `code` is assigned here rather than by the model's creating hook — and it
     * is deliberately a readable marker instead of a real code: the CTA in the
     * mail must not resolve to somebody's actual booking.
     */
    private function sampleBooking(): Booking
    {
        $booking = new Booking([
            'starts_at' => Carbon::now()->addDay(),
        ]);

        $booking->code = 'MAILTEST';

        return $booking;
    }
}
