<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit trail of every booking status transition (docs/02,
        // docs/04): who moved it from which status to which, and when. `from` is
        // null for the initial status stamped at creation. Tenant isolation comes
        // from the parent booking, so no tenant_id here (parent-scoped).
        Schema::create('booking_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            // The user who caused the transition; null for a system/job transition.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // Append-only: every entry is stamped at creation (Eloquent sets it).
            $table->timestamp('created_at');

            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_history');
    }
};
