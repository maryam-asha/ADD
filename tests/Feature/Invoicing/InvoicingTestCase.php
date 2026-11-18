<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Finance\Models\ExchangeRate;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Providers\ProviderTestCase;

abstract class InvoicingTestCase extends ProviderTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CurrencySeeder::class);
        $this->seed(SettingSeeder::class);
    }

    /** An SYP rate effective now: one pound is worth `$rateToBase` dollars. */
    protected function syp(string $rateToBase = '0.0001'): void
    {
        ExchangeRate::factory()->create([
            'currency_code' => 'SYP',
            'rate_to_base' => $rateToBase,
            'effective_from' => now()->subMinute(),
        ]);
        Cache::forget('exchange_rate_current_SYP');
    }
}
