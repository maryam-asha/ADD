<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\CurrencyConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_converts_usd_to_syp_using_the_current_rate(): void
    {
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '14700.0000', 'effective_from' => now()->subDay()]);

        $result = (new CurrencyConversionService)->convert(10.0, 'USD', 'SYP');

        $this->assertSame(147000.0, $result);
    }

    public function test_it_converts_syp_to_usd_using_the_current_rate(): void
    {
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '14700.0000', 'effective_from' => now()->subDay()]);

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
}
