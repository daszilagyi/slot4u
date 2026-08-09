<?php

namespace App\Listeners\Monitoring;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;

/**
 * Makes `/up` mean "this app can serve", not merely "PHP started" (SLO-153).
 *
 * The uptime monitor pings that URL, and Laravel's own health route answers 200
 * as long as the framework boots — which it happily does with an unreachable
 * database, while every real page 500s. A one-row probe closes that gap for the
 * cost of one trivially cheap query.
 *
 * The exception is allowed to escape: a 500 from the health endpoint is exactly
 * what an uptime monitor needs to see. The response body stays Laravel's own,
 * so nothing about the failure is disclosed to an unauthenticated caller (the
 * detail lives behind the token at /_deploy/health, SLO-152).
 */
class VerifyDatabaseIsReachable
{
    public function handle(DiagnosingHealth $event): void
    {
        DB::connection()->select('select 1');
    }
}
