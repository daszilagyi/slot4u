<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Privacy\ResolveErasureRequest;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Super\TenantController;
use App\Http\Requests\Admin\RejectPrivacyRequestRequest;
use App\Models\PrivacyRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Privacy\TenantDataExport;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The tenant's data-subject request queue (SLO-159) — where the tenant, as
 * controller, acts on what its customers asked for.
 *
 * Behind auth + ensure.user.tenant + ensure.staff + can:privacy.manage
 * (routes/tenant.php). The BelongsToTenant global scope keeps every read and
 * the route binding inside the current tenant, so a foreign request id 404s
 * rather than 403s (docs/01).
 *
 * Erasure runs synchronously: it is a handful of scoped updates on one
 * customer's records, and the admin is standing in front of the screen — a
 * queued job would only add a window in which the register says "done" and the
 * data is still there.
 */
class PrivacyRequestController extends Controller
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly ResolveErasureRequest $resolver,
    ) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', PrivacyRequest::class);

        $requests = PrivacyRequest::query()
            // The subject's name is the whole point of the row, and an erased
            // subject still has to be listed — hence the plain user relation,
            // not the customer scope.
            ->with(['user:id,name,email,anonymized_at', 'resolvedBy:id,name'])
            // Open requests first, then the newest history: the queue is a
            // to-do list before it is an archive.
            ->orderByRaw('case when status = ? then 0 else 1 end', ['pending'])
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Admin/Privacy/Index', [
            'requests' => $requests->map(fn (PrivacyRequest $item): array => [
                'id' => $item->id,
                'type' => $item->type->value,
                'status' => $item->status->value,
                'subject' => [
                    'id' => $item->user_id,
                    'name' => $item->user?->name,
                    // An erased subject's address is the synthetic placeholder;
                    // showing it is how the admin sees the erasure took effect.
                    'email' => $item->user?->email,
                    'anonymized' => $item->user?->anonymized_at !== null,
                ],
                'requested_at' => $item->created_at?->toIso8601String(),
                'resolved_at' => $item->resolved_at?->toIso8601String(),
                'resolved_by' => $item->resolvedBy?->name,
                'resolution_note' => $item->resolution_note,
            ])->all(),
            // How long an archived tenant's data survives, so the page that
            // offers the export can say why taking one matters (docs/19 §7).
            'retention_days' => (int) config('privacy.retention.archived_tenant_days'),
        ]);
    }

    /**
     * The tenant's own complete data set, streamed as a JSON download
     * (SLO-160, docs/19 §7.4).
     *
     * Sits on the data-protection page rather than under billing because it is
     * the controller's counterpart to the customer export next to it: the tenant
     * takes its records with it before the 90-day purge removes them. Streamed
     * from a cursor — a busy tenant's history is not a thing to assemble in
     * memory on a shared host.
     *
     * ⚠️ Available while the tenant is *reachable*, which an archived tenant is
     * not: archiving soft-deletes it and IdentifyTenant 404s the whole
     * subdomain. That is why the same export also hangs off the superadmin
     * tenant page ({@see TenantController::export()})
     * — during the grace window slot4u can still hand the data over on request,
     * which is what the archive notice tells the tenant to ask for.
     */
    public function export(TenantDataExport $export, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('viewAny', PrivacyRequest::class);

        $tenant = $this->tenants->current();
        abort_if($tenant === null, 404);

        // Recorded before the stream opens: a callback that runs while the
        // response is being flushed cannot reliably write to the database, and
        // an unlogged disclosure is worse than one logged a moment early.
        $audit->record(action: AuditAction::TenantDataExported, auditable: $tenant);

        return response()->streamDownload(
            function () use ($export, $tenant): void {
                foreach ($export->stream($tenant) as $fragment) {
                    echo $fragment;
                }
            },
            $export->filename($tenant),
            [
                'Content-Type' => 'application/json',
                // A complete business data set; no cache, anywhere.
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /**
     * `string $tenant` is not decoration: the `{tenant}` domain parameter is the
     * route's FIRST parameter, and non-class controller arguments are filled
     * positionally from the route parameters. Omitting it makes `$privacyRequest`
     * receive the tenant slug and every write fail (the MessageTemplateController
     * / DomainController convention).
     */
    public function approve(string $tenant, PrivacyRequest $privacyRequest, Request $request): RedirectResponse
    {
        Gate::authorize('resolve', $privacyRequest);

        $current = $this->tenants->current();
        abort_if($current === null, 404);

        $this->resolver->approve($privacyRequest, $current, $request->user());

        return back();
    }

    /** See {@see self::approve()} for why `string $tenant` comes first. */
    public function reject(string $tenant, PrivacyRequest $privacyRequest, RejectPrivacyRequestRequest $request): RedirectResponse
    {
        Gate::authorize('resolve', $privacyRequest);

        $current = $this->tenants->current();
        abort_if($current === null, 404);

        $this->resolver->reject(
            $privacyRequest,
            $current,
            $request->user(),
            (string) $request->validated('reason'),
        );

        return back();
    }
}
