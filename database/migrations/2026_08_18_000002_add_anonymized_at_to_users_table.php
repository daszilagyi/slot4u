<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a user whose personal data has been erased (SLO-159).
 *
 * The erasure overwrites `name`/`email`/`phone` in place rather than deleting
 * the row, so nothing downstream can tell "erased" from "oddly named" by
 * looking at the values alone. This column is the machine-readable fact: it
 * makes the anonymisation idempotent, lets the members area refuse a second
 * request, and gives any future UI something better to render than the
 * placeholder name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }
};
