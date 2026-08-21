<?php

declare(strict_types=1);

namespace App\Services\Legal;

use App\Models\LegalConsent;
use App\Models\LegalDocument;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single place that answers "which documents are in force, and for whom"
 * (SLO-161).
 *
 * It exists as one object because {@see LegalDocument} deliberately carries no
 * tenant global scope (see the model): every read therefore has to choose
 * between the platform's documents and a tenant's, and a choice repeated in six
 * controllers is a choice that will eventually be made wrongly in one of them.
 */
class LegalDocumentRegistry
{
    /**
     * The platform's documents in force — what a company accepts when it signs
     * up for slot4u.
     *
     * @return Collection<string, LegalDocument> keyed by {@see LegalDocumentType} value
     */
    public function currentPlatform(): Collection
    {
        return $this->newest(
            LegalDocument::query()->platform()->effective()
        );
    }

    /**
     * One tenant's documents in force — what its customers accept.
     *
     * @return Collection<string, LegalDocument>
     */
    public function currentForTenant(Tenant $tenant): Collection
    {
        return $this->newest(
            LegalDocument::query()->ownedBy($tenant)->effective()
        );
    }

    /**
     * The documents in force for a scope: a tenant's, or the platform's when no
     * tenant is given (the central sign-up page).
     *
     * @return Collection<string, LegalDocument>
     */
    public function currentFor(?Tenant $tenant): Collection
    {
        return $tenant === null ? $this->currentPlatform() : $this->currentForTenant($tenant);
    }

    /**
     * Whether the ids a form came back with are exactly the ones in force.
     *
     * The check exists because a version can be published between rendering a
     * form and submitting it. Silently recording consent to the new text would
     * fabricate evidence — the person never saw it — and silently recording the
     * old one would leave them on a superseded version. Neither is acceptable in
     * the table whose entire purpose is to be true, so the submission is refused
     * and the form re-shown with the current text.
     *
     * @param  array<int, mixed>  $ids
     */
    public function isCurrentSet(?Tenant $tenant, array $ids): bool
    {
        $expected = $this->currentFor($tenant)->map->getKey()->sort()->values()->all();

        $given = collect($ids)
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()->sort()->values()->all();

        return $expected === $given;
    }

    /**
     * What this user is required to have accepted right now.
     *
     * A super-admin is slot4u's own staff, not slot4u's customer, so there is
     * nothing for them to accept — asking would be the platform contracting with
     * itself. Tenant staff accept the platform's documents (their company is the
     * party to that agreement); a customer accepts their tenant's, because the
     * tenant is the controller of their data (docs/19 §1).
     *
     * @return Collection<string, LegalDocument>
     */
    public function requiredFor(User $user): Collection
    {
        if ($user->isSuperAdmin()) {
            return collect();
        }

        if ($user->isStaff()) {
            return $this->currentPlatform();
        }

        $tenant = $user->tenant;

        return $tenant === null ? collect() : $this->currentForTenant($tenant);
    }

    /**
     * Of the required documents, those this user has not accepted.
     *
     * Matched on the document id, not on the type: that is what makes a new
     * version force a fresh acceptance while leaving the old record intact.
     *
     * @return Collection<string, LegalDocument>
     */
    public function outstandingFor(User $user): Collection
    {
        $required = $this->requiredFor($user);

        if ($required->isEmpty()) {
            return $required;
        }

        $accepted = LegalConsent::query()
            ->where('user_id', $user->getKey())
            ->whereIn('legal_document_id', $required->map->getKey()->all())
            ->pluck('legal_document_id')
            ->all();

        return $required->reject(
            fn (LegalDocument $document): bool => in_array($document->getKey(), $accepted, true)
        );
    }

    /**
     * The newest effective version of each type in the given scope.
     *
     * @param  Builder<LegalDocument>  $query
     * @return Collection<string, LegalDocument>
     */
    private function newest(Builder $query): Collection
    {
        // keyBy keeps the LAST row it sees for a repeated key, and this hands
        // them over oldest-first — so what survives per type is the version that
        // came into force most recently. The id tiebreak matters when two
        // versions share an effective_from, which a same-day correction does.
        return $query->orderBy('effective_from')->orderBy('id')->get()
            ->keyBy(fn (LegalDocument $document): string => $document->type->value);
    }
}
