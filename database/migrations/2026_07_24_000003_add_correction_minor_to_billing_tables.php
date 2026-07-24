<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carries the closed-period credits (docs/10 §8.2) through the aggregate and
 * onto the invoice.
 *
 * `tenant_billing_periods.correction_minor` is the sum of the period's
 * commission_corrections rows (<= 0), kept beside — not merged into —
 * `commission_minor`, so the dashboard can show the month's own commission and
 * the credit against it separately. The invoice keeps the same split: its
 * `commission_net_minor` is the payable net after the credit, and VAT is charged
 * on that net.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_billing_periods', function (Blueprint $table) {
            $table->bigInteger('correction_minor')->default(0)->after('commission_minor');
        });

        Schema::table('commission_invoices', function (Blueprint $table) {
            $table->bigInteger('correction_minor')->default(0)->after('billable_base_minor');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_billing_periods', function (Blueprint $table) {
            $table->dropColumn('correction_minor');
        });

        Schema::table('commission_invoices', function (Blueprint $table) {
            $table->dropColumn('correction_minor');
        });
    }
};
