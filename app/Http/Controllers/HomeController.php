<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TenantStatus;
use App\Http\Controllers\Tenant\DemoLoginController;
use App\Models\Tenant;
use App\Services\Commission\BuildPublicCommissionTerms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public marketing landing on the central domain (SLO-50).
 */
class HomeController extends Controller
{
    public function __invoke(BuildPublicCommissionTerms $terms): Response
    {
        return Inertia::render('Welcome', [
            // One indexed, limit-1 query per render. Not cached on purpose: a
            // stale cache here means the page advertises a price the platform
            // no longer charges, which is the one failure mode worth a query.
            'commission' => $terms->build()?->toArray(),

            'demo_url' => $this->demoUrl(),

            // The "try it live" section (SLO-192).
            'demo_personas' => $this->demoPersonas(),

            // ⚠️ Absolute, because every platform that fetches an OG image
            // fetches it from its own servers — a root-relative path resolves
            // against THEIR host and 404s. Built server-side for the same reason
            // it cannot come from `window.location`: the page is server-rendered
            // (SSR), where there is no window (SLO-170).
            'og_image' => rtrim((string) config('app.url'), '/').'/img/og-image.png',
        ]);
    }

    /**
     * The demo tenants a visitor can walk into, with a signed admin link each
     * (SLO-192, docs/21 §2.1).
     *
     * ⚠️ Read from the database, not from a hard-coded list. A landing page that
     * names four personas in its own source is a page that keeps advertising one
     * after it has been removed, and links to a fifth that nobody added — the
     * `is_demo` flag is the only honest answer to "what can somebody try".
     *
     * The admin link is signed here, on every render, because a signature has an
     * expiry baked into it: minting it at request time is what keeps the window
     * short without the page needing to know when it was cached.
     *
     * @return list<array{slug: string, name: string, description: string|null, public_url: string, admin_url: string, admin_url_expires_at: string}>
     */
    private function demoPersonas(): array
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

    /**
     * The seeded demo tenant's public booking page, built from configuration
     * rather than written into the copy. Null when no demo slug is configured,
     * so an installation without one renders no demo link instead of a dead
     * first click.
     */
    private function demoUrl(): ?string
    {
        $slug = config('tenancy.demo_slug');

        if (! is_string($slug) || trim($slug) === '') {
            return null;
        }

        // Same scheme as the site itself: a demo link that drops to http on an
        // https page is blocked as mixed content by every current browser.
        $scheme = Str::before((string) config('app.url'), '://') === 'http' ? 'http' : 'https';

        return $scheme.'://'.$slug.'.'.config('tenancy.central_domain');
    }
}
