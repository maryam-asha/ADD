<?php

namespace Database\Factories;

use App\Domain\Providers\Enums\ProviderStatus;
use App\Domain\Providers\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Provider>
 */
class ProviderFactory extends Factory
{
    protected $model = Provider::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'contract_ref' => 'P-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => ProviderStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ProviderStatus::Inactive]);
    }
}
