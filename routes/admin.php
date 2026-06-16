<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Superadmin panel (admin.{central}) — no tenant context. Auth/permission
// gating arrives with SLO-14 (superadmin) and SLO-12 (RBAC).
Route::get('/', fn () => Inertia::render('Super/Dashboard'))->name('super.dashboard');
