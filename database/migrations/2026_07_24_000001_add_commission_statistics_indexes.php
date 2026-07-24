<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive indexes for the platform-level commission queries (SLO-123, docs/10
 * §10). Every existing commission index leads with `tenant_id`, so any
 * cross-tenant scan — the superadmin statistics dashboard, plus the two
 * scheduled sweeps — could not use one and fell back to a full table scan.
 *
 * - tenant_billing_periods.period: the stats dashboard aggregates one month
 *   across all tenants (WHERE period = ?); unique(tenant_id, period) can't serve
 *   a period-only predicate.
 * - tenant_billing_periods.status: CloseBillingPeriods scans WHERE status = 'open'
 *   across every tenant (J6).
 * - commission_invoices.(status, due_at): DunningSweep's flagOverdue and
 *   suspendPastGrace both filter status + due_at, and the dashboard lists overdue
 *   invoices by due date.
 *
 * A run migration is never edited (CLAUDE.md) — these are purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_billing_periods', function (Blueprint $table) {
            $table->index('period');
            $table->index('status');
        });

        Schema::table('commission_invoices', function (Blueprint $table) {
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_billing_periods', function (Blueprint $table) {
            $table->dropIndex(['period']);
            $table->dropIndex(['status']);
        });

        Schema::table('commission_invoices', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_at']);
        });
    }
};
