<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The pages that are a brochure rather than the product (SLO-219).
 *
 * slot4u.hu's landing and its vertical pages are public: a stranger reads them
 * without an account. That makes them the one place where being signed in must
 * not take anything away — and it used to, because the legal re-acceptance gate
 * (SLO-161) ran on every authenticated request. Someone with an outstanding
 * document could not open the home page they had just been reading anonymously,
 * and on the morning a new ÁSZF version is published that would be everyone at
 * once.
 *
 * ⚠️ Matched on ROUTE NAME, not on path. Two reasons, and the second is the one
 * that matters:
 *
 * 1. The tenant surfaces have their own `/` — it is `tenant.home`, a different
 *    name, so a path match would have exempted every tenant's booking page too.
 * 2. Every vertical landing shares the single `vertical` route (constrained by
 *    `whereIn` to the registered slugs, routes/web.php). A list of paths here
 *    would need editing each time a vertical is added, and the failure mode of
 *    forgetting is silent: the new page would simply start hiding behind the
 *    gate, and only for signed-in visitors, which is exactly the audience least
 *    likely to report it.
 *
 * Asked in two places — the gate itself, and the shared Inertia props that
 * anonymise these pages — so it is defined once. Two copies of "which pages are
 * public" is how they drift, and SLO-213 is what that looks like.
 */
final class MarketingSurface
{
    /**
     * The central domain's public marketing routes.
     *
     * @var list<string>
     */
    private const ROUTES = [
        'home',
        'vertical',
    ];

    public static function matches(Request $request): bool
    {
        return $request->routeIs(...self::ROUTES);
    }
}
