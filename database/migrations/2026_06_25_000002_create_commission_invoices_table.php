<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly commission invoice issued by slot4u TO the tenant (docs/10 §5.5) —
 * slot4u's own VAT-bearing SaaS revenue. Generated at period close when the
 * net commission is > 0. One invoice per tenant per period.
 *
 * Money is integer minor units, VAT rate integer basis points (never float).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM (tenant timezone calendar month)
            $table->bigInteger('turnover_minor')->default(0);
            $table->bigInteger('billable_base_minor')->default(0);
            $table->bigInteger('commission_net_minor')->default(0);
            $table->unsignedInteger('vat_bps')->default(2700); // HU default 27% (docs/10 §4)
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('total_gross_minor')->default(0);
            $table->char('currency', 3)->default('HUF');
            $table->string('status')->default('draft'); // CommissionInvoiceStatus
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('paid_method')->nullable();
            $table->string('provider')->nullable();     // szamlazzhu|billingo
            $table->string('provider_ref')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_invoices');
    }
};
