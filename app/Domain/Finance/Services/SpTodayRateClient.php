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
            // throw: false — Laravel's HTTP client would otherwise call
            // $response->throw() itself once both attempts are exhausted
            // and the response is still unsuccessful, raising its own
            // Illuminate\Http\Client\RequestException before the explicit
            // check below ever runs. That would silently replace this
            // class's documented \RuntimeException contract (see the class
            // docblock) with a different exception type/message for every
            // caller — including the scheduled-only usage guard's callers,
            // which only catch \Throwable but still rely on this message.
            ->retry(2, 2000, throw: false)
            ->get('/currencies');

        if (! $response->successful()) {
            throw new \RuntimeException("sp-today request failed with status {$response->status()}");
        }

        Log::info('sp-today request returned a successful HTTP response', [
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
