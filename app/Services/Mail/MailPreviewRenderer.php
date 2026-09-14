<?php

namespace App\Services\Mail;

use App\Notifications\Platform\AuthMailMessages;
use App\Support\Mail\MailBrand;
use App\Support\Mail\MailBrandSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a sample mail in a draft brand, for the superadmin's live preview
 * (SLO-245). It is the real frame — the same views and theme a sent mail goes
 * through — with the brand binding swapped for the duration of one render, so
 * the preview cannot drift from what customers receive.
 *
 * ⚠️ The logo is inlined as a data: URI here, and only here. The preview is
 * framed on the superadmin host, whose CSP allows images from itself and data:
 * — not from the apex `/storage` URL a sent mail uses. Mail clients are the
 * opposite (Gmail drops data: images), which is why a real mail never gets one.
 */
class MailPreviewRenderer
{
    public const string SAMPLE_PLATFORM = 'platform';

    public const string SAMPLE_TENANT = 'tenant';

    public const array SAMPLES = [self::SAMPLE_PLATFORM, self::SAMPLE_TENANT];

    /**
     * @param  array{header_background: string, button_background: string, canvas: string, footer_text?: string|null}  $draft
     */
    public function render(array $draft, ?UploadedFile $logo, bool $removeLogo, MailBrandSettings $stored, string $sample): string
    {
        $settings = MailBrandSettings::fromArray([
            'header_background' => $draft['header_background'],
            'button_background' => $draft['button_background'],
            'canvas' => $draft['canvas'],
            'footer_text' => $draft['footer_text'] ?? null,
            'logo_path' => $stored->logoPath,
        ]);

        $brand = MailBrand::fromSettings($settings, $this->logoDataUri($logo, $removeLogo, $stored));

        app()->instance(MailBrand::class, $brand);

        try {
            return (string) $this->sample($sample)->render();
        } finally {
            // Back to the bound resolver: the rest of this request (and a queue
            // worker, were this ever called there) must see the stored brand.
            app()->forgetInstance(MailBrand::class);
        }
    }

    private function sample(string $sample): MailMessage
    {
        if ($sample === self::SAMPLE_TENANT) {
            $tenant = __('app.super.mail_brand.preview.sample_tenant');

            $mail = (new MailMessage)
                ->subject(__('app.mail.booking_confirmed.subject', ['tenant' => $tenant]))
                ->greeting(__('app.mail.booking_confirmed.greeting', ['name' => __('app.super.mail_brand.preview.sample_customer')]))
                ->line(__('app.mail.booking_confirmed.intro', ['tenant' => $tenant]))
                ->line(__('app.mail.booking_confirmed.service', ['service' => __('app.super.mail_brand.preview.sample_service')]))
                ->line(__('app.mail.booking_confirmed.when', ['when' => __('app.super.mail_brand.preview.sample_when')]))
                ->action(__('app.mail.booking_confirmed.action'), '#')
                ->line(__('app.mail.booking_confirmed.outro'));

            $mail->viewData['tenantName'] = $tenant;

            return $mail;
        }

        return AuthMailMessages::verifyEmail(
            (object) ['name' => __('app.super.mail_brand.preview.sample_customer')],
            '#',
        );
    }

    /** The logo the draft would show: a new upload, none, or the stored/built-in one. */
    private function logoDataUri(?UploadedFile $logo, bool $removeLogo, MailBrandSettings $stored): string
    {
        if ($logo !== null) {
            return $this->dataUri((string) $logo->getContent(), (string) $logo->getMimeType());
        }

        if ($removeLogo) {
            return $this->dataUri((string) file_get_contents(base_path(MailBrand::DEFAULT_LOGO)), 'image/png');
        }

        if ($stored->logoPath !== null && Storage::disk('public')->exists($stored->logoPath)) {
            $disk = Storage::disk('public');

            return $this->dataUri((string) $disk->get($stored->logoPath), (string) $disk->mimeType($stored->logoPath));
        }

        return $this->dataUri((string) file_get_contents(base_path(MailBrand::DEFAULT_LOGO)), 'image/png');
    }

    private function dataUri(string $bytes, string $mime): string
    {
        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
