<?php

namespace App\Http\Middleware;

use App\Services\Legal\LegalDocumentRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a signed-in user at the door until they have accepted the versions in
 * force (SLO-161).
 *
 * This is what makes the versioning mean anything: without it a new text would
 * apply to nobody who already had an account, and "the current terms" would be a
 * label on a page rather than something people have agreed to.
 *
 * A super-admin is never asked — slot4u's own staff are not slot4u's customers —
 * and neither is a guest, who is asked at the point of booking instead.
 *
 * ⚠️ The exempt list is what keeps this from being a trap rather than a gate. A
 * person who must accept a document has to be able to read it, submit the
 * acceptance, and log out; every one of those routes is on the far side of this
 * middleware.
 */
class EnsureLegalConsent
{
    /**
     * Route names and paths that must stay reachable while consent is
     * outstanding. Matched on the path so it also holds for the domain-bound
     * duplicates of these routes.
     *
     * @var list<string>
     */
    private const EXEMPT = [
        'consent',
        'legal/*',
        'logout',
        // Fortify's own session routes: being logged out or re-authenticating
        // must never depend on a decision the user has not been shown yet.
        'login',
        'two-factor-challenge',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->isSuperAdmin() || $request->is(...self::EXEMPT)) {
            return $next($request);
        }

        if (app(LegalDocumentRegistry::class)->outstandingFor($user)->isEmpty()) {
            return $next($request);
        }

        // 409 rather than a redirect for a programmatic caller: an API or fetch
        // client following a redirect to an HTML form would report success for a
        // write that never happened.
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(409, __('app.legal.outstanding'));
        }

        return redirect('/consent');
    }
}
