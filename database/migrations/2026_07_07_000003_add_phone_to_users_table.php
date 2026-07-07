<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer contact number (docs/02 users schema, SLO-84). Nullable — staff/
        // admin users and pre-existing rows have none. Global email uniqueness is
        // left as-is: the MVP auth model is one user (one tenant) per email.
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
