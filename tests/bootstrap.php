<?php

/*
 * Test bootstrap. The Docker container loads the project `.env` via
 * `env_file: .env` (docker-compose.yml), which exports APP_ENV=local and the
 * dev drivers as real OS environment variables. Laravel's Env reads those
 * through getenv(), so they win over PHPUnit's <env> entries — leaving the suite
 * running as `local` (no CSRF test bypass, dev cache/mail/session drivers).
 *
 * Re-exporting the test values with putenv() here — before Laravel boots — makes
 * them authoritative. DB_* is intentionally left to the container (MariaDB,
 * matching CI), so it is not overridden.
 */

require __DIR__.'/../vendor/autoload.php';

$testEnv = [
    'APP_ENV' => 'testing',
    'APP_LOCALE' => 'hu',
    'APP_FALLBACK_LOCALE' => 'en',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'MAIL_MAILER' => 'array',
    'PENNANT_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
];

foreach ($testEnv as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
