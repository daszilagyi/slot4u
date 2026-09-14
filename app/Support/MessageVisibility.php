<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which threads a staff member sees (SLO-36, docs/03 `message.send` —
 * "saját ügyfeleknek"). A thread is a customer, so this is exactly
 * {@see CustomerVisibility}: the message query is restricted to the customers
 * that class lets the actor see, never to a second, drifting rule.
 */
final class MessageVisibility
{
    /**
     * Restrict a message query to the threads the actor may see.
     *
     * @param  Builder<Message>  $query
     */
    public static function apply(Builder $query, User $actor): void
    {
        if (CustomerVisibility::unrestricted($actor)) {
            return;
        }

        $customers = Customer::tenantScoped();
        CustomerVisibility::apply($customers, $actor);

        $query->whereIn('customer_id', $customers->select('users.id'));
    }

    /** Unread customer messages across the threads the actor sees. */
    public static function unreadForStaff(User $actor): int
    {
        $query = Message::query()
            ->where('from_customer', true)
            ->whereNull('read_at');

        self::apply($query, $actor);

        return $query->count();
    }

    /** Unread staff replies in the customer's own thread. */
    public static function unreadForCustomer(User $customer): int
    {
        return Message::query()
            ->where('customer_id', $customer->getKey())
            ->where('from_customer', false)
            ->whereNull('read_at')
            ->count();
    }
}
