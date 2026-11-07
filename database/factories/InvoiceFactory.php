<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Enums\ReferenceType;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        $sequence = $this->faker->unique()->numberBetween(1, 9999);

        return [
            'number' => now()->year.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'year' => now()->year,
            'sequence' => $sequence,
            'user_id' => User::factory(),
            'recipient' => fn (array $attributes) => User::query()->findOrFail($attributes['user_id'])->billingRecipient(),
            'creditor' => [
                'name' => 'Narrenhaus GmbH',
                'street' => 'Bahnhofstrasse 1',
                'postal_code' => '8001',
                'city' => 'Zürich',
                'country' => 'CH',
                'iban' => 'CH9300762011623852957',
                'email' => null,
                'website' => null,
                'vat_number' => null,
                'vat_note' => null,
            ],
            'currency' => 'CHF',
            'total_cents' => 1200,
            'status' => InvoiceStatus::Open,
            'issued_at' => now()->subDays(5),
            'due_at' => now()->addDays(25),
            'reference_type' => ReferenceType::Non,
            'reference' => null,
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => InvoiceStatus::Overdue,
            'issued_at' => now()->subDays(40),
            'due_at' => now()->subDays(10),
            'overdue_at' => now()->subDays(9),
        ]);
    }
}
