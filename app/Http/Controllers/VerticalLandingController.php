<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Commission\BuildPublicCommissionTerms;
use App\Services\Marketing\DemoPersonaLinks;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A trade-specific landing page — `slot4u.hu/autoszerviz` and the ones after it
 * (SLO-198, docs/22 §4).
 *
 * ## Why a separate page rather than a fifth card on the home page
 *
 * A vertical gets its own Meta Ads group, and conversion is only readable per
 * vertical if the landing is too. In search, "autószerviz időpontfoglaló" is a
 * phrase only a page about autószervizek can rank for. And the home page speaks
 * a service-industry language in which a workshop does not recognise itself.
 *
 * ## One controller, one page component, many verticals
 *
 * Everything that differs is copy, and all of it is in `lang/hu/app.php` under
 * `verticals.{slug}`. The registry in `config/verticals.php` says which slugs
 * exist and which demo tenant each one frames. Adding the next trade is a
 * config line and a block of Hungarian — no controller, route or component
 * changes, which is the whole point of building this one as a template
 * (docs/22 §4).
 *
 * ⚠️ Both halves are required. A slug in the registry with no copy would render
 * a page of missing-translation keys, so a missing block is a 404 — the same
 * answer an unknown slug gets, because in both cases the page does not exist.
 */
class VerticalLandingController extends Controller
{
    public function __invoke(
        string $vertical,
        BuildPublicCommissionTerms $terms,
        DemoPersonaLinks $demos,
    ): Response {
        $registered = config('verticals.'.$vertical);

        // The route constraint already rejects an unregistered slug; kept because
        // this class must not depend on that constraint being right, and because
        // a cached route file outlives a config change.
        abort_if(! is_array($registered), 404);

        $content = trans('app.verticals.'.$vertical);

        abort_if(! is_array($content), 404);

        $demoTenant = $registered['demo_tenant'] ?? null;

        return Inertia::render('Vertical', [
            // The page reads its own copy out of the shared translations tree
            // rather than being handed it — this only says which branch.
            'vertical' => $vertical,

            // The same commission figures the home page quotes, from the same
            // service. ⚠️ docs/22 §4 asks for a highlighted "Közepes csomag"
            // here; there are no packages any more (CLAUDE.md, docs/10), so the
            // page carries the model it actually bills on.
            'commission' => $terms->build()?->toArray(),

            // Empty when that tenant is not seeded — the live-demo section then
            // renders nothing rather than a frame pointing at a 404.
            'demo_personas' => is_string($demoTenant) ? $demos->only($demoTenant) : [],
            'demo_tenant' => is_string($demoTenant) ? $demoTenant : null,

            // Absolute, for the same reason as on the home page: an OG image is
            // fetched by the other platform's servers, and a root-relative path
            // resolves against theirs.
            'og_image' => rtrim((string) config('app.url'), '/').'/img/og-image.png',
            'canonical' => rtrim((string) config('app.url'), '/').'/'.$vertical,
        ]);
    }
}
