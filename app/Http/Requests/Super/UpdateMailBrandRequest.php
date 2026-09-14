<?php

namespace App\Http\Requests\Super;

use App\Models\PlatformSetting;
use App\Support\Mail\MailBrand;
use App\Support\Mail\MailBrandSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The mail brand form (SLO-245): three colours, a footer line and a logo.
 *
 * Only the canvas needs a contrast rule. The text on the header and on the
 * button is derived from their colours ({@see MailBrand::readableTextOn()}),
 * so it is readable whatever is chosen; the footer text is fixed and sits on the
 * canvas, so a dark canvas is the one choice that can hide something.
 */
class UpdateMailBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage', PlatformSetting::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hex = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];

        return [
            'header_background' => $hex,
            'button_background' => $hex,
            'canvas' => $hex,
            'footer_text' => ['nullable', 'string', 'max:300'],
            // PNG or JPEG only: mail clients drop SVG, and WebP is not safe in
            // Outlook. The size cap keeps the image cheap to fetch on mobile.
            'logo' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg', 'max:512', 'dimensions:min_width=40,min_height=40,max_width=1200,max_height=1200'],
            'remove_logo' => ['boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $canvas = $this->input('canvas');

                if ($validator->errors()->has('canvas') || ! is_string($canvas)) {
                    return;
                }

                $ratio = MailBrandSettings::footerContrast($canvas);

                if ($ratio < MailBrandSettings::MIN_FOOTER_CONTRAST) {
                    $validator->errors()->add('canvas', __('app.super.mail_brand.canvas_contrast', [
                        'ratio' => number_format($ratio, 1, ','),
                    ]));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'header_background' => __('app.super.mail_brand.fields.header_background'),
            'button_background' => __('app.super.mail_brand.fields.button_background'),
            'canvas' => __('app.super.mail_brand.fields.canvas'),
            'footer_text' => __('app.super.mail_brand.fields.footer_text'),
            'logo' => __('app.super.mail_brand.fields.logo'),
        ];
    }
}
