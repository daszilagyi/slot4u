<?php

namespace App\Notifications\Concerns;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Keeps a demo tenant's mail off the wire (SLO-182, docs/20 §3.1).
 *
 * The demo tenants are seeded with lifelike bookings, and the visitor playing
 * with the public demo makes more. Every one of those would otherwise mail a
 * fabricated customer address — at best a bounce against slot4u's sending
 * reputation, at worst a real person receiving a confirmation for an appointment
 * that does not exist.
 *
 * ⚠️ **Diverted, not cancelled — and the difference is the feature.** The mail is
 * rendered in full and handed to the `log` mailer instead of the SMTP one, so:
 *
 * - `NotificationSent` still fires, so the `notifications_log` row this send
 *   claimed is finalised exactly as a real delivery would be. A demo whose
 *   notification screen sat permanently empty would misrepresent the product in
 *   the one direction that costs a sale;
 * - the rendered body lands in the log, so "show me what the customer gets" is
 *   answerable during a sales call without sending anything;
 * - a broken template still fails loudly at render time rather than hiding
 *   behind a `return` that never built the mail.
 *
 * Cancelling in `via()` would have been fewer lines and would have lost all
 * three.
 */
trait SuppressedForDemoTenant
{
    /**
     * The mailer a demo tenant's mail goes to. `log` is defined in
     * `config/mail.php` on every environment, so this cannot fail over to a real
     * transport on a host that forgot to configure something.
     */
    private const DEMO_MAILER = 'log';

    /**
     * Divert the message to the log transport when its tenant is a demo one.
     * Called from `toMail()` after the message is otherwise complete.
     */
    protected function suppressWhenDemo(MailMessage $mail, Tenant $tenant): MailMessage
    {
        if ($tenant->is_demo) {
            $mail->mailer(self::DEMO_MAILER);
        }

        return $mail;
    }
}
