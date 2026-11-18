<?php

namespace Tests\Feature\Kitchen;

use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Models\StockMinimum;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Identity\Models\User;
use App\Notifications\GeneralNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A store whose stock falls below its minimum tells its manager once; a
 * store with no manager tells whoever may manage warehouses.
 */
class LowStockNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Item $flour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Notification::fake();

        $this->flour = Item::factory()->create(['category_id' => Category::factory()->stock()->create()->id]);
    }

    private function dip(Warehouse $store): void
    {
        StockMinimum::query()->create(['warehouse_id' => $store->id, 'item_id' => $this->flour->id, 'minimum' => 5]);
        $stock = app(StockService::class);
        $stock->receive($this->flour, $store->asPlace(), 6);
        $stock->issue($this->flour, $store->asPlace(), 3, IssueReason::Consumption);
    }

    public function test_manager_gets_the_low_stock_notice(): void
    {
        $manager = User::factory()->create();
        $other = User::factory()->create();
        $store = Warehouse::factory()->create(['manager_id' => $manager->id]);

        $this->dip($store);

        Notification::assertSentToTimes($manager, GeneralNotification::class, 1);
        Notification::assertSentTo($manager, GeneralNotification::class, function (GeneralNotification $n) use ($manager) {
            $data = $n->toArray($manager);

            return $data['template_key'] === 'inventory.low_stock'
                && $data['params']['on_hand'] === '3'
                && $data['params']['minimum'] === '5';
        });
        Notification::assertNotSentTo($other, GeneralNotification::class);
    }

    public function test_no_manager_tells_the_holders_of_the_manage_ability(): void
    {
        $holder = User::factory()->create();
        $holder->assignRole('admin');
        $bystander = User::factory()->create();
        $store = Warehouse::factory()->create(['manager_id' => null]);

        $this->dip($store);

        Notification::assertSentTo($holder, GeneralNotification::class, fn (GeneralNotification $n) => $n->toArray($holder)['template_key'] === 'inventory.low_stock');
        Notification::assertNotSentTo($bystander, GeneralNotification::class);
    }
}
