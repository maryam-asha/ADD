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
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '14000.0000', 'effective_from' => now()->subDays(5)]);
        $latestPast = ExchangeRate::factory()->create(['rate_usd_to_syp' => '14700.0000', 'effective_from' => now()->subDay()]);
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '15000.0000', 'effective_from' => now()->addDay()]);

        $current = ExchangeRate::current();

        $this->assertNotNull($current);
        $this->assertSame($latestPast->id, $current->id);
        $this->assertSame('14700.0000', $current->rate_usd_to_syp);
    }

    public function test_current_returns_null_when_no_rate_has_taken_effect_yet(): void
    {
        ExchangeRate::factory()->create(['effective_from' => now()->addDay()]);

        $this->assertNull(ExchangeRate::current());
    }
}
