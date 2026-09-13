<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest places on an event waitlist (SLO-228, the last part of SLO-106).
 *
 * Every other public flow books a visitor whose email belongs to an account
 * elsewhere as a guest (SLO-128). The waitlist join still refused them with a
 * validation error — which told an anonymous caller that the address exists on
 * the platform. A waiter can now be a guest too: `customer_id` becomes nullable
 * and the contact details live on the entry, exactly as on bookings.
 *
 * `(tenant_id, event_id, guest_email)` is the guest counterpart of the existing
 * `(tenant_id, event_id, customer_id)` index: the duplicate check on join and
 * the conversion when the guest books both look the entry up by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->change();

            $table->string('guest_name')->nullable()->after('customer_id');
            $table->string('guest_email')->nullable()->after('guest_name');
            $table->string('guest_phone', 50)->nullable()->after('guest_email');

            $table->index(['tenant_id', 'event_id', 'guest_email'], 'waitlist_entries_tenant_event_guest_email_idx');
        });
    }

    public function down(): void
    {
        // Guest entries have no account to fall back to, and `customer_id` cannot
        // become NOT NULL again while they exist.
        DB::table('waitlist_entries')->whereNull('customer_id')->delete();

        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->dropIndex('waitlist_entries_tenant_event_guest_email_idx');
            $table->dropColumn(['guest_name', 'guest_email', 'guest_phone']);

            $table->foreignId('customer_id')->nullable(false)->change();
        });
    }
};
