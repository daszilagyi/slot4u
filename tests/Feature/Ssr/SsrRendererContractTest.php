<?php

use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| What the renderer promises, asserted against a live one (SLO-212)
|--------------------------------------------------------------------------
|
| `resources/js/ssr.tsx` stopped using Inertia's `createServer` for one reason:
| in production the renderer runs under Passenger mounted at `/_ssr`, and
| Passenger does NOT strip the base URI. Inertia's server dispatches with an
| exact string lookup and takes no base-path option, so every request under the
| mount answered NOT_FOUND while looking perfectly healthy from outside.
|
| That behaviour is JavaScript, and this repository has no JavaScript test
| runner — so it is asserted here, from PHP, against a running renderer. CI
| starts one (`node bootstrap/ssr/ssr.js`, ci.yml "SSR" job); everywhere else
| these skip rather than fail, exactly like PublicSsrSmokeTest.
|
| ⚠️ Without these, the one property this change exists to provide is verified
| by nothing.
|
*/

beforeEach(function () {
    if (! ssrRendererReachable()) {
        $this->markTestSkipped(
            'No Inertia SSR renderer reachable at '.config('inertia.ssr.url')
            .' — start the `ssr` service (or run `node bootstrap/ssr/ssr.js`).'
        );
    }
});

function rendererUrl(string $path): string
{
    return rtrim((string) config('inertia.ssr.url'), '/').$path;
}

it('answers on the bare path, the way the docker service and CI call it', function () {
    expect(Http::get(rendererUrl('/health'))->json('status'))->toBe('OK');
});

it('⚠️ answers under a mount prefix too, the way production calls it', function () {
    // The whole reason this renderer is ours rather than Inertia's. Passenger
    // hands the app `/_ssr/health`; a dispatcher keyed on the exact URL replies
    // NOT_FOUND, and the page ships as an empty shell without anything failing.
    expect(Http::get(rendererUrl('/_ssr/health'))->json('status'))->toBe('OK');
});

it('answers under any prefix, so a future mount needs no code change', function () {
    expect(Http::get(rendererUrl('/wherever/it/ends/up/health'))->json('status'))->toBe('OK');
});

it('says NOT_FOUND for a path that is not one of its routes', function () {
    $response = Http::get(rendererUrl('/_ssr/nonsense'));

    expect($response->status())->toBe(404)
        ->and($response->json('status'))->toBe('NOT_FOUND');
});

it('⚠️ survives a request body that is not JSON', function () {
    // Found the hard way while writing this server: the parse threw inside an
    // async handler, which is an unhandled rejection, and Node exits on those.
    // One malformed POST took the whole renderer down — on an endpoint that is
    // reachable from the internet.
    $response = Http::withBody('this is not json', 'application/json')
        ->post(rendererUrl('/_ssr/render'));

    expect($response->status())->toBe(400);

    // The half that matters: it is still there afterwards.
    expect(Http::get(rendererUrl('/health'))->json('status'))->toBe('OK');
});
