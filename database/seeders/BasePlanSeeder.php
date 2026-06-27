<?php

namespace Database\Seeders;

use App\Enums\Feature;
use App\Enums\PlanLimitKey;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanLimit;
use App\Services\Plan\PlanLimitService;
use Illuminate\Database\Seeder;

/**
 * Seeds the single free `base` plan of the commission pricing model
 * (docs/10 §5.6). Idempotent — safe to re-run.
 *
 * Base limits are the values decided in docs/10 §15.2: 3 employees, 1 location,
 * 3 rooms. Resources without a limit row (admins, customers) are unlimited.
 */
class BasePlanSeeder extends Seeder
{
    /**
     * @var array<string, int>
     */
    private const BASE_LIMITS = [
        PlanLimitKey::MaxEmployees->value => 3,
        PlanLimitKey::MaxLocations->value => 1,
        PlanLimitKey::MaxRooms->value => 3,
    ];

    public function run(): void
    {
        $plan = Plan::updateOrCreate(
            ['code' => PlanLimitService::BASE_PLAN_CODE],
            [
                'name' => 'Base',
                'monthly_price_minor' => 0,
                'currency' => 'HUF',
                'is_active' => true,
            ],
        );

        foreach (self::BASE_LIMITS as $key => $value) {
            PlanLimit::updateOrCreate(
                ['plan_id' => $plan->id, 'key' => $key],
                ['value' => $value],
            );
        }

        foreach (Feature::cases() as $feature) {
            if (! $feature->enabledByDefaultOnBase()) {
                continue;
            }

            PlanFeature::updateOrCreate(
                ['plan_id' => $plan->id, 'feature_code' => $feature->value],
            );
        }
    }
}
