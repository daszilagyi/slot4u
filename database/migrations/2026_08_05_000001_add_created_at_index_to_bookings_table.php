<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive index for the platform booking-volume series (SLO-138). The superadmin
 * statistics page counts bookings created per month across every tenant, i.e.
 * `WHERE created_at >= ? AND created_at < ?` with no tenant predicate at all.
 * Every existing bookings index leads with `tenant_id`, `staff_id`, `room_id`,
 * `service_id` or `status`, so that range had no index to stand on and fell back
 * to a full table scan of the busiest table in the schema.
 *
 * A run migration is never edited (CLAUDE.md) — this is purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
