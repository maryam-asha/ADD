<?php

namespace Database\Factories;

use App\Domain\Ecosystem\Models\QuickAccessShortcut;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuickAccessShortcut>
 */
class QuickAccessShortcutFactory extends Factory
{
    protected $model = QuickAccessShortcut::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = $this->faker->unique()->slug(2);

        return [
            'key' => $key,
            'label' => ['en' => ucfirst($key), 'ar' => $key],
            'icon_url' => fake()->imageUrl(),
            'route' => '/'.$key,
            'order' => 0,
            'is_active' => true,
        ];
    }
}
