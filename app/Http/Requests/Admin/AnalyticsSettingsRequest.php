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
