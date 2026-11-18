<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_returns_the_latest_row_that_has_already_taken_effect(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'USD', 'rate_to_base' => '14000.0000', 'effective_from' => now()->subDays(5)]);
        $latestPast = ExchangeRate::factory()->create(['currency_code' => 'USD', 'rate_to_base' => '14700.0000', 'effective_from' => now()->subDay()]);
        ExchangeRate::factory()->create(['currency_code' => 'USD', 'rate_to_base' => '15000.0000', 'effective_from' => now()->addDay()]);

        $current = ExchangeRate::current('USD');

        $this->assertNotNull($current);
        $this->assertSame($latestPast->id, $current->id);
        // Model cast is now decimal:10 (2026_08_24_100000_widen_exchange_rates_rate_to_base_precision),
        // so the accessor pads to 10 decimal places, not 4.
        $this->assertSame('14700.0000000000', $current->rate_to_base);
    }

    public function test_current_returns_null_when_no_rate_has_taken_effect_yet(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'USD', 'effective_from' => now()->addDay()]);

        $this->assertNull(ExchangeRate::current('USD'));
    }

    public function test_current_is_scoped_to_the_given_currency_code(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'USD', 'rate_to_base' => '14700.0000', 'effective_from' => now()->subDay()]);

        $this->assertNull(ExchangeRate::current('EUR'));
    }
}
