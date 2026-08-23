<?php

namespace Database\Factories;

use App\Domain\Ecosystem\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['news', 'event', 'offer']),
            'image_url' => fake()->imageUrl(),
            'link_url' => null,
            'sort_order' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
    }

    public function news(): static
    {
        return $this->state(['type' => 'news']);
    }

    public function event(): static
    {
        return $this->state(['type' => 'event']);
    }

    public function offer(): static
    {
        return $this->state(['type' => 'offer']);
    }

    /** Scheduled to start in the future — not yet live. */
    public function upcoming(): static
    {
        return $this->state(fn () => ['starts_at' => now()->addDays(3)]);
    }

    /** Window already closed — no longer live. */
    public function expired(): static
    {
        return $this->state(fn () => ['ends_at' => now()->subDay()]);
    }
}
