<?php

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Set explicitly: the default resolver guesses App\Models\User, which no
     * longer exists now that models are namespaced by domain.
     *
     * @var class-string<User>
     */
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+963'.fake()->unique()->numerify('#########'),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Set explicitly, not left to the migration's column default —
            // Eloquent doesn't re-fetch DB-side defaults into an unrefreshed
            // model (the same lesson already documented in the build plan's
            // Phase 2 notes for CompanyController::store), and
            // Sanctum::actingAs() in tests uses this in-memory instance
            // directly, so a factory-created user with an unset `status`
            // would otherwise fail any `status === 'active'` check even
            // though the actual DB row is correctly 'active'.
            'status' => 'active',
            // Same lesson as `status` above: the migration's column default
            // isn't re-fetched into this unrefreshed in-memory instance, so a
            // factory-created user with an unset `preferred_currency` would
            // read as null here even though the real DB row is 'SYP'.
            'preferred_currency' => 'SYP',
        ];
    }

    /**
     * Indicate that the model's account has been deactivated (voluntary/
     * administrative pause).
     */
    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'deactivated',
        ]);
    }

    /**
     * Indicate that the model's account has been blocked (punitive/security).
     */
    public function blocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'blocked',
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
