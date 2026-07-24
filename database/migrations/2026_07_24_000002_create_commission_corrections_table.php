<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credits carried into an open period for a change to an already-invoiced one
 * (docs/10 §8.2/§15.5). A closed period is accounting-stable and is never
 * rewritten; when a booking it billed later shrinks or drops out, the commission
 * difference lands here as a negative delta on the tenant's current open period
 * and reduces the next monthly invoice.
 *
 * Two kinds of row (`type`):
 *  - booking_adjustment — a specific booking in `source_period` changed;
 *    `corrected_amount_minor`/`corrected_state` snapshot the reality that was
 *    credited, so a further change is measured against it, not the original.
 *  - carry_over — a credit larger than a period's own commission; the unused
 *    remainder moves to the next period instead of being forfeited.
 *
 * Money is integer minor units; `commission_delta_minor` is signed and never
 * positive (slot4u does not retro-charge a closed period).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // CommissionCorrectionType
            // Null for carry_over rows, which belong to a period, not a booking.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_period', 7); // YYYY-MM being corrected
            $table->string('period', 7);        // YYYY-MM the credit lands in
            $table->bigInteger('corrected_amount_minor')->nullable();
            $table->string('corrected_state')->nullable(); // CommissionItemState
            $table->bigInteger('commission_delta_minor'); // signed, <= 0 (credit)
            $table->char('currency', 3)->default('HUF');
            $table->timestamps();

            // The recompute sums a tenant's credits for one period.
            $table->index(['tenant_id', 'period']);
            // The correction reads back what a closed period was already credited.
            $table->index(['tenant_id', 'source_period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_corrections');
    }
};
