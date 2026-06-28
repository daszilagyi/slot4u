<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;

it('activates tenants whose trial has ended', function () {
    $expired = Tenant::factory()->create([
        'status' => TenantStatus::Trial,
        'trial_ends_at' => now()->subDay(),
    ]);

    $this->artisan('tenants:expire-trials')->assertSuccessful();

    expect($expired->fresh()->status)->toBe(TenantStatus::Active);
});

it('leaves tenants whose trial is still running', function () {
    $running = Tenant::factory()->create([
        'status' => TenantStatus::Trial,
        'trial_ends_at' => now()->addDays(7),
    ]);

    $this->artisan('tenants:expire-trials')->assertSuccessful();

    expect($running->fresh()->status)->toBe(TenantStatus::Trial);
});

it('does not touch suspended tenants past their trial date', function () {
    $suspended = Tenant::factory()->create([
        'status' => TenantStatus::Suspended,
        'trial_ends_at' => now()->subDay(),
    ]);

    $this->artisan('tenants:expire-trials')->assertSuccessful();

    expect($suspended->fresh()->status)->toBe(TenantStatus::Suspended);
});
