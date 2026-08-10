<?php

namespace Database\Factories;

use App\Domain\Finance\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    public function definition(): array
    {
        return [
            'rate_usd_to_syp' => '14700.0000',
            'effective_from' => now()->subDay(),
            'set_by' => null,
        ];
    }
}
