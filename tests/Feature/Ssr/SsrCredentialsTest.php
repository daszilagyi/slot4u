<?php

use App\Ssr\SsrCredentials;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Ssr\Gateway;

/*
|--------------------------------------------------------------------------
| Proving a render request came from us (SLO-212, docs/16)
|--------------------------------------------------------------------------
|
| The renderer is mounted inside our own public site (`/_ssr` under Passenger on
| the shared host), so it is reachable from the internet. Without the shared
| secret, anyone who finds the mount can POST a page object and have the server
| render arbitrary props into HTML.
|
| The secret travels as a header attached by a GLOBAL http-client middleware,
| which is the part worth testing: global means every outgoing request passes
| through it, so "does it attach to the renderer" and "does it stay off
| everything else" are equally important, and only the second one fails quietly.
|
*/

beforeEach(function () {
    config([
        'inertia.ssr.url' => 'http://ssr.test:13714',
        'inertia.ssr.shared_secret' => 'sekrit',
    ]);
});

it('attaches the secret to a request aimed at the renderer', function () {
    $request = SsrCredentials::attach(
        new Request('POST', 'http://ssr.test:13714/render'),
    );

    expect($request->getHeaderLine(SsrCredentials::HEADER))->toBe('sekrit');
});

it('⚠️ leaves every other outgoing request alone', function () {
    // The failure this guards is silent and bad: a global middleware that
    // matched too widely would post our renderer's secret to Meta's Graph API
    // (SLO-173), to the invoicing provider, to anything the app talks to.
    foreach ([
        'https://graph.facebook.com/v21.0/123/events',
        'https://api.billingo.hu/v3/documents',
        // ⚠️ The userinfo trick: a VALID url whose string starts with the
        // configured base and whose host is someone else's.
        'http://ssr.test:13714@evil.example/render',
        // Same host, different port — a neighbour on the same box.
        'http://ssr.test:9999/render',
    ] as $url) {
        $request = SsrCredentials::attach(new Request('POST', $url));

        expect($request->hasHeader(SsrCredentials::HEADER))->toBeFalse($url);
    }
});

it('attaches nothing when no secret is configured', function () {
    // Dev and CI. An empty secret must not turn into an empty header, which the
    // renderer would compare against its own empty secret and accept.
    config(['inertia.ssr.shared_secret' => '']);

    $request = SsrCredentials::attach(
        new Request('POST', 'http://ssr.test:13714/render'),
    );

    expect($request->hasHeader(SsrCredentials::HEADER))->toBeFalse();
});

it('is actually wired into the application, not merely written', function () {
    // The half that would fail silently: the helper can be perfect and the
    // secret still never sent, because nobody registered the middleware. This
    // asserts the wiring in AppServiceProvider by making a real outgoing call
    // through the app's own HTTP client and reading back what left.
    //
    // ⚠️ Deliberately NOT driving Inertia's gateway: with a vite dev server
    // running it routes SSR to the dev server instead of the bundle (SLO-222),
    // so that test would pass in CI and quietly test nothing on a developer's
    // machine — which is exactly the class of blind spot this whole issue is.
    Http::fake(['*' => Http::response(['head' => [], 'body' => '<h1>ok</h1>'])]);

    Http::post(app(Gateway::class)->getProductionUrl('/render'), ['component' => 'Welcome']);

    $sent = Http::recorded();

    expect($sent)->toHaveCount(1)
        ->and($sent[0][0]->url())->toBe('http://ssr.test:13714/render')
        ->and($sent[0][0]->header(SsrCredentials::HEADER))->toBe(['sekrit']);
});
