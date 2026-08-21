<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof that a person accepted a specific version of a specific document
 * (SLO-161, GDPR art. 7(1): the controller must be able to demonstrate consent).
 *
 * ⚠️ Deliberately NOT `user_consents`. Most of the entry points this has to cover
 * have no user row at all: a public booking is made by a guest and lands in
 * `bookings.guest_email` (SLO-159). Keying the table on `user_id` would leave
 * half the acceptances unrecorded while looking complete — the worst possible
 * outcome for a table whose only job is to be evidence. So the subject is
 * `user_id` OR `email`, and exactly one of them is set.
 *
 * `ip_address` is kept for the life of the record, unlike the IP on an audit log,
 * which the retention sweep nulls at 90 days (docs/19 §7.1). The difference is
 * what the value is *for*: on an audit row the IP is telemetry about an action
 * already recorded, while here it is part of the proof itself. A consent record
 * without the circumstances of the acceptance is a claim, not evidence.
 *
 * No user agent column: it would be a second identifier with far less probative
 * value than the timestamp and the document version, and data minimisation is
 * not optional in the table that exists to demonstrate compliance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_consents', function (Blueprint $table) {
            $table->id();
            // Always set, even for the platform terms accepted at sign-up: that
            // acceptance is made by the tenant being created, and the record has
            // to travel with the tenant (export, retention, isolation).
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Restricting the delete is the point: a document with acceptances
            // against it cannot be removed, because that would erase the evidence
            // of what was accepted. Tenants are anonymised rather than deleted
            // (docs/19 §7.2), so this never blocks the retention sweep.
            $table->foreignId('legal_document_id')->constrained()->restrictOnDelete();
            // The subject, one way or the other. A user row survives erasure
            // (anonymised in place), so this stays a valid FK afterwards.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('context', 32); // ConsentContext
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            // "Has this person accepted the current version?" — asked on every
            // request by the re-acceptance gate, so it must not be a scan.
            $table->index(['tenant_id', 'user_id', 'legal_document_id'], 'legal_consents_user_document_idx');
            // The guest side of the same question, and what an art. 15 export
            // matches on for someone who never had an account.
            $table->index(['tenant_id', 'email'], 'legal_consents_tenant_email_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_consents');
    }
};
