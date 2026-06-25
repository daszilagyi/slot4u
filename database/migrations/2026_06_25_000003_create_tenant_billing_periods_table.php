<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly commission aggregate per tenant (docs/10 §5.4) — a DERIVED cache
 * recomputed from the commission ledger (booking_commission_items, J5). Holds
 * the turnover and commission for the period plus its billing lifecycle.
 * One row per tenant per period.
 *
 * Money is integer minor units (never float).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_billing_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM (tenant timezone calendar month)
            $table->bigInteger('turnover_minor')->default(0);
            $table->bigInteger('commission_minor')->default(0);
            $table->boolean('cap_reached')->default(false);
            $table->string('status')->default('open'); // BillingPeriodStatus
            $table->foreignId('invoice_id')->nullable()->constrained('commission_invoices')->nullOnDelete();
            $table->timestamp('recomputed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_billing_periods');
    }
};
