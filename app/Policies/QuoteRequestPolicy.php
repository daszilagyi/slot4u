<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\QuoteRequest;
use App\Models\User;

/**
 * Quote requests ride the booking permissions (docs/03): they are a non-calendar
 * booking mode. Viewing needs booking.view, creating booking.create, and every
 * lifecycle action (start / submit / accept / reject / message) booking.edit.
 * Cross-tenant access is impossible — the BelongsToTenant global scope 404s
 * another tenant's record on binding — so these are permission checks only.
 * Super-admins pass via the Gate::before hook.
 */
class QuoteRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::BookingView->value);
    }

    public function view(User $user, QuoteRequest $quoteRequest): bool
    {
        return $user->can(Permission::BookingView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::BookingCreate->value);
    }

    public function update(User $user, QuoteRequest $quoteRequest): bool
    {
        return $user->can(Permission::BookingEdit->value);
    }
}
