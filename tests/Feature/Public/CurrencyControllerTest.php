<?php

namespace Tests\Feature\Public;

use App\Domain\Finance\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_listing_only_shows_active_currencies_ordered_by_order(): void
    {
        Currency::factory()->create(['code' => 'EUR', 'order' => 0, 'is_active' => true]);
        Currency::factory()->create(['code' => 'GBP', 'order' => 10, 'is_active' => false]);

        $response = $this->getJson('/api/v1/currencies');

        $response->assertOk();
        // SYP (order 1), EUR (order 0) and USD (order 2) are active; GBP is not.
        $response->assertJsonCount(3, 'data');
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('SYP'));
        $this->assertTrue($codes->contains('USD'));
        $this->assertTrue($codes->contains('EUR'));
        $this->assertFalse($codes->contains('GBP'));
    }

    public function test_the_public_listing_requires_no_authentication(): void
    {
        $this->getJson('/api/v1/currencies')->assertOk();
    }

    public function test_public_listing_returns_only_code_symbol_and_localized_name_based_on_header(): void
    {
        $responseAr = $this->withHeader('lang', 'ar')->getJson('/api/v1/currencies');
        $responseAr->assertOk();
        $sypAr = collect($responseAr->json('data'))->firstWhere('code', 'SYP');
        $this->assertEquals([
            'code' => 'SYP',
            'name' => 'ليرة سورية',
            'symbol' => 'ل.س',
        ], $sypAr);

        $responseEn = $this->withHeader('lang', 'en')->getJson('/api/v1/currencies');
        $responseEn->assertOk();
        $sypEn = collect($responseEn->json('data'))->firstWhere('code', 'SYP');
        $this->assertEquals([
            'code' => 'SYP',
            'name' => 'Syrian Pound',
            'symbol' => 'ل.س',
        ], $sypEn);
    }
}
