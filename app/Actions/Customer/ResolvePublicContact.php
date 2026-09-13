<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Resolves the visitor behind a public booking / quote request (SLO-128) — the
 * account-less counterpart of {@see FindOrCreateCustomer}, which stays the
 * admin-side flow.
 *
 * Three branches, in order:
 *
 * 1. the email is already a customer of THIS tenant → reuse that account, so the
 *    booking shows up in their members area (docs/04 "vendégből ügyfél");
 * 2. the email is unknown platform-wide → create the customer account, as before;
 * 3. the email belongs to some OTHER account (another tenant's user, a staff
 *    login, the super-admin) → book as a guest, no account.
 *
 * Branch 3 used to be a validation error ("this email belongs to another
 * account"), which both blocked a legitimate visitor who simply did not want to
 * log in and confirmed to an anonymous caller that the address exists on the
 * platform (the SLO-106 enumeration oracle). All three branches now return the
 * same shape and produce the same HTTP response.
 *
 * ⚠️ Same shape was not yet same TIME (SLO-230). Only branch 2 creates an
 * account, and creating one hashes a password — bcrypt, deliberately slow, the
 * largest single cost of the request. A caller timing the response could still
 * tell "unknown address" (slow) from "exists somewhere" (fast). Branches 1 and 3
 * therefore pay for one hash of their own, of a random string that is thrown
 * away, so every branch spends the same bcrypt work. What remains between them
 * is a handful of queries, which is noise next to the hash.
 */
class ResolvePublicContact
{
    public function __construct(private readonly CreateCustomer $createCustomer) {}

    public function __invoke(string $email, string $name, ?string $phone = null): PublicContact
    {
        $existing = Customer::tenantScoped()->where('email', $email)->first();

        if ($existing !== null) {
            $this->spendAccountCreationHashCost();

            return PublicContact::forCustomer($existing);
        }

        // Taken elsewhere (users.email is globally unique in the MVP auth model) →
        // no account can be minted for this tenant; proceed as a guest.
        if (User::query()->where('email', $email)->exists()) {
            $this->spendAccountCreationHashCost();

            return PublicContact::forGuest($name, $email, $phone);
        }

        return PublicContact::forCustomer(($this->createCustomer)([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ]));
    }

    /**
     * The one bcrypt hash {@see CreateCustomer} performs for a new account,
     * spent on a throwaway value (SLO-230). Same hasher, same configured cost,
     * so it tracks BCRYPT_ROUNDS instead of guessing a delay.
     */
    private function spendAccountCreationHashCost(): void
    {
        Hash::make(Str::random(40));
    }
}
