<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta's "user data deletion" callbacks (SLO-253, docs/28 §6).
 *
 * One row per request, so the status URL handed back to Meta can answer later.
 * Platform-level, not tenant data: the request is about a Facebook identity,
 * which may have been linked to accounts at any business.
 *
 * The Facebook user id is kept only as a sha256 hash — enough to recognise a
 * repeated request, and nothing that points back at the person once their
 * Facebook data is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_data_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->char('provider_user_hash', 64)->index();
            $table->string('confirmation_code', 32)->unique();
            $table->unsignedInteger('deleted_accounts')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_data_deletion_requests');
    }
};
