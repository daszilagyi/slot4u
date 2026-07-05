<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Announced occurrences for event_based services (docs/02, docs/04 §3):
        // a group class / workshop with a fixed start/end and capacity. Bookings
        // (M3) atomically increment booked_count under capacity. Times are UTC
        // instants (docs/01 §7). Tenant-scoped via BelongsToTenant.
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            // Optional resources; deleting one detaches the event rather than
            // cascading the occurrence away.
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('capacity');
            // Confirmed registrations; the M3 booking engine keeps this in step via
            // an atomic UPDATE ... WHERE booked_count < capacity (docs/02).
            $table->unsignedInteger('booked_count')->default(0);
            $table->boolean('waitlist_enabled')->default(false);
            $table->string('status')->default('scheduled');
            // The original recurrence rule for reference/display (freq + until);
            // null for a one-off event.
            $table->json('recurrence_rule')->nullable();
            // Groups the occurrences generated from one recurring announcement, so
            // "this and following" edits can target the tail of a series. Null for
            // a standalone event. (Schema extension over docs/02 for SLO-20.)
            $table->uuid('series_id')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'starts_at']);
            $table->index(['room_id', 'starts_at']);
            $table->index(['service_id', 'starts_at']);
            $table->index('series_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
