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
}
