<?php

use App\Http\Controllers\DeployHealthController;
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
});
