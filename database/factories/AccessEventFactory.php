<?php

namespace Database\Factories;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Models\AccessEvent;
use App\Domain\Foundation\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessEvent>
 */
class AccessEventFactory extends Factory
{
    protected $model = AccessEvent::class;

    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'access_grant_id' => null,
            'event_type' => AccessEventType::Unlock,
            'channel' => AccessEventChannel::QrScan,
            'actor_user_id' => null,
            'occurred_at' => now(),
        ];
    }
}
