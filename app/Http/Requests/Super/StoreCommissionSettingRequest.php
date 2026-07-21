<?php

namespace App\Http\Requests\Super;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommissionSettingRequest extends FormRequest
{
    /** Basis points ceiling: 10 000 bps = 100%. */
    private const MAX_BPS = 10_000;

    public function authorize(): bool
    {
        // The admin route group is already gated by auth + ensure.superadmin;
        // the controller additionally authorizes against CommissionSettingPolicy.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Money and rates are integers throughout (docs/01 §6): minor units
            // and basis points, never a float.
            'free_threshold_minor' => ['required', 'integer', 'min:0'],
            'rate_bps' => ['required', 'integer', 'min:0', 'max:'.self::MAX_BPS],
            // The integration rate is the *raised* one (docs/10 §2.4), so it can
            // never sit below the base rate.
            'rate_with_integration_bps' => ['required', 'integer', 'min:0', 'max:'.self::MAX_BPS, 'gte:rate_bps'],
            // Null = uncapped (§5.1) — distinct from a zero cap, which would mean
            // no commission is ever charged.
            'monthly_cap_minor' => ['nullable', 'integer', 'min:0'],
            // Single-currency while multi-currency is out of MVP scope
            // (docs/10 §15.7); the ledger and every existing version are HUF.
            'currency' => ['required', Rule::in(['HUF'])],
            // Backdating is allowed on purpose: it is how a correction is
            // applied to a still-open period. Closed periods never recompute
            // (§8.2), so it cannot rewrite invoiced history.
            'effective_from' => ['required', 'date'],
        ];
    }
}
