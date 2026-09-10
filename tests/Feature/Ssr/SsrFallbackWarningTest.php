<?php

use App\Http\Middleware\WarnWhenSsrFellBack;
use App\Models\Tenant;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;

/*
|--------------------------------------------------------------------------
| Saying it out loud when SSR did not happen (SLO-222)
|--------------------------------------------------------------------------
|
| Losing server rendering is not an error. Inertia falls back to the client and
| says nothing: the page works, the browser looks right, the tests pass — and a
| crawler gets an empty shell. That silence let SLO-212 live on production for
| months, and in development it is the everyday state, because the Vite dev
| server takes over the SSR route at an address this container cannot reach.
|
| So the fallback is not what these tests are about. The talking is.
|
*/

beforeEach(function () {
    config()->set('inertia.ssr.enabled', true);

    // ⚠️ A renderer that cannot be reached, on purpose. Written first without
    // this, the end-to-end test below passed or failed depending on whether the
    // docker `ssr` service happened to be up — it rendered the page for real,
    // the marker appeared, and the warning correctly did not fire. These tests
    // are about the talking, not about the renderer, so the fallback has to be
    // the guaranteed outcome rather than the likely one. Port 1 refuses
    // immediately; a made-up hostname would cost a DNS timeout per test.
    config()->set('inertia.ssr.url', 'http://127.0.0.1:1');

    Vite::useHotFile(sys_get_temp_dir().'/slot4u-no-such-hot-file');
});

it('⚠️ warns when a page went out without server-rendered markup', function () {
    Log::spy();

    $this->get('http://'.config('tenancy.central_domain'))->assertOk();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'was NOT server-rendered')
            && str_contains($message, 'empty shell'))
        ->atLeast()->once();
});

it('stays quiet when the page carries the renderer own marker', function () {
    // The success path has to be silent, or the warning becomes wallpaper and
    // stops being read — which is the same as not having it. Driven through the
    // middleware directly, because nothing in this suite renders on the server:
    // the point is that the marker, and only the marker, decides.
    $rendered = new Response(
        '<html><body><div data-server-rendered="true" id="app"><h1>ok</h1></div></body></html>',
        200,
        ['Content-Type' => 'text/html'],
    );

    Log::spy();

    (new WarnWhenSsrFellBack)->handle(request(), fn () => $rendered);

    Log::shouldNotHaveReceived('warning');
});

it('⚠️ warns on the same response once the marker is gone', function () {
    // The other half of the pair: identical markup, marker removed. Without it
    // the test above would pass on a middleware that never warns at all.
    $shell = new Response(
        '<html><body><div id="app"></div></body></html>',
        200,
        ['Content-Type' => 'text/html'],
    );

    Log::spy();

    (new WarnWhenSsrFellBack)->handle(request(), fn () => $shell);

    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('names the Vite dev server when that is what took the render', function () {
    // The commonest case by far, and the one nobody could diagnose from the
    // symptom: the page is simply empty, and the reason is a URL in another
    // container that Inertia chose without being asked.
    // ⚠️ A temp file, not storage/framework/testing — that directory exists on
    // some runs and not others, and when the write failed silently the hot flag
    // stayed false, the middleware named a different cause, and this test failed
    // only inside the full suite. `tempnam` cannot half-exist.
    $hot = tempnam(sys_get_temp_dir(), 'slot4u-hot');
    file_put_contents($hot, 'http://localhost:5173');
    Vite::useHotFile($hot);

    Log::spy();

    try {
        $this->get('http://'.config('tenancy.central_domain'))->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'Vite is running hot')
                && str_contains($message, '__inertia_ssr')
                && str_contains($message, 'SLO-222'))
            ->atLeast()->once();
    } finally {
        @unlink($hot);
    }
});

it('says nothing at all when server rendering is switched off', function () {
    // A deliberate choice is not a fault, and a log line that fires on one is a
    // log line people learn to scroll past.
    config()->set('inertia.ssr.enabled', false);
    Log::spy();

    $this->get('http://'.config('tenancy.central_domain'))->assertOk();

    Log::shouldNotHaveReceived('warning');
});

it('⚠️ never warns in production, where the smoke test is the guard', function () {
    // There a log line per request would be noise piled on a check that already
    // stopped the release.
    app()->detectEnvironment(fn () => 'production');
    Log::spy();

    $this->get('http://'.config('tenancy.central_domain'))->assertOk();

    Log::shouldNotHaveReceived('warning');
});

it('leaves an Inertia XHR visit alone', function () {
    // Those carry JSON and are never server-rendered by design; warning about
    // them would fire on every navigation in the app.
    Tenant::factory()->active()->create(['slug' => 'acme']);
    Log::spy();

    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'x'])
        ->get('http://'.config('tenancy.central_domain'));

    Log::shouldNotHaveReceived('warning');
});
