<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHourException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessHourException>
 */
class BusinessHourExceptionFactory extends Factory
{
    protected $model = BusinessHourException::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'date' => $this->faker->unique()->date(),
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
            'reason' => null,
        ];
    }

    public function closedEntirely(): static
    {
        return $this->state(fn () => [
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
        ]);
    }
}
