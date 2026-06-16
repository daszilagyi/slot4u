<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Central (apex) domain — marketing / registration. Constrained to the central
// domain so tenant subdomains fall through to routes/tenant.php.
Route::domain(config('tenancy.central_domain'))->group(function () {
    Route::get('/', fn () => Inertia::render('Welcome'))->name('home');
});
