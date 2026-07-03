<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A tenant service in any of the five booking modes (docs/02, docs/04).
        // Mode-specific fields are nullable and only meaningful for their mode
        // (enforced in ServiceRequest); free-form per-mode config lives in
        // `settings` (fulfillment_type, min/max duration, deposit, quote fields).
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Uncategorised services are allowed (null); deleting a category
            // detaches its services rather than cascading them away.
            $table->foreignId('category_id')->nullable()
                ->constrained('service_categories')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('booking_mode');
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('buffer_before_minutes')->default(0);
            $table->unsignedInteger('buffer_after_minutes')->default(0);
            // Money is integer minor units + currency, never float (docs/01 §6).
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('HUF');
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('requires_staff')->default(false);
            $table->boolean('requires_room')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->boolean('waitlist_enabled')->default(false);
            $table->boolean('online_payment_required')->default(false);
            $table->json('settings')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
