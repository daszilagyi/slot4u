<?php

use App\Http\Controllers\ConsentController;
use App\Http\Controllers\CookieConsentController;
use App\Http\Controllers\DeployHealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\VerticalLandingController;
use Illuminate\Support\Facades\Route;

// Deploy verification (SLO-152). Deliberately not domain-constrained: the smoke
// test runs against whatever host the pipeline was pointed at, and the answer is
// about the deployment, not about a tenant. Throttled because the token guard is
// the only thing standing in front of it.
Route::get('_deploy/health', DeployHealthController::class)
    ->middleware('throttle:20,1')
    ->name('deploy.health');

// Central (apex) domain — marketing / registration. Constrained to the central
// domain so tenant subdomains fall through to routes/tenant.php.
Route::domain(config('tenancy.central_domain'))->group(function () {
    Route::get('/', HomeController::class)->name('home');

    // The vertical landings — /autoszerviz and the trades after it (SLO-198,
    // docs/22 §4).
    //
    // ⚠️ Constrained to the registered slugs, which is what keeps a one-segment
    // route from swallowing the apex domain. Without `whereIn`, `/valami`
    // would render a landing page for a vertical nobody wrote, and every future
    // top-level path would silently belong to this controller.
    //
    // Registered only when there is something to register: an empty `whereIn`
    // compiles to a pattern that matches everything, which is the opposite of
    // what this line is for.
    if (($verticals = array_keys((array) config('verticals', []))) !== []) {
        Route::get('/{vertical}', VerticalLandingController::class)
            ->whereIn('vertical', $verticals)
            ->name('vertical');
    }

    // The platform's own terms and privacy notice (SLO-161). Public: nobody can
    // consent to a text they are not allowed to read, and the sign-up form links
    // straight here before an account exists.
    Route::get('/legal/{legalDocument}', [LegalController::class, 'show'])
        ->whereNumber('legalDocument')
        ->name('legal.show');

    // The cookie decision (SLO-165). Public and outside auth: someone declining
    // to be tracked cannot be asked to identify themselves first.
    Route::post('/cookie-consent', [CookieConsentController::class, 'store'])
        ->middleware('throttle:public')
        ->name('cookie_consent.store');

    // The re-acceptance screen (SLO-161). Registered on every host a signed-in
    // user can land on, because EnsureLegalConsent sends them to /consent
    // wherever they are — a host without the route would turn the gate into a
    // 404 the user cannot escape.
    Route::middleware('auth')->group(function () {
        Route::get('/consent', [ConsentController::class, 'show'])->name('consent.show');
        Route::post('/consent', [ConsentController::class, 'store'])->name('consent.store');
    });
});
