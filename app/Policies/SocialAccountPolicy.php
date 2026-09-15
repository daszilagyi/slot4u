<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A linked Google / Facebook identity (SLO-252). Only its own user may remove
 * it — not a tenant admin, not support: an unlink changes how somebody signs
 * in, and nobody else gets to decide that.
 *
 * Someone else's link answers as not found, like every record a person has no
 * business knowing exists (docs/01).
 */
class SocialAccountPolicy
{
    public function delete(User $user, SocialAccount $account): Response
    {
        return $account->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
