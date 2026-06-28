<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // End of the 14-day trial (docs/03): at expiry the tenant moves from
            // `trial` to `active` on the free base plan (no downgrade — the
            // commission model has no subscription). Null = no trial window.
            $table->timestamp('trial_ends_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('trial_ends_at');
        });
    }
};
