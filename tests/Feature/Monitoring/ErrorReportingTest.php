<?php

use App\Support\Monitoring\SentryScrubber;
use Illuminate\Support\Facades\DB;
use Sentry\Event;
use Sentry\State\HubInterface;
use Sentry\Transport\ResultStatus;

/*
|--------------------------------------------------------------------------
| Error reporting wiring (SLO-153)
|--------------------------------------------------------------------------
*/

it('sends nothing anywhere without a DSN', function () {
    // The Null-provisioner posture (SLO-135): an unconfigured environment makes
    // no outgoing call at all, so dev and CI cannot leak into someone's Sentry
    // project — and the suite never depends on the network.
    $client = app(HubInterface::class)->getClient();

    expect($client?->getOptions()->getDsn())->toBeNull();

    $result = $client?->getTransport()->send(Event::createEvent());

    expect($result?->getStatus())->toBe(ResultStatus::skipped());
});

it('routes every event through the scrubber', function () {
    // The guarantee is only worth as much as its wiring: if this callback were
    // ever dropped from the config, request bodies and emails would start
    // flowing the moment a DSN is set — with no visible symptom.
    $before = app(HubInterface::class)->getClient()?->getOptions()->getBeforeSendCallback();

    expect($before)->toBe([SentryScrubber::class, 'send']);
});

it('collects no request body at all', function () {
    expect(app(HubInterface::class)->getClient()?->getOptions()->getMaxRequestBodySize())->toBe('none');
});

it('keeps default PII collection off', function () {
    expect(app(HubInterface::class)->getClient()?->getOptions()->shouldSendDefaultPii())->toBeFalse();
});

it('answers the uptime check', function () {
    $this->get('http://'.config('tenancy.central_domain').'/up')->assertOk();
});

it('fails the uptime check when the database is unreachable', function () {
    // The reason the probe exists: Laravel's health route answers 200 as long as
    // the framework boots, so without this an uptime monitor stays green while
    // every real page 500s — the one outage nobody would be paged for.
    $original = config('database.default');

    try {
        config()->set('database.connections.unreachable', [
            'driver' => 'sqlite',
            'database' => '/nonexistent/slot4u-test.sqlite',
        ]);
        config()->set('database.default', 'unreachable');
        DB::purge('unreachable');

        $this->get('http://'.config('tenancy.central_domain').'/up')->assertStatus(500);
    } finally {
        // Before teardown: RefreshDatabase rolls back on whatever the default
        // connection is at that moment.
        config()->set('database.default', $original);
        DB::purge('unreachable');
    }
});

it('says nothing about why it failed', function () {
    // Same endpoint, unauthenticated: a database error message names the host,
    // the user and the schema.
    //
    // Asserted with debug off, because that is the only way production runs
    // (and the deploy smoke test fails the release if a debug page ever shows up
    // — SLO-152). With debug on, Laravel's health route deliberately rethrows so
    // a developer sees the real error.
    config()->set('app.debug', false);

    $original = config('database.default');

    try {
        config()->set('database.connections.unreachable', [
            'driver' => 'sqlite',
            'database' => '/nonexistent/slot4u-test.sqlite',
        ]);
        config()->set('database.default', 'unreachable');
        DB::purge('unreachable');

        $body = $this->get('http://'.config('tenancy.central_domain').'/up')->getContent();

        expect($body)
            ->not->toContain('unreachable')
            ->not->toContain('sqlite')
            ->not->toContain('SQLSTATE');
    } finally {
        config()->set('database.default', $original);
        DB::purge('unreachable');
    }
});

it('says nothing about the release or the configuration', function () {
    // Unauthenticated endpoint: liveness only. The detail lives behind the token
    // at /_deploy/health (SLO-152).
    config()->set('deploy.release', 'v9.9.9-TEST');

    $body = $this->get('http://'.config('tenancy.central_domain').'/up')->getContent();

    expect($body)
        ->not->toContain('v9.9.9-TEST')
        ->not->toContain(config('app.env'))
        ->not->toContain('mysql');
});
