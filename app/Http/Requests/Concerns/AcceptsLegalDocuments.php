<?php

namespace App\Http\Requests\Concerns;

use App\Models\LegalDocument;
use App\Models\Tenant;
use App\Services\Legal\LegalDocumentRegistry;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Collection;

/**
 * Acceptance rules for the public entry points (SLO-161).
 *
 * Shared rather than repeated because there are six places a person can enter
 * this product, and a compliance rule that is present in five of them is worth
 * very little — the missing one is exactly where a complaint will land.
 *
 * A tenant that has published nothing has nothing to be accepted: the rules go
 * away entirely rather than becoming a tick box for an empty document. Refusing
 * bookings until the tenant writes a privacy notice would take a working site
 * offline over a setting the tenant does not yet know exists.
 */
trait AcceptsLegalDocuments
{
    /**
     * Rules to merge into the request's own.
     *
     * @return array<string, mixed>
     */
    protected function legalRules(): array
    {
        if ($this->legalDocuments()->isEmpty()) {
            return [];
        }

        return [
            'accepted_legal' => ['required', 'accepted'],
            // What the form actually showed. Compared against what is in force
            // in {@see validateLegalDocumentsAreCurrent}.
            'legal_document_ids' => ['required', 'array'],
            'legal_document_ids.*' => ['integer'],
        ];
    }

    /**
     * Refuse a submission that accepted a version no longer in force.
     *
     * Call from `withValidator()`. The error is attached to `accepted_legal`
     * because that is the control the person can act on — an error on a hidden id
     * array would render nowhere.
     */
    protected function validateLegalDocumentsAreCurrent(Validator $validator): void
    {
        $documents = $this->legalDocuments();

        if ($documents->isEmpty()) {
            return;
        }

        $registry = app(LegalDocumentRegistry::class);

        if (! $registry->isCurrentSet($this->legalTenant(), (array) $this->input('legal_document_ids', []))) {
            $validator->errors()->add('accepted_legal', __('app.legal.stale'));
        }
    }

    /**
     * The documents in force at this entry point.
     *
     * @return Collection<string, LegalDocument>
     */
    protected function legalDocuments(): Collection
    {
        return app(LegalDocumentRegistry::class)->currentFor($this->legalTenant());
    }

    /**
     * The scope these documents belong to. Public requests all run on a tenant
     * host; the central sign-up path does not use this trait.
     */
    protected function legalTenant(): ?Tenant
    {
        return app(TenantManager::class)->current();
    }
}
