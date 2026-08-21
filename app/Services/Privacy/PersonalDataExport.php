<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\LegalConsent;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\PrivacyRequest;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Everything the app stores about one customer, as a plain array (SLO-159,
 * GDPR art. 15 (3): "a copy of the personal data undergoing processing").
 *
 * Scope is one customer *within one tenant*. The same person may hold accounts
 * at several tenants — those are separate controllers with separate records,
 * and merging them into one file would disclose to each tenant that the other
 * exists. Every query here therefore runs under the tenant global scope, and
 * the two models without one ({@see User}, {@see PrivacyRequest} is scoped, but
 * bookings reached by guest email are not implicitly narrowed) are filtered
 * explicitly.
 *
 * What is deliberately NOT in the file:
 * - the password hash and remember token (not "personal data undergoing
 *   processing" in any useful sense, and shipping a hash out of the system is a
 *   credential-stuffing gift);
 * - other people's data on shared records — a quote-request thread exports the
 *   customer's own messages plus the tenant's replies, because both are about
 *   the customer, but never another customer's row.
 */
final class PersonalDataExport
{
    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'subject' => $this->profile($user),
            'bookings' => $this->bookings($user),
            'quote_requests' => $this->quoteRequests($user),
            'waitlist_entries' => $this->waitlistEntries($user),
            'payments' => $this->payments($user),
            'invoices' => $this->invoices($user),
            'notifications' => $this->notifications($user),
            'privacy_requests' => $this->privacyRequests($user),
            'consents' => $this->consents($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'registered_at' => $user->created_at?->toIso8601String(),
            'anonymized_at' => $user->anonymized_at?->toIso8601String(),
        ];
    }

    /**
     * The customer's bookings, including the guest-made ones that carry their
     * email but were never linked to the account — those are their personal
     * data just the same, and art. 15 does not care which column holds it.
     *
     * @return list<array<string, mixed>>
     */
    private function bookings(User $user): array
    {
        return Booking::query()
            ->with(['service:id,name', 'staff:id,name', 'room:id,name', 'statusHistory'])
            ->where(fn ($query) => $this->matchesSubject($query, $user))
            ->orderBy('id')
            ->get()
            ->map(fn (Booking $booking): array => [
                'id' => $booking->id,
                'code' => $booking->code,
                'status' => $booking->status->value,
                'service' => $booking->service?->name,
                'staff' => $booking->staff?->name,
                'room' => $booking->room?->name,
                'starts_at' => $booking->starts_at?->toIso8601String(),
                'ends_at' => $booking->ends_at?->toIso8601String(),
                'party_size' => $booking->party_size,
                'price_minor' => $booking->price_minor,
                'currency' => $booking->currency,
                'notes' => $booking->notes,
                'guest_name' => $booking->guest_name,
                'guest_email' => $booking->guest_email,
                'guest_phone' => $booking->guest_phone,
                'source' => $booking->source->value,
                'created_at' => $booking->created_at?->toIso8601String(),
                'status_history' => $booking->statusHistory
                    ->map(fn ($entry): array => [
                        'from_status' => $entry->from_status?->value,
                        'to_status' => $entry->to_status->value,
                        'at' => $entry->created_at->toIso8601String(),
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quoteRequests(User $user): array
    {
        return QuoteRequest::query()
            ->with(['service:id,name', 'messages'])
            ->where(fn ($query) => $this->matchesSubject($query, $user))
            ->orderBy('id')
            ->get()
            ->map(fn (QuoteRequest $request): array => [
                'id' => $request->id,
                'status' => $request->status->value,
                'service' => $request->service?->name,
                'parameters' => $request->parameters,
                'guest_name' => $request->guest_name,
                'guest_email' => $request->guest_email,
                'guest_phone' => $request->guest_phone,
                'created_at' => $request->created_at?->toIso8601String(),
                // The whole thread: the customer's own messages and the tenant's
                // replies are both about the customer. `internal_notes` is not
                // here — it is the tenant's assessment, not the subject's data,
                // and art. 15 (4) protects it as another person's information.
                'messages' => $request->messages
                    ->map(fn ($message): array => [
                        'from_customer' => $message->user_id === $user->id,
                        'body' => $message->body,
                        'at' => $message->created_at?->toIso8601String(),
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function waitlistEntries(User $user): array
    {
        return WaitlistEntry::query()
            ->with(['service:id,name', 'event:id,starts_at'])
            ->where('customer_id', $user->id)
            ->orderBy('id')
            ->get()
            ->map(fn (WaitlistEntry $entry): array => [
                'id' => $entry->id,
                'status' => $entry->status->value,
                'service' => $entry->service?->name,
                // An event has no name of its own — it is an occurrence of the
                // service, identified by when it starts (docs/04 §3).
                'event_starts_at' => $entry->event?->starts_at->toIso8601String(),
                'party_size' => $entry->party_size,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payments(User $user): array
    {
        return Payment::query()
            ->with('refunds')
            ->whereIn('booking_id', $this->bookingIds($user))
            ->orderBy('id')
            ->get()
            ->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'booking_id' => $payment->booking_id,
                'provider' => $payment->provider->value,
                'status' => $payment->status->value,
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'refunds' => $payment->refunds
                    ->map(fn ($refund): array => [
                        'amount_minor' => $refund->amount_minor,
                        'currency' => $refund->currency,
                        'reason' => $refund->reason,
                        'at' => $refund->created_at?->toIso8601String(),
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Invoice metadata only — the PDF itself is the tenant's accounting record
     * and is downloadable through the members area on its own route.
     *
     * @return list<array<string, mixed>>
     */
    private function invoices(User $user): array
    {
        return Invoice::query()
            ->whereIn('booking_id', $this->bookingIds($user))
            ->orderBy('id')
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'booking_id' => $invoice->booking_id,
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'amount_minor' => $invoice->amount_minor,
                'currency' => $invoice->currency,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'storno_number' => $invoice->storno_number,
            ])
            ->all();
    }

    /**
     * What the tenant sent to this person and when — the send ledger is personal
     * data (it records that an address was contacted), and it is also the
     * customer's own evidence of what they were told.
     *
     * @return list<array<string, mixed>>
     */
    private function notifications(User $user): array
    {
        $recipients = array_values(array_filter([$user->email, $user->phone]));

        if ($recipients === []) {
            return [];
        }

        return NotificationLog::query()
            ->whereIn('recipient', $recipients)
            ->orderBy('id')
            ->get()
            ->map(fn (NotificationLog $log): array => [
                'type' => $log->type->value,
                'channel' => $log->channel,
                'recipient' => $log->recipient,
                'status' => $log->status->value,
                'sent_at' => $log->sent_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function privacyRequests(User $user): array
    {
        return PrivacyRequest::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->map(fn (PrivacyRequest $request): array => [
                'type' => $request->type->value,
                'status' => $request->status->value,
                'requested_at' => $request->created_at?->toIso8601String(),
                'resolved_at' => $request->resolved_at?->toIso8601String(),
                'resolution_note' => $request->resolution_note,
            ])
            ->all();
    }

    /**
     * What this person accepted, and which version of it (SLO-161).
     *
     * Part of an art. 15 copy because it is the subject's own record as much as
     * the controller's: the evidence used to justify processing their data is
     * data about them. Guest acceptances are matched by address, so someone who
     * booked without an account and later registered gets both halves of their
     * history rather than the tidier-looking half.
     *
     * @return list<array<string, mixed>>
     */
    private function consents(User $user): array
    {
        return LegalConsent::query()
            ->with('document:id,type,version,title')
            ->forSubject($user, $user->anonymized_at === null ? $user->email : null)
            ->orderBy('id')
            ->get()
            ->map(fn (LegalConsent $consent): array => [
                'document_type' => $consent->document?->type->value,
                'document_version' => $consent->document?->version,
                'document_title' => $consent->document?->title,
                'context' => $consent->context->value,
                'accepted_at' => $consent->accepted_at->toIso8601String(),
                'ip_address' => $consent->ip_address,
            ])
            ->all();
    }

    /**
     * Every booking id belonging to the subject — the join key for the records
     * (payments, invoices) that hold no contact data of their own.
     *
     * @return list<int>
     */
    private function bookingIds(User $user): array
    {
        return Booking::query()
            ->where(fn ($query) => $this->matchesSubject($query, $user))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * "This record is about that person": linked to the account, or carrying
     * their email as a guest. Applied as a nested where so it never widens an
     * outer query's other conditions.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function matchesSubject(Builder $query, User $user): void
    {
        $query->where('customer_id', $user->id);

        // An anonymised subject's placeholder address must not start matching
        // guest rows: it is synthetic and shared in shape by every erased user.
        if ($user->anonymized_at === null) {
            $query->orWhere('guest_email', $user->email);
        }
    }
}
