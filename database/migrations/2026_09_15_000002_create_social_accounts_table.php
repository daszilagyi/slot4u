<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Google / Facebook identity linked to a user (SLO-251, docs/28).
 *
 * `tenant_id` mirrors the owning user's tenant so the row is tenant-isolated
 * like every other tenant record (BelongsToTenant) and goes with the tenant on
 * purge. Social login is never offered to a super-admin, so it is never null.
 *
 * One provider identity belongs to exactly one user platform-wide — the same
 * shape as the global e-mail uniqueness on `users` (one e-mail, one account).
 *
 * No access or refresh token: the identity is used to sign in, never to call
 * the provider's API on the user's behalf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_user_id', 191);
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
