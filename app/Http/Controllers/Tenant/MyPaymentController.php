<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Refund;
use App\Tenancy\TenantManager;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Members area — a logged-in customer's own online payments (SLO-132). Lives in
 * the `/my` group behind auth + ensure.user.tenant + ensure.customer (NOT the
 * admin `ensure.staff`), gated by `feature_online_payment` like the rest of the
 * payment surface.
 *
 * Self-scoped through the booking: a payment carries no customer of its own, so
 * the list is filtered by the *booking's* customer_id, and the BelongsToTenant
 * global scope keeps it tenant-isolated. A guest booking (no account, SLO-128)
 * has no owner and therefore never appears here.
 *
 * Read-only: it answers "what did I pay, and what came back", so the gateway
 * payload and the tenant's internal refund reasons stay out of it.
 */
class MyPaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $timezone = app(TenantManager::class)->current()->timezone;

        /** @var Collection<int, Payment> $payments */
        $payments = Payment::query()
            ->whereHas('booking', fn (Builder $query) => $query->where('customer_id', $request->user()->getKey()))
            // Eager loaded in three queries regardless of list length (no N+1).
            ->with(['booking:id,code,service_id,starts_at', 'booking.service:id,name', 'refunds'])
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Tenant/My/Payments', [
            'payments' => $payments->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'booking_code' => $payment->booking?->code,
                'service_name' => $payment->booking?->service?->name,
                'booking_starts_local' => $this->localDateTime($payment->booking?->starts_at, $timezone),
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
                'status' => $payment->status->value,
                'paid_local' => $this->localDateTime($payment->paid_at, $timezone),
                'created_local' => $this->localDateTime($payment->created_at, $timezone),
                // A refused refund is the tenant's problem to retry, not something
                // to promise the customer — only what is on its way (or arrived).
                'refunds' => $payment->refunds
                    ->filter(fn (Refund $refund): bool => $refund->status->countsAgainstPayment())
                    ->map(fn (Refund $refund): array => [
                        'id' => $refund->id,
                        'amount_minor' => $refund->amount_minor,
                        'currency' => $refund->currency,
                        'status' => $refund->status->value,
                        'refunded_local' => $this->localDateTime($refund->refunded_at, $timezone),
                    ])->values()->all(),
            ])->values(),
        ]);
    }

    private function localDateTime(?CarbonInterface $instant, string $timezone): ?string
    {
        return $instant?->copy()->timezone($timezone)->format('Y-m-d H:i');
    }
}
