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
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);

        $result = (new CurrencyConversionService)->convert(10.0, 'USD', 'SYP');

        // 10 / 0.0000680272 ≈ 147000.0235 — not perfectly 147000.00, because
        // 0.0000680272 is itself 1/14700 rounded to 10 decimal places, not
        // the exact repeating fraction. That's expected of a real rate.
        $this->assertSame(147000.02, $result);
    }

    public function test_it_converts_syp_to_usd_using_the_current_rate(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);

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
     * fixed base (USD) rather than needing a direct EUR<->SYP rate.
     * EUR = 2 USD (illustrative), SYP = 0.0000680272 USD (the real ~1/14700
     * rate, representable at decimal(20,10) precision): 1 EUR -> base 2 USD
     * -> 29400.00 SYP, and 14700 SYP -> base ~1 USD -> 0.50 EUR.
     */
    public function test_it_converts_between_two_non_base_currencies_through_the_base(): void
    {
        Currency::factory()->create(['code' => 'EUR', 'is_base' => false, 'is_active' => true]);

        ExchangeRate::factory()->create(['currency_code' => 'EUR', 'rate_to_base' => '2.0000', 'effective_from' => now()->subDay()]);
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);

        $eurToSyp = (new CurrencyConversionService)->convert(1.0, 'EUR', 'SYP');
        $sypToEur = (new CurrencyConversionService)->convert(14700.0, 'SYP', 'EUR');

        $this->assertSame(29400.0, $eurToSyp);
        $this->assertSame(0.5, $sypToEur);
    }

    public function test_it_returns_null_when_converting_between_two_non_base_currencies_and_either_rate_is_missing(): void
    {
        Currency::factory()->create(['code' => 'EUR', 'is_base' => false, 'is_active' => true]);

        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);
        // No EUR exchange rate row exists.

        $result = (new CurrencyConversionService)->convert(50.0, 'EUR', 'SYP');

        $this->assertNull($result);
    }
}
