<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Legal\PublishLegalDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LegalDocumentRequest;
use App\Models\LegalDocument;
use App\Services\Legal\LegalDocumentRegistry;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's own terms and privacy notice (SLO-161).
 *
 * The tenant is the controller of its customers' data (docs/19 §1), so the text
 * is the tenant's to write — slot4u supplies the machinery and the versioning,
 * not the words. A tenant that has published nothing simply is not asked for
 * acceptance anywhere; the alternative, refusing bookings until someone writes a
 * privacy notice, would take a working site offline over a setting its owner does
 * not yet know exists.
 *
 * Behind auth + ensure.staff + can:privacy.manage (routes/tenant.php).
 */
class LegalDocumentController extends Controller
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly LegalDocumentRegistry $registry,
    ) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', LegalDocument::class);

        $tenant = $this->tenants->current();
        $inForce = $this->registry->currentForTenant($tenant)->map->getKey()->all();

        $documents = LegalDocument::query()
            ->ownedBy($tenant)
            ->withCount('consents')
            ->orderBy('type')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Admin/Legal/Index', [
            'documents' => $documents->map(fn (LegalDocument $document): array => [
                'id' => $document->getKey(),
                'type' => $document->type->value,
                'version' => $document->version,
                'title' => $document->title,
                'href' => '/legal/'.$document->getKey(),
                'effectiveFrom' => $document->effective_from->toIso8601String(),
                // Three states, not two: a version announced for next month is
                // neither in force nor superseded, and calling it either would
                // misrepresent what customers are currently accepting.
                'state' => $this->state($document, $inForce),
                'consents' => $document->consents_count,
                'deletable' => Gate::allows('delete', $document),
            ])->all(),
        ]);
    }

    public function store(LegalDocumentRequest $request, PublishLegalDocument $publish): RedirectResponse
    {
        Gate::authorize('create', LegalDocument::class);

        $publish($request->validated(), $this->tenants->current(), $request->user());

        return back()->with('status', __('app.legal.admin.created'));
    }

    /**
     * Withdraw a version nobody has accepted yet — a typo caught in time. The
     * policy refuses anything else, and the foreign key refuses it again.
     *
     * The id is resolved by hand rather than by route binding: LegalDocument
     * carries no tenant global scope (it holds the platform's rows too), so a
     * bound model would hand back another tenant's document for the policy to
     * refuse with a 403 — and a 403 confirms the row exists. Scoping the lookup
     * makes it a 404, like every other cross-tenant id (docs/01).
     */
    public function destroy(string $tenant, int $legalDocument): RedirectResponse
    {
        $document = LegalDocument::query()
            ->ownedBy($this->tenants->current())
            ->findOrFail($legalDocument);

        Gate::authorize('delete', $document);

        $document->delete();

        return back();
    }

    /**
     * @param  list<int>  $inForce
     */
    private function state(LegalDocument $document, array $inForce): string
    {
        if (in_array($document->getKey(), $inForce, true)) {
            return 'in_force';
        }

        return $document->effective_from->isFuture() ? 'scheduled' : 'superseded';
    }
}
