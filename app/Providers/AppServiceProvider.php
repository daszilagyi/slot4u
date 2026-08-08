<?php

namespace App\Providers;

use App\Events\BookingCanceled;
use App\Events\BookingCreated;
use App\Events\BookingPriceChanged;
use App\Events\BookingStatusChanged;
use App\Events\CommissionInvoiceIssued;
use App\Events\QuoteRequestStatusChanged;
use App\Events\WaitlistOffered;
use App\Listeners\RecordBookingCommission;
use App\Listeners\RecordNotificationDelivery;
use App\Listeners\SendBookingCancellation;
use App\Listeners\SendBookingConfirmation;
use App\Listeners\SendBookingRejection;
use App\Listeners\SendCommissionInvoiceIssued;
use App\Listeners\SendQuoteReady;
use App\Listeners\SendWaitlistOffer;
use App\Models\Room;
use App\Models\Staff;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Policies\TenantUserPolicy;
use App\Services\Domain\CloudflareCustomHostnameProvisioner;
use App\Services\Domain\CustomHostnameProvisioner;
use App\Services\Domain\DnsResolver;
use App\Services\Domain\NullCustomHostnameProvisioner;
use App\Services\Domain\SystemDnsResolver;
use App\Services\Feature\FeatureResolver;
use App\Tenancy\CustomDomainResolver;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantPublicUrl;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role as RoleModel;

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

        // Custom domains (SLO-42): the host → tenant lookup runs on every
        // request, so it is scoped to memoise within one. Verification talks to
        // public DNS through a swappable resolver.
        $this->app->scoped(CustomDomainResolver::class);
        $this->app->scoped(TenantPublicUrl::class);
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);

        // Custom hostname TLS (SLO-135). Falls back to the null provisioner
        // wherever Cloudflare is not configured — dev, CI, a fresh install —
        // so those environments make no outbound calls at all.
        $this->app->bind(CustomHostnameProvisioner::class, function (): CustomHostnameProvisioner {
            $cloudflare = new CloudflareCustomHostnameProvisioner(
                token: config('tenancy.cloudflare.token'),
                zoneId: config('tenancy.cloudflare.zone_id'),
                timeout: (int) config('tenancy.cloudflare.timeout', 15),
            );

            return $cloudflare->isConfigured() ? $cloudflare : new NullCustomHostnameProvisioner;
        });
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

        // Password policy (SLO-145). Until this, `Password::default()` was used
        // without ever defining the defaults, so the whole rule was Laravel's
        // bare minimum of eight characters — for accounts that hold a tenant's
        // whole customer base. Twelve characters plus a breach check; the check
        // is a k-anonymity lookup against haveibeenpwned, and a lookup that
        // cannot be made must never lock a legitimate user out, so the verifier
        // fails open (that is Laravel's own behaviour on a network error).
        Password::defaults(fn () => Password::min(12)->uncompromised());

        // The role editor (SLO-141) authorizes against spatie's Role model, which
        // policy auto-discovery cannot map by naming convention (different
        // namespace), so the binding is explicit.
        Gate::policy(RoleModel::class, RolePolicy::class);

        // The per-user overrides (SLO-142) authorize against User, which has no
        // auto-discoverable policy of its own. Customer extends User but keeps
        // CustomerPolicy: Laravel guesses a policy by naming convention before it
        // falls back to a parent class's registration.
        Gate::policy(User::class, TenantUserPolicy::class);

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
        // An admin editing the list price moves the commission base too
        // (docs/10 §3.3, SLO-126) — open period syncs, closed period credits.
        Event::listen(BookingPriceChanged::class, RecordBookingCommission::class);

        // Commission invoicing (SLO-69 / docs/10 §6.5): a freshly issued monthly
        // invoice is emailed to the tenant's admins.
        Event::listen(CommissionInvoiceIssued::class, SendCommissionInvoiceIssued::class);
    }
}
