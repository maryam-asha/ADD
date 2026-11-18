<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrivateOfficeRequest>
 */
class PrivateOfficeRequestFactory extends Factory
{
    protected $model = PrivateOfficeRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prospect_name' => fake()->company(),
            'contact' => fake()->phoneNumber(),
            'status' => PrivateOfficeRequestStatus::Requested,
        ];
    }

    public function quoted(): static
    {
        return $this->state([
            'status' => PrivateOfficeRequestStatus::Quoted,
            'quote_ref' => 'Q-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
    }
}
