<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The buyer's billing details, for the bookings that asked for an invoice
 * (SLO-168).
 *
 * ⚠️ Why this exists: the Billingo adapter (SLO-167) failed on its first real
 * call, and not because of the adapter. slot4u collected no buyer address
 * anywhere, and the Áfa tv. 169. § e) makes the buyer's name AND address
 * mandatory on an invoice — so no provider could have issued one.
 *
 * The decision (Daniel, 2026-08-21) is a receipt by default and an invoice on
 * request: a `nyugta` is legally sufficient for a private individual paying by
 * card, and needs none of this. So every column here is nullable and most
 * bookings will leave them so — the fields appear on the form only when someone
 * ticks "I need an invoice". Collecting an address from everyone would be more
 * personal data than the transaction requires, which is the opposite of what
 * docs/19 asks of us.
 *
 * On the booking rather than on the user: this is transaction data. It is the
 * address the invoice was issued to, and it must not change retroactively
 * because the customer later moved house — an issued invoice is a record of what
 * was true then. It also keeps erasure and export sweeping one place (docs/19).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // What the buyer asked for. Kept separately from "is there an
            // address": a request with an incomplete address is a state the
            // admin should be able to see, not one silently downgraded.
            $table->boolean('wants_invoice')->default(false)->after('source');
            $table->string('billing_name')->nullable()->after('wants_invoice');
            $table->string('billing_tax_number', 32)->nullable()->after('billing_name');
            $table->string('billing_country_code', 2)->nullable()->after('billing_tax_number');
            $table->string('billing_post_code', 16)->nullable()->after('billing_country_code');
            $table->string('billing_city', 128)->nullable()->after('billing_post_code');
            $table->string('billing_address')->nullable()->after('billing_city');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'wants_invoice',
                'billing_name',
                'billing_tax_number',
                'billing_country_code',
                'billing_post_code',
                'billing_city',
                'billing_address',
            ]);
        });
    }
};
