<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Validation for submitting a price on a quote request (SLO-27). Money is integer
 * minor units + a 3-letter currency (never float, CLAUDE.md); the optional
 * validity is a future datetime. The datetime-local input is tenant-local and
 * converted to UTC before storage (docs/01 §7), mirroring BookingRequest.
 */
class SubmitQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::BookingEdit->value);
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('valid_until');
        if (is_string($value) && $value !== '') {
            $tenant = app(TenantManager::class)->current();
            $timezone = $tenant !== null ? $tenant->timezone : (string) config('app.timezone');
            $this->merge(['valid_until' => Carbon::parse($value, $timezone)->utc()->toDateTimeString()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'price_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'valid_until' => ['nullable', 'date', 'after:now'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
