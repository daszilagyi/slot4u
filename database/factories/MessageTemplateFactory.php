<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\MessageTemplate;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageTemplate>
 */
class MessageTemplateFactory extends Factory
{
    protected $model = MessageTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'key' => NotificationType::BookingConfirmed,
            'channel' => NotificationChannel::Email,
            'locale' => 'hu',
            'subject' => 'Egyedi tárgy – :tenant',
            // The greeting is added automatically; the body is the prose below it.
            'body' => "A(z) :service foglalásod (:code) rögzítve.\nKöszönjük, hogy minket választottál!",
            'enabled' => true,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    public function forType(NotificationType $type): static
    {
        return $this->state(['key' => $type]);
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }
}
