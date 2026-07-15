<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the billable base (turnover above the free threshold, pre-cap) on the
 * monthly aggregate. It is part of the CommissionResult already computed by
 * RecomputeTenantPeriod, and the commission invoice (J6) + transparency
 * dashboard (J7) both need it — persisting it here keeps it a single source of
 * truth rather than re-deriving it downstream. Integer minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_billing_periods', function (Blueprint $table) {
            $table->bigInteger('billable_base_minor')->default(0)->after('turnover_minor');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_billing_periods', function (Blueprint $table) {
            $table->dropColumn('billable_base_minor');
        });
    }
};
