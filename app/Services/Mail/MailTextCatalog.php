<?php

namespace App\Services\Mail;

use App\Enums\NotificationType;
use App\Services\Notification\MessageTemplateCatalog;
use App\Support\Mail\MailText;

/**
 * Every system email whose words the superadmin may edit (SLO-246, docs/27 §5),
 * with its lang default and the `:variables` it is rendered with.
 *
 * Two groups:
 * - `platform` — slot4u's own mails. Subject, the lines before the button and
 *   the lines after it. Their greeting stays fixed.
 * - `customer` — the base text of the tenant mails a tenant may override
 *   (SLO-114). Same shape as the tenant's editor: subject + body; the button
 *   and the reply line are the notification's.
 *
 * The variable lists are what each notification actually passes. A text may
 * use only those — anything else would go out as a literal `:word`.
 */
class MailTextCatalog
{
    public const string GROUP_PLATFORM = 'platform';

    public const string GROUP_CUSTOMER = 'customer';

    /**
     * Lang keys under `app.mail.`: subject, body lines, outro lines (null = the
     * mail has no button, so no "after" part), and the variables.
     *
     * @var array<string, array{subject: string, body: list<string>, outro: list<string>|null, variables: list<string>}>
     */
    private const PLATFORM = [
        'verify_email' => [
            'subject' => 'verify_email.subject',
            'body' => ['verify_email.intro'],
            'outro' => ['verify_email.expire', 'verify_email.outro'],
            'variables' => ['name', 'count'],
        ],
        'reset_password' => [
            'subject' => 'reset_password.subject',
            'body' => ['reset_password.intro'],
            'outro' => ['reset_password.expire', 'reset_password.outro'],
            'variables' => ['name', 'count'],
        ],
        'staff_invitation' => [
            'subject' => 'staff_invitation.subject',
            'body' => ['staff_invitation.intro'],
            'outro' => ['staff_invitation.outro'],
            'variables' => ['name', 'tenant'],
        ],
        'commission_invoice_issued' => [
            'subject' => 'commission_invoice.issued.subject',
            'body' => ['commission_invoice.issued.intro', 'commission_invoice.amount'],
            'outro' => ['commission_invoice.issued.outro'],
            'variables' => ['name', 'tenant', 'period', 'amount', 'due'],
        ],
        'commission_invoice_overdue' => [
            'subject' => 'commission_invoice.overdue.subject',
            'body' => ['commission_invoice.overdue.intro', 'commission_invoice.amount'],
            'outro' => ['commission_invoice.overdue.outro'],
            'variables' => ['name', 'tenant', 'period', 'amount', 'due'],
        ],
        'commission_invoice_suspended' => [
            'subject' => 'commission_invoice.suspended.subject',
            'body' => ['commission_invoice.suspended.intro', 'commission_invoice.amount'],
            'outro' => ['commission_invoice.suspended.outro'],
            'variables' => ['name', 'tenant', 'period', 'amount', 'due'],
        ],
        'tenant_archived' => [
            'subject' => 'tenant_archived.subject',
            'body' => ['tenant_archived.intro', 'tenant_archived.deadline', 'tenant_archived.kept', 'tenant_archived.export'],
            'outro' => null,
            'variables' => ['name', 'tenant', 'date'],
        ],
    ];

    public function __construct(private readonly MessageTemplateCatalog $customer) {}

    /**
     * Every editable key, platform mails first, in display order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return [
            ...array_keys(self::PLATFORM),
            ...array_map(fn (NotificationType $type): string => $type->value, $this->customer->editableTypes()),
        ];
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    public function group(string $key): string
    {
        return array_key_exists($key, self::PLATFORM) ? self::GROUP_PLATFORM : self::GROUP_CUSTOMER;
    }

    /** The button's label, or null for a mail without a button. */
    public function actionLabel(string $key): ?string
    {
        if ($this->group($key) === self::GROUP_CUSTOMER) {
            return $this->lang($key.'.action');
        }

        return match ($key) {
            'tenant_archived' => null,
            'commission_invoice_issued', 'commission_invoice_overdue', 'commission_invoice_suspended' => $this->lang('commission_invoice.action'),
            default => $this->lang($key.'.action'),
        };
    }

    /** Whether the mail has an editable part after its button. */
    public function hasOutro(string $key): bool
    {
        return (self::PLATFORM[$key]['outro'] ?? null) !== null;
    }

    /** The built-in wording, placeholders left literal. */
    public function default(string $key): MailText
    {
        if (array_key_exists($key, self::PLATFORM)) {
            $entry = self::PLATFORM[$key];

            return new MailText(
                subject: $this->lang($entry['subject']),
                body: $this->lines($entry['body']),
                outro: $entry['outro'] === null ? null : $this->lines($entry['outro']),
            );
        }

        $type = NotificationType::from($key);

        return new MailText(
            subject: $this->customer->defaultSubject($type),
            body: $this->customer->defaultBody($type),
        );
    }

    /**
     * @return list<string>
     */
    public function variables(string $key): array
    {
        if (array_key_exists($key, self::PLATFORM)) {
            return self::PLATFORM[$key]['variables'];
        }

        return $this->customer->variables(NotificationType::from($key));
    }

    /**
     * Example values for the preview, one per variable name any mail uses.
     *
     * @return array<string, string>
     */
    public function sampleVars(): array
    {
        /** @var array<string, string> $samples */
        $samples = (array) __('app.super.mail_texts.samples');

        return $samples;
    }

    private function lang(string $key): string
    {
        return (string) __('app.mail.'.$key);
    }

    /**
     * @param  list<string>  $keys
     */
    private function lines(array $keys): string
    {
        return implode("\n", array_map(fn (string $key): string => $this->lang($key), $keys));
    }
}
