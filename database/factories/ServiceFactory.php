<?php

namespace Database\Factories;

use App\Enums\ServiceType;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        $key = $this->faker->unique()->slug(2);

        return [
            'key' => $key,
            'name' => ucfirst(str_replace('-', ' ', $key)),
            'url' => "https://{$key}.example.com",
            'type' => ServiceType::Oidc,
            'access_group' => "svc-{$key}",
            'self_signup' => false,
            'requires_approval' => false,
            'visible' => true,
        ];
    }

    public function selfSignup(bool $requiresApproval = false): static
    {
        return $this->state(fn (): array => ['self_signup' => true, 'requires_approval' => $requiresApproval]);
    }
}
