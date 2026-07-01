<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Enums\RoomType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating/updating a room (SLO-16). The owning location comes
 * from the route, not the body, so it cannot be forged. Gated by
 * `can:location.manage` (rooms are governed by the same permission).
 */
class RoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::LocationManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(RoomType::class)],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'active' => ['boolean'],
        ];
    }
}
