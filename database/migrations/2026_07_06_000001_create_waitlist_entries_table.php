<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FIFO waitlist for full event_based occurrences (docs/02, docs/04 §3,
        // SLO-25). When an event is full, a customer joins as `waiting`; a freed
        // seat promotes the earliest waiter to `offered` for a bounded window
        // (offered_until), then to `converted` (they booked) or `expired`.
        // Tenant-scoped via BelongsToTenant.
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // MVP waitlists an event occurrence. service_id is reserved for a
            // future service-level waitlist (docs/02 event_id|service_id) — nullable
            // and unused for now.
            $table->foreignId('event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            // Seats the waiter wants — claimed against capacity when they convert.
            // (Schema extension over docs/02: an event booking carries a party_size,
            // so the waitlist must remember how many seats to hold for.)
            $table->unsignedInteger('party_size')->default(1);
            // FIFO order within an event; assigned max(position)+1 under a lock.
            $table->unsignedInteger('position');
            $table->string('status')->default('waiting');
            // When an active offer lapses (null unless status = offered).
            $table->dateTime('offered_until')->nullable();
            $table->timestamps();

            // Next-in-line lookup (earliest waiting entry for an event), tenant-first
            // per the codebase index convention.
            $table->index(['tenant_id', 'event_id', 'status', 'position']);
            // Conversion lookup (a customer's active entry on an event).
            $table->index(['tenant_id', 'event_id', 'customer_id']);
            // Expiry scan across all tenants (the scheduled command).
            $table->index(['status', 'offered_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
