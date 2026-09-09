<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Enums\TenantStatus;
use App\Http\Controllers\Tenant\DemoLoginController;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The demo tenants a visitor can walk into, with a signed admin link each
 * (SLO-192, docs/21 §2.1).
 *
 * Extracted from HomeController when the vertical landings arrived (SLO-198):
 * two pages now need the same list, and the parts that are easy to get subtly
 * wrong — the signature, its expiry, the scheme, the smoke tenant — are exactly
 * the parts that must not be written twice.
 *
 * ⚠️ Read from the database, not from a hard-coded list. A landing page that
 * names personas in its own source is a page that keeps advertising one after it
 * has been removed, and links to a new one nobody added — the `is_demo` flag is
 * the only honest answer to "what can somebody try".
 */
final class DemoPersonaLinks
{
    /**
     * Every demo tenant worth showing, oldest first.
     *
     * @return list<array{slug: string, name: string, description: string|null, public_url: string, admin_url: string, admin_url_expires_at: string}>
     */
    public function all(): array
    {
        return $this->build(null);
    }

    /**
     * Just one, by slug — what a vertical landing shows (docs/22 §4 row 5).
     *
     * An empty list when that tenant is not seeded (or not active), so the
     * caller renders no demo section rather than a frame pointing at nothing.
     *
     * @return list<array{slug: string, name: string, description: string|null, public_url: string, admin_url: string, admin_url_expires_at: string}>
     */
    public function only(string $slug): array
    {
        return $this->build($slug);
    }

    /**
     * @return list<array{slug: string, name: string, description: string|null, public_url: string, admin_url: string, admin_url_expires_at: string}>
     */
    private function build(?string $slug): array
    {
        $scheme = Str::before((string) config('app.url'), '://') === 'http' ? 'http' : 'https';
        $central = (string) config('tenancy.central_domain');

        // One expiry for the whole list, and sent to the browser with it. The
        // signatures die 15 minutes after this render; a tab left open longer
        // would otherwise meet a 403 inside the demo frame, which reads as a
        // broken product rather than an expired link (see TryItLive).
        $expiresAt = Carbon::now()->addMinutes(DemoLoginController::LIFETIME_MINUTES);

        return Tenant::query()
            ->demo()
            ->where('status', TenantStatus::Active)
            // The smoke tenant exists to prove the framework works, not to be
            // shown to anybody (docs/20 §3.2) — it has one service and no story.
            ->where('slug', '!=', 'demo-smoke')
            ->when($slug !== null, fn ($query) => $query->where('slug', $slug))
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'settings'])
            ->map(fn (Tenant $tenant): array => [
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'description' => $this->tagline($tenant),
                'public_url' => $scheme.'://'.$tenant->slug.'.'.$central,
                'admin_url' => URL::temporarySignedRoute(
                    'tenant.demo.login',
                    $expiresAt,
                    ['tenant' => $tenant->slug],
                ),
                'admin_url_expires_at' => $expiresAt->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * One line about the business, from its own profile.
     *
     * Trimmed to a sentence: the persona descriptions are written for the
     * tenant's own page, where there is room for a paragraph, and a card that
     * carries the whole thing is a card nobody reads.
     */
    private function tagline(Tenant $tenant): ?string
    {
        $description = $tenant->settings['description'] ?? null;

        if (! is_string($description) || trim($description) === '') {
            return null;
        }

        // ⚠️ `rtrim` before the full stop is put back: a one-sentence profile has
        // no ". " to cut at, so `Str::before` returns the whole thing — already
        // punctuated — and appending blindly gives "…vállalkozás..".
        $sentence = rtrim(Str::before(trim($description), '. '), '.');

        return Str::limit($sentence.'.', 120);
    }
}
