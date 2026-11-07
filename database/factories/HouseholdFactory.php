<?php

namespace Database\Factories;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Household>
 */
class HouseholdFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Familie '.$this->faker->lastName(),
        ];
    }

    /**
     * Adds the user as member (without events, like existing data).
     */
    public function withMember(User $user, HouseholdRole $role = HouseholdRole::Member): static
    {
        return $this->afterCreating(function (Household $household) use ($user, $role): void {
            $household->members()->create(['user_id' => $user->getKey(), 'role' => $role, 'joined_at' => now()]);
        });
    }

    public function withAdmin(User $user): static
    {
        return $this->state(fn (): array => ['created_by' => $user->getKey()])->withMember($user, HouseholdRole::Admin);
    }
}
