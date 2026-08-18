<?php

namespace Database\Factories;

use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Space>
 */
class SpaceFactory extends Factory
{
    protected $model = Space::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = SpaceType::CoSpace;

        return [
            'building_id' => Building::factory(),
            'zone_id' => null,
            'space_type' => $type,
            // Kept consistent with SpaceType::isLockable() by default
            // (PRD decision #13) — a test that wants to exercise a
            // mismatch does so explicitly.
            'is_lockable' => $type->isLockable(),
            'capacity' => fake()->numberBetween(4, 40),
            'hourly_rate' => null,
            'pricing_currency' => null,
            'status' => OperationalStatus::Active,
        ];
    }

    public function ofType(SpaceType $type): static
    {
        return $this->state(fn () => [
            'space_type' => $type,
            'is_lockable' => $type->isLockable(),
        ]);
    }

    public function room(): static
    {
        return $this->ofType(SpaceType::Room)->state([
            'hourly_rate' => fake()->randomFloat(2, 5, 50),
            'pricing_currency' => 'USD',
        ]);
    }

    public function eventHall(): static
    {
        return $this->ofType(SpaceType::EventHall)->state([
            'hourly_rate' => fake()->randomFloat(2, 50, 300),
            'pricing_currency' => 'USD',
        ]);
    }

    public function business(): static
    {
        return $this->ofType(SpaceType::Business);
    }

    public function maintenance(): static
    {
        return $this->state([
            'status' => OperationalStatus::Maintenance,
            'status_reason' => fake()->sentence(),
            'status_from' => now(),
        ]);
    }

    public function requiresApproval(): static
    {
        return $this->state(['requires_approval' => true]);
    }
}
