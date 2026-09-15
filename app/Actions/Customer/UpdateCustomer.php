<?php

namespace App\Actions\Customer;

use App\Models\Customer;

/**
 * Updates a customer's profile fields (SLO-84). Role, tenant and credentials are
 * never touched here — only the admin-editable contact fields.
 *
 * A changed address is no longer a verified one (SLO-254): whoever owns the NEW
 * mailbox has proven nothing. Leaving the flag set would let the social login
 * treat the account as the address owner's own — linking their Google sign-in
 * to an account whose password somebody else still knows, which is exactly
 * what its pre-hijack rule (ResolveSocialLogin) exists to prevent.
 */
class UpdateCustomer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Customer $customer, array $data): Customer
    {
        $customer->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
        ]);

        if ($customer->isDirty('email')) {
            $customer->forceFill(['email_verified_at' => null]);
        }

        $customer->save();

        return $customer;
    }
}
