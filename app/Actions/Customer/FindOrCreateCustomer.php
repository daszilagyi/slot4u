<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Resolves a customer from a booking's contact details (docs/SLO-84 vendég-flow):
 * an existing tenant customer with that email is reused, otherwise a new customer
 * is created. Used by the admin/public booking flow so a walk-in guest becomes a
 * customer record without a manual pre-step.
 *
 * The email match is tenant-scoped (a customer belongs to exactly one tenant in
 * the MVP auth model). If the email already belongs to a different account
 * (another tenant, or a non-customer such as a staff login), it is refused with a
 * clean validation error rather than hitting the global unique constraint.
 */
class FindOrCreateCustomer
{
    public function __construct(private readonly CreateCustomer $createCustomer) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(string $email, string $name, ?string $phone = null): Customer
    {
        $existing = Customer::tenantScoped()->where('email', $email)->first();

        if ($existing !== null) {
            return $existing;
        }

        // Not a customer of this tenant — but the email may belong elsewhere
        // (global uniqueness in the MVP auth model). Refuse cleanly.
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'customer_email' => __('app.admin.customers.error.email_taken_elsewhere'),
            ]);
        }

        return ($this->createCustomer)([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ]);
    }
}
