<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outgoing-notification ledger (docs/02): one row per notification the platform
 * tries to deliver, with its delivery status and any error. The
 * (tenant_id, type, dedupe_key) unique index is the idempotency guard so a given
 * notification (e.g. a booking's confirmation, a 24h reminder) is claimed and
 * sent at most once, even across retries and scheduled re-runs (SLO-35/SLO-108).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('channel')->default('email');
            $table->string('recipient');
            $table->string('status')->default('pending');
            // Stable per-notification key (e.g. "booking:12", "booking:12:reminder_24h")
            // that makes a send idempotent; null for one-off notifications with no
            // natural dedup identity.
            $table->string('dedupe_key')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'type', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_log');
    }
};
