<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Services\Privacy\RetentionStep;
use App\Services\Privacy\RetentionSweep;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The log retention windows (SLO-160, docs/19 §7).
 *
 * Each window is tested at its boundary — one day inside and one day outside —
 * because an off-by-one here is invisible in production until either data has
 * been kept too long or destroyed too early, and neither is recoverable.
 *
 * The two log tables are treated differently on purpose and the tests pin that
 * down: the send ledger is **redacted** because its rows carry the dedupe keys
 * that make notifications exactly-once, while the audit trail is **deleted**
 * because nothing downstream depends on its rows existing.
 */
afterEach(function () {
    app(TenantManager::class)->forget();
});

/** @return array<string, RetentionStep> */
function sweepSteps(): array
{
    $steps = app(RetentionSweep::class)->run();
    $byName = [];

    foreach ($steps as $step) {
        $byName[$step->name] = $step;
    }

    return $byName;
}

it('redacts a send-ledger row past the window and keeps a fresh one', function () {
    $tenant = Tenant::factory()->active()->create();

    $old = NotificationLog::factory()->forTenant($tenant)->create(['recipient' => 'regi@example.test']);
    $old->forceFill(['created_at' => Carbon::now()->subDays(91)])->saveQuietly();

    $fresh = NotificationLog::factory()->forTenant($tenant)->create(['recipient' => 'friss@example.test']);
    $fresh->forceFill(['created_at' => Carbon::now()->subDays(89)])->saveQuietly();

    sweepSteps();

    expect($old->fresh()?->recipient)->toBe('redacted')
        ->and($fresh->fresh()?->recipient)->toBe('friss@example.test');
});

it('keeps the send-ledger row itself so a send cannot be resurrected', function () {
    $tenant = Tenant::factory()->active()->create();

    $old = NotificationLog::factory()->forTenant($tenant)->create([
        'recipient' => 'regi@example.test',
        'dedupe_key' => 'booking:1:reminder_24h',
    ]);
    $old->forceFill(['created_at' => Carbon::now()->subDays(200)])->saveQuietly();

    sweepSteps();

    // Deleting the row would drop the (tenant, type, dedupe_key) guard and let
    // a scheduled sender claim the same notification a second time.
    $row = $old->fresh();

    expect($row)->not->toBeNull()
        ->and($row?->dedupe_key)->toBe('booking:1:reminder_24h');
});

it('blanks an old audit row IP while keeping the row', function () {
    $entry = AuditLog::query()->create([
        'action' => AuditAction::TenantArchived->value,
        'ip_address' => '203.0.113.9',
    ]);
    $entry->forceFill(['created_at' => Carbon::now()->subDays(91)])->saveQuietly();

    sweepSteps();

    $refreshed = $entry->fresh();

    expect($refreshed)->not->toBeNull()
        ->and($refreshed?->ip_address)->toBeNull()
        // The trail itself is a separate legal basis with a much longer window.
        ->and($refreshed?->action)->toBe(AuditAction::TenantArchived->value);
});

it('keeps a recent audit row IP', function () {
    $entry = AuditLog::query()->create([
        'action' => AuditAction::TenantArchived->value,
        'ip_address' => '203.0.113.9',
    ]);
    $entry->forceFill(['created_at' => Carbon::now()->subDays(89)])->saveQuietly();

    sweepSteps();

    expect($entry->fresh()?->ip_address)->toBe('203.0.113.9');
});

it('deletes an audit row past two years and keeps one just inside', function () {
    $old = AuditLog::query()->create(['action' => AuditAction::TenantArchived->value]);
    $old->forceFill(['created_at' => Carbon::now()->subDays(731)])->saveQuietly();

    $kept = AuditLog::query()->create(['action' => AuditAction::TenantActivated->value]);
    $kept->forceFill(['created_at' => Carbon::now()->subDays(729)])->saveQuietly();

    sweepSteps();

    expect(AuditLog::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->whereKey($kept->id)->exists())->toBeTrue();
});

it('deletes audit rows in more than one chunk', function () {
    // The chunked delete loops until the query is dry; a loop that ran once
    // would silently leave everything past the first chunk in place.
    config()->set('privacy.chunk', 2);

    foreach (range(1, 5) as $i) {
        $row = AuditLog::query()->create(['action' => AuditAction::TenantArchived->value]);
        $row->forceFill(['created_at' => Carbon::now()->subDays(800)])->saveQuietly();
    }

    $steps = sweepSteps();

    expect($steps['audit_logs_deleted']->affected)->toBe(5)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('deletes an idle session row and keeps an active one', function () {
    DB::table('sessions')->insert([
        ['id' => 'stale', 'user_id' => null, 'ip_address' => '203.0.113.9', 'user_agent' => 'pest', 'payload' => 'x', 'last_activity' => Carbon::now()->subDays(31)->getTimestamp()],
        ['id' => 'live', 'user_id' => null, 'ip_address' => '203.0.113.9', 'user_agent' => 'pest', 'payload' => 'x', 'last_activity' => Carbon::now()->getTimestamp()],
    ]);

    sweepSteps();

    expect(DB::table('sessions')->where('id', 'stale')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'live')->exists())->toBeTrue();
});

it('never cuts closer than the session lifetime, whatever the window says', function () {
    config()->set('privacy.retention.session_days', 1);
    config()->set('session.lifetime', 60 * 24 * 30);

    DB::table('sessions')->insert([
        'id' => 'long-lived', 'user_id' => null, 'ip_address' => null, 'user_agent' => null, 'payload' => 'x',
        'last_activity' => Carbon::now()->subDays(3)->getTimestamp(),
    ]);

    sweepSteps();

    // A retention setting must not be able to sign a live user out.
    expect(DB::table('sessions')->where('id', 'long-lived')->exists())->toBeTrue();
});

it('reports the integration log step as skipped while the table does not exist', function () {
    $steps = sweepSteps();

    // docs/06 promises a 90-day window on `integration_logs`, but docs/02's
    // table was never built. Reporting "skipped" makes the unenforced duty
    // visible; a silent zero would read as "enforced, nothing to do".
    expect($steps['integration_logs_deleted']->wasSkipped())->toBeTrue()
        ->and($steps['integration_logs_deleted']->describe())->toContain('skipped');
});

it('runs every declared window in one pass', function () {
    $steps = sweepSteps();

    // A step silently dropped from run() would leave a documented retention
    // duty unenforced with nothing to show for it.
    expect(array_keys($steps))->toBe([
        'archived_tenants_purged',
        'notification_log_redacted',
        'audit_log_ips_redacted',
        'audit_logs_deleted',
        'sessions_deleted',
        'integration_logs_deleted',
    ]);
});

it('is exposed as a scheduled command', function () {
    $this->artisan('privacy:retention-sweep')
        ->expectsOutputToContain('integration_logs_deleted')
        ->assertSuccessful();
});
