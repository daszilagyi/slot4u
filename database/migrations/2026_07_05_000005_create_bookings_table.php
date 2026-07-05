<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The single bookings table all six booking modes build on (docs/02,
        // docs/04). `booking_mode` snapshots the service's mode at booking time so
        // a later service mode change never rewrites history. Times are UTC
        // instants (docs/01 §7) and nullable for the no_time_slot mode. Money is
        // integer minor units + currency (docs/01 §6). Tenant-scoped.
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Public, non-guessable identifier used in customer-facing URLs/emails.
            $table->string('code')->unique();
            // The customer is a user; null for an admin booking not yet linked to
            // one (the customers module is SLO-28). Deleting the user keeps the row.
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('booking_mode');
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('status');
            $table->unsignedInteger('party_size')->default(1);
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('HUF');
            $table->text('notes')->nullable();
            // Where the booking originated (BookingSource): the public flow or admin.
            $table->string('source')->default('admin');
            $table->dateTime('canceled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();

            // Conflict lookups + reporting run by resource and time / by status.
            $table->index(['staff_id', 'starts_at']);
            $table->index(['room_id', 'starts_at']);
            $table->index(['service_id', 'starts_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
