<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for editing a booking's list price (SLO-126, docs/10 §3.3).
 *
 * The amount is submitted in minor units like every other money field in the
 * system (docs/01 §6) — the UI converts, never the server, so there is one
 * rounding boundary rather than two.
 */
class UpdateBookingPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::BookingEdit->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Zero is legitimate — a comped booking still occupies the slot and
            // stays in the ledger at zero turnover.
            'price_minor' => ['required', 'integer', 'min:0', 'max:99999999999'],
        ];
    }
}
