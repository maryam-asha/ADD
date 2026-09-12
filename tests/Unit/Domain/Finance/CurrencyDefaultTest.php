<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CurrencyDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_one_currency_can_be_default_at_a_time(): void
    {
        // USD is seeded as default
        $usd = Currency::where('code', 'USD')->first();
        $this->assertTrue((bool) $usd->is_default);

        // Make SYP the default
        $syp = Currency::where('code', 'SYP')->first();
        $syp->update(['is_default' => true]);

        $this->assertTrue((bool) $syp->fresh()->is_default);
        $this->assertFalse((bool) $usd->fresh()->is_default);

        $defaultCount = Currency::where('is_default', true)->count();
        $this->assertSame(1, $defaultCount);
    }

    public function test_default_code_helper_returns_default_and_caches_it(): void
    {
        Cache::flush();

        $code = Currency::defaultCode();
        $this->assertSame('USD', $code);
        $this->assertTrue(Cache::has('default_currency_code'));

        // Changing default currency clears cache
        $syp = Currency::where('code', 'SYP')->first();
        $syp->update(['is_default' => true]);

        $this->assertFalse(Cache::has('default_currency_code'));
        $this->assertSame('SYP', Currency::defaultCode());
    }
}
