<?php

use App\Enums\Feature;
use App\Enums\PlanLimitKey;
use App\Models\Plan;
use Database\Seeders\BasePlanSeeder;

beforeEach(function () {
    $this->seed(BasePlanSeeder::class);
});

it('seeds exactly one free, active base plan', function () {
    expect(Plan::count())->toBe(1);

    $plan = Plan::sole();

    expect($plan->code)->toBe('base')
        ->and($plan->monthly_price_minor)->toBe(0)
        ->and($plan->currency)->toBe('HUF')
        ->and($plan->is_active)->toBeTrue();
});

it('seeds the decided base limits (8 employees, 3 locations, 8 rooms)', function () {
    $limits = Plan::sole()->limits
        ->mapWithKeys(fn ($limit) => [$limit->key->value => $limit->value])
        ->all();

    // Pinned literally on purpose: this is the one place the decision of
    // docs/10 §15.2 is written down as a number, so changing it should have to
    // pass through a test that says so (raised from 3 / 1 / 3 in SLO-195).
    expect($limits)->toBe([
        PlanLimitKey::MaxEmployees->value => 8,
        PlanLimitKey::MaxLocations->value => 3,
        PlanLimitKey::MaxRooms->value => 8,
    ]);
});

it('grants only the default-on-base features (rate-raising integrations stay off)', function () {
    $granted = Plan::sole()->features->pluck('feature_code');

    $expected = collect(Feature::cases())->filter->enabledByDefaultOnBase()->values();

    expect($granted->sort()->values()->all())->toBe($expected->sort()->values()->all())
        ->and($granted)->not->toContain(Feature::OnlinePayment)
        ->and($granted)->not->toContain(Feature::Invoicing);
});

it('is idempotent — re-running does not duplicate rows', function () {
    $this->seed(BasePlanSeeder::class);

    expect(Plan::count())->toBe(1)
        ->and(Plan::sole()->limits)->toHaveCount(3);
});
