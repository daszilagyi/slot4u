<?php

namespace App\Services\Mail;

use App\Enums\AuditAction;
use App\Models\PlatformSetting;
use App\Services\Audit\AuditLogger;
use App\Support\Mail\MailBrand;
use App\Support\Mail\MailBrandSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Reads and writes the mail brand the superadmin sets (SLO-245).
 *
 * Every mail render asks for it — a reminder run renders hundreds — so the
 * stored row is cached, and the cache is the shared store: a queue worker on a
 * long-lived process sees a change on its next mail, without a restart. A write
 * forgets the cache only after the transaction commits, so no worker can cache
 * the value a rollback discards.
 *
 * The logo goes on the `public` disk: a mail client fetches it over plain HTTP
 * from `APP_URL/storage`, which the apex host serves (unlike `public/` itself).
 */
class MailBrandStore
{
    public const string KEY = 'mail_brand';

    private const string CACHE_KEY = 'platform_settings.mail_brand';

    private const string LOGO_DIRECTORY = 'platform/mail';

    public function __construct(private readonly AuditLogger $audit) {}

    public function settings(): MailBrandSettings
    {
        /** @var array<string, mixed>|null $stored */
        $stored = Cache::rememberForever(self::CACHE_KEY, fn (): ?array => PlatformSetting::query()
            ->where('key', self::KEY)
            ->value('value'));

        return MailBrandSettings::fromArray(is_array($stored) ? $stored : null);
    }

    /** The brand every mail renders with right now. */
    public function current(): MailBrand
    {
        $settings = $this->settings();

        return MailBrand::fromSettings($settings, $this->logoUrl($settings));
    }

    public function logoUrl(MailBrandSettings $settings): ?string
    {
        return $settings->logoPath !== null
            ? Storage::disk('public')->url($settings->logoPath)
            : MailBrand::defaultLogoUrl();
    }

    /** Whether anything differs from the built-in brand. */
    public function isCustomised(): bool
    {
        return $this->settings() != MailBrandSettings::defaults();
    }

    /**
     * @param  array{header_background: string, button_background: string, canvas: string, footer_text?: string|null}  $data
     */
    public function update(array $data, ?UploadedFile $logo, bool $removeLogo): MailBrandSettings
    {
        $before = $this->settings();

        $logoPath = $before->logoPath;
        $discard = null;

        if ($logo !== null) {
            $logoPath = $logo->store(self::LOGO_DIRECTORY, 'public') ?: null;
            $discard = $before->logoPath;
        } elseif ($removeLogo) {
            $logoPath = null;
            $discard = $before->logoPath;
        }

        $after = MailBrandSettings::fromArray([
            'header_background' => $data['header_background'],
            'button_background' => $data['button_background'],
            'canvas' => $data['canvas'],
            'footer_text' => $data['footer_text'] ?? null,
            'logo_path' => $logoPath,
        ]);

        DB::transaction(function () use ($before, $after): void {
            $row = PlatformSetting::query()->updateOrCreate(
                ['key' => self::KEY],
                ['value' => $after->toArray(), 'updated_by' => Auth::id()],
            );

            $this->audit->record(AuditAction::MailBrandUpdated, $row, $before->toArray(), $after->toArray());
        });

        $this->afterWrite($discard);

        return $after;
    }

    /** Back to the built-in brand: the row and the uploaded logo both go. */
    public function reset(): void
    {
        $before = $this->settings();

        DB::transaction(function () use ($before): void {
            PlatformSetting::query()->where('key', self::KEY)->delete();

            $this->audit->record(AuditAction::MailBrandReset, null, $before->toArray(), MailBrandSettings::defaults()->toArray());
        });

        $this->afterWrite($before->logoPath);
    }

    private function afterWrite(?string $discardLogo): void
    {
        Cache::forget(self::CACHE_KEY);

        // The old file only goes once the new value is committed: deleted
        // earlier, a failed write would leave the stored brand pointing at
        // nothing.
        if ($discardLogo !== null) {
            Storage::disk('public')->delete($discardLogo);
        }
    }
}
