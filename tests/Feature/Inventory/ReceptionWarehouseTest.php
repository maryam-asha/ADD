<?php

namespace Tests\Feature\Inventory;

use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Finance\Services\CashRegisterService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Retail\Models\SaleItem;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reception takes stock from a warehouse: the primary one of the branch
 * of the register the operator has open, unless it names another — one
 * that is active, in that branch, and, for a sale, selling. A warehouse
 * short of stock refuses; another warehouse is never touched.
 */
class ReceptionWarehouseTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Branch $branch;

    private Warehouse $main;

    private SaleItem $cola;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->operator = User::factory()->create();
        $this->operator->assignRole('operations');
        Sanctum::actingAs($this->operator, ['*']);
        app(CashRegisterService::class)->openSession($this->operator, $this->branch->id);

        $this->main = $this->warehouseIn($this->branch, ['is_primary' => true, 'name' => ['ar' => 'الرئيسي', 'en' => 'Main']]);
        $this->cola = SaleItem::factory()->create(['price_points' => 20]);
        $this->stock($this->main, 10);
    }

    public function test_sale_without_warehouse_uses_the_registers_branch_primary(): void
    {
        $this->sell()->assertCreated()->assertJsonPath('data.warehouse.id', $this->main->id);

        $this->assertSame('9', $this->onHand($this->main));
    }

    public function test_sale_from_another_sellable_warehouse_of_the_branch(): void
    {
        $cafe = $this->warehouseIn($this->branch);
        $this->stock($cafe, 3);

        $this->sell(['warehouse_id' => $cafe->id])->assertCreated()->assertJsonPath('data.warehouse.id', $cafe->id);

        $this->assertSame('2', $this->onHand($cafe));
        $this->assertSame('10', $this->onHand($this->main));
    }

    public function test_sale_from_a_warehouse_with_sales_off_is_refused(): void
    {
        $store = $this->warehouseIn($this->branch, ['allows_sales' => false]);
        $this->stock($store, 3);

        $this->sell(['warehouse_id' => $store->id])
            ->assertUnprocessable()->assertJsonPath('message', __('api.reception.warehouse_not_for_sale', [], 'en'));
    }

    public function test_sale_from_another_branchs_warehouse_is_refused(): void
    {
        $other = $this->warehouseIn(Branch::factory()->create());
        $this->stock($other, 3);

        $this->sell(['warehouse_id' => $other->id])
            ->assertUnprocessable()->assertJsonPath('message', __('api.reception.warehouse_other_branch', [], 'en'));
    }

    public function test_an_inactive_warehouse_is_refused(): void
    {
        $closed = $this->warehouseIn($this->branch, ['is_active' => false]);

        $this->sell(['warehouse_id' => $closed->id])
            ->assertUnprocessable()->assertJsonPath('message', __('api.reception.warehouse_unavailable', [], 'en'));
    }

    public function test_no_register_and_no_warehouse_is_refused(): void
    {
        $other = User::factory()->create();
        $other->assignRole('operations');
        Sanctum::actingAs($other, ['*']);

        $this->sell()->assertUnprocessable()->assertJsonPath('message', __('api.reception.no_primary_warehouse', [], 'en'));
    }

    public function test_a_branch_without_a_primary_is_refused(): void
    {
        $this->main->update(['is_primary' => false]);

        $this->sell()->assertUnprocessable()->assertJsonPath('message', __('api.reception.no_primary_warehouse', [], 'en'));
    }

    public function test_consumption_without_register_from_a_named_warehouse_works(): void
    {
        $store = $this->warehouseIn($this->branch, ['allows_sales' => false]);
        $paper = Item::factory()->create(['category_id' => Category::factory()->stock()->create()->id]);
        $this->stockItem($paper, $store, 50);
        [$member, $printing] = $this->memberWithPrinting($paper);
        $other = User::factory()->create();
        $other->assignRole('operations');
        Sanctum::actingAs($other, ['*']);

        $this->postJson('/api/v1/admin/reception/resource-consumptions', [
            'user_id' => $member->id,
            'resource_type_id' => $printing->id,
            'quantity' => 5,
            'warehouse_id' => $store->id,
        ])->assertCreated();

        $this->assertSame('45', app(StockService::class)->onHand($paper, $store->asPlace()));
    }

    public function test_shortage_names_the_warehouse_and_takes_nothing_elsewhere(): void
    {
        $cafe = $this->warehouseIn($this->branch, ['name' => ['ar' => 'الكافيه', 'en' => 'Cafe']]);
        $this->stock($cafe, 1);

        $this->sell(['warehouse_id' => $cafe->id], 2)
            ->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_contains($message, 'Cafe'));

        $this->assertSame('1', $this->onHand($cafe));
        $this->assertSame('10', $this->onHand($this->main));
    }

    public function test_options_list_the_branchs_active_warehouses_primary_first(): void
    {
        $store = $this->warehouseIn($this->branch, ['allows_sales' => false]);
        $this->warehouseIn($this->branch, ['is_active' => false]);
        $this->warehouseIn(Branch::factory()->create());

        $this->getJson('/api/v1/admin/reception/warehouses')
            ->assertOk()
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonCount(2, 'data.warehouses')
            ->assertJsonPath('data.warehouses.0.id', $this->main->id)
            ->assertJsonPath('data.warehouses.0.is_primary', true)
            ->assertJsonPath('data.warehouses.1.id', $store->id)
            ->assertJsonPath('data.warehouses.1.allows_sales', false);
    }

    public function test_options_with_no_register_say_they_belong_to_no_branch(): void
    {
        $other = User::factory()->create();
        $other->assignRole('operations');
        Sanctum::actingAs($other, ['*']);
        $this->warehouseIn(Branch::factory()->create(), ['is_primary' => true]);

        // Every active warehouse, two of them primary: the desk must choose,
        // so nothing tells it which one is "its" primary.
        $this->getJson('/api/v1/admin/reception/warehouses')
            ->assertOk()
            ->assertJsonPath('data.branch_id', null)
            ->assertJsonCount(2, 'data.warehouses');
    }

    public function test_sale_items_carry_the_item_barcode(): void
    {
        $this->cola->item->update(['barcode' => '6291041500213']);

        $this->getJson('/api/v1/admin/sale-items')->assertOk()->assertJsonPath('data.0.item.barcode', '6291041500213');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function warehouseIn(Branch $branch, array $attributes = []): Warehouse
    {
        return Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $branch->id, ...$attributes]);
    }

    private function stock(Warehouse $warehouse, int $quantity): void
    {
        $this->stockItem($this->cola->item, $warehouse, $quantity);
    }

    private function stockItem(Item $item, Warehouse $warehouse, int $quantity): void
    {
        app(StockService::class)->receive($item, $warehouse->asPlace(), $quantity, 0.5);
    }

    private function onHand(Warehouse $warehouse): string
    {
        return app(StockService::class)->onHand($this->cola->item, $warehouse->asPlace());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function sell(array $extra = [], int $quantity = 1)
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        app(WalletService::class)->creditGeneral($wallet, 1000, WalletTransactionSource::TopUp, 'test');

        return $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/item-sales', [
            'user_id' => $member->id,
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => $quantity]],
            'payment_method' => 'wallet',
            ...$extra,
        ]);
    }

    /**
     * @return array{0: User, 1: ResourceType}
     */
    private function memberWithPrinting(Item $paper): array
    {
        $printing = ResourceType::create([
            'slug' => 'printing',
            'name' => ['en' => 'Printing', 'ar' => 'طباعة'],
            'unit_label' => ['en' => 'page', 'ar' => 'صفحة'],
            'inventory_item_id' => $paper->id,
            'stock_units_per_unit' => 1,
        ]);
        $plan = Plan::factory()->create();
        $plan->resourceGrants()->create(['resource_type_id' => $printing->id, 'quantity' => 100]);
        $member = User::factory()->create();
        Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
        ]);

        return [$member, $printing];
    }
}
