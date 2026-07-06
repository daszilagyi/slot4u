<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Approval flow (manual_approval, docs/04 §5, SLO-26). A `requested` booking
        // soft-holds its slot until hold_expires_at; a scheduled job auto-releases
        // lapsed holds. reject_reason mirrors cancel_reason for the rejected state.
        Schema::table('bookings', function (Blueprint $table) {
            // Set at creation for a requested booking (now + tenant approval-hold
            // hours); nulled once the request is resolved. Null for every other flow.
            $table->dateTime('hold_expires_at')->nullable()->after('approved_at');
            $table->string('reject_reason')->nullable()->after('hold_expires_at');

            // The expiry job scans requested bookings by their hold deadline across
            // all tenants; the existing `status` index alone doesn't cover the range.
            $table->index(['status', 'hold_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['status', 'hold_expires_at']);
            $table->dropColumn(['hold_expires_at', 'reject_reason']);
        });
    }
};
