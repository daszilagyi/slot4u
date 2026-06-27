<?php

use App\Models\CommissionSetting;
use Database\Seeders\CommissionSettingSeeder;

it('seeds the docs/10 §2.1 platform defaults', function () {
    $this->seed(CommissionSettingSeeder::class);

    $setting = CommissionSetting::sole();

    expect($setting->free_threshold_minor)->toBe(1_000_000)
        ->and($setting->rate_bps)->toBe(100)
        ->and($setting->rate_with_integration_bps)->toBe(150)
        ->and($setting->monthly_cap_minor)->toBe(5_000_000)
        ->and($setting->currency)->toBe('HUF');
});

it('is idempotent and never overwrites the versioned history', function () {
    $this->seed(CommissionSettingSeeder::class);
    $this->seed(CommissionSettingSeeder::class);

    expect(CommissionSetting::count())->toBe(1);
});
