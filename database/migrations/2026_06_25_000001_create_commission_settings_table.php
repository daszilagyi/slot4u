<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level commission configuration (docs/10 §5.1). No tenant_id — this
 * is the global default. Versioned: a new row is a new configuration and the
 * old one is never overwritten, so a past period can be reconstructed with the
 * settings that were effective at the time (audit/reconciliation).
 * The effective setting is the row with the greatest effective_from <= now().
 *
 * Money is integer minor units, rates integer basis points (never float).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_settings', function (Blueprint $table) {
            $table->id();
            // docs/10 §2.1 defaults: 10 000 Ft threshold, 1.0% / 1.5% rates, 50 000 Ft cap.
            $table->bigInteger('free_threshold_minor')->default(1_000_000);
            $table->unsignedInteger('rate_bps')->default(100);
            $table->unsignedInteger('rate_with_integration_bps')->default(150);
            $table->bigInteger('monthly_cap_minor')->nullable()->default(5_000_000);
            $table->char('currency', 3)->default('HUF');
            $table->timestamp('effective_from')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_settings');
    }
};
