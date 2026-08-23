<?php

namespace App\Providers;

use App\Events\BookingCanceled;
use App\Events\BookingCreated;
use App\Events\BookingPriceChanged;
use App\Events\BookingStatusChanged;
use App\Events\CommissionInvoiceIssued;
use App\Events\QuoteRequestStatusChanged;
use App\Events\WaitlistOffered;
use App\Listeners\Analytics\RecordConversionContext;
use App\Listeners\Analytics\SendConversionOnSale;
use App\Listeners\Monitoring\RecordQueueHeartbeat;
use App\Listeners\Monitoring\VerifyDatabaseIsReachable;
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
use App\Services\Backup\BackupShell;
use App\Services\Domain\CloudflareCustomHostnameProvisioner;
use App\Services\Domain\CustomHostnameProvisioner;
use App\Services\Domain\DnsResolver;
use App\Services\Domain\NullCustomHostnameProvisioner;
use App\Services\Domain\SystemDnsResolver;
use App\Services\Feature\FeatureResolver;
use App\Services\Monitoring\Heartbeats;
use App\Support\Analytics\PageAnalytics;
use App\Tenancy\CustomDomainResolver;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantPublicUrl;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
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

        // Offsite backups (SLO-154). The shell that runs mysqldump/tar/openssl is
        // built here because its binary and timeout are configuration, and a
        // container-resolved class would silently get the constructor defaults.
        $this->app->bind(BackupShell::class, fn (): BackupShell => new BackupShell(
            shellBinary: (string) config('backup.shell_binary'),
            timeout: (int) config('backup.timeout'),
        ));

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

        // Singleton, not scoped: the heartbeat recorder throttles its writes with
        // in-process state, and the process it has to throttle is a queue worker
        // run — which would otherwise write on every loop of its own lifetime.
        $this->app->singleton(Heartbeats::class);

        // What this page measures (SLO-172 platform, SLO-56 tenant). Scoped so
        // the root Blade and the CSP builder cannot answer "does the tag load?"
        // differently within one request — a policy that disagrees with the
        // markup is the SLO-150 bug.
        //
        // No console special-case. A queue worker or an artisan command holds a
        // synthetic Request whose host is not the marketing domain, which carries
        // no consent cookie and binds no tenant, so it resolves to nothing by the
        // same conditions everything else does — and the branch that would have
        // asserted that explicitly (`runningInConsole()`) is true under Pest,
        // which would have made every test of this feature test nothing at all.
        $this->app->scoped(
            PageAnalytics::class,
            fn ($app): PageAnalytics => PageAnalytics::forRequest($app['request']),
        );
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

        $this->definePublicRateLimiters();

        // The measurement partial gets its decision injected rather than
        // resolving the container itself (SLO-172, SLO-56), so a test can render
        // the root view with a disabled instance and the Blade stays free of
        // service location.
        View::composer(
            'partials.analytics',
            fn (ViewContract $view) => $view->with('analytics', app(PageAnalytics::class)),
        );

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

        // Server-side ad conversions (SLO-173). Order matters between these two,
        // and only on BookingCreated: the context listener writes the row that
        // the sale listener then looks for, so a booking created straight into
        // `confirmed` would otherwise find nothing and never report.
        //
        // RecordConversionContext is intentionally synchronous — it reads the
        // visitor's consent and Meta cookies off the live request, which a queued
        // listener would no longer have.
        Event::listen(BookingCreated::class, RecordConversionContext::class);
        Event::listen(BookingCreated::class, SendConversionOnSale::class);
        Event::listen(BookingStatusChanged::class, SendConversionOnSale::class);
        Event::listen(BookingStatusChanged::class, RecordBookingCommission::class);
        // An admin editing the list price moves the commission base too
        // (docs/10 §3.3, SLO-126) — open period syncs, closed period credits.
        Event::listen(BookingPriceChanged::class, RecordBookingCommission::class);

        // Commission invoicing (SLO-69 / docs/10 §6.5): a freshly issued monthly
        // invoice is emailed to the tenant's admins.
        Event::listen(CommissionInvoiceIssued::class, SendCommissionInvoiceIssued::class);

        // Monitoring (SLO-153 / docs/17). The queue worker stamps that it ran, so
        // `monitor:health` can tell a quiet queue from a dead cron; `/up` gains a
        // database probe, so the uptime monitor stops reporting green while every
        // real page is failing.
        Event::listen(Looping::class, RecordQueueHeartbeat::class);
        Event::listen(DiagnosingHealth::class, VerifyDatabaseIsReachable::class);
    }

    /**
     * Rate limiters for the unauthenticated tenant surface (SLO-147).
     *
     * These replace bare `throttle:60,1` limits, which key on the caller alone.
     * On a multi-tenant app that means one tenant's visitors share a bucket with
     * every other tenant's: a burst aimed at one booking page would lock out the
     * customers of an unrelated tenant sitting behind the same NAT. Keying on the
     * host as well as the caller gives each tenant its own bucket.
     *
     * The host is the right key precisely because it needs no middleware to have
     * run first: a verified custom domain has already been rewritten to the
     * tenant's canonical subdomain by ResolveCustomDomain (a global middleware),
     * so throttling cannot depend on where in the chain it happens to sit.
     *
     * Deliberately NOT added: a per-tenant ceiling on top. It would let an
     * attacker spend a tenant's whole quota and deny that tenant's real
     * customers — trading a fairness problem for an availability one.
     */
    private function definePublicRateLimiters(): void
    {
        // Booking pages and public writes: availability lookups and inserts.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)
            ->by($this->publicRateLimitKey($request)));

        // Checkout is tighter: every attempt opens a payment row.
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(20)
            ->by($this->publicRateLimitKey($request)));

        // Crawler-facing assets: the OG image is a GD render on a cache miss.
        RateLimiter::for('seo', fn (Request $request) => Limit::perMinute(60)
            ->by($this->publicRateLimitKey($request)));

        // Gateway callbacks. Generous on purpose: a refused delivery can mean a
        // payment the app never learns about, and gateways differ on whether they
        // retry a 429. This is an amplification guard, not an access control —
        // the signature check is what authenticates the caller (SLO-130).
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(120)
            ->by($this->publicRateLimitKey($request)));
    }

    /** One bucket per (tenant host, caller) pair. */
    private function publicRateLimitKey(Request $request): string
    {
        return $request->getHost().'|'.($request->user()?->getAuthIdentifier() ?? $request->ip());
    }
}
