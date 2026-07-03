<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for an employee editing their OWN staff profile (SLO-17). The
 * editable subset is deliberately narrow: name, title, bio, colour. `active`
 * and the user link stay under admin control (staff.manage). Authorization of
 * ownership happens in the controller via the StaffPolicy `update` ability.
 */
class StaffProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
