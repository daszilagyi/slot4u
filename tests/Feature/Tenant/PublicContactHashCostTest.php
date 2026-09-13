<?php

use App\Actions\Customer\CreateCustomer;
use App\Actions\Customer\ResolvePublicContact;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Equal hash work on every branch of the public contact (SLO-230)
|--------------------------------------------------------------------------
|
| The public flows answer the same SHAPE whether an address is unknown, a
| customer of this tenant, or an account elsewhere (SLO-106/128). The TIME
| differed: only the unknown address created an account, and creating one runs
| bcrypt — so a caller timing the response could still tell the three apart.
|
| Timing itself is not asserted (a test that measures milliseconds is a flaky
| test). What is asserted is the cause: every branch performs exactly one
| password hash.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $this->hashTenant = $tenant;
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/**
 * Resolve a contact while counting the password hashes it computes.
 *
 * A counting decorator around the real hasher rather than a mock: the model's
 * `hashed` cast asks the hasher more than `make` (isHashed, needsRehash), and a
 * partial mock built without its config breaks on the first of those.
 */
function hashCostResolve(string $email): int
{
    $counter = new class(app('hash'))
    {
        public int $makes = 0;

        public function __construct(private readonly object $inner) {}

        public function make(string $value, array $options = []): string
        {
            $this->makes++;

            return $this->inner->make($value, $options);
        }

        public function __call(string $method, array $arguments): mixed
        {
            return $this->inner->{$method}(...$arguments);
        }
    };

    app()->instance('hash', $counter);
    Hash::clearResolvedInstance('hash');

    app(ResolvePublicContact::class)($email, 'Teszt Vendég', null);

    return $counter->makes;
}

it('hashes once for an address nobody owns — the account it creates', function () {
    expect(hashCostResolve('new@example.test'))->toBe(1)
        ->and(User::query()->where('email', 'new@example.test')->exists())->toBeTrue();
});

it('hashes once for an existing customer of this tenant, too', function () {
    app(CreateCustomer::class)(['name' => 'Régi', 'email' => 'regular@example.test', 'phone' => null]);

    expect(hashCostResolve('regular@example.test'))->toBe(1);
});

it('hashes once for an address that belongs to an account elsewhere, too', function () {
    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    User::factory()->create(['tenant_id' => $other->id, 'email' => 'taken@example.test']);

    expect(hashCostResolve('taken@example.test'))->toBe(1)
        // Still a guest: the equalising hash must not have created anything.
        ->and(User::query()->where('email', 'taken@example.test')->count())->toBe(1);
});
