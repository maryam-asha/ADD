<?php

namespace Tests\Unit\Domain\Finance\Services;

use App\Domain\Finance\Services\SpTodayRateClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpTodayRateClientTest extends TestCase
{
    private function fakeBody(array $overrides = []): array
    {
        return array_replace_recursive([
            'ok' => true,
            'data' => [
                'currencies' => [
                    ['code' => 'USD', 'cities' => ['damascus' => ['buy' => 13225, 'sell' => 13275], 'alhasakah' => ['buy' => 13250, 'sell' => 13300]]],
                    ['code' => 'EUR', 'cities' => ['damascus' => ['buy' => 15300, 'sell' => 15480]]],
                ],
            ],
        ], $overrides);
    }

    public function test_it_extracts_usd_damascus_buy_and_sell(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(), 200, [
            'X-RateLimit-Limit' => '100',
            'X-RateLimit-Remaining' => '99',
        ])]);

        $result = app(SpTodayRateClient::class)->fetchUsdDamascusRates();

        $this->assertSame(13275, $result['sell']);
        $this->assertSame(13225, $result['buy']);
        $this->assertSame($this->fakeBody(), $result['raw']);
    }

    public function test_it_throws_on_a_non_2xx_response(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response('', 401)]);

        $this->expectException(\RuntimeException::class);

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();
    }

    public function test_it_throws_when_ok_is_not_true(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response(['ok' => false], 200)]);

        $this->expectException(\RuntimeException::class);

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();
    }

    public function test_it_throws_when_usd_is_missing_from_the_currency_list(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(['data' => ['currencies' => [['code' => 'EUR', 'cities' => ['damascus' => ['buy' => 1, 'sell' => 2]]]]]]), 200)]);

        $this->expectException(\RuntimeException::class);

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();
    }

    public function test_it_throws_when_the_damascus_city_is_missing_for_usd(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(['data' => ['currencies' => [['code' => 'USD', 'cities' => ['alhasakah' => ['buy' => 1, 'sell' => 2]]]]]]), 200)]);

        $this->expectException(\RuntimeException::class);

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();
    }
}
