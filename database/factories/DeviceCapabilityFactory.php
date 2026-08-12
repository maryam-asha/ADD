<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\DeviceCapability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceCapability>
 */
class DeviceCapabilityFactory extends Factory
{
    protected $model = DeviceCapability::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'capability' => 'generate_passcode',
        ];
    }
}
