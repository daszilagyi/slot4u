<?php

namespace App\Support\Mail;

use App\Services\Notification\MessageTemplateRenderer;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The editable words of one system email (SLO-246): subject, the lines before
 * the button, and the lines after it. Placeholders (`:name`) are literal here
 * and substituted on render.
 *
 * Everything else about the mail stays in code: the greeting, the button and
 * its URL, the frame. A text edit cannot break a link or the layout.
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
    ) {}

    /**
     * Fill a mail: subject, body, the button (when the mail has one), outro.
     * The greeting is the caller's, set before or after — it is not editable.
     *
     * @param  array<string, string|int|null>  $vars
     * @param  array{0: string, 1: string}|null  $action
     */
    public function applyTo(MailMessage $mail, array $vars, ?array $action = null): MailMessage
    {
        $renderer = app(MessageTemplateRenderer::class);

        $mail->subject($renderer->substitute($this->subject, $vars));

        foreach ($renderer->bodyLines($renderer->substitute($this->body, $vars)) as $line) {
            $mail->line($line);
        }

        if ($action !== null) {
            $mail->action($action[0], $action[1]);
        }

        foreach ($renderer->bodyLines($renderer->substitute($this->outro ?? '', $vars)) as $line) {
            $mail->line($line);
        }

        return $mail;
    }
}
