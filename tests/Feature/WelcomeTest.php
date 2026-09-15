<?php

use App\Enums\Role;
use App\Models\CommissionSetting;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\CommissionSettingSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The public marketing landing (SLO-50)
|--------------------------------------------------------------------------
|
| The landing states a price, so the thing worth testing is not that it renders
| — it is that the price it states is the price the platform actually charges.
| Every figure comes from commission_settings and the worked example runs
| through the real CommissionCalculator, so a change to either shows up here
| rather than in a customer's first invoice.
|
*/

function centralUrl(string $path = '/'): string
{
    return 'http://'.config('tenancy.central_domain').$path;
}

it('renders the welcome inertia page on the central domain', function () {
    $this->get(centralUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->has('translations')
            ->where('locale', 'hu')
        );
});

it('quotes the commission figures that are actually configured', function () {
    $this->seed(CommissionSettingSeeder::class);

    $this->get(centralUrl())
        ->assertInertia(fn (Assert $page) => $page
            ->where('commission.free_threshold_minor', 1_000_000)
            ->where('commission.rate_bps', 100)
            ->where('commission.rate_with_integration_bps', 150)
            ->where('commission.monthly_cap_minor', 5_000_000)
            ->where('commission.currency', 'HUF')
        );
});

it('⚠️ follows the settings rather than the copy', function () {
    // The failure this guards against: someone publishes a new settings version
    // in the superadmin panel and the landing goes on advertising the old deal.
    // Nothing about these numbers appears in a lang file or a component.
    CommissionSetting::query()->create([
        'free_threshold_minor' => 2_500_000,
        'rate_bps' => 220,
        'rate_with_integration_bps' => 260,
        'monthly_cap_minor' => null,
        'currency' => 'EUR',
        'effective_from' => Carbon::now()->subDay(),
        'created_by' => null,
    ]);

    $this->get(centralUrl())
        ->assertInertia(fn (Assert $page) => $page
            ->where('commission.free_threshold_minor', 2_500_000)
            ->where('commission.rate_bps', 220)
            ->where('commission.currency', 'EUR')
            // An uncapped platform advertises no cap at all, rather than a "0".
            ->where('commission.monthly_cap_minor', null)
        );
});

it('⚠️ works the example marginally, not on the whole turnover', function () {
    // The single most tempting thing to get wrong on a pricing page: "1% of your
    // turnover". It is 1% of the part ABOVE the threshold, and the example on
    // the page is produced by the same calculator that produces the invoice.
    $this->seed(CommissionSettingSeeder::class);

    $this->get(centralUrl())
        ->assertInertia(function (Assert $page) {
            $turnover = $page->toArray()['props']['commission']['example_turnover_minor'];
            $base = $page->toArray()['props']['commission']['example_billable_base_minor'];
            $fee = $page->toArray()['props']['commission']['example_commission_minor'];

            // 30 000 Ft turnover, 10 000 Ft of it free, 1% of the rest = 200 Ft.
            expect($turnover)->toBe(3_000_000)
                ->and($base)->toBe(2_000_000)
                ->and($fee)->toBe(20_000)
                // The whole point, stated as a property rather than a constant:
                // charging on the full turnover would give 30 000.
                ->and($fee)->toBeLessThan(intdiv($turnover * 100, 10_000));
        });
});

it('states the model without figures when nothing is configured', function () {
    // A fresh installation, before ProductionSeeder has run. The landing must
    // still answer 200 — and must not invent a threshold nobody set.
    expect(CommissionSetting::query()->count())->toBe(0);

    $this->get(centralUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('commission', null));
});

it('ignores a settings version that is not in force yet', function () {
    // Versions are scheduled ahead (docs/10 §5.1). Advertising next quarter's
    // rate today would be quoting a price nobody is charged.
    $this->seed(CommissionSettingSeeder::class);

    CommissionSetting::query()->create([
        'free_threshold_minor' => 9_900_000,
        'rate_bps' => 900,
        'rate_with_integration_bps' => 950,
        'monthly_cap_minor' => null,
        'currency' => 'HUF',
        'effective_from' => Carbon::now()->addMonth(),
        'created_by' => null,
    ]);

    $this->get(centralUrl())
        ->assertInertia(fn (Assert $page) => $page->where('commission.rate_bps', 100));
});

