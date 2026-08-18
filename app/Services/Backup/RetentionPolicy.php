<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Support\Carbon;

/**
 * Decides which backup runs have expired (SLO-154).
 *
 * Pure and separate from everything that can delete: the rule that chooses what
 * to destroy should be provable on its own, without a bucket and without a
 * mistake costing anything.
 *
 * Daily backups for `keep_daily` days, then one per ISO week for `keep_weekly`
 * weeks, then nothing. Three invariants hold regardless of configuration:
 *
 *  - the newest run is never expired (a rule that can empty the destination is a
 *    deletion tool, not a retention policy);
 *  - a directory whose name is not a run timestamp is never expired — something
 *    else put it there and this is not the code that gets to decide about it;
 *  - `keep_weekly` weeks are counted from *now*, not from the newest backup, so a
 *    host that stopped backing up months ago still keeps what it has instead of
 *    watching it age out unattended.
 */
class RetentionPolicy
{
    public const RUN_ID_FORMAT = 'Y-m-d_His';

    private const RUN_ID_PATTERN = '/^\d{4}-\d{2}-\d{2}_\d{6}$/';

    public function __construct(
        private readonly int $keepDaily,
        private readonly int $keepWeekly,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (int) config('backup.keep_daily'),
            (int) config('backup.keep_weekly'),
        );
    }

    /**
     * @param  list<string>  $runIds
     * @return list<string> the run ids that may be deleted, oldest first
     */
    public function expired(array $runIds, Carbon $now): array
    {
        /** @var array<string, Carbon> $dated */
        $dated = [];

        foreach ($runIds as $runId) {
            $at = self::parse($runId);

            if ($at !== null) {
                $dated[$runId] = $at;
            }
        }

        if ($dated === []) {
            return [];
        }

        uasort($dated, static fn (Carbon $a, Carbon $b): int => $a <=> $b);

        $newest = array_key_last($dated);
        $dailyCutoff = $now->copy()->subDays($this->keepDaily);
        $weeklyCutoff = $now->copy()->subWeeks($this->keepWeekly);

        // Walking newest-first makes "the newest run of its week" a single pass:
        // the first run seen for a week is the one that survives it.
        $weeksKept = [];
        $expired = [];

        foreach (array_reverse($dated, preserve_keys: true) as $runId => $at) {
            if ($runId === $newest || $at->greaterThanOrEqualTo($dailyCutoff)) {
                continue;
            }

            if ($at->lessThan($weeklyCutoff)) {
                $expired[] = $runId;

                continue;
            }

            $week = $at->format('o-W');

            if (! isset($weeksKept[$week])) {
                $weeksKept[$week] = true;

                continue;
            }

            $expired[] = $runId;
        }

        return array_reverse($expired);
    }

    /**
     * A run id is a UTC timestamp. Anything else is not ours.
     */
    public static function parse(string $runId): ?Carbon
    {
        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            return null;
        }

        return Carbon::createFromFormat(self::RUN_ID_FORMAT, $runId, 'UTC');
    }
}
