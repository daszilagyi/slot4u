<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Http\Requests\Super\PreviewMailBrandRequest;
use App\Http\Requests\Super\UpdateMailBrandRequest;
use App\Models\PlatformSetting;
use App\Services\Mail\MailBrandStore;
use App\Services\Mail\MailPreviewRenderer;
use App\Support\Mail\MailBrandSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The look every system email shares (SLO-245, docs/27): header colour, button
 * colour, background, footer line and logo, with a live preview of the real
 * mail frame. One change reaches every tenant's customers, which is why it
 * lives on the superadmin host and is audited.
 */
class MailBrandController extends Controller
{
    public function __construct(private readonly MailBrandStore $store) {}

    public function edit(): Response
    {
        Gate::authorize('manage', PlatformSetting::class);

        $settings = $this->store->settings();
        $defaults = MailBrandSettings::defaults();

        return Inertia::render('Super/MailBrand/Edit', [
            'brand' => [
                'header_background' => $settings->headerBackground,
                'button_background' => $settings->buttonBackground,
                'canvas' => $settings->canvas,
                'footer_text' => $settings->footerText,
                'has_logo' => $settings->logoPath !== null,
            ],
            'defaults' => [
                'header_background' => $defaults->headerBackground,
                'button_background' => $defaults->buttonBackground,
                'canvas' => $defaults->canvas,
            ],
            'customised' => $this->store->isCustomised(),
        ]);
    }

    public function update(UpdateMailBrandRequest $request): RedirectResponse
    {
        /** @var array{header_background: string, button_background: string, canvas: string, footer_text?: string|null} $data */
        $data = $request->safe()->only(['header_background', 'button_background', 'canvas', 'footer_text']);

        $this->store->update($data, $request->file('logo'), $request->boolean('remove_logo'));

        return back()->with('status', __('app.super.mail_brand.saved'));
    }

    public function destroy(): RedirectResponse
    {
        Gate::authorize('manage', PlatformSetting::class);

        $this->store->reset();

        return back()->with('status', __('app.super.mail_brand.reset_done'));
    }

    public function preview(PreviewMailBrandRequest $request, MailPreviewRenderer $renderer): JsonResponse
    {
        /** @var array{header_background: string, button_background: string, canvas: string, footer_text?: string|null} $draft */
        $draft = $request->safe()->only(['header_background', 'button_background', 'canvas', 'footer_text']);

        $ratio = MailBrandSettings::footerContrast($draft['canvas']);

        return response()->json([
            'html' => $renderer->render(
                $draft,
                $request->file('logo'),
                $request->boolean('remove_logo'),
                $this->store->settings(),
                (string) $request->validated('sample'),
            ),
            'footer_contrast' => round($ratio, 2),
            'footer_contrast_ok' => $ratio >= MailBrandSettings::MIN_FOOTER_CONTRAST,
        ]);
    }
}
