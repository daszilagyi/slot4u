<?php

namespace App\Providers;

use App\Models\Room;
use App\Models\Staff;
use App\Models\User;
use App\Services\Feature\FeatureResolver;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Relations\Relation;
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

        // Scoped so the base-plan default lookup is memoised once per request/job
        // and shared across the Pennant feature definitions and the Inertia share.
        $this->app->scoped(FeatureResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Stable polymorphic aliases for schedulable resources (SLO-19): store
        // 'staff'/'room' in schedulable_type instead of the FQCN, matching the
        // docs/02 schema and surviving class renames. Non-enforcing so other
        // polymorphic types (e.g. audit_logs auditable) keep their FQCN.
        Relation::morphMap([
            'staff' => Staff::class,
            'room' => Room::class,
        ]);

        // Platform super-admins bypass all tenant permission checks.
        Gate::before(fn ($user) => $user instanceof User && $user->isSuperAdmin() ? true : null);
    }
}
