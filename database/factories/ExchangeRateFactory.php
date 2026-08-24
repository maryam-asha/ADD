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
            // USD is the base currency (docs/decisions/money-model.md) — the
            // base never gets a row here, so the default state models the
            // other seeded currency, SYP, instead. rate_to_base is "units of
            // base per 1 unit of currency_code": 0.0000680272 is the real
            // 1/14700 rate (1 USD ≈ 14,700 SYP), now representable at
            // decimal(20,10) precision (2026_08_24_100000_widen_exchange_rates_rate_to_base_precision)
            // without the ~47% error decimal(12,4) forced.
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
            'effective_from' => now()->subDay(),
            'set_by' => null,
        ];
    }
}
