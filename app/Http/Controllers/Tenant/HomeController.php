<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Seo\OgImageGenerator;
use App\Settings\TenantBranding;
use App\Settings\TenantSettings;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantPublicUrl;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature as Pennant;

/**
 * Public tenant homepage (SLO-29): the {tenant}.slot4u.hu landing — company
 * profile, locations, and the active service catalogue grouped by category.
 * Runs in the public route group (identify.tenant → ensure.tenant.active), no
 * auth. Everything is tenant-scoped via the BelongsToTenant global scope. Cover
 * image is gated by feature_branding; logo/primary colour come from the shared
 * `tenant` Inertia prop. Inertia SSR serving of this page is SLO-88.
 */
class HomeController extends Controller
{
    public function index(): Response
    {
        // The tenant is guaranteed bound by identify.tenant before this runs.
        $tenant = app(TenantManager::class)->current();
        $settings = TenantSettings::fromArray($tenant->settings);
        $branding = TenantBranding::fromArray($tenant->branding);
        $branded = Pennant::active(Feature::Branding->value);

        // The branded OG image is rendered lazily on first /og-image.png hit; here
        // we only compute the cheap cacheKey hash to bust crawler caches on rebrand.
        $ogImage = url('/og-image.png').'?v='.app(OgImageGenerator::class)->cacheKey($tenant);

        // The page answers on both the subdomain and any verified custom domain
        // (SLO-42), which is duplicate content unless one of them is declared
        // canonical. Share and canonical URLs therefore point at the tenant's
        // primary public host, not at whichever host this request arrived on.
        $canonical = app(TenantPublicUrl::class)->to($tenant, '/');

        return Inertia::render('Tenant/Home', [
            'og_image' => $ogImage,
            'og_url' => $canonical,
            'canonical_url' => $canonical,
            'profile' => [
                'name' => $tenant->name,
                'description' => $settings->description,
                'email' => $settings->email,
                'phone' => $settings->phone,
                'address' => $this->address($settings),
                'opening_hours' => $settings->openingHours,
                'social' => (object) $settings->social,
            ],
            'branding' => [
                // logo_url + primary_color are already in the shared `tenant` prop;
                // the cover is cosmetic and only shown for branded tenants.
                'cover_url' => $branded ? $branding->coverUrl() : null,
            ],
            'categories' => $this->serviceCatalogue(),
            'locations' => $this->locations(),
        ]);
    }

    /**
     * Active services grouped by category (category order = sort_order, name).
     * Only non-empty categories are returned; services with no category fall into
     * a trailing bucket (id = null).
     *
     * @return list<array<string, mixed>>
     */
    private function serviceCatalogue(): array
    {
        $services = Service::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'category_id', 'name', 'description', 'booking_mode', 'duration_minutes', 'price_minor', 'currency']);

        /** @var Collection<int|string, Collection<int, Service>> $byCategory */
        $byCategory = $services->groupBy(fn (Service $service) => $service->category_id ?? 'none');

        $categories = ServiceCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        $groups = [];
        foreach ($categories as $category) {
            $items = $byCategory->get($category->id);
            if ($items !== null && $items->isNotEmpty()) {
                $groups[] = [
                    'id' => $category->id,
                    'name' => $category->name,
                    'services' => $items->map(fn (Service $service) => $this->service($service))->values(),
                ];
            }
        }

        // Uncategorised services last (only if any).
        $uncategorised = $byCategory->get('none');
        if ($uncategorised !== null && $uncategorised->isNotEmpty()) {
            $groups[] = [
                'id' => null,
                'name' => null,
                'services' => $uncategorised->map(fn (Service $service) => $this->service($service))->values(),
            ];
        }

        return $groups;
    }

    /**
     * @return array<string, mixed>
     */
    private function service(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'description' => $service->description,
            'booking_mode' => $service->booking_mode->value,
            'duration_minutes' => $service->duration_minutes,
            'price_minor' => (int) $service->price_minor,
            'currency' => $service->currency,
        ];
    }

    /**
     * Active locations with their public contact details (no rooms — the picker
     * that needs rooms arrives with the booking flow, SLO-30/31).
     *
     * @return list<array<string, mixed>>
     */
    private function locations(): array
    {
        return Location::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'address', 'phone'])
            ->map(fn (Location $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'address' => $this->locationAddress($location),
                'phone' => $location->phone,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{line: string|null, city: string|null, postal_code: string|null}|null
     */
    private function address(TenantSettings $settings): ?array
    {
        if ($settings->addressLine === null && $settings->addressCity === null && $settings->addressPostal === null) {
            return null;
        }

        return [
            'line' => $settings->addressLine,
            'city' => $settings->addressCity,
            'postal_code' => $settings->addressPostal,
        ];
    }

    /**
     * @return array{line: string|null, city: string|null, postal_code: string|null}|null
     */
    private function locationAddress(Location $location): ?array
    {
        $address = $location->address;
        if (! is_array($address) || $address === []) {
            return null;
        }

        return [
            'line' => $address['line'] ?? null,
            'city' => $address['city'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
        ];
    }
}
