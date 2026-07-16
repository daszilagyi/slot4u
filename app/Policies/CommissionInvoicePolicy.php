<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CommissionInvoice;
use App\Models\User;

/**
 * A tenant may read its own commission invoices (docs/10 §9) but never write
 * them: the slot4u→tenant invoice is issued by the platform (§6.5) and settled by
 * a superadmin (J8). `billing.view` is tenant-admin only per docs/03; the
 * BelongsToTenant global scope makes a cross-tenant invoice a 404 rather than a
 * 403. Super-admins pass via the Gate::before hook.
 */
class CommissionInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::BillingView->value);
    }

    public function view(User $user, CommissionInvoice $invoice): bool
    {
        return $user->can(Permission::BillingView->value);
    }
}
