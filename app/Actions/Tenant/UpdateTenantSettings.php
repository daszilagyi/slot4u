<?php

namespace App\Actions\Tenant;

use App\Models\Tenant;
use App\Settings\TenantBranding;
use App\Settings\TenantSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Persists the tenant settings page (SLO-21): company profile + booking rules
 * always; branding (colour, logo, cover) only when the feature is enabled.
 * Uploaded images land on the `public` disk under a per-tenant prefix; replaced
 * or removed files are deleted so no orphans accumulate.
 */
class UpdateTenantSettings
{
    /**
     * @param  array<string, mixed>  $data  validated SettingsRequest payload
     */
    public function __invoke(Tenant $tenant, array $data, bool $brandingEnabled): Tenant
    {
        // The display name lives on the tenant row (not the settings JSON).
        if (isset($data['name']) && is_string($data['name'])) {
            $tenant->name = $data['name'];
        }

        $tenant->settings = $this->buildSettings($data)->toArray();

        if ($brandingEnabled) {
            $tenant->branding = $this->buildBranding($tenant, $data)->toArray();
        }

        $tenant->save();

        return $tenant;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildSettings(array $data): TenantSettings
    {
        return TenantSettings::fromArray([
            'description' => $data['description'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address_line' => $data['address_line'] ?? null,
            'address_city' => $data['address_city'] ?? null,
            'address_postal' => $data['address_postal'] ?? null,
            'opening_hours' => $data['opening_hours'] ?? null,
            'social' => $data['social'] ?? [],
            'cancellation_deadline_hours' => $data['cancellation_deadline_hours'] ?? null,
            'slot_interval_minutes' => $data['slot_interval_minutes'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildBranding(Tenant $tenant, array $data): TenantBranding
    {
        $current = TenantBranding::fromArray($tenant->branding);

        $logoPath = $this->resolveImage(
            $current->logoPath,
            $data['logo'] ?? null,
            (bool) ($data['remove_logo'] ?? false),
            $tenant,
        );

        $coverPath = $this->resolveImage(
            $current->coverPath,
            $data['cover'] ?? null,
            (bool) ($data['remove_cover'] ?? false),
            $tenant,
        );

        return new TenantBranding(
            primaryColor: is_string($data['primary_color'] ?? null) && $data['primary_color'] !== ''
                ? $data['primary_color']
                : $current->primaryColor,
            logoPath: $logoPath,
            coverPath: $coverPath,
        );
    }

    /**
     * Returns the path to keep for an image slot: a newly uploaded file (deleting
     * the old one), null when explicitly removed, or the unchanged current path.
     */
    private function resolveImage(?string $currentPath, ?UploadedFile $upload, bool $remove, Tenant $tenant): ?string
    {
        $disk = Storage::disk('public');

        if ($upload !== null) {
            if ($currentPath !== null) {
                $disk->delete($currentPath);
            }

            return $upload->store("tenants/{$tenant->id}", 'public');
        }

        if ($remove) {
            if ($currentPath !== null) {
                $disk->delete($currentPath);
            }

            return null;
        }

        return $currentPath;
    }
}
