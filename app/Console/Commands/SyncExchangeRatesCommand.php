<?php

namespace App\Console\Commands;

use App\Domain\Finance\Enums\ExchangeRateSource;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\SpTodayRateClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncExchangeRatesCommand extends Command
{
    protected $signature = 'finance:sync-exchange-rates';

    protected $description = 'Sync exchange rates from sp-today API and store new historical rows with source=api.';

    public function handle(SpTodayRateClient $client): int
    {
        $this->info('Fetching exchange rates from sp-today...');

        try {
            $rates = $client->fetchUsdDamascusRates();
        } catch (\Throwable $e) {
            $this->error('Failed to fetch rates from sp-today: ' . $e->getMessage());
            Log::error('SyncExchangeRatesCommand: fetch failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $sell = $rates['sell'] ?? null;
        if (! is_numeric($sell) || (float) $sell <= 0) {
            $this->error('Invalid USD sell rate received from sp-today.');
            Log::error('SyncExchangeRatesCommand: non-positive sell rate', ['sell' => $sell]);

            return self::FAILURE;
        }

        $usdSell = (float) $sell;
        // rate_to_base stores USD per 1 unit of foreign currency:
        // 1 USD = $usdSell SYP  =>  1 SYP = (1 / $usdSell) USD
        $sypRateToBase = number_format(1 / $usdSell, 10, '.', '');

        $sypRate = ExchangeRate::create([
            'currency_code' => 'SYP',
            'rate_to_base' => $sypRateToBase,
            'source' => ExchangeRateSource::Api,
            'effective_from' => now(),
            'set_by' => null,
        ]);

        Cache::forget('exchange_rate_current_SYP');
        $this->info("Successfully synced SYP exchange rate: {$sypRateToBase} (USD sell: {$usdSell})");

        // Sync other active currencies if present in the raw payload (e.g. EUR)
        $rawCurrencies = collect($rates['raw']['data']['currencies'] ?? []);
        $activeCurrencies = Currency::where('is_active', true)
            ->whereNotIn('code', ['USD', 'SYP'])
            ->get();

        foreach ($activeCurrencies as $currency) {
            $curData = $rawCurrencies->firstWhere('code', $currency->code);
            $curSell = $curData['cities']['damascus']['sell'] ?? null;

            if (is_numeric($curSell) && (float) $curSell > 0) {
                // curSell is SYP per 1 unit of currency (e.g. 1 EUR = 14500 SYP).
                // usdSell is SYP per 1 USD (e.g. 1 USD = 13200 SYP).
                // 1 unit of currency = (curSell / usdSell) USD.
                $curRateToBase = number_format(((float) $curSell) / $usdSell, 10, '.', '');

                ExchangeRate::create([
                    'currency_code' => $currency->code,
                    'rate_to_base' => $curRateToBase,
                    'source' => ExchangeRateSource::Api,
                    'effective_from' => now(),
                    'set_by' => null,
                ]);

                Cache::forget("exchange_rate_current_{$currency->code}");
                $this->info("Successfully synced {$currency->code} exchange rate: {$curRateToBase}");
            }
        }

        return self::SUCCESS;
    }
}
