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
 * (docs/10 §5.6). Idempotent — safe to re-run, and re-run it is: the
 * ProductionSeeder runs this on every deploy, so a change to the values below
 * reaches existing installations without a migration.
 *
 * Base limits are the values decided in docs/10 §15.2. Resources without a
 * limit row (admins, customers) are unlimited.
 */
class BasePlanSeeder extends Seeder
{
    /**
     * The base plan's quantitative ceilings (docs/10 §15.2, raised 2026-09-03 —
     * SLO-195; previously 3 / 1 / 3).
     *
     * Under a commission model these are an abuse guard, not a price list:
     * slot4u earns a share of what a tenant turns over, so capping a growing
     * salon at three chairs caps slot4u's own revenue along with it. The
     * original figures predate that reasoning being tested against a real
     * business — the demo personas of docs/20 §2 are four of them, and three
     * could not have been built at all.
     *
     * Generous but finite: an unlimited plan would take the ceiling out of the
     * admin UI (StaffController and LocationController show the remaining
     * headroom) and leave nothing between a normal tenant and a runaway import.
     *
     * @var array<string, int>
     */
    private const BASE_LIMITS = [
        PlanLimitKey::MaxEmployees->value => 8,
        PlanLimitKey::MaxLocations->value => 3,
        PlanLimitKey::MaxRooms->value => 8,
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
