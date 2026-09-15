<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The superadmin's wording of the system emails (SLO-246, docs/27 §5):
        // the platform's own mails, and the base text of every customer mail a
        // tenant has not overridden. One row per (mail, locale); no row = the
        // lang default. Deliberately no `tenant_id`, like platform_settings.
        Schema::create('platform_mail_texts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64);
            $table->string('locale', 10);
            $table->string('subject');
            $table->text('body');
            // The lines after the button. Null on a mail without one.
            $table->text('outro')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['key', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_mail_texts');
    }
};
