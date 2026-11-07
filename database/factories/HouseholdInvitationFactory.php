<?php

namespace Database\Factories;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HouseholdInvitation>
 */
class HouseholdInvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'token_hash' => HouseholdInvitation::hash(HouseholdInvitation::newToken()),
            'role' => HouseholdRole::Member,
            'expires_at' => now()->addDays(HouseholdInvitation::LIFETIME_DAYS),
        ];
    }

    public function token(string $token): static
    {
        return $this->state(fn (): array => ['token_hash' => HouseholdInvitation::hash($token)]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}
