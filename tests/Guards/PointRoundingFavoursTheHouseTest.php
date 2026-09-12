<?php

namespace Tests\Guards;

use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invariant 2: credits floor, debits ceil. The exact-boundary case is
 * the one a float implementation silently gets wrong, so it is asserted
 * here rather than left to the unit tests.
 */
class PointRoundingFavoursTheHouseTest extends TestCase
{
    use RefreshDatabase;

    private PointConversionService $points;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingService::class)->setDefault('point.rate_usd', '0.10', SettingValueType::String);
        $this->points = app(PointConversionService::class);
    }

    public function test_a_credit_never_rounds_up(): void
    {
        $this->assertSame(340, $this->points->usdToPoints('34.09'));
        $this->assertSame(0, $this->points->usdToPoints('0.09'));
    }

    public function test_a_debit_never_rounds_down(): void
    {
        $this->assertSame(8, $this->points->usdToPointsCeil('0.71'));
        $this->assertSame(1, $this->points->usdToPointsCeil('0.01'));
    }

    public function test_an_exact_amount_is_never_inflated(): void
    {
        $this->assertSame(18, $this->points->usdToPointsCeil('1.80'));
        $this->assertSame(500, $this->points->usdToPointsCeil('50.00'));
    }
}
