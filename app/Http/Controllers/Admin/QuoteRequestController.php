<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Quote\AcceptQuoteRequest;
use App\Actions\Quote\ChangeQuoteRequestStatus;
use App\Actions\Quote\CreateQuoteRequest;
use App\Actions\Quote\PostQuoteMessage;
use App\Actions\Quote\RejectQuoteRequest;
use App\Actions\Quote\SubmitQuote;
use App\Enums\QuoteRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuoteMessageRequest;
use App\Http\Requests\Admin\QuoteRequestStoreRequest;
use App\Http\Requests\Admin\SubmitQuoteRequest;
use App\Models\QuoteRequest;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Admin write paths for the quote_request booking mode (docs/04 §6, SLO-27).
 * Behind auth + ensure.user.tenant + ensure.feature:feature_quote_request +
 * the booking permissions (routes/tenant.php). Route-bound {quoteRequest} is
 * tenant-scoped (BelongsToTenant → cross-tenant 404); the lifecycle/state logic
 * lives in the action layer, so an illegal transition surfaces as a 422. The full
 * admin list/UI is SLO-28 — this is the API surface it will drive.
 */
class QuoteRequestController extends Controller
{
    // $tenant absorbs the subdomain route parameter.
    public function store(QuoteRequestStoreRequest $request, CreateQuoteRequest $create): RedirectResponse
    {
        Gate::authorize('create', QuoteRequest::class);

        $data = $request->validated();

        // Resolve tenant-scoped (BelongsToTenant → 404 on a cross-tenant id).
        $service = Service::query()->findOrFail($data['service_id']);

        $create($service, $data, $request->user());

        return back();
    }

    public function start(Request $request, string $tenant, QuoteRequest $quoteRequest, ChangeQuoteRequestStatus $change): RedirectResponse
    {
        Gate::authorize('update', $quoteRequest);

        $change($quoteRequest, QuoteRequestStatus::InProgress, $request->user());

        return back();
    }

    public function submit(SubmitQuoteRequest $request, string $tenant, QuoteRequest $quoteRequest, SubmitQuote $submit): RedirectResponse
    {
        Gate::authorize('update', $quoteRequest);

        $data = $request->validated();
        $validUntil = isset($data['valid_until']) ? Carbon::parse($data['valid_until']) : null;

        $submit(
            $quoteRequest,
            (int) $data['price_minor'],
            (string) $data['currency'],
            $validUntil,
            $data['internal_notes'] ?? null,
            $request->user(),
        );

        return back();
    }

    public function accept(Request $request, string $tenant, QuoteRequest $quoteRequest, AcceptQuoteRequest $accept): RedirectResponse
    {
        Gate::authorize('update', $quoteRequest);

        $accept($quoteRequest, $request->user(), $request->boolean('generate_booking', true));

        return back();
    }

    public function reject(Request $request, string $tenant, QuoteRequest $quoteRequest, RejectQuoteRequest $reject): RedirectResponse
    {
        Gate::authorize('update', $quoteRequest);

        $reject($quoteRequest, $request->user());

        return back();
    }

    public function message(QuoteMessageRequest $request, string $tenant, QuoteRequest $quoteRequest, PostQuoteMessage $post): RedirectResponse
    {
        Gate::authorize('update', $quoteRequest);

        $post($quoteRequest, (string) $request->validated('body'), $request->user());

        return back();
    }
}
