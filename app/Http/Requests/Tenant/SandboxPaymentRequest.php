<?php

namespace App\Http\Requests\Tenant;

use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The outcome a tester picks on the sandbox checkout page (SLO-130). Public, like
 * the rest of the booking flow — the unguessable `provider_ref` in the URL is the
 * access key, and the route only exists while the sandbox gateway is enabled.
 */
class SandboxPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in([PaymentStatus::Paid->value, PaymentStatus::Failed->value])],
        ];
    }

    public function outcome(): PaymentStatus
    {
        return PaymentStatus::from((string) $this->validated('outcome'));
    }
}
