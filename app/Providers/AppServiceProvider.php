<?php

namespace App\Providers;

use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped (not singleton): reset per request AND between queue jobs via
        // the worker's forgetScopedInstances(), so tenant state never leaks
        // across jobs on a long-running worker.
        $this->app->scoped(TenantManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Platform super-admins bypass all tenant permission checks.
        Gate::before(fn ($user) => $user instanceof User && $user->isSuperAdmin() ? true : null);
    }
}
