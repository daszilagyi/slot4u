<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ]);

        // A reset link only works for whoever reads the mailbox, so using one
        // proves the address (SLO-254) — the moment a staff invitation, an
        // admin-created customer or a social sign-up's first password becomes
        // verified.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()]);
        }

        $user->save();
    }
}
