<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\ExchangeRateSuggestionSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs the full accept/reject decision for one sp-today fetch. Every
 * failure path logs and returns — nothing here may throw past this class,
 * so a vendor hiccup never crashes the scheduled command.
 * docs/decisions/exchange-rate-external-suggestion.md.
 */
class ExchangeRateSuggestionIngestor
{
    public function __construct(private readonly SpTodayRateClient $client) {}

    public function run(): void
    {
        try {
            $rates = $this->client->fetchUsdDamascusRates();
        } catch (\Throwable $e) {
            Log::error('sp-today exchange rate suggestion fetch failed', ['reason' => $e->getMessage()]);

            return;
        }

        $sell = $rates['sell'] ?? null;
        $buy = $rates['buy'] ?? null;

        if (! is_numeric($sell) || (float) $sell <= 0) {
            Log::error('sp-today exchange rate suggestion rejected: sell field missing, non-numeric, or not positive', ['sell' => $sell]);

            return;
        }

        if (! is_numeric($buy)) {
            Log::error('sp-today exchange rate suggestion rejected: buy field missing or non-numeric (response contract break)', ['buy' => $buy]);

            return;
        }

        if ((float) $sell < (float) $buy) {
            Log::error('sp-today exchange rate suggestion rejected: sell is below buy (response contract break, not a market condition)', [
                'sell' => $sell,
                'buy' => $buy,
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($sell, $rates) {
                ExchangeRateSuggestion::where('status', ExchangeRateSuggestionStatus::Pending)
                    ->update(['status' => ExchangeRateSuggestionStatus::Superseded]);

                ExchangeRateSuggestion::create([
                    'source' => ExchangeRateSuggestionSource::SpToday,
                    'rate_usd_to_syp' => $sell,
                    'raw_payload' => $rates['raw'],
                    'fetched_at' => now(),
                    'status' => ExchangeRateSuggestionStatus::Pending,
                ]);
            });
        } catch (\Throwable $e) {
            // The transaction rolls back atomically on its own — this catch
            // only stops a local DB failure (not a vendor hiccup) from
            // throwing past run(), per the "nothing may throw past this
            // class" constraint.
            Log::error('sp-today exchange rate suggestion persist failed', ['reason' => $e->getMessage()]);
        }
    }
}
