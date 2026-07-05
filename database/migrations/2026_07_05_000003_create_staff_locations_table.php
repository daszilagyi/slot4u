<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which locations a staff member works at (docs/02, SLO-51). Both sides are
        // already tenant-scoped through their parent rows, so the pivot needs no
        // tenant_id; cascade on either deletion keeps it clean. A staff member's
        // per-location availability is expressed via schedules.location_id, which
        // must reference one of these assigned locations.
        Schema::create('staff_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['staff_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_locations');
    }
};
