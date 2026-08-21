<?php

use App\Http\Controllers\ConsentController;
use App\Http\Controllers\DeployHealthController;
use App\Http\Controllers\LegalDocumentController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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
    Route::get('/', fn () => Inertia::render('Welcome'))->name('home');

    // The platform's own terms and privacy notice (SLO-161). Public: nobody can
    // consent to a text they are not allowed to read, and the sign-up form links
    // straight here before an account exists.
    Route::get('/legal/{legalDocument}', [LegalDocumentController::class, 'show'])
        ->whereNumber('legalDocument')
        ->name('legal.show');

    // The re-acceptance screen (SLO-161). Registered on every host a signed-in
    // user can land on, because EnsureLegalConsent sends them to /consent
    // wherever they are — a host without the route would turn the gate into a
    // 404 the user cannot escape.
    Route::middleware('auth')->group(function () {
        Route::get('/consent', [ConsentController::class, 'show'])->name('consent.show');
        Route::post('/consent', [ConsentController::class, 'store'])->name('consent.store');
    });
});
