<?php

namespace Database\Factories;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => SocialProvider::Google,
            'provider_user_id' => (string) fake()->unique()->numerify('####################'),
            'email' => fake()->safeEmail(),
            'name' => fake()->name(),
            'avatar_url' => null,
        ];
    }

    /** Linked to `$user`, in the user's tenant. */
    public function linkedTo(User $user): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
    }

    public function facebook(): static
    {
        return $this->state(fn (): array => ['provider' => SocialProvider::Facebook]);
    }
}
