<?php

namespace Database\Factories;

use App\Domain\Booking\Models\SpaceCoworkingWindow;
use App\Domain\Foundation\Models\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpaceCoworkingWindow>
 */
class SpaceCoworkingWindowFactory extends Factory
{
    protected $model = SpaceCoworkingWindow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'space_id' => Space::factory()->room(),
            'starts_at' => now()->addHour()->startOfHour(),
            'ends_at' => now()->addHours(5)->startOfHour(),
            'seats' => 4,
        ];
    }
}
