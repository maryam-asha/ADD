<?php

namespace Database\Factories;

use App\Domain\Experience\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addDays(fake()->numberBetween(2, 30))->setTime(18, 0);

        return [
            'title' => ['en' => fake()->sentence(3), 'ar' => 'فعالية'],
            'description' => ['en' => fake()->sentence(), 'ar' => 'وصف'],
            'start_at' => $start,
            'end_at' => (clone $start)->addHours(2),
            'capacity' => null,
            'requires_approval' => false,
            'has_registration_form' => false,
            'is_free' => true,
            'price_points' => 0,
            'status' => 'published',
            'order' => 0,
        ];
    }

    public function requiresApproval(): static
    {
        return $this->state(fn () => ['requires_approval' => true]);
    }

    public function withForm(): static
    {
        return $this->state(fn () => ['has_registration_form' => true]);
    }

    public function paid(int $points = 150): static
    {
        return $this->state(fn () => ['is_free' => false, 'price_points' => $points]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft']);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }

    public function past(): static
    {
        return $this->state(fn () => [
            'start_at' => now()->subDays(3),
            'end_at' => now()->subDays(3)->addHours(2),
        ]);
    }
}
