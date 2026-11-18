<?php

namespace Tests\Unit\Support;

use App\Support\PlainAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlainAmountTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            'whole' => ['300.00', '300'],
            'one decimal' => ['150.50', '150.5'],
            'two decimals' => ['12.75', '12.75'],
            'zero' => ['0.00', '0'],
            'negative' => ['-5.10', '-5.1'],
            'large syp' => ['123456789012.00', '123456789012'],
            'db sum float' => [300.5, '300.5'],
            'int' => [40, '40'],
            'trailing zero in integer part' => ['1000.00', '1000'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_it_drops_trailing_zeros(mixed $in, string $out): void
    {
        $this->assertSame($out, PlainAmount::of($in));
    }

    public function test_null_stays_null(): void
    {
        $this->assertNull(PlainAmount::of(null));
    }
}
