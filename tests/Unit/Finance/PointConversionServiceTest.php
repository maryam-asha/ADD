<?php

namespace Tests\Unit\Finance;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PointConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PointConversionService $points;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingService::class)->setDefault('point.rate_usd', '0.10', SettingValueType::String);
        $this->points = app(PointConversionService::class);
    }

    public function test_it_reads_the_rate_from_settings(): void
    {
        $this->assertSame('0.10', $this->points->rate());
    }

    public function test_the_published_rate_is_always_a_plain_four_decimal_string(): void
    {
        $this->assertSame('0.1000', $this->points->publishedRate());

        foreach (['.05' => '0.0500', '5.' => '5.0000', '+0.10' => '0.1000', '12.3456' => '12.3456'] as $stored => $published) {
            app(SettingService::class)->set('point.rate_usd', $stored, SettingValueType::String);

            $this->assertSame($published, app(PointConversionService::class)->publishedRate());
        }
    }

    public function test_it_converts_points_to_usd(): void
    {
        $this->assertSame('50.00', $this->points->pointsToUsd(500));
    }

    public function test_points_revalue_when_the_admin_changes_the_rate(): void
    {
        app(SettingService::class)->set('point.rate_usd', '0.12', SettingValueType::String);

        $this->assertSame('12.00', app(PointConversionService::class)->pointsToUsd(100));
    }

    public function test_credits_round_down(): void
    {
        $this->assertSame(340, $this->points->usdToPoints('34.09'));
    }

    public function test_debits_round_up(): void
    {
        $this->assertSame(8, $this->points->usdToPointsCeil('0.71'));
    }

    public function test_an_exact_debit_does_not_gain_a_point(): void
    {
        $this->assertSame(18, $this->points->usdToPointsCeil('1.80'));
    }

    public function test_negative_points_convert_for_display(): void
    {
        $this->assertSame('-5.00', $this->points->pointsToUsd(-50));
    }

    public function test_it_rejects_a_negative_credit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->points->usdToPoints('-5.00');
    }

    public function test_it_returns_null_when_no_exchange_rate_exists(): void
    {
        Currency::factory()->create(['code' => 'EUR', 'is_base' => false, 'is_default' => false]);

        $this->assertNull($this->points->pointsToCurrency(100, 'EUR'));
    }

    public function test_it_converts_points_to_a_foreign_currency(): void
    {
        ExchangeRate::factory()->create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
        ]);

        // 340 points -> $34.00 -> /0.0000680272 -> ~499,800 SYP
        $syp = $this->points->pointsToCurrency(340, 'SYP');

        $this->assertNotNull($syp);
        $this->assertEqualsWithDelta(499800, (float) $syp, 100);
    }

    /**
     * The same vectors the dashboard's top-up-math.spec.ts asserts, so the
     * two implementations are proven to agree (spec 2026-10-10 §6).
     * Each new rate row is newer than the last (higher id, same
     * effective_from), and saving one clears ExchangeRate::current()'s cache.
     */
    public function test_currency_to_points_floors_the_exact_value_once(): void
    {
        $vectors = [
            // [amount, currency, rate_to_base (null = base currency), expected points]
            ['500000', 'SYP', '0.0000680272', 340],
            ['501200', 'SYP', '0.0000680272', 340], // the old path credited 341
            ['150000.00', 'SYP', '0.0000666667', 100],
            ['5000', 'SYP', '0.0068027211', 340],   // new pound
            ['0.30', 'USD', null, 3],               // float division gives 2
            ['10.05', 'USD', null, 100],
        ];

        foreach ($vectors as [$amount, $currency, $rate, $expected]) {
            if ($rate !== null) {
                ExchangeRate::factory()->create(['currency_code' => $currency, 'rate_to_base' => $rate]);
            }

            $this->assertSame($expected, $this->points->currencyToPoints($amount, $currency), "{$amount} {$currency}");
        }
    }

    public function test_currency_to_points_is_null_without_an_exchange_rate(): void
    {
        $this->assertNull($this->points->currencyToPoints('5000', 'SYP'));
    }
}
