<?php

namespace App\Http\Requests\Super;

/**
 * A draft of one email's words, for the live preview (SLO-246). Same limits as
 * saving, minus the content rules: the preview has to SHOW a stray `:word` or
 * a tag — the save beside it refuses it — and a half-typed body is not empty
 * on purpose.
 */
class PreviewMailTextRequest extends UpdateMailTextRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['nullable', 'string', 'max:255'],
            'greeting' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'action_label' => ['nullable', 'string', 'max:120'],
            'outro' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [];
    }
}
