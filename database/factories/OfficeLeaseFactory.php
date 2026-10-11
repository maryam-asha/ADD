<?php

namespace Database\Factories;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Providers\Enums\Payer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A year-long, quarterly-billed USD lease starting today. Needs the USD
 * currency row (CurrencySeeder) — `currency` is a foreign key.
 *
 * @extends Factory<OfficeLease>
 */
class OfficeLeaseFactory extends Factory
{
    protected $model = OfficeLease::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'space_id' => Space::factory()->ofType(SpaceType::Business),
            'private_office_request_id' => PrivateOfficeRequest::factory()->state(['status' => PrivateOfficeRequestStatus::Contracted]),
            'provider_id' => null,
            'billed_to' => Payer::Company,
            'currency' => 'USD',
            'monthly_amount' => '500.00',
            'starts_on' => now()->toDateString(),
            'duration_months' => 12,
            'billing_cycle_months' => 3,
            'ends_on' => fn (array $attributes) => CarbonImmutable::parse($attributes['starts_on'])
                ->addMonthsNoOverflow($attributes['duration_months'])->subDay()->toDateString(),
            'status' => OfficeLeaseStatus::Active,
        ];
    }
}
