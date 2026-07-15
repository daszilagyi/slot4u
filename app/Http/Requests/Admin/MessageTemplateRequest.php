<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for saving a tenant email-template override (SLO-114). The `key` comes
 * from the route and is validated against the editable catalogue in the controller;
 * here we validate the editable fields. `enabled` lets a tenant keep a draft that
 * still falls back to the platform default until they switch it on.
 */
class MessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::TemplateManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
