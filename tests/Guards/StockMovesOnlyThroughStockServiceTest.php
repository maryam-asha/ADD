<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * A stock quantity changes only through Add\Inventory\Stock\Services\StockService:
 * it writes the ledger row and moves the balance in one transaction under a
 * row lock, which is what keeps every balance equal to its ledger and stock
 * from going below zero. Anything else writing either table — a sale, a
 * consumption, a seeder taking a shortcut — would let them drift apart.
 */
class StockMovesOnlyThroughStockServiceTest extends TestCase
{
    use ScansSourceFiles;

    private const SERVICE = 'packages/add/inventory/src/Stock/Services/StockService.php';

    /**
     * The one-time move of stock into warehouses (2026-11-05): it re-points
     * rows to their new warehouse and writes no quantity, so balance and
     * ledger stay equal. Nothing else is exempt.
     */
    private const WAREHOUSE_MOVE = 'database/migrations/2026_11_05_200000_move_stock_into_warehouses.php';

    public function test_nothing_but_the_stock_service_writes_stock_rows(): void
    {
        $writes = '/(StockMovement|StockBalance)::(query\(\)->)?(create|insert|insertOrIgnore|upsert|update|forceCreate|firstOrCreate|updateOrCreate)\b'
            .'|new\s+(StockMovement|StockBalance)\b'
            .'|DB::table\([\'"]inv_stock_(movements|balances)[\'"]\)/';

        $violations = [];

        foreach ([app_path(), database_path(), base_path('packages/add/inventory/src'), base_path('packages/add/inventory/database')] as $dir) {
            foreach ($this->phpFilesIn($dir) as $path => $source) {
                if (! in_array($path, [self::SERVICE, self::WAREHOUSE_MOVE], true) && preg_match($writes, $source)) {
                    $violations[] = $path;
                }
            }
        }

        $this->assertSame([], $violations, 'Change stock only through StockService (receive, issue, transfer, adjust).');
    }
}
