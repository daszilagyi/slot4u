<?php

use App\Enums\Feature;
use App\Enums\NotificationType;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\QuoteRequest;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantFeature;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

/**
 * The tenant-isolation sweep (SLO-146, docs/01).
 *
 * The suite already had cross-tenant tests scattered through the feature files,
 * but they were written one endpoint at a time — a new route could ship without
 * anyone ever pointing a foreign id at it. This walks the *route table* instead,
 * so coverage is the default and an omission has to be argued for in writing.
 *
 * Route-model binding runs before the controller and before any Form Request, so
 * a foreign id is refused without a payload: every case here is "call the route
 * with another tenant's id and expect 404". 404 rather than 403 is deliberate
 * (docs/01) — a 403 would confirm the record exists.
 *
 * A route parameter that is neither mapped below nor listed as a non-model
 * parameter fails the sweep on purpose: adding an endpoint should force a
 * decision here, not slip past it.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/**
 * Route parameters that do not address a tenant-owned record by id, with the
 * reason each one is safe. Everything else must have a factory below.
 *
 * @return array<string, string>
 */
function sweepNonModelParams(): array
{
    return [
        // The subdomain itself: the tenant being addressed, not a record in one.
        'tenant' => 'The tenant subdomain, resolved by IdentifyTenant.',
        // A payment gateway slug ("sandbox"), not a row.
        'provider' => 'A payment gateway name; the webhook authenticates by signature.',
        // A NotificationType value shared by every tenant; the controller looks the
        // template up inside the current tenant, so the name carries no cross-tenant
        // reach. Covered by its own test below.
        'key' => 'A notification type name, shared vocabulary; scoped in the controller.',
        // A role NAME, likewise shared vocabulary, resolved inside the tenant's
        // spatie team. Covered by its own test below.
        'role' => 'A role name, resolved within the tenant team context.',
    ];
}

/**
 * One record of each addressable model, created inside the given tenant.
 *
 * @return array<string, Model>
 */
function sweepRecords(Tenant $tenant): array
{
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    $location = Location::factory()->forTenant($tenant)->create();
    $room = Room::factory()->forTenant($tenant)->create(['location_id' => $location->id]);
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service = Service::factory()->forTenant($tenant)->create();
    $booking = Booking::factory()->forTenant($tenant)->create(['service_id' => $service->id]);
    $payment = Payment::factory()->create(['tenant_id' => $tenant->id, 'booking_id' => $booking->id]);

    $customer = User::factory()->create(['tenant_id' => $tenant->id]);
    $customer->assignRole(Role::Customer->value);

    $records = [
        'booking' => $booking,
        'category' => ServiceCategory::factory()->forTenant($tenant)->create(),
        'customer' => Customer::query()->findOrFail($customer->getKey()),
        'event' => Event::factory()->forTenant($tenant)->create(['service_id' => $service->id]),
        'exception' => ScheduleException::factory()->forTenant($tenant)->forSchedulable($staff)->create(),
        'invoice' => Invoice::factory()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
        ]),
        'location' => $location,
        'payment' => $payment,
        'quoteRequest' => QuoteRequest::factory()->forTenant($tenant)->create(['service_id' => $service->id]),
        'room' => $room,
        'schedule' => Schedule::factory()->forTenant($tenant)->forSchedulable($staff)->create(),
        'service' => $service,
        'staff' => $staff,
        'tenantDomain' => TenantDomain::factory()->create(['tenant_id' => $tenant->id]),
        'user' => $customer,
    ];

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    return $records;
}

/**
 * Every tenant-domain route that addresses at least one record by parameter.
 *
 * @return list<RoutingRoute>
 */
function sweepRoutes(): array
{
    $nonModel = sweepNonModelParams();

    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        function (RoutingRoute $route) use ($nonModel): bool {
            if (! str_contains((string) $route->getDomain(), '{tenant}')) {
                return false;
            }

            $params = array_diff($route->parameterNames(), array_keys($nonModel));

            return $params !== [];
        }
    ));
}

/** Turn every feature on, so a feature gate can never mask the binding check. */
function sweepEnableFeatures(Tenant $tenant): void
{
    foreach (Feature::cases() as $feature) {
        TenantFeature::factory()->create([
            'tenant_id' => $tenant->id,
            'feature_code' => $feature,
            'enabled' => true,
        ]);
    }
}

