<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The greeting and the button's label become editable too (SLO-258).
        // Nullable: a row saved before this — or a mail without a button — keeps
        // the lang default for that part.
        Schema::table('platform_mail_texts', function (Blueprint $table) {
            $table->string('greeting')->nullable()->after('subject');
            $table->string('action_label', 120)->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('platform_mail_texts', function (Blueprint $table) {
            $table->dropColumn(['greeting', 'action_label']);
        });
    }
};
