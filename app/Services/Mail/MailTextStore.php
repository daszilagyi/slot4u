<?php

namespace App\Services\Mail;

use App\Enums\AuditAction;
use App\Models\PlatformMailText;
use App\Services\Audit\AuditLogger;
use App\Support\Mail\MailText;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the superadmin's email texts (SLO-246).
 *
 * Same shape as MailBrandStore: every mail render asks, so each locale's rows
 * sit in the shared cache and a write forgets them after the transaction
 * commits — a long-lived queue worker picks up an edit on its next mail.
 *
 * If the table cannot be read (a deploy between the code and the migration),
 * every mail falls back to its lang default and still goes out.
 */
class MailTextStore
{
    private const string CACHE_PREFIX = 'platform_mail_texts.';

    public function __construct(
        private readonly MailTextCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /** The superadmin's text for this mail, or null when they have not edited it. */
    public function stored(string $key, string $locale): ?MailText
    {
        $row = $this->rows($locale)[$key] ?? null;

        if ($row === null) {
            return null;
        }

        // A part left empty — a row saved before the greeting and the label
        // were editable (SLO-258) — keeps its lang default.
        $default = $this->catalog->default($key);

        return new MailText(
            subject: $row['subject'],
            body: $row['body'],
            outro: $this->catalog->hasOutro($key) ? $row['outro'] : null,
            greeting: $row['greeting'] ?? $default->greeting,
            actionLabel: $this->catalog->hasButton($key) ? ($row['action_label'] ?? $default->actionLabel) : null,
        );
    }

    /** What a mail renders with: the stored text, else the lang default. */
    public function resolve(string $key, string $locale): MailText
    {
        return $this->stored($key, $locale) ?? $this->catalog->default($key);
    }

    public function save(string $key, string $locale, MailText $text): void
    {
        $before = $this->stored($key, $locale) ?? $this->catalog->default($key);
        $outro = $this->catalog->hasOutro($key) ? $text->outro : null;
        $actionLabel = $this->catalog->hasButton($key) ? $text->actionLabel : null;

        DB::transaction(function () use ($key, $locale, $text, $outro, $actionLabel, $before): void {
            $row = PlatformMailText::query()->updateOrCreate(
                ['key' => $key, 'locale' => $locale],
                [
                    'subject' => $text->subject,
                    'greeting' => $text->greeting,
                    'body' => $text->body,
                    'action_label' => $actionLabel,
                    'outro' => $outro,
                    'updated_by' => Auth::id(),
                ],
            );

            $this->audit->record(
                AuditAction::MailTextUpdated,
                $row,
                ['key' => $key, 'locale' => $locale, ...$this->values($before)],
                ['key' => $key, 'locale' => $locale, ...$this->values(new MailText($text->subject, $text->body, $outro, $text->greeting, $actionLabel))],
            );
        });

        Cache::forget(self::CACHE_PREFIX.$locale);
    }

    /** Back to the lang default for this mail. */
    public function reset(string $key, string $locale): void
    {
        $before = $this->stored($key, $locale);

        if ($before === null) {
            return;
        }

        DB::transaction(function () use ($key, $locale, $before): void {
            PlatformMailText::query()->where('key', $key)->where('locale', $locale)->delete();

            $this->audit->record(
                AuditAction::MailTextReset,
                null,
                ['key' => $key, 'locale' => $locale, ...$this->values($before)],
                ['key' => $key, 'locale' => $locale, ...$this->values($this->catalog->default($key))],
            );
        });

        Cache::forget(self::CACHE_PREFIX.$locale);
    }

    /**
     * @return array<string, array{subject: string, greeting: string|null, body: string, action_label: string|null, outro: string|null}>
     */
    private function rows(string $locale): array
    {
        try {
            /** @var array<string, array{subject: string, greeting: string|null, body: string, action_label: string|null, outro: string|null}> */
            return Cache::rememberForever(self::CACHE_PREFIX.$locale, fn (): array => PlatformMailText::query()
                ->where('locale', $locale)
                ->get(['key', 'subject', 'greeting', 'body', 'action_label', 'outro'])
                ->mapWithKeys(fn (PlatformMailText $row): array => [$row->key => [
                    'subject' => $row->subject,
                    'greeting' => $row->greeting,
                    'body' => $row->body,
                    'action_label' => $row->action_label,
                    'outro' => $row->outro,
                ]])
                ->all());
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @return array{subject: string, greeting: string, body: string, action_label: string|null, outro: string|null}
     */
    private function values(MailText $text): array
    {
        return [
            'subject' => $text->subject,
            'greeting' => $text->greeting,
            'body' => $text->body,
            'action_label' => $text->actionLabel,
            'outro' => $text->outro,
        ];
    }
}
