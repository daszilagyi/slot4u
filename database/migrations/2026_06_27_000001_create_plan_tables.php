<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-level plan catalogue. In the commission pricing model there is a
        // single free `base` plan (docs/10 §5.6); the table stays multi-row so a
        // future paid add-on tier can be introduced without a schema change.
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('monthly_price_minor')->default(0);
            $table->string('currency', 3)->default('HUF');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Quantitative limits per plan (max_employees, max_locations, ...). A missing
        // key means "unlimited". Resolved by PlanLimitService.
        Schema::create('plan_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->unsignedInteger('value');
            $table->timestamps();

            $table->unique(['plan_id', 'key']);
        });

        // Features granted by a plan by default. Tenant-level overrides live in
        // tenant_features (superadmin toggles).
        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature_code');
            $table->timestamps();

            $table->unique(['plan_id', 'feature_code']);
        });

        // Tenant-scoped feature overrides (superadmin enables/disables per tenant).
        Schema::create('tenant_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('feature_code');
            $table->boolean('enabled')->default(true);
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'feature_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_features');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plan_limits');
        Schema::dropIfExists('plans');
    }
};
