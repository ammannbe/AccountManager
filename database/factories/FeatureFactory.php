<?php

namespace Database\Factories;

use App\Models\Feature;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feature>
 */
class FeatureFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->word();

        return [
            'service_id' => Service::factory(),
            'key' => "feature.{$name}",
            'name' => ucfirst($name),
        ];
    }
}
