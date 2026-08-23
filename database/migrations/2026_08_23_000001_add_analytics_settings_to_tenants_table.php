<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant measurement configuration (SLO-56): the tenant's OWN GA4 property
 * and Meta Pixel, plus — from the Conversions API work that follows — the access
 * token those server-side events are sent with.
 *
 * A separate column from `settings`, and `text` rather than `json`, for the same
 * reason `invoicing` is: it is stored with the `encrypted:array` cast, and an
 * encrypted payload is a ciphertext string, not JSON a database could validate.
 *
 * Why encrypt ids that are, in the end, public? Because the column is where the
 * CAPI access token will live, and a token is only as safe as the least careful
 * write to the row it shares. Splitting "the public half" from "the secret half"
 * across two columns would mean two places to remember — and the failure mode of
 * forgetting is a live credential sitting in a plaintext column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('analytics')->nullable()->after('invoicing');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('analytics');
        });
    }
};
