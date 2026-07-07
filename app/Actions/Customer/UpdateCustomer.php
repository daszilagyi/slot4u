<?php

namespace App\Actions\Customer;

use App\Models\Customer;

/**
 * Updates a customer's profile fields (SLO-84). Role, tenant and credentials are
 * never touched here — only the admin-editable contact fields.
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
        $customer->save();

        return $customer;
    }
}
