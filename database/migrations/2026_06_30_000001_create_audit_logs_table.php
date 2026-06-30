<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for superadmin and critical tenant operations (SLO-78, docs/03
 * superadmin/audit; docs/02 schema). One immutable row per action: who did
 * what, when, and the old/new values.
 *
 * Not BelongsToTenant: this is a platform-level log read only in the superadmin
 * panel. `tenant_id` records which tenant the audited entity belongs to (NULL
 * for platform-wide actions); the actor (`user_id`) is typically a superadmin
 * whose own tenant_id is NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Audited entity's tenant (NULL = platform-wide). Not a tenant-scoped
            // model, so no cascade: keep the trail even if the tenant is purged.
            $table->foreignId('tenant_id')->nullable()->index();
            // Actor; NULL = system/automated action.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            // Immutable: created_at only (the model disables updated_at).
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
