<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Refusing an erasure request (SLO-159).
 *
 * The reason is required, not optional: art. 12 (4) obliges the controller to
 * inform the subject why it is not acting, and a refusal recorded without one
 * would leave the tenant with a register that cannot answer the only question
 * anyone will ever ask of it. Authorisation is the route's `can:` + the policy.
 */
class RejectPrivacyRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
