<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor authentication (SLO-149, docs/01 OWASP A07).
 *
 * Fortify has supported this since the app was scaffolded; the feature was
 * simply never switched on, so `config/fortify.php` said 2FA was "out of MVP
 * scope" and these columns never existed. The accounts it matters for are not
 * incidental ones: a tenant-admin holds a company's entire customer list, and
 * the superadmin sees every tenant and can impersonate into any of them.
 *
 * ⚠️ The secret and the recovery codes are `text`, not `string`, because
 * Fortify stores them ENCRYPTED — the ciphertext of a short secret is far longer
 * than the secret. They are also hidden on the model: a two-factor secret in an
 * Inertia prop is a second factor that anybody who can read the page has.
 *
 * `two_factor_confirmed_at` is what separates "scanned the QR" from "proved they
 * can generate a code". Without it a mistyped setup would lock the account out
 * of its own second factor, which is the classic way 2FA turns into a support
 * ticket instead of a protection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
