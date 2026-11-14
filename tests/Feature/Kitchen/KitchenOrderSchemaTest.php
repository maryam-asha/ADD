<?php

namespace Tests\Feature\Kitchen;

use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Models\KitchenOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The storage an order without a bill stands on: no bill, two waiting
 * statuses that are open but owe nothing, and the kitchen-arrival time.
 */
class KitchenOrderSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_may_have_no_bill(): void
    {
        $order = KitchenOrder::factory()->withoutBill()->create();
        $order->refresh();

        $this->assertNull($order->billable_type);
        $this->assertNull($order->billable_id);
        $this->assertFalse($order->hasBill());
        $this->assertNull($order->billType());
        $this->assertSame(0, KitchenOrder::query()->onABill()->count());
        $this->assertSame('kitchen', $order->route->value);
        $this->assertSame('bill', $order->payment_choice->value);
    }

    public function test_an_order_on_a_bill_has_one(): void
    {
        $order = KitchenOrder::factory()->create();

        $this->assertTrue($order->hasBill());
        $this->assertSame('walkin', $order->billType());
        $this->assertSame(1, KitchenOrder::query()->onABill()->count());
    }

    public function test_new_statuses_are_open_but_not_owed(): void
    {
        foreach ([KitchenOrderStatus::AwaitingPayment, KitchenOrderStatus::AwaitingReception] as $status) {
            $this->assertTrue($status->isOpen());
            $this->assertFalse($status->isOwed());
            $this->assertFalse($status->reachesKitchen());
        }

        $this->assertTrue(KitchenOrderStatus::Pending->reachesKitchen());
        $this->assertFalse(KitchenOrderStatus::Cancelled->isOpen());
        $this->assertSame(['pending', 'preparing', 'ready'], KitchenOrderStatus::kitchenBoardValues());
    }

    public function test_existing_orders_reached_the_kitchen_when_sent(): void
    {
        $order = KitchenOrder::factory()->create();
        $sent = now()->subHour()->startOfSecond();
        KitchenOrder::query()->whereKey($order->id)->update(['created_at' => $sent, 'reached_kitchen_at' => null]);

        $migration = require database_path('migrations/2026_11_14_100000_kitchen_orders_without_a_bill.php');
        $migration->down();
        $migration->up();

        $this->assertEquals($sent, $order->fresh()->reached_kitchen_at);
    }

    public function test_admin_queue_default_excludes_the_new_statuses(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = User::factory()->create();
        $staff->assignRole('admin');
        Sanctum::actingAs($staff, ['*']);

        $pending = KitchenOrder::factory()->create();
        $branchId = $pending->branch_id;
        KitchenOrder::factory()->withoutBill()->create(['branch_id' => $branchId, 'status' => KitchenOrderStatus::AwaitingPayment]);
        KitchenOrder::factory()->withoutBill()->create(['branch_id' => $branchId, 'status' => KitchenOrderStatus::AwaitingReception]);

        $this->getJson("/api/v1/admin/kitchen/orders?branch_id={$branchId}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);

        $this->getJson("/api/v1/admin/kitchen/orders?branch_id={$branchId}&status=awaiting_reception")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
