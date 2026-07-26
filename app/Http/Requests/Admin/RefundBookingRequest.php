<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for a manual refund issued from the booking page (SLO-131). The
 * amount is bounded here only in shape (a positive integer of minor units); the
 * real ceiling — what is still refundable on the booking's settled payments — is
 * enforced in the action, which holds the payment rows.
 *
 * Gated on booking.cancel: refunding is the money side of calling a booking off,
 * and docs/03 gives that permission to the roles allowed to make that call.
 */
class RefundBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::BookingCancel->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
