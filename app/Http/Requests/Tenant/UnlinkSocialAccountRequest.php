<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\SocialAccount;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Unlinking a Google / Facebook sign-in from the profile (SLO-252).
 *
 * Refused when it is the last way in — no password and no other provider —
 * because that would leave an account nobody can open short of a password
 * reset its owner may not know to try.
 */
class UnlinkSocialAccountRequest extends FormRequest
{
    /**
     * The policy's Response, not a bool: returned as a bool, its "not found"
     * would collapse into a 403 and confirm another customer's link exists.
     */
    public function authorize(): Response
    {
        return Gate::inspect('delete', $this->account());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();
                $account = $this->account();

                $others = $user->socialAccounts()
                    ->withoutGlobalScopes()
                    ->whereKeyNot($account->getKey())
                    ->exists();

                if (! $user->hasPassword() && ! $others) {
                    $validator->errors()->add('social', __('app.auth.social.errors.last_sign_in_method'));
                }
            },
        ];
    }

    public function account(): SocialAccount
    {
        /** @var SocialAccount $account */
        $account = $this->route('socialAccount');

        return $account;
    }
}
