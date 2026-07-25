<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest contact details on the records a public visitor can create (SLO-128).
 *
 * `users.email` is globally unique in the MVP auth model, so a visitor whose email
 * already belongs to some other account (another tenant's customer, a staff login,
 * the super-admin) could not be turned into a customer of this tenant — the public
 * flow refused the booking outright. Such a visitor now books as a guest: no
 * account, the contact details live on the booking/quote request itself and
 * `customer_id` stays null (already nullable on both tables).
 *
 * `guest_email` is indexed because it is the only handle the admin list has on a
 * guest (search) and the only way to recognise repeat guests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('guest_name')->nullable()->after('customer_id');
            $table->string('guest_email')->nullable()->after('guest_name');
            $table->string('guest_phone', 50)->nullable()->after('guest_email');

            $table->index(['tenant_id', 'guest_email'], 'bookings_tenant_guest_email_idx');
        });

        Schema::table('quote_requests', function (Blueprint $table): void {
            $table->string('guest_name')->nullable()->after('customer_id');
            $table->string('guest_email')->nullable()->after('guest_name');
            $table->string('guest_phone', 50)->nullable()->after('guest_email');

            $table->index(['tenant_id', 'guest_email'], 'quote_requests_tenant_guest_email_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_tenant_guest_email_idx');
            $table->dropColumn(['guest_name', 'guest_email', 'guest_phone']);
        });

        Schema::table('quote_requests', function (Blueprint $table): void {
            $table->dropIndex('quote_requests_tenant_guest_email_idx');
            $table->dropColumn(['guest_name', 'guest_email', 'guest_phone']);
        });
    }
};
