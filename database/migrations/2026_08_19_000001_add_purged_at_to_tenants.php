<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when an archived tenant's personal data was purged (SLO-160, docs/19 §7).
 *
 * The column is what makes the retention sweep idempotent: a purged tenant is
 * skipped on every later run, so an interrupted sweep can simply be run again.
 * It is also the honest answer to "has this been carried out" — `deleted_at`
 * only says the tenant was archived, not that anything was erased.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestamp('purged_at')->nullable()->after('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('purged_at');
        });
    }
};
