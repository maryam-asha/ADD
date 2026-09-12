<?php

namespace Tests\Feature\Console;

use App\Domain\Finance\Enums\ExchangeRateSource;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\SpTodayRateClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class SyncExchangeRatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_command_inserts_new_rate_with_api_source_and_leaves_previous_untouched(): void
    {
        // 1. Create an initial older rate
        $oldRate = ExchangeRate::create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000700000',
            'source' => ExchangeRateSource::Manual,
            'effective_from' => now()->subDay(),
        ]);

        Cache::put('exchange_rate_current_SYP', $oldRate);

        // 2. Mock SpTodayRateClient returning sell = 13200
        $clientMock = Mockery::mock(SpTodayRateClient::class);
        $clientMock->shouldReceive('fetchUsdDamascusRates')->once()->andReturn([
            'sell' => 13200,
            'buy' => 13150,
            'raw' => ['data' => ['currencies' => []]],
        ]);
        $this->app->instance(SpTodayRateClient::class, $clientMock);

        // 3. Run command
        $this->artisan('finance:sync-exchange-rates')
            ->expectsOutputToContain('Successfully synced SYP exchange rate')
            ->assertSuccessful();

        // 4. Verify old rate still exists untouched
        $this->assertDatabaseHas('exchange_rates', [
            'id' => $oldRate->id,
            'rate_to_base' => '0.0000700000',
            'source' => ExchangeRateSource::Manual->value,
        ]);

        // 5. Verify new rate inserted with source = api
        $expectedRate = number_format(1 / 13200, 10, '.', '');
        $this->assertDatabaseHas('exchange_rates', [
            'currency_code' => 'SYP',
            'rate_to_base' => $expectedRate,
            'source' => ExchangeRateSource::Api->value,
        ]);

        // 6. Verify ExchangeRate::current('SYP') returns the new rate
        $current = ExchangeRate::current('SYP');
        $this->assertNotNull($current);
        $this->assertSame(ExchangeRateSource::Api, $current->source);
        $this->assertEquals($expectedRate, (string) $current->rate_to_base);

        // 7. Manual override: if an admin updates the rate, it immediately takes precedence
        $manualRate = ExchangeRate::create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000800000',
            'source' => ExchangeRateSource::Manual,
            'effective_from' => now(),
        ]);

        $currentAfterManual = ExchangeRate::current('SYP');
        $this->assertSame($manualRate->id, $currentAfterManual->id);
        $this->assertSame(ExchangeRateSource::Manual, $currentAfterManual->source);
    }

    public function test_sync_command_handles_api_failure_gracefully(): void
    {
        $clientMock = Mockery::mock(SpTodayRateClient::class);
        $clientMock->shouldReceive('fetchUsdDamascusRates')
            ->once()
            ->andThrow(new \RuntimeException('Connection timed out'));
        $this->app->instance(SpTodayRateClient::class, $clientMock);

        $this->artisan('finance:sync-exchange-rates')
            ->expectsOutputToContain('Failed to fetch rates from sp-today')
            ->assertFailed();
    }
}