it('links the demo tenant on its own subdomain', function () {
    config(['tenancy.demo_slug' => 'demo']);

    $this->get(centralUrl())
        ->assertInertia(fn (Assert $page) => $page
            ->where('demo_url', 'http://demo.'.config('tenancy.central_domain')));
});

it('offers no demo link when no demo tenant is configured', function () {
    // Better a missing button than a first click into a 404.
    config(['tenancy.demo_slug' => null]);

    $this->get(centralUrl())
        ->assertInertia(fn (Assert $page) => $page->where('demo_url', null));
});

it('offers the registration the landing sends people to', function () {
    // The issue's acceptance criterion is that a visitor can register FROM the
    // landing, so the target of the primary call to action is part of the page.
    $this->get(centralUrl('/register'))->assertOk();
});

/*
|--------------------------------------------------------------------------
| Who the marketing site thinks it is looking at (SLO-215)
|--------------------------------------------------------------------------
|
| The session cookie is scoped to `.{central}`, so a demo sign-in follows the
| visitor back here. The header read that as "customer" and took away the
| registration CTA — from the one visitor who had just tried the product.
|
| These assert the prop the header branches on, because the branch itself is
| React. A prop that is right and a header that ignores it would still be a bug,
| which is what the browser pass is for; a prop that is wrong makes the header
| unfixable.
|
*/

/** A tenant user, signed in the way the shared cookie leaves them. Staff by default. */
function welcomeVisitorFrom(bool $isDemo, string $slug, Role $role = Role::TenantAdmin): User
{
    $tenant = Tenant::factory()->active()->create(['slug' => $slug]);

    if ($isDemo) {
        $tenant->is_demo = true;
        $tenant->save();
    }

    $user = User::factory()->create(['tenant_id' => $tenant->getKey()]);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->getKey());
    $user->assignRole($role->value);
    $registrar->setPermissionsTeamId(null);

    return $user;
}

it('⚠️ still treats someone who only tried the demo as a prospect', function () {
    // The regression. A demo sign-in is a trial, not an account: the landing has
    // to keep offering registration, or the release's own demo entry point ends
    // up closing the door behind the visitor it just invited in.
    $this->actingAs(welcomeVisitorFrom(isDemo: true, slug: 'demo-erdeklodo'));

    $this->get(centralUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.is_demo_visitor', true)
            // No workspace of their own to go back to — they do not have one yet,
            // and offering the demo's dashboard as "yours" would be a lie.
            ->where('auth.user.workspace_url', null)
        );
});

it('sends a real customer back to their own workspace, on their own subdomain', function () {
    $user = welcomeVisitorFrom(isDemo: false, slug: 'valodi-ugyfel');

    $this->actingAs($user);

    $this->get(centralUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.is_demo_visitor', false)
            // ⚠️ Absolute, and it has to be: their dashboard is not on this host.
            ->where(
                'auth.user.workspace_url',
                'http://valodi-ugyfel.'.config('tenancy.central_domain').'/dashboard',
            )
        );
});

it('⚠️ sends a superadmin to the admin panel, not back to the page already open', function () {
    // The production bug (SLO-249). A superadmin has no tenant, so the prop was
    // null and the header button fell back to `/` — the home page they were
    // already on. The button looked dead.
    $this->actingAs(superAdmin());

    $this->get(centralUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.is_demo_visitor', false)
            ->where('auth.user.workspace_url', superUrl('/'))
        );
});

it('sends a customer to their members area, not a staff dashboard that refuses them', function () {
    // A customer account on a real tenant: `/dashboard` sits behind
    // ensure.staff, so the old link was a 403 (SLO-249). Signing in already
    // sends them to /my/bookings; the button now agrees.
    $this->actingAs(welcomeVisitorFrom(isDemo: false, slug: 'vendeg-ugyfel', role: Role::Customer));

    $this->get(centralUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.workspace_url', tenantHost('vendeg-ugyfel', '/my/bookings'))
        );
});
