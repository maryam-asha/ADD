<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_name' => fake()->company().' LLC',
            'contract_ref' => 'C-'.fake()->unique()->numberBetween(1000, 9999),
            'branch_id' => Branch::factory(),
            'status' => CompanyStatus::Active,
        ];
    }
}
