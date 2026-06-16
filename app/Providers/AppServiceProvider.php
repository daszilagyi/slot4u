<?php

namespace App\Providers;

use App\Tenancy\TenantManager;
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
        //
    }
}
