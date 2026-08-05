<?php

namespace App\Http\Requests\Super;

use App\Services\Platform\BuildPlatformStatistics;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filter for the superadmin platform statistics page (SLO-138): the billing
 * month the turnover distribution reports on, and how far back the growth series
 * reaches. Both optional — an empty filter reports the current month over
 * {@see BuildPlatformStatistics::DEFAULT_MONTHS} months.
 *
 * The window length is an allow-list rather than a free integer: it is a bounded
 * choice in the UI, and letting it be typed would let a request ask for a
 * thousand conditional sums in one query.
 */
class PlatformStatisticsFilterRequest extends FormRequest
{
    /** The selectable growth-window lengths, in months. */
    public const ALLOWED_MONTHS = [6, 12, 24];

    public function authorize(): bool
    {
        // The admin route group is already gated by auth + ensure.superadmin;
        // the controller additionally checks the TenantPolicy.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The YYYY-MM period key stored on the billing aggregate (docs/10 §5.4).
            'period' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'months' => ['nullable', 'integer', Rule::in(self::ALLOWED_MONTHS)],
        ];
    }
}
