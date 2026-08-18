<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PrivacyRequest;
use App\Models\User;

/**
 * Who may work the tenant's data-subject request queue (SLO-159).
 *
 * Auto-discovered by naming convention. Tenant membership is not tested here:
 * {@see PrivacyRequest} carries the tenant global scope, so another tenant's
 * request 404s on route binding — a stronger answer than 403, and the same rule
 * the rest of the panel follows (docs/01).
 *
 * There is no `update`: a resolved request is a compliance record, and reopening
 * or rewriting one would make the register worth less than not having it.
 */
class PrivacyRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PrivacyManage->value);
    }

    /**
     * Deciding a request — executing the erasure or refusing it.
     *
     * Both branches share one ability because they are one decision, and
     * splitting them would let a tenant grant "may refuse" without "may erase",
     * which is a compliance posture no tenant should be able to configure by
     * accident.
     */
    public function resolve(User $user, PrivacyRequest $request): bool
    {
        return $user->can(Permission::PrivacyManage->value) && $request->status->isOpen();
    }
}
