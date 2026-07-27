<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer invoices (docs/02, SLO-133) — the invoice the TENANT issues to ITS
 * customer for a settled payment, unrelated to the slot4u commission invoices
 * (docs/10 §4), which live in `commission_invoices`.
 *
 * One row per invoiced payment (unique `payment_id`): the row is created pending
 * when the payment settles and the queued issuer fills in the number and PDF, so
 * a provider outage leaves a retryable obligation instead of a silent gap. A full
 * refund voids the invoice in place (`status = storno` + the storno number/PDF)
 * rather than creating a second row, so a payment always has exactly one invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 32); // InvoiceProvider
            $table->string('provider_ref', 191)->nullable();
            $table->string('number', 64)->nullable(); // the provider's invoice number
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('HUF');
            $table->string('status', 32)->default('pending'); // InvoiceStatus
            $table->string('pdf_path', 512)->nullable(); // private disk, per-tenant prefix
            $table->timestamp('issued_at')->nullable();
            // Storno of a fully refunded invoice (kept on the same row).
            $table->string('storno_number', 64)->nullable();
            $table->string('storno_pdf_path', 512)->nullable();
            $table->timestamp('stornoed_at')->nullable();
            // Last provider error, shown to the admin next to the retry action.
            $table->string('error', 500)->nullable();
            $table->timestamps();

            // The admin booking page reads a booking's invoice; the members list
            // and the failure sweep read a tenant's by status.
            $table->index(['tenant_id', 'booking_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
