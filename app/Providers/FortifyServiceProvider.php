<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\DisableTwoFactorAuthentication;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\RegisterResponse;
use App\Tenancy\TenantHostResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication as FortifyDisableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Domain-aware post-login / post-registration redirect (super-admin →
        // admin panel, tenant user → their subdomain dashboard).
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The superadmin may not switch off their own second factor (SLO-149).
        // Bound over Fortify's action rather than guarded in a middleware: the
        // controller method-injects it, so this is the one place every path to
        // disabling goes through — the button, a hand-issued DELETE, and any
        // future caller.
        $this->app->bind(
            FortifyDisableTwoFactorAuthentication::class,
            DisableTwoFactorAuthentication::class,
        );

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Headless Fortify: render our own Inertia pages (i18n via lang files).
        Fortify::loginView(fn () => Inertia::render('Auth/Login'));
        // Host-aware (SLO-95): a tenant subdomain registers a customer, the
        // central domain registers a new tenant. The Fortify route is domain-less,
        // so the tenant is resolved from the request host.
        Fortify::registerView(function (Request $request) {
            $tenant = app(TenantHostResolver::class)->resolve($request->getHost());

            if ($tenant !== null) {
                // Mirror ensure.tenant.active: a suspended tenant does not accept
                // sign-ups (the Fortify route is outside that middleware chain).
                if (! $tenant->status->isOperational()) {
                    return Inertia::render('Tenant/Suspended', ['tenantName' => $tenant->name])
                        ->toResponse($request)->setStatusCode(503);
                }

                return Inertia::render('Auth/RegisterCustomer');
            }

            return Inertia::render('Auth/Register', [
                'centralDomain' => config('tenancy.central_domain'),
            ]);
        });
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword'));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'email' => $request->string('email')->value(),
            'token' => $request->route('token'),
        ]));
        Fortify::verifyEmailView(fn () => Inertia::render('Auth/VerifyEmail'));

        // The second-factor step of signing in (SLO-149). Reached after the
        // password was accepted, so it renders on whatever host the person was
        // logging in to — Fortify's routes are domain-less, like the login form.
        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge'));

        // Re-asking for the password before touching the second factor.
        //
        // ⚠️ This is not ceremony. The threat 2FA defends against is a session
        // somebody else is holding; without this wall that same session could
        // simply turn the second factor off, or read the recovery codes, and the
        // protection would be worth exactly nothing.
        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Public self-service registration — throttle by IP against bot abuse and
        // (with the global email-unique rule) cross-tenant email enumeration.
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));

        // Fortify throttles login but NOT register, so attach our `register`
        // limiter to its register routes once they are registered. Without this
        // the `register` limiter above is dead config (POST /register is otherwise
        // unthrottled on every tenant subdomain, SLO-95 security review).
        $this->app->booted(function () {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if (in_array($route->getName(), ['register', 'register.store'], true)) {
                    $route->middleware('throttle:register');
                }
            }
        });
    }
}
