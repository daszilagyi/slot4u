<?php

namespace App\Actions\Customer;

use App\Actions\Staff\InviteStaff;
use App\Enums\Role;
use App\Models\Customer;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates a customer (SLO-84): a {@see Customer} (User) with the customer role in
 * the current tenant's team. Admin-created customers can't log in until they set a
 * password (M4 members area) — the password is a throwaway and the account is not
 * verified. tenant_id is stamped from the current tenant, never from input.
 */
class CreateCustomer
{
    public function __construct(private readonly TenantManager $tenants) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): Customer
    {
        $tenant = $this->tenants->current();

        if ($tenant === null) {
            throw new RuntimeException('Cannot create a customer outside tenant context.');
        }

        return DB::transaction(function () use ($data, $tenant): Customer {
            $customer = new Customer;
            $customer->fill([
                'tenant_id' => $tenant->getKey(),
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'locale' => $tenant->locale,
            ]);
            $customer->password = Hash::make(Str::random(40));
            $customer->save();

            $this->assignCustomerRole($customer, $tenant->getKey());

            return $customer;
        });
    }

    /**
     * Grant the customer role within the tenant's team, restoring the previous
     * team context afterwards (mirrors {@see InviteStaff}).
     */
    private function assignCustomerRole(Customer $customer, int $tenantId): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenantId);

        try {
            $customer->assignRole(Role::Customer->value);
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
