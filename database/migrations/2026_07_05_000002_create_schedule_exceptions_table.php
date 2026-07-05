<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Date-specific overrides to the weekly schedule (docs/02 §Elérhetőség):
        // time off (holiday, leave) or extra opening on a single calendar date.
        // Polymorphic over staff|room, same as schedules. `date` and the optional
        // times are wall-clock local (tenant timezone). Null start/end = whole day.
        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('schedulable_type');
            $table->unsignedBigInteger('schedulable_id');
            $table->date('date');
            // Null = the whole day (both must be null or both set — validation).
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            // 'off' closes the resource, 'extra' opens it (ScheduleExceptionType).
            $table->string('type');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['schedulable_type', 'schedulable_id', 'date'], 'schedule_exceptions_schedulable_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_exceptions');
    }
};
