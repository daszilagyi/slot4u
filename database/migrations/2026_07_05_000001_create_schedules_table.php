<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recurring weekly availability for a bookable resource (docs/02 §Elérhetőség).
        // Polymorphic: a schedule belongs to a staff member OR a room (morph map
        // aliases 'staff'/'room' — AppServiceProvider). Times are wall-clock local
        // values in the tenant timezone (docs/01 §7): they describe a recurring
        // pattern, not an instant, so the availability engine (M3) resolves them to
        // concrete UTC slots per date and handles DST there. Tenant-scoped via
        // BelongsToTenant; the tenant_id FK indexes the scope.
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('schedulable_type');
            $table->unsignedBigInteger('schedulable_id');
            // Availability may differ per location for the same resource (SLO-51);
            // null = applies regardless of location. Deleting a location detaches.
            $table->foreignId('location_id')->nullable()
                ->constrained('locations')->nullOnDelete();
            // ISO-8601 weekday: 1 = Monday … 7 = Sunday (Carbon dayOfWeekIso).
            $table->unsignedTinyInteger('day_of_week');
            // Wall-clock local time-of-day; a band lives within a single day
            // (end_time > start_time enforced in validation — no midnight crossing).
            $table->time('start_time');
            $table->time('end_time');
            // Optional validity window (e.g. a summer schedule); null = open-ended.
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();

            // The availability engine looks bands up by resource + weekday.
            $table->index(['schedulable_type', 'schedulable_id', 'day_of_week'], 'schedules_schedulable_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
