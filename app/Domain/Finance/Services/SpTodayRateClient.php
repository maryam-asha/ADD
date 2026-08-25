<?php

namespace App\Domain\Finance\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to sp-today's currency-rates API. Only two callers may ever
 * reference this class — App\Console\Commands\FetchExchangeRateSuggestion
 * and App\Domain\Finance\Services\ExchangeRateSuggestionIngestor — enforced
 * by tests/Guards/SpTodayClientUsageIsScheduledOnlyTest.php. This is never
 * reachable from a request cycle.
 *
 * docs/decisions/exchange-rate-external-suggestion.md records the Phase 0
 * findings this class implements: the real base path (api/v1, not the
 * originally-assumed api-dashboard path), and that "damascus" is sp-today's
 * nationwide rate — there is no Aleppo-specific one.
 */
class SpTodayRateClient
{
    /**
     * @return array{sell: mixed, buy: mixed, raw: array}
     */
    public function fetchUsdDamascusRates(): array
    {
        $response = Http::baseUrl(config('services.sptoday.base_url'))
            ->withHeaders(['X-API-Key' => config('services.sptoday.api_key')])
            ->timeout(5)
            ->retry(1, 2000)
            ->get('/currencies');

        if (! $response->successful()) {
            throw new \RuntimeException("sp-today request failed with status {$response->status()}");
        }

        Log::info('sp-today rate fetch succeeded', [
            'rate_limit_remaining' => $response->header('X-RateLimit-Remaining'),
        ]);

        $body = $response->json();

        if (($body['ok'] ?? null) !== true) {
            throw new \RuntimeException('sp-today response did not report ok=true');
        }

        $usd = collect($body['data']['currencies'] ?? [])->firstWhere('code', 'USD');

        if (! $usd || ! isset($usd['cities']['damascus'])) {
            throw new \RuntimeException('sp-today response is missing the USD/damascus rate');
        }

        return [
            'sell' => $usd['cities']['damascus']['sell'] ?? null,
            'buy' => $usd['cities']['damascus']['buy'] ?? null,
            'raw' => $body,
        ];
    }
}
