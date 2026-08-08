<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query filters for the admin calendar (SLO-44). The resource ids are checked
 * against the *current tenant's* rows, so a foreign id fails validation rather than
 * silently returning an empty grid. `date` is a tenant-local calendar day, not an
 * instant — the timezone conversion happens in the calendar builder (docs/01 §7).
 */
class CalendarFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::BookingView->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->id();

        return [
            'view' => ['nullable', Rule::in(['day', 'week'])],
            'group' => ['nullable', Rule::in(['staff', 'room'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'staff_id' => ['nullable', 'integer', Rule::exists('staff', 'id')->where('tenant_id', $tenantId)],
            'room_id' => ['nullable', 'integer', Rule::exists('rooms', 'id')->where('tenant_id', $tenantId)],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('tenant_id', $tenantId)],
            // Typed into the quick-booking dialog's customer picker (SLO-136). Not a
            // grid filter: it only narrows the `customers` partial-reload prop, which
            // is why it never reaches the calendar builder.
            'customer_search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
