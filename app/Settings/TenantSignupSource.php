<?php

declare(strict_types=1);

namespace App\Settings;

use App\Http\Middleware\RememberCampaignSource;
use App\Models\Tenant;
use App\Services\Marketing\CampaignAttribution;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Where a tenant came from (SLO-210, docs/22 §4).
 *
 * The question this exists to answer is a business one: which campaign, and
 * which vertical landing, produced a company that actually signed up. GA4 and
 * Meta can say how many clicks and how many `Lead` events a campaign bought;
 * only we can say which of those became a tenant, and the whole argument for
 * building vertical landings (docs/22 §7.1) is comparing acquisition cost
 * between them.
 *
 * ⚠️ **Columns, not a JSON blob** — a deliberate break from the `settings` /
 * `analytics` pattern on the same table. Those are configuration: read whole,
 * written whole, never aggregated. This is analytical data whose entire point
 * is `GROUP BY` and `WHERE`, and a JSON path is exactly where that gets
 * awkward — `json_extract` returns a bare scalar on SQLite and a quoted JSON
 * value on MariaDB, so any grouping written against it works in the test suite
 * and returns `"google"` with quotes in production. Different purpose, different
 * storage.
 *
 * ⚠️ Every value except `landingPath` arrives from the QUERY STRING, so it is
 * attacker-controlled text that ends up in the database and on a superadmin
 * screen. Hence the length cap and the control-character strip: not because a
 * long `utm_campaign` is an attack, but because nothing else on the path from
 * an ad click to a column would ever say no.
 *
 * `landingPath` is safe by construction: the marketing routes are `/` and
 * `/{vertical}`, and the latter is constrained by `whereIn` to the registered
 * slugs (routes/web.php), so it can only be one of a handful of known strings.
 *
 * ⚠️ `utm_term` and `utm_content` are NOT captured. Partly scope — the question
 * on the table is which vertical and which campaign, and those two answer which
 * keyword and which creative — and partly posture: `utm_term` is the phrase the
 * person typed into a search box, which is the most personal thing in the set,
 * and the cheapest way not to hold it is not to collect it. Add them when there
 * is a search campaign whose keywords we actually intend to read.
 */
final class TenantSignupSource
{
    /**
     * Long enough for any campaign name a human types, short enough that a
     * generated URL cannot fill the column. Google's own analytics truncates
     * these dimensions well below this.
     */
    public const MAX_LENGTH = 200;

    private function __construct(
        public readonly ?string $utmSource,
        public readonly ?string $utmMedium,
        public readonly ?string $utmCampaign,
        /** The marketing page the visitor arrived on — `/` or `/{vertical}`. */
        public readonly ?string $landingPath,
        /** When they arrived, so a campaign can be judged on time-to-signup too. */
        public readonly ?Carbon $landedAt,
    ) {}

    public static function none(): self
    {
        return new self(null, null, null, null, null);
    }

    /**
     * What this request says about where the visitor came from.
     *
     * ⚠️ Only ever called for a request on the central marketing surface. On a
     * tenant's own host the `utm_*` in the URL belongs to the TENANT's campaign,
     * and harvesting it into slot4u's records would be the platform helping
     * itself to data it processes for someone else — the same controller
     * boundary as docs/19 §11.1.2 and §11.6.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            utmSource: self::clean($request->query('utm_source')),
            utmMedium: self::clean($request->query('utm_medium')),
            utmCampaign: self::clean($request->query('utm_campaign')),
            landingPath: '/'.ltrim($request->path(), '/'),
            landedAt: Carbon::now(),
        );
    }

    /**
     * Rebuilt from the session (see {@see CampaignAttribution}).
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];

        $landedAt = self::clean($data['landed_at'] ?? null);

        return new self(
            utmSource: self::clean($data['utm_source'] ?? null),
            utmMedium: self::clean($data['utm_medium'] ?? null),
            utmCampaign: self::clean($data['utm_campaign'] ?? null),
            landingPath: self::clean($data['landing_path'] ?? null),
            // Re-parsed rather than trusted: a session survives a deploy, and a
            // value written by an older shape must not blow up a listing.
            landedAt: $landedAt === null ? null : self::parse($landedAt),
        );
    }

    public static function fromTenant(Tenant $tenant): self
    {
        return new self(
            utmSource: self::clean($tenant->signup_utm_source),
            utmMedium: self::clean($tenant->signup_utm_medium),
            utmCampaign: self::clean($tenant->signup_utm_campaign),
            landingPath: self::clean($tenant->signup_landing_path),
            landedAt: $tenant->signup_landed_at,
        );
    }

    /**
     * The session shape. Short keys, because nothing outside the session reads
     * them and a prefix there would only be noise.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'utm_source' => $this->utmSource,
            'utm_medium' => $this->utmMedium,
            'utm_campaign' => $this->utmCampaign,
            'landing_path' => $this->landingPath,
            'landed_at' => $this->landedAt?->toIso8601String(),
        ];
    }

    /**
     * The column shape. Prefixed, because `utm_source` sitting unqualified on
     * `tenants` would read as the tenant's own campaign rather than as the one
     * that brought them to us — and the tenant genuinely has its own (SLO-56).
     *
     * @return array<string, string|Carbon|null>
     */
    public function toColumns(): array
    {
        return [
            'signup_utm_source' => $this->utmSource,
            'signup_utm_medium' => $this->utmMedium,
            'signup_utm_campaign' => $this->utmCampaign,
            'signup_landing_path' => $this->landingPath,
            'signup_landed_at' => $this->landedAt,
        ];
    }

    /**
     * Whether this carries a campaign, as opposed to only recording that
     * someone showed up on a page.
     *
     * The distinction decides which touch wins ({@see RememberCampaignSource}):
     * an organic arrival must never displace the paid click that follows it, or
     * we would be paying for traffic we then record as free.
     */
    public function hasCampaign(): bool
    {
        return $this->utmSource !== null
            || $this->utmMedium !== null
            || $this->utmCampaign !== null;
    }

    /** Nothing worth storing — not even which page they landed on. */
    public function isEmpty(): bool
    {
        return ! $this->hasCampaign() && $this->landingPath === null;
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // Control characters out before the trim, so a value that is only
        // padding does not survive as whitespace.
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value));

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, self::MAX_LENGTH);
    }

    private static function parse(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
