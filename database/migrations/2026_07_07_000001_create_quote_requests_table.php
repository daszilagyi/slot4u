<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ask-first booking mode (docs/02, docs/04 §6, SLO-27): the customer fills
        // a service-defined form (`parameters`), the tenant works it up into a
        // price (`price_minor` + `valid_until`), and on `accepted` an optional
        // booking is generated (`booking_id`). Tenant-scoped via BelongsToTenant.
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            // The requester (a registered customer). Nullable: the M4 public form
            // may capture a non-registered enquirer, whose contact details then
            // live in `parameters`.
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('new');
            // The filled-in service form (keyed by the service's quote_fields).
            $table->json('parameters')->nullable();
            // The quoted price + its validity window (set at the `quoted` step).
            // Money is integer minor units + currency (never float, CLAUDE.md).
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->dateTime('valid_until')->nullable();
            // Admin-only working notes (never shown to the customer).
            $table->text('internal_notes')->nullable();
            // The booking generated when the quote is accepted (docs/04 §6).
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // Admin list: the tenant's requests by status, newest first.
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'service_id']);
            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_requests');
    }
};
