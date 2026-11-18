<?php

namespace Tests\Feature\Kitchen;

use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderSource;
use App\Domain\Kitchen\Exceptions\KitchenOrderException;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Kitchen\Services\KitchenOrderService;
use App\Domain\Retail\Models\SaleItem;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The kitchen's side of an order: accepting it draws every line from the
 * kitchen store in one transaction (or nothing at all), rejecting it needs
 * a reason and draws nothing, and an accepted order moves on to ready and
 * delivered. The queue lists what is still open, oldest first.
 */
class KitchenOrderActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $member;

    private Branch $branch;

    private Space $cospace;

    private Warehouse $kitchen;

    private SaleItem $cola;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $building = Building::factory()->create(['branch_id' => $this->branch->id]);
        $this->cospace = Space::factory()->create(['building_id' => $building->id]);

        Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id, 'is_primary' => true]);
        $this->kitchen = Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id]);
        KitchenStore::create(['branch_id' => $this->branch->id, 'warehouse_id' => $this->kitchen->id]);

        $this->cola = SaleItem::factory()->create(['price_points' => 20]);
        $this->cola->item->update(['name' => ['en' => 'Cola', 'ar' => 'كولا']]);
        app(StockService::class)->receive($this->cola->item, $this->kitchen->asPlace(), 10, 0.5);

        $this->member = User::factory()->create(['phone' => '+963900000001']);
        $this->member->assignRole('member');

        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
        Sanctum::actingAs($this->staff, ['*']);
    }

    /** A pending order of `$quantity` colas on a fresh open coworking session of the member. */
    private function order(int $quantity = 2, DeliveryType $delivery = DeliveryType::Pickup, Booking|WalkinSession|null $bill = null): KitchenOrder
    {
        $bill ??= WalkinSession::factory()->create(['user_id' => $this->member->id, 'space_id' => $this->cospace->id]);

        $order = KitchenOrder::query()->create([
            'user_id' => $this->member->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->kitchen->id,
            'billable_type' => $bill->getMorphClass(),
            'billable_id' => $bill->id,
            'delivery_type' => $delivery,
            'delivery_note' => $delivery === DeliveryType::Note ? 'By the window' : null,
            'source' => OrderSource::App,
            'status' => KitchenOrderStatus::Pending,
            'total_points' => 20 * $quantity,
        ]);

        $order->lines()->create([
            'sale_item_id' => $this->cola->id,
            'inventory_item_id' => $this->cola->inventory_item_id,
            'quantity' => $quantity,
            'unit_price_points' => 20,
            'line_points' => 20 * $quantity,
        ]);

        return $order;
    }

    /** @param array<string, mixed> $body */
    private function act(KitchenOrder $order, string $action, array $body = []): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/admin/kitchen/orders/{$order->id}/{$action}", $body);
    }

    private function onHand(): string
    {
        return app(StockService::class)->onHand($this->cola->item, $this->kitchen->asPlace());
    }

    /** @return Collection<int, StockMovement> */
    private function issues()
    {
        return StockMovement::query()->where('type', 'issue')->get();
    }

    public function test_accept_draws_stock_and_starts_preparing(): void
    {
        $order = $this->order(2);

        $this->act($order, 'accept')
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'preparing')
            ->assertJsonPath('data.member.id', $this->member->id)
            ->assertJsonPath('data.member.phone', '+963900000001');

        $this->assertSame(0, bccomp('8', $this->onHand(), 4));

        $issue = $this->issues()->sole();
        $this->assertSame(['kitchen_order', $order->id, 'sale'], [$issue->source_type, $issue->source_id, $issue->reason->value]);
        $this->assertSame(KitchenOrder::SOURCE, 'kitchen_order');

        $order->refresh();
        $this->assertSame(KitchenOrderStatus::Preparing, $order->status);
        $this->assertSame($this->staff->id, (int) $order->accepted_by);
        $this->assertNotNull($order->accepted_at);
    }

    /** Review Focus 1: two kitchen screens accept the same order — one wins, stock is drawn once. */
    public function test_accepting_twice_draws_stock_once(): void
    {
        $order = $this->order(2);
        // The second screen still holds the order as it saw it: pending.
        $stale = KitchenOrder::query()->findOrFail($order->id);

        $this->act($order, 'accept')->assertOk();

        $this->act($order, 'accept')
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.kitchen.order_not_pending', [], 'en'));

        try {
            app(KitchenOrderService::class)->accept($stale, $this->staff);
            $this->fail('A stale pending copy of an accepted order was accepted again.');
        } catch (KitchenOrderException $e) {
            $this->assertSame(['api.kitchen.order_not_pending', 409], [$e->messageKey, $e->status]);
        }

        $this->assertSame(0, bccomp('8', $this->onHand(), 4));
        $this->assertCount(1, $this->issues());
    }

    /** Review Focus 2: two pending orders for the last of the shelf — the second accept changes nothing. */
    public function test_accept_with_stock_short_changes_nothing(): void
    {
        $first = $this->order(8);
        $second = $this->order(3);

        $this->act($first, 'accept')->assertOk();

        $this->act($second, 'accept')
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.kitchen.out_of_stock', ['item' => 'Cola'], 'en'));

        $this->assertSame(0, bccomp('2', $this->onHand(), 4));
        $this->assertCount(1, $this->issues());

        $second->refresh();
        $this->assertSame(KitchenOrderStatus::Pending, $second->status);
        $this->assertNull($second->accepted_at);
        $this->assertNull($second->accepted_by);

        // Still pending, so the kitchen can reject it with a reason.
        $this->act($second, 'reject', ['reason' => 'Out of cola'])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_accept_with_two_lines_rolls_back_the_first_when_the_second_is_short(): void
    {
        $order = $this->order(4);
        $order->lines()->create([
            'sale_item_id' => $this->cola->id,
            'inventory_item_id' => $this->cola->inventory_item_id,
            'quantity' => 7,
            'unit_price_points' => 20,
            'line_points' => 140,
        ]);

        $this->act($order, 'accept')->assertUnprocessable();

        $this->assertSame(0, bccomp('10', $this->onHand(), 4));
        $this->assertCount(0, $this->issues());
        $this->assertSame(KitchenOrderStatus::Pending, $order->refresh()->status);
    }

    public function test_accept_refuses_a_closed_bill(): void
    {
        $bill = WalkinSession::factory()->create(['user_id' => $this->member->id, 'space_id' => $this->cospace->id]);
        $order = $this->order(2, bill: $bill);
        $bill->update(['checked_out_at' => now()]);

        $this->act($order, 'accept')
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.kitchen.bill_closed', [], 'en'));

        $this->assertSame(0, bccomp('10', $this->onHand(), 4));
        $this->assertSame(KitchenOrderStatus::Pending, $order->refresh()->status);
    }

    public function test_accept_works_on_a_checked_in_booking_bill(): void
    {
        $bill = Booking::factory()->create([
            'user_id' => $this->member->id,
            'space_id' => $this->cospace->id,
            'checked_in_at' => now()->subHour(),
            'checked_out_at' => null,
        ]);
        $order = $this->order(2, bill: $bill);

        $this->act($order, 'accept')->assertOk()->assertJsonPath('data.status', 'preparing');

        $this->assertSame(0, bccomp('8', $this->onHand(), 4));
    }

    public function test_accept_refuses_a_checked_out_booking_bill(): void
    {
        $bill = Booking::factory()->create([
            'user_id' => $this->member->id,
            'space_id' => $this->cospace->id,
            'checked_in_at' => now()->subHours(2),
            'checked_out_at' => now()->subHour(),
        ]);
        $order = $this->order(2, bill: $bill);

        $this->act($order, 'accept')
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.kitchen.bill_closed', [], 'en'));

        $this->assertSame(0, bccomp('10', $this->onHand(), 4));
        $this->assertSame(KitchenOrderStatus::Pending, $order->refresh()->status);
    }

    public function test_reject_needs_a_reason_and_draws_nothing(): void
    {
        $order = $this->order(2);

        $this->act($order, 'reject')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->act($order, 'reject', ['reason' => '   '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->act($order, 'reject', ['reason' => str_repeat('x', 301)])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame(KitchenOrderStatus::Pending, $order->refresh()->status);

        $this->act($order, 'reject', ['reason' => 'The grill is down'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'The grill is down');

        $order->refresh();
        $this->assertSame($this->staff->id, (int) $order->rejected_by);
        $this->assertNotNull($order->rejected_at);
        $this->assertSame(0, bccomp('10', $this->onHand(), 4));
        $this->assertCount(0, $this->issues());

        // Rejected is final: it cannot be accepted, nor rejected again.
        $this->act($order, 'accept')->assertStatus(409);
        $this->act($order, 'reject', ['reason' => 'Again'])->assertStatus(409);
    }

    public function test_ready_then_delivered(): void
    {
        $order = $this->order(2, DeliveryType::Note);

        // Not accepted yet: neither ready nor delivered.
        $this->act($order, 'ready')->assertStatus(409)->assertJsonPath('message', __('api.kitchen.order_not_preparing', [], 'en'));
        $this->act($order, 'deliver')->assertStatus(409)->assertJsonPath('message', __('api.kitchen.order_not_deliverable', [], 'en'));

        $this->act($order, 'accept')->assertOk();
        $this->act($order, 'ready')->assertOk()->assertJsonPath('data.status', 'ready');
        $this->assertNotNull($order->refresh()->ready_at);

        // Ready only once.
        $this->act($order, 'ready')->assertStatus(409);

        $this->act($order, 'deliver')->assertOk()->assertJsonPath('data.status', 'delivered');

        $order->refresh();
        $this->assertSame(KitchenOrderStatus::Delivered, $order->status);
        $this->assertSame($this->staff->id, (int) $order->delivered_by);
        $this->assertNotNull($order->delivered_at);

        // Delivered is final.
        $this->act($order, 'deliver')->assertStatus(409);
        // Delivering draws nothing more.
        $this->assertCount(1, $this->issues());
    }

    public function test_pickup_order_can_go_straight_to_delivered(): void
    {
        $order = $this->order(1, DeliveryType::Pickup);

        $this->act($order, 'accept')->assertOk();
        $this->act($order, 'deliver')->assertOk()->assertJsonPath('data.status', 'delivered');

        $order->refresh();
        $this->assertNull($order->ready_at);
        $this->assertNotNull($order->delivered_at);
    }

    public function test_an_order_of_a_deleted_member_still_lists(): void
    {
        $order = $this->order(1);
        $this->member->delete();

        $this->getJson('/api/v1/admin/kitchen/orders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.member.id', $this->member->id)
            ->assertJsonPath('data.0.member.phone', '+963900000001');
    }

    public function test_queue_lists_open_orders_oldest_first(): void
    {
        $this->travelTo(now()->subMinutes(30));
        $oldest = $this->order(1);
        $this->travelBack();

        $this->travelTo(now()->subMinutes(5));
        $newest = $this->order(1);
        $this->travelBack();

        $this->travelTo(now()->subMinutes(20));
        $middle = $this->order(1);
        $this->travelBack();

        $preparing = $middle;
        $this->act($preparing, 'accept')->assertOk();

        $rejected = $this->order(1);
        $this->act($rejected, 'reject', ['reason' => 'No'])->assertOk();

        $otherBranch = KitchenOrder::factory()->create();

        $response = $this->getJson('/api/v1/admin/kitchen/orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

        $this->assertSame(
            [$oldest->id, $middle->id, $newest->id, $otherBranch->id],
            array_column($response->json('data'), 'id'),
        );

        $first = $response->json('data.0');
        $this->assertSame(['id' => $this->member->id, 'name' => $this->member->name, 'phone' => '+963900000001'], $first['member']);
        $this->assertGreaterThanOrEqual(30 * 60, $first['waited_seconds']);
        $this->assertSame('preparing', $response->json('data.1.status'));

        // The branch filter, and history by status.
        $this->assertSame(
            [$oldest->id, $middle->id, $newest->id],
            array_column($this->getJson("/api/v1/admin/kitchen/orders?branch_id={$this->branch->id}")->assertOk()->json('data'), 'id'),
        );
        $this->assertSame(
            [$rejected->id],
            array_column($this->getJson('/api/v1/admin/kitchen/orders?status=rejected,delivered')->assertOk()->json('data'), 'id'),
        );
        $this->assertSame(
            [$otherBranch->id],
            array_column($this->getJson("/api/v1/admin/kitchen/orders?user_id={$otherBranch->user_id}")->assertOk()->json('data'), 'id'),
        );
        $this->getJson('/api/v1/admin/kitchen/orders?status=pending,eaten')->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->getJson("/api/v1/admin/kitchen/orders/{$oldest->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $oldest->id)
            ->assertJsonPath('data.member.id', $this->member->id);
    }

    public function test_history_can_list_newest_first(): void
    {
        $this->travelTo(now()->subMinutes(30));
        $oldest = $this->order(1);
        $this->travelBack();

        $this->travelTo(now()->subMinutes(5));
        $newest = $this->order(1);
        $this->travelBack();

        $this->travelTo(now()->subMinutes(20));
        $middle = $this->order(1);
        $this->travelBack();

        $ids = fn (string $query) => array_column($this->getJson("/api/v1/admin/kitchen/orders?{$query}")->assertOk()->json('data'), 'id');

        $this->assertSame([$newest->id, $middle->id, $oldest->id], $ids('sort=newest'));
        $this->assertSame([$oldest->id, $middle->id, $newest->id], $ids('sort=oldest'));
        $this->assertSame([$oldest->id, $middle->id, $newest->id], $ids(''));
        $this->getJson('/api/v1/admin/kitchen/orders?sort=sideways')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_actions_need_kitchen_orders_manage(): void
    {
        Role::create(['name' => 'kitchen-viewer', 'guard_name' => 'web'])->givePermissionTo('kitchen-orders.view');
        $viewer = User::factory()->create();
        $viewer->assignRole('kitchen-viewer');
        Sanctum::actingAs($viewer, ['*']);

        $order = $this->order(2);

        $this->getJson('/api/v1/admin/kitchen/orders')->assertOk();
        $this->getJson("/api/v1/admin/kitchen/orders/{$order->id}")->assertOk();

        $this->act($order, 'accept')->assertForbidden();
        $this->act($order, 'reject', ['reason' => 'No'])->assertForbidden();
        $this->act($order, 'ready')->assertForbidden();
        $this->act($order, 'deliver')->assertForbidden();

        $this->assertSame(KitchenOrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(0, bccomp('10', $this->onHand(), 4));

        // Without view, not even the queue.
        $nobody = User::factory()->create();
        $nobody->assignRole('member');
        Sanctum::actingAs($nobody, ['*']);
        $this->getJson('/api/v1/admin/kitchen/orders')->assertForbidden();
    }
}
