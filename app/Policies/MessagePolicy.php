<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\User;
use App\Support\CustomerVisibility;

/**
 * Staff access to message threads (SLO-36, docs/03 `message.send`). A thread is
 * a customer, so the ownership half is {@see CustomerVisibility}: an employee
 * answers only their own customers. A foreign customer already 404s on route
 * binding; this is the permission check on top.
 *
 * The customer side has no ability here: the members area only ever reads the
 * signed-in customer's own thread, so there is no id to authorise.
 */
class MessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::MessageSend->value);
    }

    public function reply(User $user, Customer $customer): bool
    {
        return $user->can(Permission::MessageSend->value)
            && CustomerVisibility::owns($user, $customer);
    }
}
