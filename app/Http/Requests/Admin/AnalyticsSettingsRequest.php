<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Settings\TenantAnalyticsSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The tenant's own measurement ids (SLO-56).
 *
 * Authorisation is the route's (`ensure.feature:feature_analytics` +
 * `can:settings.edit`).
 *
 * Both fields are matched against an anchored pattern rather than accepted as
 * free text. They are interpolated into a `<script src>` and into Meta's
 * `fbq('init', …)`, so "any string up to 64 characters" would be a stored
 * injection with a settings form in front of it. Rejecting a typo'd id is also
 * the kinder failure: the alternative is a tenant who configured measurement,
 * sees no error, and discovers weeks later that nothing was ever recorded.
 */
class AnalyticsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ga4_measurement_id' => [
                'nullable', 'string', 'max:32',
                'regex:'.TenantAnalyticsSettings::GA4_PATTERN,
            ],
            'meta_pixel_id' => [
                'nullable', 'string', 'max:32',
                'regex:'.TenantAnalyticsSettings::META_PIXEL_PATTERN,
            ],
            // No pattern. Meta documents no stable shape for the token, and a
            // rule invented here would start rejecting valid tokens the day they
            // change it. A wrong one fails loudly on the first conversion, and
            // the failure is recorded on the row (SLO-173).
            //
            // Blank means "keep the stored one" — the form never receives it, so
            // it cannot send it back (see the controller).
            'meta_access_token' => ['nullable', 'string', 'max:512'],
            'meta_test_event_code' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ga4_measurement_id.regex' => __('app.analytics.settings.ga4_invalid'),
            'meta_pixel_id.regex' => __('app.analytics.settings.pixel_invalid'),
        ];
    }
}
