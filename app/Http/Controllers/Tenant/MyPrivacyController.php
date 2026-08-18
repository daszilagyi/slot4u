<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Privacy\RecordDataExport;
use App\Actions\Privacy\ResolveErasureRequest;
use App\Actions\Privacy\SubmitErasureRequest;
use App\Enums\PrivacyRequestType;
use App\Http\Controllers\Controller;
use App\Models\PrivacyRequest;
use App\Services\Privacy\PersonalDataExport;
use App\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Members area — the customer's own data-protection page (SLO-159).
 *
 * Lives in the `/my` group behind auth + ensure.user.tenant + ensure.customer.
 * Like the rest of that group every action targets `$request->user()`, so there
 * is no id to authorise and a customer can never reach another account's data.
 *
 * The two rights are served differently on purpose: the export is answered on
 * the spot (slot4u holds the data and the subject is entitled to a copy), while
 * the erasure is only recorded — the tenant is the controller and makes that
 * call ({@see ResolveErasureRequest}).
 */
class MyPrivacyController extends Controller
{
    public function __construct(
        private readonly TenantManager $tenants,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $requests = PrivacyRequest::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Tenant/My/Privacy', [
            'requests' => $requests->map(fn (PrivacyRequest $item): array => [
                'id' => $item->id,
                'type' => $item->type->value,
                'status' => $item->status->value,
                'requested_at' => $item->created_at?->toIso8601String(),
                'resolved_at' => $item->resolved_at?->toIso8601String(),
                'resolution_note' => $item->resolution_note,
            ])->all(),
            // Whether the erasure button does anything right now. A pending
            // request already obliges the tenant, and an anonymised account has
            // nothing left to erase — in both cases a live button would promise
            // an action that the POST would only turn into a no-op.
            'has_pending_erasure' => $requests->contains(
                fn (PrivacyRequest $item): bool => $item->type === PrivacyRequestType::Erasure && $item->status->isOpen(),
            ),
            'anonymized' => $user->anonymized_at !== null,
        ]);
    }

    /**
     * Serve the art. 15 copy as a JSON download.
     *
     * Built and sent in the request rather than queued to a mailed link: the
     * data set is one customer's own records, so it is small, and a link mailed
     * to an address is one forwarded mail away from being someone else's copy.
     */
    public function download(Request $request, PersonalDataExport $export, RecordDataExport $record): JsonResponse
    {
        $tenant = $this->tenants->current();
        abort_if($tenant === null, 404);

        $user = $request->user();
        $payload = $export->for($user);

        $record($tenant, $user);

        return response()
            ->json($payload, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="'.$this->filename($tenant->slug, $user->id).'"',
                // The file is a complete personal-data set; no cache, anywhere.
                'Cache-Control' => 'no-store, private',
            ]);
    }

    public function requestErasure(Request $request, SubmitErasureRequest $submit): RedirectResponse
    {
        $tenant = $this->tenants->current();
        abort_if($tenant === null, 404);

        $user = $request->user();

        // An anonymised account has nothing left to erase. Answered as a plain
        // no-op redirect rather than an error: the customer's stated wish has
        // already been carried out, which is not a failure.
        if ($user->anonymized_at === null) {
            $submit($tenant, $user);
        }

        return back();
    }

    private function filename(string $slug, int $userId): string
    {
        return 'slot4u-'.$slug.'-'.$userId.'-adatexport.json';
    }
}
