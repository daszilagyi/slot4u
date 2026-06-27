<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant override of the platform commission settings (docs/10 §5.2),
 * set by the superadmin. One row per tenant (tenant_id is the primary key).
 * A NULL field means "inherit from the effective commission_settings".
 *
 * Money is integer minor units, rates integer basis points (never float).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_commission_overrides', function (Blueprint $table) {
            $table->foreignId('tenant_id')->primary()->constrained()->cascadeOnDelete();
            $table->bigInteger('free_threshold_minor')->nullable();
            $table->unsignedInteger('rate_bps')->nullable();
            $table->unsignedInteger('rate_with_integration_bps')->nullable();
            $table->bigInteger('monthly_cap_minor')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_commission_overrides');
    }
};
