<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'space_id' => null,
            'type' => 'lock',
            'external_ref' => null,
            'metadata' => null,
            'status' => 'offline',
        ];
    }
}
