<?php

use App\Enums\SocialProvider;
use App\Http\Controllers\Auth\SocialCallbackController;
use App\Http\Controllers\Auth\SocialConsumeController;
use App\Http\Controllers\Auth\SocialEmailController;
use App\Http\Controllers\Auth\SocialRedirectController;
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

    // Social sign-in (SLO-251, docs/28). The redirect and consume steps exist on
    // every host a person can sign in on (the tenant copies are in
    // routes/tenant.php); the provider callback exists ONLY here, because it
    // is the single redirect URI Google and Meta accept.
    Route::middleware('throttle:social')->group(function () {
        Route::get('/auth/social/consume', SocialConsumeController::class)->name('social.consume');
        Route::get('/auth/social/email', [SocialEmailController::class, 'show'])->name('social.email');
        Route::post('/auth/social/email', [SocialEmailController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('social.email.store');
        Route::get('/auth/social/email/confirm', [SocialEmailController::class, 'confirm'])->name('social.email.confirm');
        Route::get('/auth/{provider}/redirect', SocialRedirectController::class)
            ->whereIn('provider', array_column(SocialProvider::cases(), 'value'))
            ->name('social.redirect');
        Route::get('/auth/{provider}/callback', SocialCallbackController::class)
            ->whereIn('provider', array_column(SocialProvider::cases(), 'value'))
            ->name('social.callback');
    });

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
