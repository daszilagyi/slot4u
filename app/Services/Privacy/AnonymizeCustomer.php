<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Actions\Waitlist\JoinWaitlist;
use App\Enums\AuditAction;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Erases one customer's personal data while leaving the tenant's business
 * record standing (SLO-159, GDPR art. 17).
 *
 * The shape of the operation is "overwrite, don't delete". Deleting the user row
 * would cascade through bookings and take the tenant's turnover history with it
 * — and turnover is the commission base (docs/10 §3.1), so an erasure would
 * silently rewrite what the tenant already owes slot4u. Every booking therefore
 * keeps its time, service, status and price; only the columns that identify a
 * person are overwritten.
 *
 * ⚠️ **Two things are deliberately left alone**, and both are exceptions to the
 * "personal data appears nowhere" rule rather than oversights:
 *
 * 1. **Issued invoices.** The `invoices` rows carry no contact columns, but the
 *    stored PDF does — and an issued invoice is an accounting record the tenant
 *    must keep for eight years (Szt. 169. §). Art. 17 (3) (b) exempts exactly
 *    this. The row and its PDF stay.
 * 2. **The audit trail.** `audit_logs` is the security record of what staff did,
 *    kept on a separate legal basis with its own retention window (SLO-160). No
 *    current {@see AuditAction} records customer contact data, and
 *    the erasure adds its own entry without copying the erased values into it.
 *
 * Runs in one transaction: a half-erased customer — say, an anonymised profile
 * still reachable through a guest email on a booking — is worse than an erasure
 * that failed and can be retried.
 */
final class AnonymizeCustomer
{
    /** Recipient placeholder on a send-ledger row we keep for its shape, not its address. */
    public const REDACTED_RECIPIENT = 'redacted';

    public function __construct(private readonly AnonymizeUserProfile $profiles) {}

    /**
     * Erase `$user` within `$tenant`. Idempotent: a second call on an already
     * anonymised user is a no-op, so a retried job cannot double-apply.
     */
    public function erase(User $user, Tenant $tenant): void
    {
        if ($user->anonymized_at !== null) {
            return;
        }

        // Captured before the profile is overwritten — the ledger and the guest
        // columns are matched by the *old* address, which is about to be gone.
        $originalEmail = $user->email;
        $originalPhone = $user->phone;

        DB::transaction(function () use ($user, $tenant, $originalEmail, $originalPhone): void {
            $this->eraseBookings($user, $tenant, $originalEmail);
            $this->eraseQuoteRequests($user, $tenant, $originalEmail);
            $this->eraseWaitlistEntries($user, $tenant);
            $this->redactNotificationLog($tenant, $originalEmail, $originalPhone);
            $this->eraseProfile($user, $tenant);
        });
    }

    /**
     * Bookings keep everything that makes them a business record and lose
     * everything that makes them a person: the guest contact columns and the
     * free-text note, which is where a receptionist writes "allergic to X".
     */
    private function eraseBookings(User $user, Tenant $tenant, ?string $originalEmail): void
    {
        Booking::query()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($query) => $this->matchesSubject($query, $user, $originalEmail))
            ->update([
                'guest_name' => null,
                'guest_email' => null,
                'guest_phone' => null,
                'notes' => null,
                // `cancel_reason` and `reject_reason` are free text a staff
                // member wrote about this booking; treat them like notes.
                'cancel_reason' => null,
                'reject_reason' => null,
            ]);
    }

    /**
     * Quote requests lose the guest columns, the tenant's internal notes, and
     * the customer's own messages. `parameters` is the answered form (party
     * size, dates) and could hold anything the tenant asked for, so it goes too.
     */
    private function eraseQuoteRequests(User $user, Tenant $tenant, ?string $originalEmail): void
    {
        $requestIds = QuoteRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($query) => $this->matchesSubject($query, $user, $originalEmail))
            ->pluck('id');

        if ($requestIds->isEmpty()) {
            return;
        }

        QuoteRequest::query()
            ->whereIn('id', $requestIds)
            ->update([
                'guest_name' => null,
                'guest_email' => null,
                'guest_phone' => null,
                'internal_notes' => null,
                'parameters' => null,
            ]);

        // The thread structure survives so the tenant's own replies still make
        // sense as a record; the customer's words do not.
        QuoteRequestMessage::query()
            ->whereIn('quote_request_id', $requestIds)
            ->where('user_id', $user->id)
            ->update(['body' => $this->placeholder('erased_message', $tenant)]);
    }

    /**
     * Waitlist places are deleted, not anonymised: a place is a live promise to
     * contact someone, and the one thing an erased customer must never receive
     * is an offer email at a synthetic address.
     *
     * The gap this leaves in `position` is harmless — a join takes `max + 1` and
     * the queue is read with `orderBy('position')`, so neither depends on the
     * numbers being contiguous ({@see JoinWaitlist}).
     */
    private function eraseWaitlistEntries(User $user, Tenant $tenant): void
    {
        WaitlistEntry::query()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $user->id)
            ->delete();
    }

    /**
     * The send ledger keeps its rows — they carry the dedupe keys that make
     * notifications exactly-once, and deleting them could resurrect a send —
     * but loses the address it was sent to.
     */
    private function redactNotificationLog(Tenant $tenant, ?string $email, ?string $phone): void
    {
        $recipients = array_values(array_filter([$email, $phone]));

        if ($recipients === []) {
            return;
        }

        NotificationLog::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('recipient', $recipients)
            ->update(['recipient' => self::REDACTED_RECIPIENT]);
    }

    /**
     * The account itself — see {@see AnonymizeUserProfile}, which the
     * archived-tenant purge shares so the two can never forget a different set
     * of columns.
     */
    private function eraseProfile(User $user, Tenant $tenant): void
    {
        $this->profiles->erase($user, $tenant, 'erased_customer');
    }

    /**
     * A user-visible replacement string, resolved in the *tenant's* locale
     * rather than the request's (see {@see AnonymizeUserProfile::placeholder()}).
     */
    private function placeholder(string $key, Tenant $tenant): string
    {
        return $this->profiles->placeholder($key, $tenant);
    }

    /**
     * The same "this record is about that person" test the export uses: linked
     * to the account, or carrying their address as a guest.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function matchesSubject(Builder $query, User $user, ?string $originalEmail): void
    {
        $query->where('customer_id', $user->id);

        if ($originalEmail !== null) {
            $query->orWhere('guest_email', $originalEmail);
        }
    }
}
