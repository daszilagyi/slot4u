<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\User;
use App\Support\CustomerVisibility;

/**
 * Customer management authorisation (docs/03 matrix, SLO-84). Tenant-admin and
 * manager manage every customer; an employee is restricted to "saját ügyfelei"
 * ({@see CustomerVisibility}). Cross-tenant / non-customer records 404 on binding
 * (Customer::resolveRouteBinding), so these are permission + ownership checks
 * only. Super-admins pass via the Gate::before hook.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CustomerView->value);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can(Permission::CustomerView->value)
            && CustomerVisibility::owns($user, $customer);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CustomerEdit->value);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can(Permission::CustomerEdit->value)
            && CustomerVisibility::owns($user, $customer);
    }
}
