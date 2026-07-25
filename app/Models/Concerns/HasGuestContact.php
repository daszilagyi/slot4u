<?php

namespace App\Models\Concerns;

use App\Actions\Customer\ResolvePublicContact;
use App\Models\User;
use App\Notifications\GuestRecipient;
use Illuminate\Database\Eloquent\Model;

/**
 * Contact details for a record that may belong either to a customer account or to
 * an account-less guest (SLO-128).
 *
 * The public flows resolve the visitor into one of the two (see
 * {@see ResolvePublicContact}); everything downstream —
 * notifications, the admin list, the confirmation page — asks the record for its
 * contact rather than reaching for `->customer` and finding null.
 *
 * @phpstan-require-extends Model
 *
 * @property int|null $customer_id
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property-read User|null $customer
 */
trait HasGuestContact
{
    /** A guest record: no account behind it, only the contact details on the row. */
    public function isGuest(): bool
    {
        return $this->customer_id === null && filled($this->guest_email);
    }

    public function contactName(): ?string
    {
        $customer = $this->customer;

        return $customer !== null ? $customer->name : $this->guest_name;
    }

    public function contactEmail(): ?string
    {
        $customer = $this->customer;

        return $customer !== null ? $customer->email : $this->guest_email;
    }

    public function contactPhone(): ?string
    {
        $customer = $this->customer;

        return $customer !== null ? $customer->phone : $this->guest_phone;
    }

    /**
     * Who to notify about this record: the customer account, or an ad-hoc
     * recipient carrying the guest's name and address. Null when there is neither
     * (an admin-created walk-in with no contact details at all).
     */
    public function contactNotifiable(): User|GuestRecipient|null
    {
        $customer = $this->customer;

        if ($customer !== null) {
            return $customer;
        }

        if (blank($this->guest_email)) {
            return null;
        }

        return new GuestRecipient((string) $this->guest_email, (string) ($this->guest_name ?? ''));
    }
}
