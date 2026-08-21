<?php

use App\Models\CommissionSetting;
use Database\Seeders\CommissionSettingSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

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
