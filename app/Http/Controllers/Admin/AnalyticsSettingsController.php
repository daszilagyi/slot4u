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
                // ⚠️ The token itself never leaves the server (SLO-173). It is
                // the credential that lets anyone post conversions into the
                // tenant's ad account; a "reveal" affordance would put it into an
                // Inertia prop, and from there into any page cache or error
                // report that touches it. The screen learns only that one is set.
                'has_meta_access_token' => $settings->hasMetaAccessToken(),
                'meta_test_event_code' => $settings->metaTestEventCode,
                'server_conversions' => $settings->sendsServerConversions(),
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
        $current = TenantAnalyticsSettings::fromArray($tenant->analytics);

        // The two ids behave the OPPOSITE way to the access token, and both are
        // right:
        //
        //  - An empty id means "stop measuring with this vendor". The value is
        //    shown in the form, so a blank field is a decision, not an omission —
        //    and a tenant who wants to stop feeding an account must be able to
        //    say so by emptying the box.
        //  - An empty token means "leave it alone". The form cannot show the
        //    stored value, so it cannot send it back either, and treating a blank
        //    as a deletion would wipe the credential every time somebody edited
        //    the pixel id.
        //
        // Spread over the RAW stored array so a key this class does not model yet
        // survives an edit made through this screen.
        $tenant->analytics = [
            ...(array) $tenant->analytics,
            ...(new TenantAnalyticsSettings(
                ga4MeasurementId: $this->str($data, 'ga4_measurement_id'),
                metaPixelId: $this->str($data, 'meta_pixel_id'),
                metaAccessToken: $this->str($data, 'meta_access_token') ?? $current->metaAccessToken,
                metaTestEventCode: $this->str($data, 'meta_test_event_code'),
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
