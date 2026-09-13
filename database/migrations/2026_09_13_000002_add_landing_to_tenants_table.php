<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant's public landing template and its content (SLO-238, docs/25).
 *
 * ⚠️ Its own column, not a key inside `settings`. The settings page rebuilds
 * the whole `settings` JSON from the keys it knows (UpdateTenantSettings ->
 * TenantSettings::toArray()), so landing content stored there would vanish the
 * first time a tenant saved their opening hours. Null means the default page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->json('landing')->nullable()->after('branding');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('landing');
        });
    }
};
