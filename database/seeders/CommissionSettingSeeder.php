<?php

namespace Database\Seeders;

use App\Models\CommissionSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the initial platform commission configuration (docs/10 §2.1 defaults):
 * 10 000 Ft free threshold, 1.0% base / 1.5% raised rate, 50 000 Ft monthly cap.
 *
 * commission_settings is versioned (a new config is a new row), so this only
 * creates the baseline version when none exists — never overwrites history.
 */
class CommissionSettingSeeder extends Seeder
{
    public function run(): void
    {
        if (CommissionSetting::query()->exists()) {
            return;
        }

        CommissionSetting::query()->create([
            'free_threshold_minor' => 1_000_000,
            'rate_bps' => 100,
            'rate_with_integration_bps' => 150,
            'monthly_cap_minor' => 5_000_000,
            'currency' => 'HUF',
            'effective_from' => Carbon::parse('2020-01-01T00:00:00Z'),
            'created_by' => null,
        ]);
    }
}
