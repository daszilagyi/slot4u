<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Privacy\ResolveErasureRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectPrivacyRequestRequest;
use App\Models\PrivacyRequest;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

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
        ]);
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
