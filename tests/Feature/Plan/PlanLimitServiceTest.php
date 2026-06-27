<?php

use App\Enums\PlanLimitKey;
use App\Services\Plan\PlanLimitService;
use Database\Seeders\BasePlanSeeder;

beforeEach(function () {
    $this->seed(BasePlanSeeder::class);
    $this->service = new PlanLimitService;
});

it('resolves configured base limits', function () {
    expect($this->service->limitFor(PlanLimitKey::MaxEmployees))->toBe(3)
        ->and($this->service->limitFor(PlanLimitKey::MaxLocations))->toBe(1)
        ->and($this->service->limitFor(PlanLimitKey::MaxRooms))->toBe(3);
});

it('treats an unconfigured key as unlimited (null)', function () {
    expect($this->service->limitFor(PlanLimitKey::MaxAdmins))->toBeNull()
        ->and($this->service->limitFor(PlanLimitKey::MaxCustomers))->toBeNull();
});

it('allows creation below the limit and blocks at the cap', function () {
    expect($this->service->withinLimit(PlanLimitKey::MaxEmployees, 2))->toBeTrue()
        ->and($this->service->withinLimit(PlanLimitKey::MaxEmployees, 3))->toBeFalse()
        ->and($this->service->withinLimit(PlanLimitKey::MaxEmployees, 4))->toBeFalse();
});

it('never blocks an unlimited resource', function () {
    expect($this->service->withinLimit(PlanLimitKey::MaxAdmins, 1_000))->toBeTrue();
});

it('reports remaining headroom, null when unlimited', function () {
    expect($this->service->remaining(PlanLimitKey::MaxRooms, 1))->toBe(2)
        ->and($this->service->remaining(PlanLimitKey::MaxRooms, 5))->toBe(0)
        ->and($this->service->remaining(PlanLimitKey::MaxAdmins, 5))->toBeNull();
});
