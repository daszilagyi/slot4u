<?php

namespace App\Http\Requests\Tenant;

use App\Http\Requests\Concerns\NormalizesPhone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A customer editing their own profile in the members area (SLO-96). Only the
 * name and phone are editable; the email is the login identity and is not
 * changed here. Authorisation is implicit — the controller only ever touches
 * $request->user(), and ensure.customer already gated the group.
 */
class UpdateMyProfileRequest extends FormRequest
{
    use NormalizesPhone;

    protected function prepareForValidation(): void
    {
        $this->normalizePhone();
    }

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
            'name' => ['required', 'string', 'max:255'],
            'phone' => $this->phoneRules(),
        ];
    }
}
