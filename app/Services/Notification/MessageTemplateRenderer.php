<?php

namespace App\Services\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\MessageTemplate;
use App\Models\Tenant;

/**
 * Resolves and renders a tenant's notification-template override (SLO-113). A
 * notification asks for the override matching its (tenant, type, locale) on the
 * email channel; if an enabled one exists, its subject/body are substituted with
 * the notification's variables, otherwise the notification falls back to its
 * built-in lang default.
 *
 * The lookup runs WITHOUT the tenant global scope and filters `tenant_id`
 * explicitly: the notification renders on a queue worker where no tenant is bound,
 * so relying on the ambient scope would silently return nothing (and, worse, could
 * match the wrong tenant if one happened to be bound). Same discipline as the
 * notifications_log writes.
 */
class MessageTemplateRenderer
{
    /**
     * The tenant's enabled email override for this notification kind + locale, or
     * null when there is none (→ the notification uses its default).
     */
    public function resolve(Tenant $tenant, NotificationType $type, string $locale): ?MessageTemplate
    {
        return MessageTemplate::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('key', $type->value)
            ->where('channel', NotificationChannel::Email->value)
            ->where('locale', $locale)
            ->where('enabled', true)
            ->first();
    }

    /**
     * Substitute `:name`-style placeholders in a template string. strtr replaces the
     * longest key first, so `:tenant_name` wins over `:tenant`. Null values render as
     * an empty string rather than leaving the raw placeholder in the mail.
     *
     * @param  array<string, string|int|null>  $vars
     */
    public function substitute(string $text, array $vars): string
    {
        $replacements = [];

        foreach ($vars as $name => $value) {
            $replacements[':'.$name] = (string) ($value ?? '');
        }

        return strtr($text, $replacements);
    }

    /**
     * Split a substituted template body into mail lines: one paragraph per non-blank
     * line, blank lines dropped (each ->line() is already its own paragraph).
     *
     * @return list<string>
     */
    public function bodyLines(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            fn (string $line): bool => $line !== '',
        ));
    }
}
