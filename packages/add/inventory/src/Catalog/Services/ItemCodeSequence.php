<?php

namespace Add\Inventory\Catalog\Services;

use Add\Inventory\Catalog\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * Numbered item codes: config inventory.items.sku_prefix and the next
 * number of one counter, padded to sku_pad digits (ITM-00001, ITM-00002…),
 * never the item's id. Call it inside the transaction that saves the item:
 * the counter row is locked until it commits, so two saves never take the
 * same number, and one that rolls back takes none.
 *
 * The counter starts after the highest number already used in the same
 * shape (items numbered before it existed), and steps over a code someone
 * typed in that shape. A deleted item's number is not given again.
 */
class ItemCodeSequence
{
    private const NAME = 'items.sku';

    public function next(): string
    {
        $value = $this->lockedValue();

        do {
            $code = $this->format(++$value);
        } while (Item::query()->where('sku', $code)->exists());

        DB::table('inv_sequences')->where('name', self::NAME)->update(['value' => $value]);

        return $code;
    }

    private function lockedValue(): int
    {
        $read = fn () => DB::table('inv_sequences')->where('name', self::NAME)->lockForUpdate()->value('value');
        $value = $read();

        if ($value === null) {
            // Ignored when a concurrent save created it first; the locked
            // read below then waits for that one.
            DB::table('inv_sequences')->insertOrIgnore(['name' => self::NAME, 'value' => $this->highestUsed()]);
            $value = $read();
        }

        return (int) $value;
    }

    /**
     * The highest number among the codes already in this shape.
     */
    private function highestUsed(): int
    {
        $prefix = (string) config('inventory.items.sku_prefix');
        $pattern = '/^'.preg_quote($prefix, '/').'(\d+)$/';

        return (int) Item::query()
            ->where('sku', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%')
            ->pluck('sku')
            ->map(fn (string $sku) => preg_match($pattern, $sku, $match) ? (int) $match[1] : 0)
            ->max();
    }

    private function format(int $number): string
    {
        return config('inventory.items.sku_prefix').str_pad((string) $number, (int) config('inventory.items.sku_pad'), '0', STR_PAD_LEFT);
    }
}
