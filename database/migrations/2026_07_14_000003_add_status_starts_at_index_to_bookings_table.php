<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes the hourly reminder scan (SLO-110): `bookings:remind` selects confirmed
 * bookings whose starts_at falls inside the next 24 hours, across every tenant —
 * a status filter plus a range on starts_at. The table's existing starts_at indexes
 * are all resource-anchored ((staff_id|room_id|service_id, starts_at)), so none of
 * them serves a status-first scan. Mirrors the (status, hold_expires_at) index that
 * serves the soft-hold job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['status', 'starts_at']);
        });
    }
};
