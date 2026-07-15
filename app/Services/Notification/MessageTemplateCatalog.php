<?php

namespace App\Services\Notification;

use App\Enums\NotificationType;

/**
 * The catalogue of editable notification templates (SLO-114). It names the
 * notification kinds a tenant may customise and, for each, exposes the built-in
 * default subject + body (assembled from the lang lines the notifications render
 * from) and the `:placeholder` variables available — so the editor can seed a
 * starting point and hint what may be interpolated.
 *
 * The payment_* types are omitted: they arrive with the M6 payment flow.
 */
class MessageTemplateCatalog
{
    /**
     * The ordered body lines (lang sub-keys under `app.mail.<key>`) that make up the
     * default body for each editable type — the subject, greeting and CTA are
     * handled separately, so they are not part of the editable body.
     *
     * @var array<string, list<string>>
     */
    private const BODY_LINES = [
        'booking_confirmed' => ['intro', 'code', 'service', 'when', 'outro'],
        'booking_modified' => ['intro', 'previous', 'when', 'service', 'code', 'outro'],
        'booking_canceled' => ['intro', 'code', 'service', 'when', 'reason', 'outro'],
        'booking_rejected' => ['intro', 'code', 'service', 'when', 'reason', 'outro'],
        'waitlist_offer' => ['intro', 'service', 'when', 'deadline', 'outro'],
        'reminder_24h' => ['intro', 'service', 'when', 'code', 'outro'],
        'quote_ready' => ['intro', 'service', 'price', 'valid_until', 'outro'],
    ];

    /**
     * The notification kinds a tenant may edit, in display order.
     *
     * @return list<NotificationType>
     */
    public function editableTypes(): array
    {
        return array_map(
            fn (string $key): NotificationType => NotificationType::from($key),
            array_keys(self::BODY_LINES),
        );
    }

    public function isEditable(NotificationType $type): bool
    {
        return array_key_exists($type->value, self::BODY_LINES);
    }

    /**
     * The default subject, with `:placeholder` tokens left literal so the editor
     * shows them to the tenant.
     */
    public function defaultSubject(NotificationType $type): string
    {
        return (string) __('app.mail.'.$type->value.'.subject');
    }

    /**
     * The default body assembled from the notification's lang lines, one paragraph
     * per line, placeholders left literal.
     */
    public function defaultBody(NotificationType $type): string
    {
        $lines = array_map(
            fn (string $key): string => (string) __('app.mail.'.$type->value.'.'.$key),
            self::BODY_LINES[$type->value],
        );

        return implode("\n", $lines);
    }

    /**
     * The `:placeholder` variable names available to this template, derived from its
     * default subject + body + the shared greeting (so `name` is always present).
     *
     * @return list<string>
     */
    public function variables(NotificationType $type): array
    {
        $text = $this->defaultSubject($type)."\n"
            .$this->defaultBody($type)."\n"
            .(string) __('app.mail.greeting');

        preg_match_all('/:([a-z_]+)/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }
}
