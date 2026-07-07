<?php

use App\Actions\Customer\CreateCustomer;
use App\Actions\Customer\FindOrCreateCustomer;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** Active tenant, set as current + team context (so role checks resolve). */
function custTenant(array $overrides = []): Tenant
{
    $tenant = Tenant::factory()->active()->create($overrides);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

function custUser(Tenant $tenant, Role $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $user;
}

function makeCustomer(array $data = []): Customer
{
    return app(CreateCustomer::class)(array_merge([
        'name' => 'Teszt Ügyfél',
        'email' => 'cust'.uniqid().'@example.test',
        'phone' => null,
    ], $data));
}

// --- CreateCustomer ---

it('creates a customer as a tenant user with the customer role', function () {
    $tenant = custTenant();

    $customer = makeCustomer(['name' => 'Kovács Anna', 'email' => 'anna@example.test', 'phone' => '+36301234567']);

    expect($customer->tenant_id)->toBe($tenant->id)
        ->and($customer->name)->toBe('Kovács Anna')
        ->and($customer->phone)->toBe('+36301234567')
        ->and($customer->hasRole(Role::Customer->value))->toBeTrue();

    // Visible through the customer scope.
    expect(Customer::tenantScoped()->whereKey($customer->id)->exists())->toBeTrue();
});

it('excludes non-customer users from the customer scope', function () {
    $tenant = custTenant();
    custUser($tenant, Role::Employee); // an employee is a tenant user but not a customer
    makeCustomer();

    expect(Customer::tenantScoped()->count())->toBe(1);
});

// --- FindOrCreateCustomer ---

it('reuses an existing tenant customer by email', function () {
    custTenant();
    $existing = makeCustomer(['email' => 'reuse@example.test']);

    $resolved = app(FindOrCreateCustomer::class)('reuse@example.test', 'Más Név');

    expect($resolved->id)->toBe($existing->id)
        ->and(Customer::tenantScoped()->count())->toBe(1);
});

it('creates a new customer for an unknown email', function () {
    custTenant();

    $customer = app(FindOrCreateCustomer::class)('new@example.test', 'Új Ügyfél', '+3612345');

    expect($customer->email)->toBe('new@example.test')
        ->and($customer->hasRole(Role::Customer->value))->toBeTrue()
        ->and(Customer::tenantScoped()->count())->toBe(1);
});

it('refuses an email that already belongs to another account', function () {
    $tenantA = custTenant(['slug' => 'acme']);
    makeCustomer(['email' => 'shared@example.test']);

    $tenantB = custTenant(['slug' => 'other']);

    expect(fn () => app(FindOrCreateCustomer::class)('shared@example.test', 'Ütköző'))
        ->toThrow(ValidationException::class);

    expect(Customer::tenantScoped()->count())->toBe(0); // nothing created in tenant B
});

// --- Tenant isolation ---

it('scopes customers to the current tenant', function () {
    $tenantA = custTenant(['slug' => 'acme']);
    makeCustomer(['email' => 'a@example.test']);

    $tenantB = custTenant(['slug' => 'other']);
    makeCustomer(['email' => 'b@example.test']);

    // Ambient tenant is B.
    expect(Customer::tenantScoped()->count())->toBe(1)
        ->and(Customer::tenantScoped()->first()->email)->toBe('b@example.test');
});

// --- Employee ownership scope ---

it('shows an employee only customers with a booking at their staff', function () {
    $tenant = custTenant();
    $employee = custUser($tenant, Role::Employee);
    $staff = Staff::factory()->forTenant($tenant)->create(['user_id' => $employee->id]);
    $otherStaff = Staff::factory()->forTenant($tenant)->create();

    $mine = makeCustomer(['email' => 'mine@example.test']);
    Booking::factory()->forTenant($tenant)->create(['customer_id' => $mine->id, 'staff_id' => $staff->id]);

    $theirs = makeCustomer(['email' => 'theirs@example.test']);
    Booking::factory()->forTenant($tenant)->create(['customer_id' => $theirs->id, 'staff_id' => $otherStaff->id]);

    app(TenantManager::class)->forget();

    $this->actingAs($employee)
        ->get(tenantHost($tenant->slug, '/customers'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Customers/Index')
            ->has('customers.data', 1)
            ->where('customers.data.0.email', 'mine@example.test'));
});

it('404s when an employee opens a customer outside their own scope', function () {
    $tenant = custTenant(['slug' => 'acme']);
    $employee = custUser($tenant, Role::Employee);
    Staff::factory()->forTenant($tenant)->create(['user_id' => $employee->id]);
    $otherStaff = Staff::factory()->forTenant($tenant)->create();

    // A customer of another staff — exists in-tenant, but not the employee's.
    $theirs = makeCustomer(['email' => 'theirs@example.test']);
    Booking::factory()->forTenant($tenant)->create(['customer_id' => $theirs->id, 'staff_id' => $otherStaff->id]);
    app(TenantManager::class)->forget();

    // 404 (not 403) — the ownership scope hides existence, like a cross-tenant id.
    $this->actingAs($employee)
        ->get(tenantHost('acme', "/customers/{$theirs->id}"))
        ->assertNotFound();
});

it('shows a manager every customer', function () {
    $tenant = custTenant(['slug' => 'acme']);
    $manager = custUser($tenant, Role::Manager);
    makeCustomer(['email' => 'one@example.test']);
    makeCustomer(['email' => 'two@example.test']);
    app(TenantManager::class)->forget();

    $this->actingAs($manager)
        ->get(tenantHost('acme', '/customers'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('customers.data', 2));
});

// --- Index search + HTTP ---

it('searches customers by name, email or phone', function () {
    $tenant = custTenant(['slug' => 'acme']);
    $admin = custUser($tenant, Role::TenantAdmin);
    makeCustomer(['name' => 'Szabó Béla', 'email' => 'bela@example.test', 'phone' => '111']);
    makeCustomer(['name' => 'Nagy Éva', 'email' => 'eva@example.test', 'phone' => '222']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', '/customers?search=Szabó'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.name', 'Szabó Béla'));
});

// --- Customer card aggregates ---

it('shows correct aggregates on the customer card', function () {
    $tenant = custTenant(['slug' => 'acme']);
    $admin = custUser($tenant, Role::TenantAdmin);
    $customer = makeCustomer(['email' => 'card@example.test']);

    // Two completed (counted in spend), one canceled (not), one confirmed (not spend).
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Completed)->create(['customer_id' => $customer->id, 'price_minor' => 300000]);
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Completed)->create(['customer_id' => $customer->id, 'price_minor' => 200000]);
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Canceled)->create(['customer_id' => $customer->id, 'price_minor' => 999000]);
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create(['customer_id' => $customer->id, 'price_minor' => 100000]);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/customers/{$customer->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Customers/Show')
            ->where('customer.bookings_count', 4)
            ->where('customer.completed_count', 2)
            ->where('customer.total_spend_minor', 500000)
            ->has('customer.recent_bookings', 4));
});

