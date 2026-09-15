<?php

namespace App\Support\Mail;

use App\Services\Notification\MessageTemplateRenderer;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The editable words of one system email (SLO-246, SLO-258): subject, greeting,
 * the lines before the button, the button's label, and the lines after it.
 * Placeholders (`:name`) are literal here and substituted on render.
 *
 * What stays in code: the button's URL and the frame. A text edit cannot break
 * a link or the layout.
 *
 * Each non-empty line becomes one paragraph. The frame runs the lines through
 * CommonMark with HTML escaped and unsafe links dropped, so `**bold**`, a
 * `[link](https://…)` and `- item` lists render; raw HTML does not.
 */
final readonly class MailText
{
    public function __construct(
        public string $subject,
        public string $body,
        public ?string $outro = null,
        public string $greeting = '',
        // Null on a mail without a button.
        public ?string $actionLabel = null,
    ) {}

    /**
     * Fill a mail: subject, greeting, body, the button (when the mail has one
     * and the caller gives its URL), outro.
     *
     * @param  array<string, string|int|null>  $vars
     */
    public function applyTo(MailMessage $mail, array $vars, ?string $actionUrl = null): MailMessage
    {
        $renderer = app(MessageTemplateRenderer::class);

        $mail->subject($renderer->substitute($this->subject, $vars));

        if ($this->greeting !== '') {
            $mail->greeting($renderer->substitute($this->greeting, $vars));
        }

        foreach ($renderer->bodyLines($renderer->substitute($this->body, $vars)) as $line) {
            $mail->line($line);
        }

        if ($actionUrl !== null && $this->actionLabel !== null) {
            $mail->action($renderer->substitute($this->actionLabel, $vars), $actionUrl);
        }

        foreach ($renderer->bodyLines($renderer->substitute($this->outro ?? '', $vars)) as $line) {
            $mail->line($line);
        }

        return $mail;
    }
}
