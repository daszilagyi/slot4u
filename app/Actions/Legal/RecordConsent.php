<?php

declare(strict_types=1);

namespace App\Actions\Legal;

use App\Enums\ConsentContext;
use App\Models\LegalConsent;
use App\Models\LegalDocument;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Writes the evidence that someone accepted a document (SLO-161, GDPR art. 7(1)).
 *
 * ⚠️ Every acceptance is a new row — this never deduplicates. Consent is an act,
 * and a second act is evidence of itself: collapsing a customer's tenth booking
 * onto the acceptance they gave at their first would throw away the timestamp and
 * the circumstances of nine of them. The re-acceptance gate only asks whether a
 * row exists, so the extra rows cost it nothing.
 */
class RecordConsent
{
    /**
     * Record one acceptance.
     *
     * The subject is a user or an email — half the entry points are public and
     * produce no user row (docs/04 guest booking). When a user is given, their
     * address is not copied into `email` as well: two handles for one subject
     * would mean two things to keep in step, and the user row already carries it.
     */
    public function __invoke(
        LegalDocument $document,
        Tenant $tenant,
        ConsentContext $context,
        ?User $user = null,
        ?string $email = null,
        ?string $ipAddress = null,
    ): LegalConsent {
        return LegalConsent::create([
            'tenant_id' => $tenant->getKey(),
            'legal_document_id' => $document->getKey(),
            'user_id' => $user?->getKey(),
            'email' => $user === null ? $this->normalise($email) : null,
            'context' => $context,
            'accepted_at' => now(),
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Record an acceptance of every document in force at one entry point.
     *
     * The caller shows one tick box covering all of them, which is what the law
     * allows for a notice bundled with terms — but the record is per document, so
     * a later change to one of them can force re-acceptance of that one alone.
     *
     * @param  Collection<string, LegalDocument>|iterable<LegalDocument>  $documents
     * @return Collection<int, LegalConsent>
     */
    public function many(
        iterable $documents,
        Tenant $tenant,
        ConsentContext $context,
        ?User $user = null,
        ?string $email = null,
        ?string $ipAddress = null,
    ): Collection {
        $recorded = collect();

        foreach ($documents as $document) {
            $recorded->push($this($document, $tenant, $context, $user, $email, $ipAddress));
        }

        return $recorded;
    }

    private function normalise(?string $email): ?string
    {
        $email = is_string($email) ? trim($email) : null;

        return $email === null || $email === '' ? null : mb_strtolower($email);
    }
}
