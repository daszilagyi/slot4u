<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds against a customer payment (docs/02, SLO-131). A cancelled paid booking
 * records its refund here as an intent first (`pending`) and the queued gateway
 * call settles it, so a gateway outage leaves an auditable obligation instead of
 * losing it. Several partial refunds may stack on one payment; their sum is capped
 * at the settled amount by the action layer.
 *
 * `tenant_id` is denormalised from the payment so the rows are tenant-isolated the
 * same way as everything else (docs/01 DoD) — a refund is money leaving a tenant's
 * account and must never be readable across tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('HUF');
            $table->string('status', 32)->default('pending'); // RefundStatus
            $table->string('reason', 500)->nullable();
            $table->string('provider_ref', 191)->nullable(); // gateway refund id
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            // The booking page reads a payment's refunds; the retry view reads the
            // tenant's open ones.
            $table->index(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
