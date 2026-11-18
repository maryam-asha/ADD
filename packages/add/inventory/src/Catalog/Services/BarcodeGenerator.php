<?php

namespace Add\Inventory\Catalog\Services;

use Add\Inventory\Catalog\Models\Item;

/**
 * Barcodes for items that come without one: EAN-13 starting with 2 — the
 * range GS1 leaves for codes used inside one business — so a scanner reads
 * them and they never collide with a supplier's product code.
 */
class BarcodeGenerator
{
    public function next(): string
    {
        do {
            $body = '2'.str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT);
            $code = $body.self::checkDigit($body);
        } while (Item::query()->where('barcode', $code)->exists());

        return $code;
    }

    /**
     * The EAN-13 check digit of its first twelve digits.
     */
    public static function checkDigit(string $twelve): int
    {
        $sum = 0;

        foreach (str_split($twelve) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }
}
