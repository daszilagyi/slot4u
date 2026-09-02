<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a tenant as a sales-demo workspace (SLO-182, docs/20 §3.1).
 *
 * An explicit, indexed column rather than a key in the `settings` json, and the
 * reason is the whole point of the flag: every guardrail that hangs off it —
 * mail suppression, the sandbox-only payment and invoicing branch, the exclusion
 * from the billing close and from the platform statistics — is a query
 * predicate. A json key cannot be indexed portably across MariaDB and the SQLite
 * the suite runs on, and "is this tenant allowed to send real email" is not a
 * question that may ever be answered by a full scan or, worse, by a silently
 * missing key.
 *
 * Default false: the safe answer for every existing row and every tenant that
 * signs up later. Demo is the exception a seeder opts into, never a default a
 * misconfiguration can drift into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false)->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};
