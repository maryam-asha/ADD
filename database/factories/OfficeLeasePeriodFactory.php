<?php

namespace Database\Factories;

use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficeLeasePeriod>
 */
class OfficeLeasePeriodFactory extends Factory
{
    protected $model = OfficeLeasePeriod::class;

    public function definition(): array
    {
        return [
            'office_lease_id' => OfficeLease::factory(),
            'sequence' => 1,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addMonthsNoOverflow(3)->subDay()->toDateString(),
            'amount' => '1500.00',
            'status' => LeasePeriodStatus::Upcoming,
        ];
    }
}
