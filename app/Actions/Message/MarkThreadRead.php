<?php

namespace App\Actions\Message;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Marks the other side's messages in a customer's thread as read (SLO-36).
 *
 * The tenant side is one shared inbox: when any staff member allowed to see the
 * thread opens it, the customer's messages count as read for the whole tenant —
 * the customer wrote to the business, not to a person.
 */
class MarkThreadRead
{
    /** The customer opened their thread: the staff replies are read. */
    public function byCustomer(User $customer): int
    {
        return $this->mark($customer, fromCustomer: false);
    }

    /** Staff opened the thread: the customer's messages are read. */
    public function byStaff(User $customer): int
    {
        return $this->mark($customer, fromCustomer: true);
    }

    private function mark(User $customer, bool $fromCustomer): int
    {
        return Message::query()
            ->where('customer_id', $customer->getKey())
            ->where('from_customer', $fromCustomer)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);
    }
}
