<?php

use App\Services\Backup\RetentionPolicy;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Backup retention (SLO-154)
|--------------------------------------------------------------------------
|
| This is the only code in the application whose job is to delete backups, so
| it is tested on its own, away from anything that owns a bucket.
|
*/

$now = Carbon::parse('2026-08-10 03:00:00', 'UTC');

/** @param list<int> $daysAgo */
$runs = function (array $daysAgo) use ($now): array {
    return array_map(
        fn (int $days): string => $now->copy()->subDays($days)->format(RetentionPolicy::RUN_ID_FORMAT),
        $daysAgo,
    );
};

it('keeps every backup inside the daily window', function () use ($now, $runs) {
    $policy = new RetentionPolicy(keepDaily: 14, keepWeekly: 8);

    expect($policy->expired($runs([0, 1, 7, 13]), $now))->toBe([]);
});

it('thins older backups down to one per ISO week', function () use ($now) {
    $policy = new RetentionPolicy(keepDaily: 7, keepWeekly: 8);

    $expired = $policy->expired([
        '2026-08-08_030000', // inside the daily window
        '2026-07-21_030000', // ISO week 30, newest of it
        '2026-07-20_030000', // ISO week 30
        '2026-07-19_030000', // ISO week 29, newest of it
        '2026-07-18_030000', // ISO week 29
        '2026-07-17_030000', // ISO week 29
    ], $now);

    expect($expired)->toBe([
        '2026-07-17_030000',
        '2026-07-18_030000',
        '2026-07-20_030000',
    ]);
});

it('deletes everything past the weekly window', function () use ($now, $runs) {
    $policy = new RetentionPolicy(keepDaily: 7, keepWeekly: 4);

    $ids = $runs([1, 60, 90]);

    expect($policy->expired($ids, $now))->toBe([$ids[2], $ids[1]]);
});

it('never expires the most recent backup, however old it is', function () use ($now, $runs) {
    // A host whose scheduler died months ago still has exactly one thing worth
    // having. A retention rule that can empty the destination is a deletion
    // tool, not a retention policy.
    $policy = new RetentionPolicy(keepDaily: 1, keepWeekly: 1);

    expect($policy->expired($runs([400]), $now))->toBe([]);
});

it('leaves directories it did not create alone', function () use ($now) {
    // Something else put them there. This is not the code that gets to decide
    // about them.
    $policy = new RetentionPolicy(keepDaily: 0, keepWeekly: 0);

    expect($policy->expired(['manual-export', 'pre-migration-2025', 'notes.txt'], $now))->toBe([]);
});

it('reads back the run ids it writes', function () {
    $at = Carbon::parse('2026-02-01 23:45:07', 'UTC');

    $runId = $at->format(RetentionPolicy::RUN_ID_FORMAT);

    expect(RetentionPolicy::parse($runId)?->toIso8601String())->toBe($at->toIso8601String())
        ->and(RetentionPolicy::parse('2026-02-01'))->toBeNull();
});
