<?php

namespace App\Actions\Message;

use App\Events\MessageSent;
use App\Models\Booking;
use App\Models\Message;
use App\Models\User;

/**
 * Appends a message to a customer's thread (SLO-36) and fires {@see MessageSent}.
 *
 * Authorisation is the caller's: the admin route binds the customer through
 * CustomerVisibility, the members area only ever passes the signed-in customer,
 * and both Form Requests check that an attached booking belongs to the thread.
 * tenant_id is stamped by BelongsToTenant from the ambient tenant.
 */
class SendMessage
{
    public function fromCustomer(User $customer, string $body, ?Booking $booking = null): Message
    {
        return $this->store($customer, $customer, true, $body, $booking);
    }

    public function fromStaff(User $customer, User $staff, string $body, ?Booking $booking = null): Message
    {
        return $this->store($customer, $staff, false, $body, $booking);
    }

    private function store(User $customer, User $sender, bool $fromCustomer, string $body, ?Booking $booking): Message
    {
        $message = new Message;
        $message->fill([
            'customer_id' => $customer->getKey(),
            'sender_id' => $sender->getKey(),
            'from_customer' => $fromCustomer,
            'booking_id' => $booking?->getKey(),
            'body' => $body,
        ]);
        $message->save();

        MessageSent::dispatch($message);

        return $message;
    }
}
