<?php

namespace App\Http\Requests\Super;

use App\Services\Mail\MailPreviewRenderer;

/**
 * A draft of the mail brand form, for the live preview (SLO-245). Same fields
 * and the same limits as saving, minus the contrast rule: the preview has to
 * SHOW a poor choice — with the warning beside it — not refuse to draw it.
 */
class PreviewMailBrandRequest extends UpdateMailBrandRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'sample' => ['required', 'string', 'in:'.implode(',', MailPreviewRenderer::SAMPLES)],
        ]);
    }

    public function after(): array
    {
        return [];
    }
}
