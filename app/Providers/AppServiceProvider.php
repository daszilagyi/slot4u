<?php

namespace App\Providers;

use App\Events\BookingCanceled;
use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Events\QuoteRequestStatusChanged;
use App\Events\WaitlistOffered;
use App\Listeners\RecordBookingCommission;
use App\Listeners\RecordNotificationDelivery;
use App\Listeners\SendBookingCancellation;
use App\Listeners\SendBookingConfirmation;
use App\Listeners\SendBookingRejection;
use App\Listeners\SendQuoteReady;
use App\Listeners\SendWaitlistOffer;
use App\Models\Room;
use App\Models\Staff;
use App\Models\User;
use App\Services\Feature\FeatureResolver;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
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

        // Notification wiring (SLO-35/SLO-108/SLO-109): the booking lifecycle mails
        // the customer — confirmed (or moved, on a reschedule), canceled, rejected —
        // plus the waitlist offer and the finished quote. Every tracked
        // notification's delivery outcome is recorded in notifications_log.
        Event::listen(BookingCreated::class, SendBookingConfirmation::class);
        Event::listen(BookingStatusChanged::class, SendBookingConfirmation::class);
        Event::listen(BookingStatusChanged::class, SendBookingRejection::class);
        Event::listen(BookingCanceled::class, SendBookingCancellation::class);
        Event::listen(WaitlistOffered::class, SendWaitlistOffer::class);
        Event::listen(QuoteRequestStatusChanged::class, SendQuoteReady::class);
        Event::listen(NotificationSent::class, [RecordNotificationDelivery::class, 'sent']);
        Event::listen(NotificationFailed::class, [RecordNotificationDelivery::class, 'failed']);

        // Commission ledger (SLO-68 / docs/10 §6.3): a booking created into or
        // transitioning through a billable status updates its ledger entry and
        // recomputes the tenant's monthly aggregate, synchronously with the change.
        Event::listen(BookingCreated::class, RecordBookingCommission::class);
        Event::listen(BookingStatusChanged::class, RecordBookingCommission::class);
    }
}
