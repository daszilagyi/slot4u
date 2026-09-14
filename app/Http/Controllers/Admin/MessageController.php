<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Message\MarkThreadRead;
use App\Actions\Message\SendMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MessageRequest;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Services\Message\ThreadPresenter;
use App\Support\BookingVisibility;
use App\Support\MessageVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's message inbox (SLO-36). Behind auth + staff + feature_messages +
 * message.send (routes/tenant.php). A thread is a customer: route-bound
 * {customer} goes through Customer::resolveRouteBinding, so a foreign or
 * not-own customer 404s, and {@see MessageVisibility} narrows the inbox the same
 * way.
 */
class MessageController extends Controller
{
    private const int PREVIEW_LENGTH = 120;

    public function index(Request $request, ThreadPresenter $presenter): Response
    {
        Gate::authorize('viewAny', Message::class);

        // One row per thread: the newest message id and the unread count.
        $query = Message::query()
            ->selectRaw('customer_id, MAX(id) as last_id, SUM(CASE WHEN from_customer = ? AND read_at IS NULL THEN 1 ELSE 0 END) as unread', [true])
            ->groupBy('customer_id')
            ->orderByDesc('last_id');

        MessageVisibility::apply($query, $request->user());

        $threads = $query->paginate(20)->withQueryString();

        $lastMessages = Message::query()
            ->whereIn('id', $threads->getCollection()->pluck('last_id'))
            ->get(['id', 'body', 'from_customer', 'created_at'])
            ->keyBy('id');

        $customers = User::query()
            ->whereIn('id', $threads->getCollection()->pluck('customer_id'))
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $threads->through(function (Message $row) use ($lastMessages, $customers, $presenter): array {
            $last = $lastMessages->get($row->getAttribute('last_id'));
            $customer = $customers->get($row->customer_id);

            return [
                'customer_id' => $row->customer_id,
                'customer_name' => $customer?->name,
                'customer_email' => $customer?->email,
                'preview' => $last !== null ? Str::limit($last->body, self::PREVIEW_LENGTH) : '',
                'last_from_customer' => (bool) $last?->from_customer,
                'last_local' => $presenter->local($last?->created_at),
                'unread' => (int) $row->getAttribute('unread'),
            ];
        });

        return Inertia::render('Admin/Messages/Index', [
            'threads' => $threads,
        ]);
    }

    // $tenant absorbs the subdomain route parameter (before the bound {customer}).
    public function show(Request $request, string $tenant, Customer $customer, ThreadPresenter $presenter, MarkThreadRead $markRead): Response
    {
        Gate::authorize('reply', [Message::class, $customer]);

        $markRead->byStaff($customer);
        $actor = $request->user();

        return Inertia::render('Admin/Messages/Show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
            ],
            'messages' => $presenter->messages($customer),
            'bookings' => $presenter->bookingOptions(
                $customer,
                fn ($query) => BookingVisibility::apply($query, $actor),
            ),
            'selected_booking' => $request->integer('booking') ?: null,
        ]);
    }

    public function store(MessageRequest $request, string $tenant, Customer $customer, SendMessage $send): RedirectResponse
    {
        Gate::authorize('reply', [Message::class, $customer]);

        $send->fromStaff($customer, $request->user(), $request->validated('body'), $request->booking());

        return back();
    }
}
