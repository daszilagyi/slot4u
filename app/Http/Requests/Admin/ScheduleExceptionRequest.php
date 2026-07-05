<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Enums\ScheduleExceptionType;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a schedule exception (SLO-19): time off or extra opening on a
 * single date. Times are optional — both null means the whole day; if one is set
 * both must be, and end_time must be after start_time. The schedulable reference
 * is constrained to the current tenant.
 */
class ScheduleExceptionRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::enum(ScheduleExceptionType::class)],
            'start_time' => ['nullable', 'required_with:end_time', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_with:start_time', 'date_format:H:i', 'after:start_time'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * An 'extra' opening must specify a time range — an all-day "extra" is
     * meaningless (it would just mirror the default open state).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('type') === ScheduleExceptionType::Extra->value
                && $this->input('start_time') === null) {
                $validator->errors()->add('start_time', __('app.admin.schedule.error.extra_needs_time'));
            }
        });
    }
}
