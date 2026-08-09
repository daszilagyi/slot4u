<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liveness marks for the moving parts that have no daemon behind them (SLO-153).
 *
 * On the shared-hosting profile (SLO-125) the queue worker and the scheduler are
 * cron lines: nothing supervises them, and if a cron stops firing the app simply
 * goes quiet — confirmation emails stop, invoices stop, and nothing says so.
 * Each run stamps its name here, and `monitor:health` alerts on a stamp that
 * stopped moving.
 *
 * A table rather than the cache on purpose: a cache flush (a routine deploy
 * step elsewhere, a Redis eviction) would look exactly like a dead worker, and
 * an alerting system that cries wolf gets muted.
 *
 * Platform-level infrastructure, so deliberately not tenant-scoped: a heartbeat
 * belongs to the installation, not to a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heartbeats', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->timestamp('beat_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heartbeats');
    }
};
