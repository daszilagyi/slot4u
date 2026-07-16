<?php

namespace App\Http\Requests\Super;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantCommissionOverrideRequest extends FormRequest
{
    /** Basis points ceiling: 10 000 bps = 100%. */
    private const MAX_BPS = 10_000;

    public function authorize(): bool
    {
        // The admin route group is already gated by auth + ensure.superadmin;
        // the controller additionally authorizes against
        // TenantCommissionOverridePolicy.
        return true;
    }

    /**
     * Every field is nullable because NULL is meaningful here: it inherits the
     * platform version (docs/10 §5.2). No cross-field rate rule — an override
     * field may be set while its counterpart inherits, so the pair is only
     * comparable after resolution.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'free_threshold_minor' => ['nullable', 'integer', 'min:0'],
            'rate_bps' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_BPS],
            'rate_with_integration_bps' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_BPS],
            'monthly_cap_minor' => ['nullable', 'integer', 'min:0'],
            // Why this tenant is priced differently — the next superadmin will
            // want to know.
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
