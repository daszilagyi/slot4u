<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LegalDocument;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shows one version of one document to whoever is being asked to accept it
 * (SLO-161).
 *
 * Public by necessity — a person cannot consent to a text they are not allowed
 * to read, and that includes before they have an account. What is *not* public
 * is another tenant's text: the document has to belong to the host it is being
 * read on, or it 404s like any other cross-tenant id (docs/01). A platform
 * document is readable everywhere, because a tenant's customers are shown the
 * tenant's documents but its staff accept slot4u's on the same subdomain.
 *
 * Superseded versions stay readable on purpose. Someone holding a consent record
 * that names version 1.2 must be able to see what 1.2 said, or the record proves
 * nothing.
 */
class LegalController extends Controller
{
    public function show(string ...$routeParameters): Response|RedirectResponse
    {
        // The {tenant} domain parameter arrives first on tenant routes, so the
        // model is resolved by hand rather than by route binding — the binding
        // would hand a string to a typed model parameter (a convention this
        // codebase has been bitten by before).
        $id = (int) end($routeParameters);

        $document = LegalDocument::query()->findOrFail($id);

        $tenantId = app(TenantManager::class)->id();

        abort_unless(
            $document->tenant_id === null || $document->tenant_id === $tenantId,
            404,
        );

        // A document published as a link lives somewhere else; sending the reader
        // there beats rendering an empty page that claims to be the text.
        if (! $document->isInline()) {
            return redirect()->away((string) $document->url);
        }

        return Inertia::render('Legal/Show', [
            'document' => [
                'type' => $document->type->value,
                'version' => $document->version,
                'title' => $document->title,
                'body' => $document->body,
                'effectiveFrom' => $document->effective_from->toIso8601String(),
            ],
        ]);
    }
}
