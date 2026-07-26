<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-side payments (docs/02 "Ügyfél-oldali fizetés", SLO-130) — the tenant's
 * OWN revenue collected through its gateway, independent of the slot4u commission
 * invoices (docs/10 §4).
 *
 * One row per checkout attempt on a booking, so an abandoned or refused attempt
 * keeps its own auditable record and the customer can retry. `provider_ref` is the
 * gateway's transaction id: it is the webhook's idempotency key, and — being
 * unguessable — also the lookup key of the sandbox checkout page.
 *
 * Money is integer minor units + currency (never float).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32); // PaymentProvider
            $table->string('provider_ref', 191)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('HUF');
            $table->string('status', 32)->default('pending'); // PaymentStatus
            $table->timestamp('paid_at')->nullable();
            $table->json('payload')->nullable(); // masked gateway callback (docs/06)
            $table->timestamps();

            // Webhook lookup + replay guard: a provider reference identifies exactly
            // one payment. Nullable refs (a checkout that never reached the gateway)
            // are exempt, as SQL treats NULLs as distinct.
            $table->unique(['provider', 'provider_ref']);
            // The booking view and the expiry sweep both read a booking's attempts.
            $table->index(['tenant_id', 'booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
