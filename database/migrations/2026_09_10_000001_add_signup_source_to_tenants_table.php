<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which campaign produced this tenant (SLO-210, docs/22 §4).
 *
 * ⚠️ Columns rather than one JSON blob, unlike `settings` / `analytics` on the
 * same table. Those hold configuration: read whole, written whole, never
 * aggregated. This is analytical data whose entire purpose is `GROUP BY` and
 * `WHERE` — "which vertical brought the cheaper tenant" is a question you ask of
 * a column, and a JSON path answers it differently on SQLite (bare scalar) than
 * on MariaDB (quoted JSON value), which is the sort of difference that passes
 * the test suite and comes back wrong in production.
 *
 * Indexed where we will actually group: source, campaign and landing path.
 * `signup_utm_medium` is not — it is read alongside the others, never grouped
 * on its own, and an index nothing uses still costs every insert.
 *
 * Nullable throughout and NOT backfilled: every tenant that exists today
 * genuinely has no known source, and inventing "direct" for them would turn an
 * absence of data into a claim about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            // 200 matches TenantSignupSource::MAX_LENGTH, which is where the
            // truncation actually happens. The column length is the second
            // line of defence, not the first — a value that reached here
            // untruncated would be a bug in the value object, and a silent
            // database truncation is not how it should surface.
            $table->string('signup_utm_source', 200)->nullable()->after('analytics')->index();
            $table->string('signup_utm_medium', 200)->nullable()->after('signup_utm_source');
            $table->string('signup_utm_campaign', 200)->nullable()->after('signup_utm_medium')->index();
            $table->string('signup_landing_path', 200)->nullable()->after('signup_utm_campaign')->index();
            $table->timestamp('signup_landed_at')->nullable()->after('signup_landing_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex(['signup_utm_source']);
            $table->dropIndex(['signup_utm_campaign']);
            $table->dropIndex(['signup_landing_path']);
            $table->dropColumn([
                'signup_utm_source',
                'signup_utm_medium',
                'signup_utm_campaign',
                'signup_landing_path',
                'signup_landed_at',
            ]);
        });
    }
};
