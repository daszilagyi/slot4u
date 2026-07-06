<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\WaitlistEntry;

/**
 * Waitlist entries ride the booking permissions (docs/03): putting a customer on
 * the list is part of creating a booking. Cross-tenant access is impossible — the
 * BelongsToTenant global scope 404s another tenant's record on binding — so these
 * are permission checks only. Super-admins pass via the Gate::before hook.
 */
class WaitlistEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::BookingView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::BookingCreate->value);
    }

    public function delete(User $user, WaitlistEntry $entry): bool
    {
        return $user->can(Permission::BookingCancel->value);
    }
}
