<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The documents a person can be asked to accept (SLO-161, GDPR art. 7(1)).
 *
 * `tenant_id` is nullable because there are two different agreements in this
 * product, not one (docs/19 §1). The slot4u terms bind the *tenant* — the company
 * that signs up for the platform. The tenant's privacy notice binds nobody but
 * describes what the *tenant* does with its customers' data, because the tenant
 * is the controller and slot4u only the processor. A single-level table would
 * have to pretend one of those two is the other.
 *
 *   tenant_id IS NULL  → a platform document, accepted by tenants
 *   tenant_id IS SET   → that tenant's own document, accepted by its customers
 *
 * A version is never edited in place once it is effective: consent is consent to
 * a *text*, and rewriting the text under a recorded acceptance turns the proof
 * into a claim. New wording means a new row, which is also what triggers
 * re-acceptance.
 *
 * ⚠️ The unique index cannot cover platform documents: MySQL and SQLite both
 * treat NULLs as distinct in a unique index, so (NULL, 'terms', '1.0') may be
 * inserted twice as far as the database is concerned. Tenant documents — where
 * the volume and the untrusted input are — are fully covered; the platform side
 * is a superadmin-only surface guarded by validation. Said out loud rather than
 * left to be discovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 16);     // LegalDocumentType
            $table->string('version', 32);  // the tenant's own label: "1.0", "2026-08-21"
            $table->string('title');
            // Either the text itself or a link to it. The tenant may already
            // publish its notice on its own site, and forcing a copy in here
            // would guarantee the two drift apart.
            $table->longText('body')->nullable();
            $table->string('url', 2048)->nullable();
            // When this version becomes the one to accept. Future-dated rows are
            // drafts that publish themselves; that is deliberate, because a legal
            // text usually has an announced effective date.
            $table->timestamp('effective_from');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'type', 'version']);
            // "Which version is in force right now for this scope and type" —
            // the query every consent check and every acceptance form runs.
            $table->index(['tenant_id', 'type', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
