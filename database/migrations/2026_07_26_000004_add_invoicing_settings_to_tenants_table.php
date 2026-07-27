<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant invoicing configuration (SLO-133): seller details and the invoicing
 * provider's API key.
 *
 * A separate column from `settings` on purpose — it holds a CREDENTIAL, so it is
 * stored encrypted at rest (`encrypted:array` cast) and must never be shared into
 * an Inertia prop the way the plain settings json is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('invoicing')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('invoicing');
        });
    }
};
