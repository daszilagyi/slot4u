<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commission ledger (docs/10 §5.3) — the SOURCE OF TRUTH for turnover-based
 * billing. One row per commission-bearing booking, carrying the list-price and
 * rate snapshot taken at the moment the booking became billable (§2.4, so a
 * mid-month integration toggle never applies retroactively). The
 * tenant_billing_periods aggregate is recomputed from these rows (J5).
 *
 * Money is integer minor units, the rate is integer basis points (never float).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_commission_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // One ledger entry per booking; the state (not deletion) tracks its fate.
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM (tenant timezone calendar month)
            $table->bigInteger('amount_minor'); // booking list-price snapshot
            $table->unsignedInteger('rate_bps'); // rate snapshot at billable time (§2.4)
            $table->timestamp('realized_at'); // when it became billable — chronological order key
            $table->string('state')->default('billable'); // CommissionItemState
            $table->foreignId('settings_id')->constrained('commission_settings');
            $table->char('currency', 3)->default('HUF');
            $table->timestamps();

            // The recompute reads a tenant's billable rows for one period in order.
            $table->index(['tenant_id', 'period', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_commission_items');
    }
};
