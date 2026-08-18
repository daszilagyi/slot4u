<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Destination (SLO-154, docs/18-backup-es-restore.md)
    |--------------------------------------------------------------------------
    |
    | The filesystem disk the backups are written to. It must be OFFSITE — the
    | point of this whole subsystem is surviving the loss of the hosting account,
    | and a backup that lives on the machine it is protecting protects nothing.
    | In production that means an S3-compatible bucket (Backblaze B2, Wasabi, ...)
    | whose credentials belong to a different provider than the web host.
    |
    | A local disk is a legitimate value for a restore drill (docs/18 §5), not for
    | production.
    |
    */

    'disk' => env('BACKUP_DISK', 's3'),

    // Prefix inside the bucket. Environment-scoped, so a staging host pointed at
    // the same bucket can never prune production's backups: retention only ever
    // sees what is under its own prefix.
    'prefix' => trim((string) env('BACKUP_PREFIX', 'backups/'.env('APP_ENV', 'production')), '/'),

    /*
    |--------------------------------------------------------------------------
    | What is backed up
    |--------------------------------------------------------------------------
    |
    | The database always. The storage tree (tenant logos and cover images, issued
    | invoice PDFs — docs/02) as a second, separate artifact rather than one
    | combined archive: in a real incident the urgent download is a few megabytes
    | of schema and rows, and nobody should have to pull a gigabyte of images to
    | get at it.
    |
    */

    'include_storage' => (bool) env('BACKUP_INCLUDE_STORAGE', true),

    // The tree that gets archived: tenant logos and cover images live under
    // `app/public`, issued invoice PDFs under `app/private`.
    'storage_path' => storage_path('app'),

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    |
    | The dump contains every customer's name, email and phone number. A private
    | bucket is the baseline; a passphrase is the belt, and it is what keeps a
    | leaked read-only bucket credential from becoming a data breach.
    |
    | Format is deliberately plain `openssl enc` — AES-256-CBC, PBKDF2, salted —
    | so a restore never depends on this application being runnable. The exact
    | decrypt command is in docs/18 §4.
    |
    | ⚠ The passphrase lives in the prod `.env`, which is NOT in the backup (see
    | below). Losing the host therefore loses the passphrase unless it is also
    | kept in a password manager. docs/18 §2 makes that a setup step, because an
    | undecryptable backup is indistinguishable from no backup.
    |
    */

    'passphrase' => (string) env('BACKUP_PASSPHRASE', ''),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Daily backups for `keep_daily` days, then one per ISO week for `keep_weekly`
    | weeks. Unbounded history is a bill, not a safety net — but the most recent
    | backup is never pruned under any setting, because a retention rule that can
    | empty the bucket is a deletion tool.
    |
    | Pruning runs only after a successful upload, so a run that failed to produce
    | a new backup can never shorten the history it failed to extend.
    |
    */

    'keep_daily' => (int) env('BACKUP_KEEP_DAILY', 14),

    'keep_weekly' => (int) env('BACKUP_KEEP_WEEKLY', 8),

    /*
    |--------------------------------------------------------------------------
    | Staleness alert
    |--------------------------------------------------------------------------
    |
    | Hours since the last successful backup before `monitor:health` calls it an
    | incident (SLO-153). The schedule is daily, so 36 hours tolerates exactly one
    | missed run — long enough not to page on a slow night, short enough that the
    | gap is still measured in hours when someone looks.
    |
    | A backup nobody watches is a backup that stopped six months ago.
    |
    */

    'stale_after_hours' => (int) env('BACKUP_STALE_AFTER_HOURS', 36),

    /*
    |--------------------------------------------------------------------------
    | Execution
    |--------------------------------------------------------------------------
    |
    | Binaries are configurable because shared hosting rarely has them on the
    | default PATH under cron (docs/13: PHP itself is /opt/cpanel/ea-php84/...).
    |
    | `mysqldump` runs with --single-transaction --quick so it neither locks the
    | booking tables nor buffers a whole table in memory, and --no-tablespaces so
    | it does not need the PROCESS privilege a shared-hosting user does not have.
    |
    */

    // Which database connection to dump. Empty means the default one, which is
    // what production wants; naming one explicitly is for a host that runs more
    // than one database on the same application.
    'connection' => env('BACKUP_DB_CONNECTION', ''),

    'mysqldump_binary' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),

    'gzip_binary' => env('BACKUP_GZIP_BINARY', 'gzip'),

    'tar_binary' => env('BACKUP_TAR_BINARY', 'tar'),

    // bash rather than sh, and not negotiable: the dump is a pipeline, and only
    // bash has `set -o pipefail`. Under dash a mysqldump that dies mid-table
    // still exits 0 through the pipe into gzip, and the run would upload a
    // truncated archive while reporting success.
    'shell_binary' => env('BACKUP_SHELL_BINARY', 'bash'),

    'openssl_binary' => env('BACKUP_OPENSSL_BINARY', 'openssl'),

    // Seconds. Generous: the dump competes with live traffic on a shared host.
    'timeout' => (int) env('BACKUP_TIMEOUT', 1800),

    // A dump smaller than this is treated as a failed dump. mysqldump can exit 0
    // after writing nothing but a header when a permission check fails mid-way,
    // and an empty archive that uploads cleanly is the worst possible outcome:
    // green logs, no data.
    'minimum_dump_bytes' => (int) env('BACKUP_MINIMUM_DUMP_BYTES', 1024),

    // Where the artifacts are assembled before upload. Outside storage/app on
    // purpose — a working directory inside the tree being archived would put the
    // last backup inside the next one.
    'working_directory' => storage_path('framework/backup'),

];
