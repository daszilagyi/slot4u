<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user UI language preference (SLO-9). Nullable: when null the locale falls
 * back to the tenant locale (on tenant domains) or the app default. Used by the
 * SetLocale middleware to resolve the request locale outside tenant context
 * (e.g. the superadmin panel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 8)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
