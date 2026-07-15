<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-editable notification templates (docs/02, SLO-112/SLO-113). Each row
 * overrides the built-in (lang) default for one notification kind: given a
 * (tenant, key, channel, locale) the outgoing mail renders its subject + body from
 * here, with `:placeholder` substitution. No row → the platform default is used, so
 * this table is purely additive over the existing notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // A NotificationType value (booking_confirmed, reminder_24h, …).
            $table->string('key');
            $table->string('channel')->default('email');
            $table->string('locale', 10);
            $table->string('subject');
            $table->text('body');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            // At most one template per notification kind, channel and locale.
            $table->unique(['tenant_id', 'key', 'channel', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
