<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AnalyticsSettingsRequest;
use App\Settings\TenantAnalyticsSettings;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a tenant points its public booking pages at its OWN GA4 property and
 * Meta Pixel (SLO-56).
 *
 * Behind `ensure.feature:feature_analytics` + `can:settings.edit`
 * (routes/tenant.php).
 *
 * Unlike the invoicing screen, both values travel BOTH ways: they are printed
 * into every public page anyway, so hiding them from the form that sets them
 * would protect nothing and would make a typo impossible to spot. The column is
 * still encrypted at rest, because the Conversions API access token shares it.
 */
class AnalyticsSettingsController extends Controller
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function index(): Response
    {
        $tenant = $this->tenants->current();
        abort_if($tenant === null, 404);

        $settings = TenantAnalyticsSettings::fromArray($tenant->analytics);

        return Inertia::render('Admin/Settings/Analytics', [
            'settings' => [
                'ga4_measurement_id' => $settings->ga4MeasurementId,
                'meta_pixel_id' => $settings->metaPixelId,
            ],
            // Which consent category each vendor answers to, so the screen can
            // tell the tenant the truth about when measurement actually runs
            // rather than implying "saved" means "measuring".
            'categories' => [
                'ga4' => (string) config('analytics.tenant.ga4_category'),
                'meta_pixel' => (string) config('analytics.tenant.meta_pixel_category'),
            ],
        ]);
    }

    public function update(AnalyticsSettingsRequest $request): RedirectResponse
    {
        $tenant = $this->tenants->current();
        abort_if($tenant === null, 404);

        $data = $request->validated();

        // An empty field means "stop measuring with this vendor", not "keep what
        // was there" — the opposite of the invoicing API key. The value IS shown
        // in the form, so a blank field is a deliberate erasure, and a tenant who
        // wants to stop feeding an account must be able to say so by clearing the
        // box.
        //
        // Spread over the RAW stored array rather than replacing it: this screen
        // owns two keys of a column that will hold more (the Conversions API
        // settings land here next), and a wholesale overwrite would silently drop
        // whatever it does not yet know about.
        $tenant->analytics = [
            ...(array) $tenant->analytics,
            ...(new TenantAnalyticsSettings(
                ga4MeasurementId: $this->str($data, 'ga4_measurement_id'),
                metaPixelId: $this->str($data, 'meta_pixel_id'),
            ))->toArray(),
        ];

        $tenant->save();

        return back()->with('status', __('app.analytics.settings.saved'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
