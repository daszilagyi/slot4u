<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Enums\AuditAction;
use App\Enums\TenantStatus;
use App\Models\AnalyticsConversion;
use App\Models\Booking;
use App\Models\Location;
use App\Models\NotificationLog;
use App\Models\PrivacyRequest;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestMessage;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Audit\AuditLogger;
use App\Settings\TenantSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Erases an archived tenant's personal data once its grace period has run out
 * (SLO-160, docs/19 §7).
 *
 * ## Anonymise, not delete — and why
 *
 * The obvious reading of "delete the tenant after 90 days" is a hard delete, and
 * it is the wrong one. The `tenants` row cascades to bookings, and the bookings
 * carry the turnover that the platform's commission is computed from
 * (docs/10 §3.1). Hard-deleting a tenant would therefore retroactively rewrite
 * slot4u's own revenue history and orphan the commission invoices it already
 * issued to that tenant — invoices both parties must keep for eight years
 * (Szt. 169. §). GDPR art. 17 (3) (b) exempts exactly that duty.
 *
 * So the purge keeps the *skeleton* — the tenant row, its bookings' times,
 * prices and statuses, its issued invoices and their PDFs — and removes
 * everything about a **person**: names, addresses, phone numbers, free text and
 * the credentials that would let anyone back in. This is the same shape as the
 * single-customer erasure ({@see AnonymizeCustomer}), applied tenant-wide.
 *
 * ## Why it is tenant-wide rather than a loop over customers
 *
 * A guest booking made by someone who never held an account is matched to no
 * user at all. Looping over `users` would leave it standing. Every step here is
 * therefore a bulk statement over `tenant_id`, and the per-user pass only
 * anonymises the account rows themselves.
 *
 * ## Concurrency
 *
 * The whole purge runs in one transaction that opens by taking a row lock on
 * the tenant and re-checking every precondition. A superadmin restoring the
 * tenant at the same instant is a plain `UPDATE tenants` and therefore blocks on
 * that lock: it either lands first (and we skip, because the tenant is no longer
 * trashed) or after (and finds an already-purged tenant). Killing the process
 * mid-way rolls the transaction back whole, so the next daily run simply redoes
 * it — which is also why {@see Tenant::$purged_at} is stamped last.
 */
final class PurgeTenant
{
    public function __construct(
        private readonly AnonymizeUserProfile $profiles,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Purge `$tenant` if it is still eligible. Returns false when it is not —
     * already purged, restored, or not yet past `$cutoff` — so the caller can
     * count what actually happened rather than what it attempted.
     *
     * `$cutoff` is the archive instant a tenant must predate to be purged; the
     * caller derives it from the configured grace window so that a single sweep
     * measures every tenant against the same moment.
     */
    public function purge(Tenant $tenant, Carbon $cutoff): bool
    {
        return (bool) DB::transaction(function () use ($tenant, $cutoff): bool {
            $locked = Tenant::withTrashed()->lockForUpdate()->find($tenant->getKey());

            if (! $this->isPurgeable($locked, $cutoff)) {
                return false;
            }

            /** @var Tenant $locked */
            $this->eraseAccounts($locked);
            $this->eraseBookings($locked);
            $this->eraseQuoteRequests($locked);
            $this->eraseWaitlist($locked);
            $this->eraseConversions($locked);
            $this->redactNotificationLog($locked);
            $this->erasePrivacyRequests($locked);
            $this->eraseStaffProfiles($locked);
            $this->eraseLocations($locked);
            $this->eraseTenantProfile($locked);

            // Stamped last: a run that dies before this point leaves the tenant
            // eligible, and every step above is an overwrite, so the retry is a
            // no-op where it already succeeded.
            $locked->forceFill(['purged_at' => Carbon::now()])->saveQuietly();

            // The trail records that the purge happened, never what it erased —
            // an audit row holding the purged values would defeat the purge.
            $this->audit->record(
                action: AuditAction::TenantPurged,
                auditable: $locked,
                newValues: ['purged_at' => $locked->purged_at?->toIso8601String()],
            );

            return true;
        });
    }

    /**
     * Every precondition, re-read under the row lock rather than trusted from
     * the caller's copy: the candidate list was built before the lock, and the
     * whole point of the lock is that the world may have moved since.
     */
    private function isPurgeable(?Tenant $tenant, Carbon $cutoff): bool
    {
        if ($tenant === null || $tenant->purged_at !== null) {
            return false;
        }

        // A restored tenant is a live business again. Both tests matter: the
        // status is what the superadmin panel shows, `deleted_at` is what
        // actually hides the tenant from IdentifyTenant, and a purge must not
        // proceed on a disagreement between them.
        if (! $tenant->trashed() || $tenant->status !== TenantStatus::Archived) {
            return false;
        }

        return $tenant->deleted_at !== null && $tenant->deleted_at->lessThanOrEqualTo($cutoff);
    }

    /**
     * The accounts. Everyone gets the same placeholder name: after a purge there
     * is no longer a meaningful distinction between the tenant's customers and
     * its staff — both are simply people the platform no longer knows.
     */
    private function eraseAccounts(Tenant $tenant): void
    {
        User::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereNull('anonymized_at')
            ->chunkById($this->chunk(), function ($users) use ($tenant): void {
                foreach ($users as $user) {
                    $this->profiles->erase($user, $tenant, 'erased_user');
                }
            });
    }

    /**
     * Bookings keep everything that makes them a business record — the time, the
     * service, the status and the price the commission was charged on — and lose
     * every column that describes a person.
     */
    private function eraseBookings(Tenant $tenant): void
    {
        Booking::query()
            ->where('tenant_id', $tenant->getKey())
            ->update([
                'guest_name' => null,
                'guest_email' => null,
                'guest_phone' => null,
                'notes' => null,
                'cancel_reason' => null,
                'reject_reason' => null,
            ]);
    }

    private function eraseQuoteRequests(Tenant $tenant): void
    {
        $requestIds = QuoteRequest::query()
            ->where('tenant_id', $tenant->getKey())
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
                // The answered form — whatever the tenant chose to ask for, so
                // it has to be treated as free text about a person.
                'parameters' => null,
            ]);

