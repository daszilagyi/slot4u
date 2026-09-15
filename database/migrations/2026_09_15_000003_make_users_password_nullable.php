<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer who signed up with Google or Facebook has no password (SLO-251).
 *
 * Null rather than a random hash, because "has a password" is a question the
 * product asks: a provider may only be unlinked while another way in remains,
 * and the password form offers "set" instead of "change" to such an account.
 * The password login answers a null hash with its own message
 * (FortifyServiceProvider::authenticateUsing), never with a hash comparison.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Irreversible for passwordless rows as they are: give them an unusable
        // hash first so the NOT NULL constraint can come back.
        DB::table('users')->whereNull('password')->update(['password' => '!']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
