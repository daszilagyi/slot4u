<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which buyer is which partner at the invoicing provider (SLO-167).
 *
 * ⚠️ This table exists because Billingo cannot find a partner by email. Its
 * `GET /partners?query=` matches on NAME only — verified against the live API,
 * where a query for an address returns nothing while a query for a name returns
 * the row. Without a mapping of our own, every invoice would create another
 * partner, and a tenant's Billingo account would fill with duplicates of its own
 * customers.
 *
 * Matching on name instead was the alternative, and a bad one: names collide, and
 * a customer who corrects a typo in their name would silently become a second
 * partner.
 *
 * Keyed by email because that is the handle every buyer has — a public booking
 * carries `guest_email` and no user row at all (SLO-128). A buyer with no email
 * simply has no mapping, and gets a fresh partner each time; that is honest, and
 * rare enough not to be worth a worse key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoicing_partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Per provider: the same customer is a different row at Billingo than
            // at whatever the tenant switches to next (SLO-134).
            $table->string('provider', 16);
            $table->string('email');
            // A string, not an integer: Billingo's ids are numeric but the next
            // provider's may not be, and this column is the seam.
            $table->string('partner_ref', 64);
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoicing_partners');
    }
};
