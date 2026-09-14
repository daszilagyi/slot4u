<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant ↔ customer messaging (SLO-36, docs/02 §Kommunikáció). One thread
        // per customer: the customer writes to the TENANT (a shared inbox), not to
        // a particular staff user, so the thread key is `customer_id` and there is
        // no `recipient_id`. `from_customer` holds the direction explicitly rather
        // than deriving it from `sender_id`, which is nulled if a staff account goes.
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('from_customer');
            // Optional context: the booking the message is about.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            // When the OTHER side first opened the thread after this message.
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Thread render, in order.
            $table->index(['tenant_id', 'customer_id', 'id']);
            // Unread counters for either side.
            $table->index(['tenant_id', 'from_customer', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
