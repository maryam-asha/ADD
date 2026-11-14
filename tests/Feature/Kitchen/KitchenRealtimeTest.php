<?php

namespace Tests\Feature\Kitchen;

use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderSource;
use App\Domain\Kitchen\Events\KitchenOrderChanged;
use App\Domain\Kitchen\Events\MemberKitchenOrderUpdated;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Kitchen\Services\KitchenOrderService;
use App\Domain\Retail\Models\SaleItem;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\NotificationText;
use App\Services\Notification\PushProvider;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\TestCase;

/**
 * The kitchen board and the member's app hear about every order change:
 * a nudge (ids and a status) on private-kitchen and private-member.{id},
 * and the push for the change the member cares about — never for one that
 * rolled back.
 */
class KitchenRealtimeTest extends TestCase
{
    use ReadsNotices, RefreshDatabase;

    private const EVENTS = [KitchenOrderChanged::class, MemberKitchenOrderUpdated::class];

    private User $staff;

    private User $member;

    private Branch $branch;

    private Space $cospace;

    private Warehouse $kitchen;

    private SaleItem $cola;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);

        $this->branch = Branch::factory()->create();
        $building = Building::factory()->create(['branch_id' => $this->branch->id]);
        $this->cospace = Space::factory()->create(['building_id' => $building->id]);

        Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id, 'is_primary' => true]);
        $this->kitchen = Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id]);
        KitchenStore::create(['branch_id' => $this->branch->id, 'warehouse_id' => $this->kitchen->id]);

        $this->cola = SaleItem::factory()->create(['price_points' => 20]);
        app(StockService::class)->receive($this->cola->item, $this->kitchen->asPlace(), 10, 0.5);

        $this->member = User::factory()->create();
        $this->member->assignRole('member');

        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
    }

    private function onChannel(object $event, string $channel): bool
    {
        return array_map(fn (PrivateChannel $c) => $c->name, $event->broadcastOn()) === [$channel];
    }

    private function openSession(): WalkinSession
    {
        return WalkinSession::factory()->create([
            'user_id' => $this->member->id,
            'space_id' => $this->cospace->id,
            'checked_in_at' => now()->subHour(),
        ]);
    }

    private function order(DeliveryType $delivery = DeliveryType::Pickup, KitchenOrderStatus $status = KitchenOrderStatus::Pending, ?WalkinSession $bill = null): KitchenOrder
    {
        $bill ??= $this->openSession();
        $order = KitchenOrder::query()->create([
            'user_id' => $this->member->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->kitchen->id,
            'billable_type' => $bill->getMorphClass(),
            'billable_id' => $bill->id,
            'delivery_type' => $delivery,
            'delivery_note' => $delivery === DeliveryType::Note ? 'By the window' : null,
            'source' => OrderSource::App,
            'status' => $status,
            'total_points' => 20,
        ]);
        $order->lines()->create([
            'sale_item_id' => $this->cola->id,
            'inventory_item_id' => $this->cola->inventory_item_id,
            'quantity' => 1,
            'unit_price_points' => 20,
            'line_points' => 20,
        ]);

        return $order;
    }

    /** @param array<string, mixed> $body */
    private function act(KitchenOrder $order, string $action, array $body = []): TestResponse
    {
        Sanctum::actingAs($this->staff, ['dashboard']);

        return $this->postJson("/api/v1/admin/kitchen/orders/{$order->id}/{$action}", $body);
    }

    /** @return array{type: string, id: int} */
    private function billRef(WalkinSession $session): array
    {
        return ['type' => $session->getMorphClass(), 'id' => $session->id];
    }

    public function test_placing_broadcasts_to_the_kitchen(): void
    {
        $session = $this->openSession();
        Event::fake(self::EVENTS);

        $order = app(KitchenOrderService::class)->place(
            $this->member,
            [['sale_item_id' => $this->cola->id, 'quantity' => 1]],
            null,
            ['type' => 'pickup'],
            $this->billRef($session),
            ['method' => 'bill'],
        )->order;

        Event::assertDispatchedTimes(KitchenOrderChanged::class, 1);
        Event::assertDispatched(KitchenOrderChanged::class, fn (KitchenOrderChanged $e) => $this->onChannel($e, 'private-kitchen')
            && $e->broadcastAs() === 'kitchen_order.created'
            && $e->broadcastWith() === ['order_id' => $order->id, 'branch_id' => $this->branch->id, 'status' => 'pending']);
        Event::assertNotDispatched(MemberKitchenOrderUpdated::class);
    }

    public function test_a_rolled_back_placing_announces_nothing(): void
    {
        $session = $this->openSession();
        Event::fake(self::EVENTS);

        try {
            DB::transaction(function () use ($session) {
                app(KitchenOrderService::class)->place(
                    $this->member,
                    [['sale_item_id' => $this->cola->id, 'quantity' => 1]],
                    null,
                    ['type' => 'pickup'],
                    $this->billRef($session),
                    ['method' => 'bill'],
                );

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        Event::assertNotDispatched(KitchenOrderChanged::class);
    }

    public function test_status_change_reaches_the_member(): void
    {
        $order = $this->order();
        Event::fake(self::EVENTS);

        $this->act($order, 'accept')->assertOk();

        Event::assertDispatched(MemberKitchenOrderUpdated::class, fn (MemberKitchenOrderUpdated $e) => $this->onChannel($e, "private-member.{$this->member->id}")
            && $e->broadcastAs() === 'kitchen_order.updated'
            && $e->broadcastWith() === ['order_id' => $order->id, 'status' => 'preparing']);
        Event::assertDispatched(KitchenOrderChanged::class, fn (KitchenOrderChanged $e) => $this->onChannel($e, 'private-kitchen')
            && $e->broadcastAs() === 'kitchen_order.updated'
            && $e->broadcastWith() === ['order_id' => $order->id, 'branch_id' => $this->branch->id, 'status' => 'preparing']);
        $this->assertNoticed($this->member, 'kitchen_order.accepted');
    }

    public function test_ready_pushes_only_for_pickup(): void
    {
        $pickup = $this->order(DeliveryType::Pickup, KitchenOrderStatus::Preparing);
        $brought = $this->order(DeliveryType::Note, KitchenOrderStatus::Preparing);

        $this->act($brought, 'ready')->assertOk();
        $this->assertNotNoticed($this->member, 'kitchen_order.ready');

        $this->act($pickup, 'ready')->assertOk();
        $this->assertNoticed($this->member, 'kitchen_order.ready');

        $this->act($pickup, 'deliver')->assertOk();
        $this->assertNoticed($this->member, 'kitchen_order.delivered');
    }

    public function test_rejection_push_carries_the_reason(): void
    {
        $order = $this->order();

        $this->act($order, 'reject', ['reason' => 'Out of ice'])->assertOk();

        $notice = $this->assertNoticed($this->member, 'kitchen_order.rejected');
        $this->assertStringContainsString('Out of ice', $this->noticeText($notice, 'en')['body']);
        $this->assertStringContainsString('Out of ice', $this->noticeText($notice, 'ar')['body']);
    }

    public function test_a_refused_action_announces_and_pushes_nothing(): void
    {
        $order = $this->order(status: KitchenOrderStatus::Delivered);
        Event::fake(self::EVENTS);

        $this->act($order, 'accept')->assertStatus(409);

        Event::assertNothingDispatched();
        $this->assertNotNoticed($this->member, 'kitchen_order.accepted');
    }

    public function test_the_member_cancelling_tells_the_kitchen_only(): void
    {
        $order = $this->order();
        Event::fake(self::EVENTS);

        Sanctum::actingAs($this->member, ['member-app']);
        $this->postJson("/api/v1/member/kitchen-orders/{$order->id}/cancel")->assertOk();

        Event::assertDispatched(KitchenOrderChanged::class, fn (KitchenOrderChanged $e) => $e->broadcastWith()['status'] === 'cancelled');
        Event::assertNotDispatched(MemberKitchenOrderUpdated::class);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_system_cancel_tells_the_member_and_the_kitchen(): void
    {
        $session = $this->openSession();
        $order = $this->order(bill: $session);
        $this->order(DeliveryType::Pickup, KitchenOrderStatus::Preparing, $session);
        Event::fake(self::EVENTS);

        $cancelled = DB::transaction(fn () => app(KitchenOrderService::class)->cancelPendingFor($session));

        $this->assertSame([$order->id], $cancelled->pluck('id')->all());
        Event::assertDispatchedTimes(MemberKitchenOrderUpdated::class, 1);
        Event::assertDispatched(MemberKitchenOrderUpdated::class, fn (MemberKitchenOrderUpdated $e) => $e->broadcastWith() === ['order_id' => $order->id, 'status' => 'cancelled']);
        Event::assertDispatched(KitchenOrderChanged::class, fn (KitchenOrderChanged $e) => $e->broadcastWith() === ['order_id' => $order->id, 'branch_id' => $this->branch->id, 'status' => 'cancelled']);
        $this->assertNoticed($this->member, 'kitchen_order.cancelled_by_system');
    }

    public function test_every_template_exists_in_both_languages(): void
    {
        foreach (['accepted', 'ready', 'rejected', 'delivered', 'cancelled_by_system'] as $name) {
            $this->assertTrue(NotificationText::exists("kitchen_order.{$name}"), $name);
        }
    }

    public function test_kitchen_channel_needs_kitchen_orders_view(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => '1',
            'broadcasting.connections.pusher.options.cluster' => 'eu',
        ]);
        require base_path('routes/channels.php');

        $auth = fn () => $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-kitchen']);

        Sanctum::actingAs($this->staff, ['dashboard']);
        $auth()->assertOk()->assertJsonStructure(['auth']);

        Role::create(['name' => 'cook', 'guard_name' => 'web'])->givePermissionTo('kitchen-orders.view');
        $cook = User::factory()->create();
        $cook->assignRole('cook');
        Sanctum::actingAs($cook, ['dashboard']);
        $auth()->assertOk();

        // The member app's token of someone who also works the kitchen is out.
        Sanctum::actingAs($cook, ['member-app']);
        $auth()->assertForbidden();

        Sanctum::actingAs($this->member, ['member-app']);
        $auth()->assertForbidden();
    }
}
