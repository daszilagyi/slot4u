<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\LegalDocument;
use App\Models\User;

/**
 * Who may publish a tenant's legal documents (SLO-161).
 *
 * Reuses `privacy.manage` rather than adding a permission of its own: the person
 * a tenant appoints to handle data-subject requests is the person who owns its
 * privacy notice, and splitting the two would let a tenant grant "answers
 * erasure requests" without "may say what we do with the data" — a division that
 * looks tidy and means nothing.
 *
 * ⚠️ Unlike most policies here, tenant ownership IS tested: {@see LegalDocument}
 * carries no tenant global scope (it has to hold the platform's rows too), so a
 * foreign id would otherwise resolve. Platform documents are not reachable
 * through this policy at all — they belong to the superadmin panel, behind
 * `ensure.superadmin`.
 */
class LegalDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PrivacyManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PrivacyManage->value);
    }

    /**
     * Withdrawing a version before anyone has accepted it.
     *
     * A version with acceptances against it is evidence and can never be
     * deleted — that is enforced by the foreign key too, so this is the polite
     * refusal rather than the only one. A typo caught before the first customer
     * arrives, though, should not be permanent.
     */
    public function delete(User $user, LegalDocument $document): bool
    {
        return $user->can(Permission::PrivacyManage->value)
            && $document->tenant_id !== null
            && $document->tenant_id === $user->tenant_id
            && ! $document->hasConsents();
    }
}
