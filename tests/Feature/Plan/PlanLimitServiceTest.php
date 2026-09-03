<?php

use App\Enums\PlanLimitKey;
use App\Services\Plan\PlanLimitService;
use Database\Seeders\BasePlanSeeder;

beforeEach(function () {
    $this->seed(BasePlanSeeder::class);
    $this->service = new PlanLimitService;
});

it('resolves configured base limits', function () {
    expect($this->service->limitFor(PlanLimitKey::MaxEmployees))->toBe(8)
        ->and($this->service->limitFor(PlanLimitKey::MaxLocations))->toBe(3)
        ->and($this->service->limitFor(PlanLimitKey::MaxRooms))->toBe(8);
});

it('treats an unconfigured key as unlimited (null)', function () {
    expect($this->service->limitFor(PlanLimitKey::MaxAdmins))->toBeNull()
        ->and($this->service->limitFor(PlanLimitKey::MaxCustomers))->toBeNull();
});

it('allows creation below the limit and blocks at the cap', function () {
    // Read the cap rather than restate it: what this test is about is the
    // boundary behaviour — one below passes, exactly at it fails — and that
    // must keep holding wherever the number is set (SLO-195 moved it once).
    $cap = $this->service->limitFor(PlanLimitKey::MaxEmployees);

    expect($this->service->withinLimit(PlanLimitKey::MaxEmployees, $cap - 1))->toBeTrue()
        ->and($this->service->withinLimit(PlanLimitKey::MaxEmployees, $cap))->toBeFalse()
        ->and($this->service->withinLimit(PlanLimitKey::MaxEmployees, $cap + 1))->toBeFalse();
});

it('never blocks an unlimited resource', function () {
    expect($this->service->withinLimit(PlanLimitKey::MaxAdmins, 1_000))->toBeTrue();
});

it('reports remaining headroom, null when unlimited', function () {
    $cap = $this->service->limitFor(PlanLimitKey::MaxRooms);

    expect($this->service->remaining(PlanLimitKey::MaxRooms, 1))->toBe($cap - 1)
        // Never negative, even for a tenant already over the cap — which is
        // exactly what every existing tenant would be if a limit were ever
        // lowered again.
        ->and($this->service->remaining(PlanLimitKey::MaxRooms, $cap + 2))->toBe(0)
        ->and($this->service->remaining(PlanLimitKey::MaxAdmins, 5))->toBeNull();
});
