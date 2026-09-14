<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Message\MarkThreadRead;
use App\Actions\Message\SendMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\MyMessageRequest;
use App\Services\Message\ThreadPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Members area — the customer's conversation with the tenant (SLO-36). In the
 * `/my` group (auth + ensure.customer) behind feature_messages. There is no id:
 * the thread is always the signed-in customer's own, so the customer can only
 * ever talk to this tenant, and never read anyone else's thread.
 */
class MyMessageController extends Controller
{
    public function index(Request $request, ThreadPresenter $presenter, MarkThreadRead $markRead): Response
    {
        $customer = $request->user();
        $markRead->byCustomer($customer);

        return Inertia::render('Tenant/My/Messages', [
            'messages' => $presenter->messages($customer),
            'bookings' => $presenter->bookingOptions($customer),
            'selected_booking' => $request->integer('booking') ?: null,
        ]);
    }

    public function store(MyMessageRequest $request, SendMessage $send): RedirectResponse
    {
        $send->fromCustomer($request->user(), $request->validated('body'), $request->booking());

        return back();
    }
}
