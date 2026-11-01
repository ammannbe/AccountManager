<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceAccount>
 */
class ServiceAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'service_id' => Service::factory(),
            'status' => AccountStatus::Active,
        ];
    }
}
