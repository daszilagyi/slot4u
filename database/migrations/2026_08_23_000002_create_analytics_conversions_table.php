<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per booking per server-side conversion event (SLO-173).
 *
 * The row exists for three reasons, and the third is the one that shaped the
 * schema:
 *
 * 1. **Idempotency.** A booking's status can move to `confirmed` more than once
 *    over its life. The unique key means a second attempt collides in the
 *    database rather than depending on every future caller remembering to check.
 * 2. **Observability.** A fire-and-forget outbound call that quietly stops
 *    working is invisible: the tenant sees fewer conversions and assumes fewer
 *    people booked. `status` and `last_error` are what make "Meta has been
 *    rejecting our token for a week" a question somebody can answer.
 * 3. **Consent has to outlive the request.** Whether the visitor allowed
 *    marketing is knowable only while their cookie is in hand — at booking time.
 *    The sale may be confirmed hours later by an admin, in a request that has
 *    nothing to do with that visitor. So the row is created at booking time and
 *    ONLY if consent was given: no row is the durable record of "no".
 *
 * `fbp` / `fbc` are Meta's browser identifiers, read from the visitor's own
 * cookies. They are nulled the moment the event is sent (see the job) — they
 * have no purpose afterwards, and personal data with no purpose should not be
 * sitting in a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // Named rather than assumed: a second ad platform would add rows
            // here, not a second table.
            $table->string('provider', 32);
            $table->string('event_name', 64);

            // The booking code. Also what the browser event sends as its
            // `eventID`, which is how Meta knows the two are one conversion.
            $table->string('event_id', 64);

            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->string('fbp', 128)->nullable();
            $table->string('fbc', 255)->nullable();
            $table->string('event_source_url', 2048)->nullable();

            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // The idempotency guarantee, in the one place that cannot be talked
            // out of it.
            $table->unique(['tenant_id', 'booking_id', 'provider', 'event_name'], 'analytics_conversions_unique');
            // "What is stuck?" — the only query the operator side ever runs.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_conversions');
    }
};
