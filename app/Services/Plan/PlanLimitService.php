<?php

namespace App\Services\Plan;

use App\Enums\PlanLimitKey;
use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * Resolves quantitative plan limits. In the commission model every tenant runs
 * on the single free `base` plan (docs/10 §5.6), so limits are platform-wide;
 * the API still takes no tenant argument but is the single choke point that a
 * future per-tenant override can extend.
 *
 * A limit key absent from the plan means the resource is unlimited.
 */
class PlanLimitService
{
    public const BASE_PLAN_CODE = 'base';

    /**
     * Memoised limits for the base plan, keyed by limit key value.
     *
     * @var Collection<string, int>|null
     */
    private ?Collection $limits = null;

    /**
     * The configured limit for a key, or null when unlimited / not configured.
     */
    public function limitFor(PlanLimitKey $key): ?int
    {
        return $this->limits()->get($key->value);
    }

    /**
     * Whether one more of the resource may be created given the current count.
     * Unlimited (null) limits always pass.
     */
    public function withinLimit(PlanLimitKey $key, int $current): bool
    {
        $limit = $this->limitFor($key);

        return $limit === null || $current < $limit;
    }

    /**
     * How many more of the resource may be created, or null when unlimited.
     */
    public function remaining(PlanLimitKey $key, int $current): ?int
    {
        $limit = $this->limitFor($key);

        return $limit === null ? null : max(0, $limit - $current);
    }

    /**
     * @return Collection<string, int>
     */
    private function limits(): Collection
    {
        if ($this->limits !== null) {
            return $this->limits;
        }

        $plan = Plan::query()
            ->where('code', self::BASE_PLAN_CODE)
            ->where('is_active', true)
            ->with('limits')
            ->firstOrFail();

        return $this->limits = $plan->limits
            ->mapWithKeys(fn ($limit) => [$limit->key->value => $limit->value]);
    }
}
