<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The message thread inside a quote request (docs/04 §6, SLO-27). A focused
        // table rather than the generic `messages` table of docs/02 §144 — that one
        // (sender/recipient/read_at, feature_messages) is a broader M5 concern; the
        // quote conversation only needs an ordered author/body log. Tenant-scoped.
        Schema::create('quote_request_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_request_id')->constrained()->cascadeOnDelete();
            // The author (an admin or, from M4, the customer). Nullable = a system
            // message; the record survives the user being deleted.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            // Thread render: a request's messages in chronological order.
            $table->index(['tenant_id', 'quote_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_request_messages');
    }
};
