<?php

namespace App\Http\Requests\Tenant;

use App\Http\Requests\Admin\MessageRequest;
use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A customer's message to the tenant (SLO-36). The members-area group already
 * requires a signed-in customer; the thread is always their own, so the only
 * thing to check is that an attached booking is theirs too.
 */
class MyMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.MessageRequest::MAX_BODY],
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

    /** The attached booking, if it is the signed-in customer's own. */
    public function booking(): ?Booking
    {
        $id = $this->input('booking_id');

        if ($id === null) {
            return null;
        }

        return Booking::query()
            ->where('customer_id', $this->user()->getKey())
            ->whereKey((int) $id)
            ->first();
    }
}
