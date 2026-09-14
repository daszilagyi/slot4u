<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'customer_id' => User::factory(),
            'sender_id' => null,
            'from_customer' => true,
            'booking_id' => null,
            'body' => 'Szeretném megkérdezni, mit hozzak magammal az első alkalomra.',
        ];
    }

    /** A message the customer wrote in their own thread. */
    public function fromCustomer(User $customer): static
    {
        return $this->state([
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'sender_id' => $customer->id,
            'from_customer' => true,
        ]);
    }

    /** A staff reply in the customer's thread. */
    public function fromStaff(User $customer, User $staff): static
    {
        return $this->state([
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'sender_id' => $staff->id,
            'from_customer' => false,
        ]);
    }
}
