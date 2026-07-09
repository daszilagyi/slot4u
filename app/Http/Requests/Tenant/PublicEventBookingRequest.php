<?php

namespace App\Http\Requests\Tenant;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for a public (guest) event sign-up or waitlist join (SLO-100). The
 * event is route-bound ({event}, tenant-scoped → cross-tenant 404), so only the
 * party size + guest contact details are validated here. Anyone may submit (public
 * page); the atomic capacity claim / waitlist FIFO live in the action layer.
 */
class PublicEventBookingRequest extends FormRequest
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
        $event = $this->route('event');
        // A party can never exceed the event's total capacity; the current
        // free-seat check is the action's atomic claim, not validation.
        $maxParty = $event instanceof Event ? max(1, $event->capacity) : 1;

        return [
            'party_size' => ['required', 'integer', 'min:1', 'max:'.$maxParty],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
