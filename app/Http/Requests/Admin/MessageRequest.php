<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Models\Booking;
use App\Models\Customer;
use App\Support\BookingVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A staff reply in a customer's thread (SLO-36). An attached booking must be
 * this customer's, and one the actor may see — an employee cannot pin a
 * colleague's booking to the conversation.
 */
class MessageRequest extends FormRequest
{
    public const int MAX_BODY = 5000;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::MessageSend->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
            'booking_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->input('booking_id') === null) {
                    return;
                }

                if ($this->booking() === null) {
                    $validator->errors()->add('booking_id', __('app.messages.booking_invalid'));
                }
            },
        ];
    }

    /** The attached booking, if it belongs to this thread and the actor sees it. */
    public function booking(): ?Booking
    {
        $customer = $this->route('customer');
        $id = $this->input('booking_id');

        if (! $customer instanceof Customer || $id === null) {
            return null;
        }

        $booking = Booking::query()
            ->where('customer_id', $customer->getKey())
            ->whereKey((int) $id)
            ->first();

        return $booking !== null && BookingVisibility::owns($this->user(), $booking) ? $booking : null;
    }
}
