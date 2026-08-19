<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Searches the *live database schema* for a string (SLO-159, SLO-160).
 *
 * This is the evidence behind both erasure guarantees — a customer's under
 * art. 17, and an archived tenant's under the retention policy. Written as a
 * sweep rather than a list of tables somebody remembered: a new column holding
 * a person's name should fail the test the day it lands, not the day a
 * regulator asks.
 *
 * Shared by both test suites on purpose. The single-customer erasure and the
 * tenant purge are two implementations of the same promise, and a harness that
 * existed twice would eventually check two different things.
 */
final class PersonalDataSweep
{
    /**
     * Framework bookkeeping, not tenant data. The queue tables are excluded
     * because a serialised job payload legitimately holds a recipient address
     * mid-flight.
     *
     * @var list<string>
     */
    private const IGNORED_TABLES = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    /** @var list<string> */
    private const TEXT_TYPES = ['varchar', 'text', 'char', 'json', 'longtext', 'mediumtext', 'blob'];

    /**
     * Every "table.column" in the live schema whose value contains `$needle`.
     *
     * @return list<string>
     */
    public static function find(string $needle): array
    {
        $hits = [];

        foreach (Schema::getTableListing() as $table) {
            if (in_array(self::bare($table), self::IGNORED_TABLES, true)) {
                continue;
            }

            foreach (Schema::getColumns($table) as $column) {
                if (! in_array(strtolower((string) $column['type_name']), self::TEXT_TYPES, true)) {
                    continue;
                }

                $found = DB::table($table)
                    ->where($column['name'], 'like', '%'.$needle.'%')
                    ->exists();

                if ($found) {
                    $hits[] = $table.'.'.$column['name'];
                }
            }
        }

        return $hits;
    }

    /**
     * SQLite reports table names schema-qualified ("main.bookings"), MariaDB
     * bare. Comparing the qualified name against the ignore list would never
     * match on the test database, and the sweep would quietly search the queue
     * tables too.
     */
    private static function bare(string $table): string
    {
        return str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
    }
}
