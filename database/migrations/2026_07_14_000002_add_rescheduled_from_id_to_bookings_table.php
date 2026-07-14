<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a rescheduled booking to the one it replaces (docs/04 §2). A reschedule is
 * a cancel + create pair (RescheduleBooking), so without this pointer the new row
 * is indistinguishable from a fresh booking: the customer would get a cancellation
 * email for the old slot and a confirmation for the new one instead of a single
 * "your booking was moved" mail (SLO-109). It also gives the admin a durable audit
 * chain of which booking superseded which.
 *
 * Self-referencing FK; nullOnDelete so purging an old booking never cascades into
 * deleting its (live) replacement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('rescheduled_from_id')
                ->nullable()
                ->after('reject_reason')
                ->constrained('bookings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rescheduled_from_id');
        });
    }
};
