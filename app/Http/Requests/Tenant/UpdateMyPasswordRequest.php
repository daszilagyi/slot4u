<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * A customer changing their own password in the members area (SLO-96). The
 * current password must be confirmed (defence against session hijack / shared
 * devices), and the new password meets the default strength rules and must be
 * confirmed. Errors surface on `current_password` / `password`.
 */
class UpdateMyPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ];
    }
}
