<?php

namespace Tests\Feature\Kitchen;

use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Models\KitchenStore;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A branch names one warehouse as its kitchen store; the rules keep it a
 * selling warehouse of that branch that is not the main store.
 */
class KitchenStoreTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->branch = Branch::factory()->create();
    }

    private function warehouse(Branch $branch, array $attributes = []): Warehouse
    {
        return Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $branch->id] + $attributes);
    }

    public function test_sets_the_branch_kitchen_store(): void
    {
        $kitchen = $this->warehouse($this->branch);

        $this->putJson("/api/v1/admin/kitchen/stores/{$this->branch->id}", ['warehouse_id' => $kitchen->id])
            ->assertOk()
            ->assertJsonPath('data.branch.id', $this->branch->id)
            ->assertJsonPath('data.warehouse.id', $kitchen->id);

        $store = KitchenStore::forBranch($this->branch->id);
        $this->assertSame($kitchen->id, $store->warehouse_id);
        $this->assertSame($kitchen->id, $store->place()->id);

        $row = collect($this->getJson('/api/v1/admin/kitchen/stores')->assertOk()->json('data'))
            ->firstWhere('branch.id', $this->branch->id);
        $this->assertSame($kitchen->id, $row['warehouse']['id']);
    }

    public function test_a_branch_without_a_store_lists_a_null_warehouse(): void
    {
        $row = collect($this->getJson('/api/v1/admin/kitchen/stores')->assertOk()->json('data'))
            ->firstWhere('branch.id', $this->branch->id);

        $this->assertNull($row['warehouse']);
    }

    public function test_refuses_the_primary_store(): void
    {
        $main = $this->warehouse($this->branch, ['is_primary' => true]);

        $this->putJson("/api/v1/admin/kitchen/stores/{$this->branch->id}", ['warehouse_id' => $main->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.warehouse_id.0', __('api.kitchen.store_is_primary'));
    }

    public function test_refuses_a_non_selling_store(): void
    {
        $store = $this->warehouse($this->branch, ['allows_sales' => false]);

        $this->putJson("/api/v1/admin/kitchen/stores/{$this->branch->id}", ['warehouse_id' => $store->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.warehouse_id.0', __('api.kitchen.store_not_sellable'));
    }

    public function test_refuses_another_branchs_store(): void
    {
        $other = $this->warehouse(Branch::factory()->create());

        $this->putJson("/api/v1/admin/kitchen/stores/{$this->branch->id}", ['warehouse_id' => $other->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.warehouse_id.0', __('api.kitchen.store_not_in_branch'));
    }

    public function test_replacing_keeps_one_row_per_branch(): void
    {
        $first = $this->warehouse($this->branch);
        $second = $this->warehouse($this->branch);

        $this->putJson("/api/v1/admin/kitchen/stores/{$this->branch->id}", ['warehouse_id' => $first->id])->assertOk();
        $this->putJson("/api/v1/admin/kitchen/stores/{$this->branch->id}", ['warehouse_id' => $second->id])->assertOk();

        $this->assertSame(1, KitchenStore::query()->where('branch_id', $this->branch->id)->count());
        $this->assertSame($second->id, KitchenStore::forBranch($this->branch->id)->warehouse_id);
    }
}
