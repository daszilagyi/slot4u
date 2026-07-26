<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Tenant\UpdateTenantSettings;
use App\Enums\Feature;
use App\Enums\RefundPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsRequest;
use App\Settings\TenantBranding;
use App\Settings\TenantSettings;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature as Pennant;

/**
 * Tenant company-profile + branding settings (SLO-21). Lives behind
 * auth + ensure.user.tenant + can:settings.edit (routes/tenant.php). The branding
 * section is feature-gated (`feature_branding`); when off, the UI locks it and
 * the SettingsRequest rejects any branding input.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function edit(): Response
    {
        $tenant = $this->tenants->current();
        abort_if($tenant === null, 404);

        $branding = TenantBranding::fromArray($tenant->branding);

        return Inertia::render('Admin/Settings/Index', [
            'name' => $tenant->name,
            'settings' => TenantSettings::fromArray($tenant->settings)->toArray(),
            'branding' => [
                'primary_color' => $branding->primaryColor,
                'logo_url' => $branding->logoUrl(),
                'cover_url' => $branding->coverUrl(),
            ],
            'brandingEnabled' => Pennant::active(Feature::Branding->value),
            'slotIntervals' => TenantSettings::SLOT_INTERVALS,
            'socialPlatforms' => TenantSettings::SOCIAL_PLATFORMS,
            // The refund policy only does anything for a tenant that can take money
            // online, so the section is shown gated on the same feature (SLO-131).
            'refundPolicies' => array_map(fn (RefundPolicy $policy) => $policy->value, RefundPolicy::cases()),
            'onlinePaymentEnabled' => Pennant::active(Feature::OnlinePayment->value),
        ]);
    }

    public function update(SettingsRequest $request, UpdateTenantSettings $updateSettings): RedirectResponse
    {
        $tenant = $this->tenants->current();
        abort_if($tenant === null, 404);

        $updateSettings($tenant, $request->validated(), $request->brandingEnabled());

        return back();
    }
}
