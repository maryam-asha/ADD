<?php

namespace Database\Factories;

use App\Domain\Identity\Models\Guest;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
{
    protected $model = Guest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hosting_user_id' => User::factory(),
            'full_name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'valid_until' => now()->addDay(),
        ];
    }
}