        // Unlike the single-customer erasure, which keeps the tenant's own
        // replies so the thread still reads as a record, nothing here needs to
        // stay readable: the tenant itself is gone.
        QuoteRequestMessage::query()
            ->where('tenant_id', $tenant->getKey())
            ->update(['body' => $this->profiles->placeholder('erased_message', $tenant)]);
    }

    /** Waitlist places are live promises to contact someone; they go entirely. */
    private function eraseWaitlist(Tenant $tenant): void
    {
        WaitlistEntry::query()->where('tenant_id', $tenant->getKey())->delete();
    }

    /**
     * Ad-conversion rows go entirely (SLO-173). They exist to report a sale to a
     * platform on this tenant's behalf, and there is no longer a tenant on whose
     * behalf to report it.
     */
    private function eraseConversions(Tenant $tenant): void
    {
        AnalyticsConversion::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->delete();
    }

    /**
     * The send ledger keeps its rows and loses the addresses — the rows carry
     * the dedupe keys that make notifications exactly-once, and deleting them
     * could resurrect a send to someone the platform just forgot.
     */
    private function redactNotificationLog(Tenant $tenant): void
    {
        NotificationLog::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('recipient', '!=', AnonymizeCustomer::REDACTED_RECIPIENT)
            ->update(['recipient' => AnonymizeCustomer::REDACTED_RECIPIENT]);
    }

    /**
     * Data-subject requests keep their type, status and timestamps — the proof
     * that the tenant handled them — and lose the note, which is free text an
     * admin wrote about a named person.
     */
    private function erasePrivacyRequests(Tenant $tenant): void
    {
        PrivacyRequest::query()
            ->where('tenant_id', $tenant->getKey())
            ->update(['resolution_note' => null]);
    }

    /**
     * Staff profiles are public-facing personal data: a name, a job title, a
     * biography and a photograph. The row stays so the bookings that reference
     * it keep their shape.
     */
    private function eraseStaffProfiles(Tenant $tenant): void
    {
        Staff::query()
            ->where('tenant_id', $tenant->getKey())
            ->update([
                'name' => $this->profiles->placeholder('erased_user', $tenant),
                'title' => null,
                'bio' => null,
                'photo' => null,
            ]);
    }

    /** A location's phone and street address identify the business's people. */
    private function eraseLocations(Tenant $tenant): void
    {
        Location::query()
            ->where('tenant_id', $tenant->getKey())
            ->update(['phone' => null, 'address' => null]);
    }

    /**
     * The tenant's own contact block and its invoicing credentials.
     *
     * `name` and `slug` stay: they identify the counterparty on commission
     * invoices slot4u must keep for eight years, and an invoice naming nobody is
     * not an invoice. The booking-rule keys in `settings` stay too — they are
     * configuration, they identify no one, and dropping them would leave the
     * JSON in a shape {@see TenantSettings::fromArray()} has to guess at.
     *
     * `invoicing` is cleared outright because it holds the tenant's invoicing
     * provider API key. A credential belonging to a company that has left has no
     * reason to sit in the database, encrypted or not.
     */
    private function eraseTenantProfile(Tenant $tenant): void
    {
        $settings = $tenant->settings ?? [];

        foreach (['description', 'email', 'phone', 'address_line', 'address_city', 'address_postal', 'opening_hours', 'social'] as $key) {
            unset($settings[$key]);
        }

        $tenant->forceFill([
            'settings' => $settings,
            'branding' => null,
            'invoicing' => null,
        ])->saveQuietly();
    }

    private function chunk(): int
    {
        return max(1, (int) config('privacy.chunk', 500));
    }
}
