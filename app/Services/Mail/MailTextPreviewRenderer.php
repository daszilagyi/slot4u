<?php

namespace App\Services\Mail;

use App\Support\Mail\MailText;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Renders a draft of one email's words in the real frame, with example values
 * for its variables, for the superadmin's live preview (SLO-246).
 *
 * It builds the mail the way the notification does — greeting, the text's
 * subject/body/button/outro, and for a customer mail the tenant's name in the
 * frame and the reply line — so what the editor shows is what goes out.
 */
class MailTextPreviewRenderer
{
    public function __construct(private readonly MailTextCatalog $catalog) {}

    /**
     * @return array{subject: string, html: string}
     */
    public function render(string $key, MailText $draft): array
    {
        $samples = $this->catalog->sampleVars();
        // Only this mail's own variables, as the notification passes them: a
        // `:word` it does not have must show in the preview the way it would
        // go out — literally.
        $vars = array_intersect_key($samples, array_flip($this->catalog->variables($key)));
        $label = $this->catalog->actionLabel($key);

        $mail = $draft->applyTo(
            (new MailMessage)->greeting((string) __('app.mail.greeting', ['name' => $vars['name'] ?? ''])),
            $vars,
            $label === null ? null : [$label, '#'],
        );

        if ($this->catalog->group($key) === MailTextCatalog::GROUP_CUSTOMER) {
            $mail->line((string) __('app.mail.reply.invite', ['tenant' => $samples['tenant'] ?? '']));
            $mail->viewData['tenantName'] = $samples['tenant'] ?? '';
        }

        return [
            'subject' => (string) $mail->subject,
            'html' => (string) $mail->render(),
        ];
    }
}
