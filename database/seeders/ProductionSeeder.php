<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The data the platform cannot run without (SLO-166).
 *
 * ⚠️ This exists because the deploy never seeded anything, and the gap surfaced
 * the way it always does: SLO-161 shipped the whole consent machinery to
 * production, where it sat inert because no platform document had been created.
 * Every check was green — the app booted, the migrations ran, the smoke test
 * passed — and sign-up quietly asked nobody to accept anything.
 *
 * That is the failure mode this guards. Missing catalogue data does not crash;
 * it makes a feature politely do nothing.
 *
 * Two rules hold this together:
 *
 * 1. **Everything called here is idempotent.** Each seeder returns early when its
 *    rows exist, so the deploy can run this on every release. That is the point:
 *    the NEXT piece of required data goes out by itself, instead of living in a
 *    release note somebody has to remember (the v0.7.4 tag carried exactly such
 *    a note, by hand).
 * 2. **No demo or test data, ever.** {@see TenantDemoSeeder} and the placeholder
 *    user belong to {@see DatabaseSeeder}, which is a development convenience and
 *    must never run on a real host. A test pins that boundary.
 */
class ProductionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The seeders a working environment requires, in dependency order.
     *
     * Kept as a list rather than inline calls so a test can assert what is in it
     * — the risk here is a seeder being forgotten, and a list can be checked.
     *
     * @return list<class-string<Seeder>>
     */
    public static function required(): array
    {
        return [
            // Roles and permissions: without them nobody can do anything inside
            // a tenant, including the admin who just signed up.
            PermissionSeeder::class,
            // The single free `base` plan — the plan-limit layer has nothing to
            // measure against without it (docs/03).
            BasePlanSeeder::class,
            // Commission configuration (docs/10 §2.1): no rate, no billing.
            CommissionSettingSeeder::class,
            // Platform terms and privacy notice (SLO-161): without a version in
            // force, sign-up records no acceptance and GDPR art. 7(1) is
            // undemonstrable from the first tenant onwards.
            LegalDocumentSeeder::class,
        ];
    }

    public function run(): void
    {
        foreach (self::required() as $seeder) {
            $this->call($seeder);
        }
    }
}
