<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Domain\AddTenantDomain;
use App\Actions\Domain\DeleteTenantDomain;
use App\Actions\Domain\ProvisionTenantDomain;
use App\Actions\Domain\SetPrimaryTenantDomain;
use App\Actions\Domain\VerifyTenantDomain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TenantDomainRequest;
use App\Models\TenantDomain;
use App\Services\Domain\CustomHostnameProvisioner;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantPublicUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Custom domain management for a tenant (SLO-42).
 *
 * Behind auth + ensure.user.tenant + ensure.feature:feature_custom_domain +
 * can:settings.edit (routes/tenant.php). The BelongsToTenant global scope keeps
 * every read and the route binding inside the current tenant, so a foreign
 * domain id 404s rather than 403s (docs/01).
 *
 * Verification is synchronous: it is a couple of DNS lookups and the admin is
 * standing in front of the screen waiting for the answer, so a queued job would
 * only add latency and a polling UI.
 */
class DomainController extends Controller
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly TenantPublicUrl $publicUrl,
        private readonly CustomHostnameProvisioner $provisioner,
    ) {}

    public function index(): Response
    {
        $tenant = $this->tenants->current();
        abort_if($tenant === null, 404);
        Gate::authorize('viewAny', TenantDomain::class);

        $domains = TenantDomain::query()
            ->orderByDesc('is_primary')
            ->orderBy('domain')
            ->get();

        return Inertia::render('Admin/Domains/Index', [
            'domains' => $domains->map(fn (TenantDomain $domain): array => [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'is_primary' => $domain->is_primary,
                'verified' => $domain->isVerified(),
                'verified_at' => $domain->verified_at?->toIso8601String(),
                'last_checked_at' => $domain->last_checked_at?->toIso8601String(),
                'last_error' => $domain->last_error,
                'txt_name' => $domain->verificationRecordName(),
                'txt_value' => $domain->verification_token,
                // Edge provisioning (SLO-135): verified is not the same as
                // reachable, and the tenant needs to see which one is missing.
                'provisioning_status' => $domain->provisioning_status?->value,
                'certificate_status' => $domain->certificate_status,
                'provisioning_error' => $domain->provisioning_error,
                'live' => $domain->isLive(),
            ])->all(),
            // What the tenant must point DNS at, and where its public links
            // currently resolve to — the two things the setup guide needs.
            'cname_target' => config('tenancy.cname_target') ?: $this->publicUrl->subdomain($tenant),
            'subdomain' => $this->publicUrl->subdomain($tenant),
            'public_host' => $this->publicUrl->host($tenant),
            // Without a provider the UI must not promise a certificate it will
            // never get — dev and a fresh install are in exactly that state.
            'provisioning_enabled' => $this->provisioner->isConfigured(),
        ]);
    }

    public function store(TenantDomainRequest $request, AddTenantDomain $add): RedirectResponse
    {
        $tenant = $this->tenants->current();
        abort_if($tenant === null, 404);
        Gate::authorize('create', TenantDomain::class);

        $add($tenant, (string) $request->validated()['domain']);

        return back()->with('status', __('app.admin.domains.flash.added'));
    }

    public function verify(string $tenant, TenantDomain $tenantDomain, VerifyTenantDomain $verify): RedirectResponse
    {
        Gate::authorize('update', $tenantDomain);

        $verified = $verify($tenantDomain);

        return back()->with('status', __($verified ? 'app.admin.domains.flash.verified' : 'app.admin.domains.flash.verification_failed'));
    }

    public function primary(string $tenant, TenantDomain $tenantDomain, SetPrimaryTenantDomain $setPrimary): RedirectResponse
    {
        Gate::authorize('update', $tenantDomain);

        // Only a verified domain may become the canonical host — promoting an
        // unverified one would point emails at a host that serves nothing.
        abort_unless($tenantDomain->isVerified(), 422);

        $setPrimary($tenantDomain);

        return back()->with('status', __('app.admin.domains.flash.primary_set'));
    }

    /**
     * Retry the edge registration after a provider refusal, or re-read a
     * certificate that was still validating (SLO-135). Synchronous: the admin
     * pressed a button and is waiting for the answer.
     */
    public function provision(string $tenant, TenantDomain $tenantDomain, ProvisionTenantDomain $provision): RedirectResponse
    {
        Gate::authorize('update', $tenantDomain);

        abort_unless($tenantDomain->isVerified(), 422);

        $active = $provision($tenantDomain, refresh: $tenantDomain->provider_hostname_id !== null);

        return back()->with('status', __($active
            ? 'app.admin.domains.flash.provisioned'
            : 'app.admin.domains.flash.provisioning_pending'));
    }

    public function destroy(string $tenant, TenantDomain $tenantDomain, DeleteTenantDomain $delete): RedirectResponse
    {
        Gate::authorize('delete', $tenantDomain);

        $delete($tenantDomain);

        return back()->with('status', __('app.admin.domains.flash.deleted'));
    }
}
