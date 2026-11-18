<?php

namespace Add\Inventory\Tests\Unit;

use Add\Inventory\Catalog\Services\BarcodeGenerator;
use Add\Inventory\Tests\TestCase;

class BarcodeGeneratorTest extends TestCase
{
    public function test_check_digit_matches_a_known_ean13(): void
    {
        // 4006381333931 is a published EAN-13.
        $this->assertSame(1, BarcodeGenerator::checkDigit('400638133393'));
        // 2 + 1×3 = 5 → 5; the code the feature tests use, 2000000000015.
        $this->assertSame(5, BarcodeGenerator::checkDigit('200000000001'));
    }

    public function test_next_is_13_digits_starting_with_2_and_valid(): void
    {
        $code = app(BarcodeGenerator::class)->next();

        $this->assertMatchesRegularExpression('/^2\d{12}$/', $code);
        $this->assertSame((int) $code[12], BarcodeGenerator::checkDigit(substr($code, 0, 12)));
    }
}
