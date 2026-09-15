<?php

namespace App\Http\Requests\Super;

use App\Models\PlatformMailText;
use App\Services\Mail\MailTextCatalog;
use App\Support\Mail\MailText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * One system email's words, as the superadmin saves them (SLO-246, SLO-258).
 *
 * The frame escapes HTML and drops unsafe links on its own, so the rules here
 * are about what would reach a customer looking broken, not about injection:
 * - no raw HTML — it would arrive as visible `<tags>`;
 * - no images — "simple formatting" is paragraphs, bold, links and lists, and
 *   a remote image in every tenant's mail is a tracking pixel;
 * - only the variables this mail is rendered with — any other `:word` would
 *   go out literally.
 */
class UpdateMailTextRequest extends FormRequest
{
    /** Every editable part of a mail, in the order the mail shows them. */
    protected const array FIELDS = ['subject', 'greeting', 'body', 'action_label', 'outro'];

    public function authorize(): bool
    {
        return $this->user()?->can('manage', PlatformMailText::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        abort_unless($this->catalog()->has($this->mailKey()), 404);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'greeting' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            // Only a mail with a button has a label to say.
            'action_label' => [$this->catalog()->hasButton($this->mailKey()) ? 'required' : 'nullable', 'string', 'max:120'],
            'outro' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $allowed = $this->catalog()->variables($this->mailKey());

                foreach (self::FIELDS as $field) {
                    $text = (string) $this->input($field, '');

                    if (preg_match('/<\s*\/?\s*[a-z!]/i', $text) === 1) {
                        $validator->errors()->add($field, __('app.super.mail_texts.errors.html'));
                    }

                    if (str_contains($text, '![')) {
                        $validator->errors()->add($field, __('app.super.mail_texts.errors.image'));
                    }

                    preg_match_all('/:([a-z_]+)/', $text, $matches);
                    $unknown = array_values(array_diff(array_unique($matches[1]), $allowed));

                    if ($unknown !== []) {
                        $validator->errors()->add($field, __('app.super.mail_texts.errors.variables', [
                            'variables' => implode(', ', array_map(fn (string $v): string => ':'.$v, $unknown)),
                        ]));
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_combine(self::FIELDS, array_map(
            fn (string $field): string => (string) __('app.super.mail_texts.fields.'.$field),
            self::FIELDS,
        ));
    }

    /** The draft as the mail renders it; parts the mail does not have are dropped. */
    public function mailText(): MailText
    {
        $catalog = $this->catalog();
        $key = $this->mailKey();

        return new MailText(
            subject: (string) $this->validated('subject'),
            body: (string) $this->validated('body'),
            outro: $catalog->hasOutro($key) ? $this->validated('outro') : null,
            greeting: (string) $this->validated('greeting'),
            actionLabel: $catalog->hasButton($key) ? (string) $this->validated('action_label') : null,
        );
    }

    public function mailKey(): string
    {
        return (string) $this->route('key');
    }

    private function catalog(): MailTextCatalog
    {
        return app(MailTextCatalog::class);
    }
}