// --- Create / update via endpoints ---

it('lets an admin create a customer via the endpoint', function () {
    $tenant = custTenant(['slug' => 'acme']);
    $admin = custUser($tenant, Role::TenantAdmin);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/customers'), ['name' => 'Új Ügyfél', 'email' => 'brand@example.test', 'phone' => '+3612'])
        ->assertRedirect()->assertSessionHasNoErrors();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    app(TenantManager::class)->set($tenant);
    expect(Customer::tenantScoped()->where('email', 'brand@example.test')->exists())->toBeTrue();
});

it('validates a unique email on create', function () {
    $tenant = custTenant(['slug' => 'acme']);
    $admin = custUser($tenant, Role::TenantAdmin);
    makeCustomer(['email' => 'taken@example.test']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->post(tenantHost('acme', '/customers'), ['name' => 'Dup', 'email' => 'taken@example.test'])
        ->assertSessionHasErrors('email');
});

it('lets an admin update a customer via the endpoint', function () {
    $tenant = custTenant(['slug' => 'acme']);
    $admin = custUser($tenant, Role::TenantAdmin);
    $customer = makeCustomer(['name' => 'Régi Név', 'email' => 'upd@example.test']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', "/customers/{$customer->id}"), ['name' => 'Új Név', 'email' => 'upd@example.test', 'phone' => '+3699'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($customer->fresh()->name)->toBe('Új Név')
        ->and($customer->fresh()->phone)->toBe('+3699');
});

// --- Authorization ---

it('forbids the customer list without customer.view (403)', function () {
    $tenant = custTenant(['slug' => 'acme']);
    // A bare user with no roles has no customer.view.
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    app(TenantManager::class)->forget();

    $this->actingAs($user)
        ->get(tenantHost('acme', '/customers'))
        ->assertForbidden();
});

it('404s when viewing another tenant\'s customer (cross-tenant)', function () {
    $tenant = custTenant(['slug' => 'acme']);
    $admin = custUser($tenant, Role::TenantAdmin);

    $other = custTenant(['slug' => 'other']);
    $foreign = makeCustomer(['email' => 'foreign@example.test']);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/customers/{$foreign->id}"))
        ->assertNotFound();
});

it('404s when opening a non-customer user as a customer', function () {
    $tenant = custTenant(['slug' => 'acme']);
    $admin = custUser($tenant, Role::TenantAdmin);
    $employee = custUser($tenant, Role::Employee); // a user, but not a customer
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/customers/{$employee->id}"))
        ->assertNotFound();
});
