<?php

namespace App\Http\Requests\Admin;

use App\Enums\Feature;
use App\Enums\Permission;
use App\Settings\TenantSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature as Pennant;

/**
 * Validation for the tenant settings page (SLO-21): company profile + booking
 * rules are always editable; the branding fields (primary colour, logo, cover)
 * are only accepted when the tenant's `feature_branding` flag is enabled, so a
 * tenant without the feature cannot smuggle branding in past the locked UI.
 */
class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::SettingsEdit->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Company profile. `name` is the tenant's display name (also editable
            // by a superadmin); slug/timezone/locale stay superadmin-only (docs/03).
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'address_city' => ['nullable', 'string', 'max:120'],
            'address_postal' => ['nullable', 'string', 'max:20'],
            'opening_hours' => ['nullable', 'string', 'max:1000'],
            'social' => ['nullable', 'array'],
            'social.website' => ['nullable', 'url', 'max:255'],
            'social.facebook' => ['nullable', 'url', 'max:255'],
            'social.instagram' => ['nullable', 'url', 'max:255'],

            // Booking rules (consumed by the M3 engine).
            'cancellation_deadline_hours' => ['required', 'integer', 'min:0', 'max:336'],
            'slot_interval_minutes' => ['required', 'integer', Rule::in(TenantSettings::SLOT_INTERVALS)],

            // Branding — validated here, but only honoured when the feature is on
            // (see withValidator + UpdateTenantSettings).
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:2048'],
            'cover' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:4096'],
            'remove_logo' => ['boolean'],
            'remove_cover' => ['boolean'],
        ];
    }

    /**
     * Reject any branding input when the tenant lacks `feature_branding` — the UI
     * locks that section, and this closes the direct-POST bypass.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (Pennant::active(Feature::Branding->value)) {
                return;
            }

            $touchesBranding = $this->filled('primary_color')
                || $this->hasFile('logo')
                || $this->hasFile('cover')
                || $this->boolean('remove_logo')
                || $this->boolean('remove_cover');

            if ($touchesBranding) {
                $validator->errors()->add('primary_color', __('app.admin.settings.error.branding_locked'));
            }
        });
    }

    public function brandingEnabled(): bool
    {
        return Pennant::active(Feature::Branding->value);
    }
}
