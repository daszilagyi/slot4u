<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Actions\Legal\PublishLegalDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LegalDocumentRequest;
use App\Models\LegalDocument;
use App\Services\Legal\LegalDocumentRegistry;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform's own terms and privacy notice (SLO-161) — what a company accepts
 * when it signs up for slot4u.
 *
 * Its own controller rather than a flag on the tenant one because the two answer
 * to different people: a tenant admin owns the text its customers accept, and
 * nobody but slot4u may touch the text tenants accept. Behind auth +
 * ensure.superadmin (routes/admin.php); there is no policy, because the host is
 * the authorisation.
 *
 * ⚠️ Publishing a new version here forces every tenant admin on the platform
 * through the re-acceptance screen on their next request. That is the intended
 * behaviour and the reason the effective date is settable: announce it, then let
 * it take effect.
 */
class LegalDocumentController extends Controller
{
    public function __construct(private readonly LegalDocumentRegistry $registry) {}

    public function index(): Response
    {
        $inForce = $this->registry->currentPlatform()->map->getKey()->all();

        $documents = LegalDocument::query()
            ->platform()
            ->withCount('consents')
            ->orderBy('type')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Super/Legal/Index', [
            'documents' => $documents->map(fn (LegalDocument $document): array => [
                'id' => $document->getKey(),
                'type' => $document->type->value,
                'version' => $document->version,
                'title' => $document->title,
                'href' => '/legal/'.$document->getKey(),
                'effectiveFrom' => $document->effective_from->toIso8601String(),
                'state' => in_array($document->getKey(), $inForce, true)
                    ? 'in_force'
                    : ($document->effective_from->isFuture() ? 'scheduled' : 'superseded'),
                'consents' => $document->consents_count,
            ])->all(),
        ]);
    }

    public function store(LegalDocumentRequest $request, PublishLegalDocument $publish): RedirectResponse
    {
        // No tenant: this is the platform scope. The superadmin host binds no
        // tenant, so the request's uniqueness rule already scoped itself to the
        // platform rows.
        $publish($request->validated(), null, $request->user());

        return back()->with('status', __('app.legal.admin.created'));
    }
}
