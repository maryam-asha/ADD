<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\CurrencyConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_converts_usd_to_syp_using_the_current_rate(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'USD', 'rate_to_base' => '14700.0000', 'effective_from' => now()->subDay()]);

        $result = (new CurrencyConversionService)->convert(10.0, 'USD', 'SYP');

        $this->assertSame(147000.0, $result);
    }

    public function test_it_converts_syp_to_usd_using_the_current_rate(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'USD', 'rate_to_base' => '14700.0000', 'effective_from' => now()->subDay()]);

        $result = (new CurrencyConversionService)->convert(14700.0, 'SYP', 'USD');

        $this->assertSame(1.0, $result);
    }

    public function test_it_returns_the_same_amount_when_currencies_match(): void
    {
        $result = (new CurrencyConversionService)->convert(500.0, 'SYP', 'SYP');

        $this->assertSame(500.0, $result);
    }

    public function test_it_returns_null_when_no_exchange_rate_exists(): void
    {
        $result = (new CurrencyConversionService)->convert(10.0, 'USD', 'SYP');

        $this->assertNull($result);
    }

    /**
     * The behavior the whole `currencies`/`exchange_rates` generalization
     * exists to unlock: two non-base currencies converting through the
     * fixed base (SYP) rather than needing a direct EUR<->USD rate.
     * USD = 100 SYP, EUR = 120 SYP (chosen so both directions land on
     * clean numbers): 50 EUR -> base 6000 SYP -> 60 USD, and the reverse,
     * 60 USD -> base 6000 SYP -> 50 EUR.
     */
    public function test_it_converts_between_two_non_base_currencies_through_the_base(): void
    {
        Currency::factory()->create(['code' => 'EUR', 'is_base' => false, 'is_active' => true]);

        ExchangeRate::factory()->create(['currency_code' => 'USD', 'rate_to_base' => '100.0000', 'effective_from' => now()->subDay()]);
        ExchangeRate::factory()->create(['currency_code' => 'EUR', 'rate_to_base' => '120.0000', 'effective_from' => now()->subDay()]);

        $eurToUsd = (new CurrencyConversionService)->convert(50.0, 'EUR', 'USD');
        $usdToEur = (new CurrencyConversionService)->convert(60.0, 'USD', 'EUR');

        $this->assertSame(60.0, $eurToUsd);
        $this->assertSame(50.0, $usdToEur);
    }

    public function test_it_returns_null_when_converting_between_two_non_base_currencies_and_either_rate_is_missing(): void
    {
        Currency::factory()->create(['code' => 'EUR', 'is_base' => false, 'is_active' => true]);

        ExchangeRate::factory()->create(['currency_code' => 'USD', 'rate_to_base' => '100.0000', 'effective_from' => now()->subDay()]);
        // No EUR exchange rate row exists.

        $result = (new CurrencyConversionService)->convert(50.0, 'EUR', 'USD');

        $this->assertNull($result);
    }
}
