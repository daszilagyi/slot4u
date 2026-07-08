<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for cancelling a booking from the admin list (SLO-85). The reason is
 * optional (unlike a rejection, docs/04 §5) and shown to the customer. Authorises
 * on the permission only; the employee ownership scope is enforced as a 404 in the
 * controller (hidden existence), before this runs.
 */
class CancelBookingRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