it('refuses a foreign tenant id on every route-bound tenant endpoint', function () {
    $home = Tenant::factory()->active()->create(['slug' => 'acme', 'timezone' => 'UTC']);
    $foreign = Tenant::factory()->active()->create(['slug' => 'other', 'timezone' => 'UTC']);
    sweepEnableFeatures($home);
    sweepEnableFeatures($foreign);

    // The visitor is fully privileged inside their own tenant: the sweep must fail
    // on the tenant boundary, never on a missing permission.
    app(PermissionRegistrar::class)->setPermissionsTeamId($home->getKey());
    $admin = User::factory()->create(['tenant_id' => $home->id]);
    $admin->assignRole(Role::TenantAdmin->value);
    $member = User::factory()->create(['tenant_id' => $home->id]);
    $member->assignRole(Role::Customer->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $records = sweepRecords($foreign);
    $routes = sweepRoutes();

    expect($routes)->not->toBeEmpty();

    $unmapped = [];
    $leaked = [];

    foreach ($routes as $route) {
        $uri = $route->uri();
        $method = collect($route->methods())->first(fn (string $m) => $m !== 'HEAD') ?? 'GET';

        // Substitute the foreign record's key for every model parameter, honouring
        // the route's own binding field ({booking:code} addresses by code).
        $path = $uri;
        $skip = false;
        foreach ($route->parameterNames() as $name) {
            if ($name === 'tenant') {
                continue;
            }

            if (array_key_exists($name, sweepNonModelParams())) {
                // A non-model segment still has to be filled with something valid
                // enough to reach the binding of the other parameters.
                $path = str_replace('{'.$name.'}', 'sandbox', $path);

                continue;
            }

            if (! array_key_exists($name, $records)) {
                $unmapped[] = $name.' ('.$method.' /'.$uri.')';
                $skip = true;

                break;
            }

            $field = $route->bindingFieldFor($name) ?? $records[$name]->getRouteKeyName();
            $value = (string) $records[$name]->getAttribute($field);
            $path = str_replace('{'.$name.'}', rawurlencode($value), $path);
        }

        if ($skip) {
            continue;
        }

        // The actor the route expects: the members area wants a customer, the admin
        // panel a staff user, and the public pages nobody. Read off the route so a
        // new endpoint lands in the right bucket by itself.
        $middleware = $route->gatherMiddleware();
        $actor = match (true) {
            in_array('ensure.customer', $middleware, true) => $member,
            in_array('auth', $middleware, true) => $admin,
            default => null,
        };

        $request = $actor === null ? test() : test()->actingAs($actor);
        $response = $request->call($method, tenantHost('acme', '/'.$path));

        if ($response->getStatusCode() !== 404) {
            $leaked[] = $method.' /'.$uri.' → '.$response->getStatusCode();
        }
    }

    expect($unmapped)->toBe([], 'Unmapped route parameter(s) — add a factory to sweepRecords() '
        .'or document them in sweepNonModelParams(): '.implode(', ', $unmapped));

    expect($leaked)->toBe([], 'Endpoint(s) answered something other than 404 for another '
        .'tenant\'s id: '.implode(', ', $leaked));
});

it('sweeps a meaningful number of endpoints', function () {
    // A guard on the guard: if the filter above ever stops matching (a renamed
    // domain placeholder, say), the sweep would pass by testing nothing at all.
    expect(count(sweepRoutes()))->toBeGreaterThan(40);
});

// --- The name-addressed parameters the sweep deliberately skips ---

it('keeps a role name inside its own tenant', function () {
    // {role} is a name, not an id: two tenants can both have a "Recepciós". The
    // editor must act on the caller's own, never on the other tenant's row.
    $home = Tenant::factory()->active()->create(['slug' => 'acme']);
    $foreign = Tenant::factory()->active()->create(['slug' => 'other']);

    app(PermissionRegistrar::class)->setPermissionsTeamId($foreign->getKey());
    $foreignRole = Spatie\Permission\Models\Role::create([
        'name' => 'Recepciós',
        'guard_name' => 'web',
        'tenant_id' => $foreign->id,
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($home->getKey());
    $admin = User::factory()->create(['tenant_id' => $home->id]);
    $admin->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->delete(tenantHost('acme', '/settings/roles/'.rawurlencode('Recepciós')))
        ->assertNotFound();

    expect(Spatie\Permission\Models\Role::query()->whereKey($foreignRole->getKey())->exists())->toBeTrue();
});

it('keeps a message template edit inside its own tenant', function () {
    // {key} is a NotificationType shared by every tenant; the write must land on
    // the caller's own row and never touch (or reveal) another tenant's copy.
    $home = Tenant::factory()->active()->create(['slug' => 'acme']);
    $foreign = Tenant::factory()->active()->create(['slug' => 'other']);

    $foreignTemplate = MessageTemplate::factory()->create([
        'tenant_id' => $foreign->id,
        'key' => NotificationType::BookingConfirmed,
        'subject' => 'Idegen tárgy',
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($home->getKey());
    $admin = User::factory()->create(['tenant_id' => $home->id]);
    $admin->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->put(tenantHost('acme', '/settings/templates/'.NotificationType::BookingConfirmed->value), [
            'subject' => 'Saját tárgy',
            'body' => 'Saját törzs',
        ])
        ->assertRedirect();

    expect($foreignTemplate->fresh()->subject)->toBe('Idegen tárgy');
});
