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

it('seeds the decided base limits (3 employees, 1 location, 3 rooms)', function () {
    $limits = Plan::sole()->limits
        ->mapWithKeys(fn ($limit) => [$limit->key->value => $limit->value])
        ->all();

    expect($limits)->toBe([
        PlanLimitKey::MaxEmployees->value => 3,
        PlanLimitKey::MaxLocations->value => 1,
        PlanLimitKey::MaxRooms->value => 3,
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
