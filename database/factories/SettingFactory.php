<?php

namespace Database\Factories;

use App\Domain\Settings\Enums\SettingScope;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'test.'.$this->faker->unique()->word(),
            'scope_type' => SettingScope::Global,
            'scope_id' => 0,
            'type' => SettingValueType::Int,
            'value' => (string) $this->faker->numberBetween(1, 100),
        ];
    }
}
