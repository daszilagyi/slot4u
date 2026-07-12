<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\WaitlistStatus;
use App\Http\Controllers\Controller;
use App\Models\QuoteRequest;
use App\Models\WaitlistEntry;
use App\Tenancy\TenantManager;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Members area — a logged-in customer's own waitlist positions and quote
 * requests (SLO-98). Lives in the `/my` group behind auth + ensure.user.tenant
 * + ensure.customer (NOT the admin `ensure.staff`), and each read is gated by
 * its feature (ensure.feature) so a customer only reaches a section the tenant
 * has switched on. Both listings are customer-self-scoped: they filter by
 * `customer_id`, and the BelongsToTenant global scope keeps them tenant-isolated.
 * Read-only — the admin's `internal_notes` is never presented.
 */
class MyRequestsController extends Controller
{
    /**
     * The customer's own waitlist positions, newest first.
     */
    public function waitlist(Request $request): Response
    {
        $timezone = app(TenantManager::class)->current()->timezone;

        /** @var Collection<int, WaitlistEntry> $entries */
        $entries = WaitlistEntry::query()
            ->where('customer_id', $request->user()->getKey())
            ->with(['event:id,starts_at,service_id', 'event.service:id,name', 'service:id,name'])
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Tenant/My/Waitlist', [
            'entries' => $entries->map(function (WaitlistEntry $entry) use ($timezone): array {
                // An event entry names its service through the event; a
                // service-level entry (no event) names it directly.
                $service = $entry->event?->service;

                return [
                    'id' => $entry->id,
                    'service_name' => ($service ?? $entry->service)?->name,
                    'event_starts_local' => $this->localDateTime($entry->event?->starts_at, $timezone),
                    'position' => $entry->position,
                    'status' => $entry->status->value,
                    // The offer deadline only means anything while an offer is live.
                    'offered_until_local' => $entry->status === WaitlistStatus::Offered
                        ? $this->localDateTime($entry->offered_until, $timezone)
                        : null,
                    'party_size' => $entry->party_size,
                ];
            })->values(),
        ]);
    }

    /**
     * The customer's own quote requests, newest first. The admin-only
     * `internal_notes` and the raw `parameters` json are never presented.
     */
    public function quotes(Request $request): Response
    {
        $timezone = app(TenantManager::class)->current()->timezone;

        /** @var Collection<int, QuoteRequest> $quotes */
        $quotes = QuoteRequest::query()
            ->where('customer_id', $request->user()->getKey())
            ->with(['service:id,name'])
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Tenant/My/Quotes', [
            'quotes' => $quotes->map(fn (QuoteRequest $quote): array => [
                'id' => $quote->id,
                'service_name' => $quote->service?->name,
                'status' => $quote->status->value,
                // Only meaningful once the tenant has worked up a price.
                'price_minor' => $quote->price_minor,
                'currency' => $quote->currency,
                'valid_until_local' => $this->localDateTime($quote->valid_until, $timezone),
                'created_local' => $this->localDateTime($quote->created_at, $timezone),
            ])->values(),
        ]);
    }

    private function localDateTime(?CarbonInterface $instant, string $timezone): ?string
    {
        return $instant?->copy()->timezone($timezone)->format('Y-m-d H:i');
    }
}
