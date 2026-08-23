<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The index the busiest tenant screen has been missing (SLO-155).
 *
 * Every tenant-scoped query begins `where tenant_id = ?` — the global scope puts
 * it there — and the admin booking list then orders by `starts_at desc, id desc`.
 * The table had indexes for all of that separately and none for both together:
 *
 *   tenant_id                 (the foreign key's own)
 *   (staff_id, starts_at)     conflict lookups
 *   (room_id, starts_at)      conflict lookups
 *   (service_id, starts_at)   reporting
 *   (status, starts_at)       the expiry/dunning sweeps
 *   (tenant_id, guest_email)  the guest lookup
 *
 * So the list read every one of a tenant's bookings through the tenant index and
 * then filesorted them. That is invisible on a demo tenant with forty rows and
 * is the first thing to hurt on one with forty thousand — the failure mode this
 * whole issue is about, where nobody can date when the page got slow.
 *
 * `(tenant_id, starts_at)` serves the ordering, the date-range filter and the
 * tenant scope in one read. The `id` tiebreaker comes free: an InnoDB secondary
 * index carries the primary key already.
 *
 * ⚠️ Additive only. No column changes, no data movement — safe to apply while
 * the app is serving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['tenant_id', 'starts_at'], 'bookings_tenant_starts_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_tenant_starts_at_idx');
        });
    }
};
