<?php

namespace Tests\Feature\Retail;

use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Finance\Services\CashRegisterService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\ResourceConsumption;
use App\Domain\Membership\Models\ResourceType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A plan's printing pages use up paper: recording the consumption at
 * reception issues the sheets from the desk's branch, in the same
 * transaction — and a shelf that cannot cover it refuses the consumption.
 */
class ConsumptionUsesStockTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Item $paper;

    private Warehouse $store;

    private ResourceType $printing;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
        app(CashRegisterService::class)->openSession($operator, $this->branch->id);

        $this->paper = Item::factory()->create(['category_id' => Category::factory()->stock()->create()->id]);
        $this->store = Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id, 'is_primary' => true]);
        app(StockService::class)->receive($this->paper, $this->store->asPlace(), 50);

        $this->printing = ResourceType::create([
            'slug' => 'printing',
            'name' => ['en' => 'Printing', 'ar' => 'طباعة'],
            'unit_label' => ['en' => 'page', 'ar' => 'صفحة'],
        ]);

        $plan = Plan::factory()->create();
        $plan->resourceGrants()->create(['resource_type_id' => $this->printing->id, 'quantity' => 100]);
        $this->member = User::factory()->create();
        Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $this->member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
        ]);
    }

    private function record(int $pages)
    {
        return $this->postJson('/api/v1/admin/reception/resource-consumptions', [
            'user_id' => $this->member->id,
            'resource_type_id' => $this->printing->id,
            'quantity' => $pages,
        ]);
    }

    private function onHand(): string
    {
        return app(StockService::class)->onHand($this->paper, $this->store->asPlace());
    }

    public function test_a_type_without_stock_uses_none(): void
    {
        $this->record(12)->assertCreated();

        $this->assertSame('50', $this->onHand());
    }

    public function test_a_linked_type_issues_its_stock_from_the_desks_primary_warehouse(): void
    {
        $this->putJson("/api/v1/admin/resource-types/{$this->printing->id}", [
            'name' => ['en' => 'Printing', 'ar' => 'طباعة'],
            'unit_label' => ['en' => 'page', 'ar' => 'صفحة'],
            'inventory_item_id' => $this->paper->id,
            'stock_units_per_unit' => 2,
        ])->assertOk();

        $this->record(12)->assertCreated()->assertJsonPath('data.remaining', 88);

        $this->assertSame('26', $this->onHand());
        $issue = StockMovement::query()->where('type', 'issue')->sole();
        $this->assertSame(['resource_consumption', ResourceConsumption::sole()->id, 'consumption'], [$issue->source_type, $issue->source_id, $issue->reason->value]);
    }

    public function test_a_shelf_short_refuses_the_consumption(): void
    {
        $this->printing->update(['inventory_item_id' => $this->paper->id, 'stock_units_per_unit' => 1]);

        $this->record(60)->assertUnprocessable();

        $this->assertSame(0, ResourceConsumption::query()->count());
        $this->assertSame('50', $this->onHand());
    }

    public function test_only_stock_can_be_linked(): void
    {
        $chair = Item::factory()->create(['category_id' => Category::factory()->create()->id]);

        $this->putJson("/api/v1/admin/resource-types/{$this->printing->id}", [
            'name' => ['en' => 'Printing', 'ar' => 'طباعة'],
            'unit_label' => ['en' => 'page', 'ar' => 'صفحة'],
            'inventory_item_id' => $chair->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('inventory_item_id');
    }
}
