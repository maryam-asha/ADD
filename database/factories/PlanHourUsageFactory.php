<?php

namespace Database\Factories;

use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\PlanHourUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanHourUsage>
 */
class PlanHourUsageFactory extends Factory
{
    protected $model = PlanHourUsage::class;

    public function definition(): array
    {
        return [
            'membership_id' => Membership::factory(),
            'user_id' => User::factory(),
            'plan_space_hour_id' => null,
            'usable_type' => (new WalkinSession)->getMorphClass(),
            'usable_id' => WalkinSession::factory(),
            'cycle_start' => now(),
            'seconds' => 3600,
        ];
    }
}
