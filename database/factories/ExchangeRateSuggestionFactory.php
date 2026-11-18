<?php

namespace Database\Factories;

use App\Domain\Finance\Enums\ExchangeRateSuggestionSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRateSuggestion>
 */
class ExchangeRateSuggestionFactory extends Factory
{
    protected $model = ExchangeRateSuggestion::class;

    public function definition(): array
    {
        return [
            'source' => ExchangeRateSuggestionSource::SpToday,
            // Matches the live sp-today USD/damascus sample recorded in
            // docs/decisions/exchange-rate-external-suggestion.md.
            'rate_usd_to_syp' => '13275.0000000000',
            'raw_payload' => [
                'ok' => true,
                'data' => [
                    'currencies' => [
                        ['code' => 'USD', 'cities' => ['damascus' => ['buy' => 13225, 'sell' => 13275]]],
                    ],
                ],
            ],
            'fetched_at' => now(),
            'status' => ExchangeRateSuggestionStatus::Pending,
        ];
    }
}
