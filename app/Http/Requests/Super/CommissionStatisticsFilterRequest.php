<?php

namespace App\Http\Requests\Super;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filter for the superadmin commission statistics dashboard (SLO-123): the
 * billing month to report on. Optional — an empty filter reports the month the
 * platform is currently accruing into.
 */
class CommissionStatisticsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The admin route group is already gated by auth + ensure.superadmin;
        // the controller additionally checks the TenantBillingPeriodPolicy.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The YYYY-MM period key stored on the aggregate (docs/10 §5.4).
            'period' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ];
    }
}
