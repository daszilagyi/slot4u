<?php

namespace App\Actions\Customer;

use App\Models\Customer;

/**
 * The visitor behind a public booking / quote request (SLO-128), resolved by
 * {@see ResolvePublicContact} into exactly one of two shapes:
 *
 * - a {@see Customer} of this tenant (existing or freshly created), or
 * - an account-less guest, whose details travel on the record itself.
 *
 * Callers do not branch on which one it is: {@see self::recordAttributes()} yields
 * the columns to persist either way.
 */
final class PublicContact
{
    private function __construct(
        public readonly ?Customer $customer,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $phone,
    ) {}

    public static function forCustomer(Customer $customer): self
    {
        return new self($customer, (string) $customer->name, (string) $customer->email, $customer->phone);
    }

    public static function forGuest(string $name, string $email, ?string $phone): self
    {
        return new self(null, $name, $email, $phone);
    }

    public function isGuest(): bool
    {
        return $this->customer === null;
    }

    /**
     * The contact columns for a booking / quote request. A customer-backed contact
     * leaves the guest columns null — the account is the single source of truth for
     * its own name and address, so copying them onto the row would let the two
     * drift apart after a profile edit.
     *
     * @return array<string, mixed>
     */
    public function recordAttributes(): array
    {
        if ($this->customer !== null) {
            return [
                'customer_id' => $this->customer->getKey(),
                'guest_name' => null,
                'guest_email' => null,
                'guest_phone' => null,
            ];
        }

        return [
            'customer_id' => null,
            'guest_name' => $this->name,
            'guest_email' => $this->email,
            'guest_phone' => $this->phone,
        ];
    }
}
