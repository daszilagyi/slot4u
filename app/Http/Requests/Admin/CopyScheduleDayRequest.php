<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for copying a resource's bands from one weekday onto others (SLO-19,
 * "másolás napok közt"). Target days exclude the source day; the copy Action
 * replaces the target days' existing bands so the result never self-overlaps.
 */
class CopyScheduleDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::ScheduleManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->id();
        $table = $this->input('schedulable_type') === 'room' ? 'rooms' : 'staff';

        return [
            'schedulable_type' => ['required', Rule::in(['staff', 'room'])],
            'schedulable_id' => [
                'required',
                'integer',
                Rule::exists($table, 'id')->where('tenant_id', $tenantId),
            ],
            'source_day' => ['required', 'integer', 'between:1,7'],
            'target_days' => ['required', 'array', 'min:1'],
            'target_days.*' => ['integer', 'between:1,7', 'different:source_day', 'distinct'],
        ];
    }
}
