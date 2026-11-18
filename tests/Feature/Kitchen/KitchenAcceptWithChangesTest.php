<?php

namespace Tests\Feature\Kitchen;

use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderSource;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Retail\Models\SaleItem;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\NotificationText;
use App\Services\Notification\PushProvider;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\TestCase;

/**
 * The café accepts an order with fewer of an item, or without it, when it is
 * short: the change applies at once, the order's total drops to what is
 * made, what was already paid beyond that goes back as points, and the
 * member is told.
 */
class KitchenAcceptWithChangesTest extends TestCase
{
    use ReadsNotices, RefreshDatabase;

    private User $staff;

    private User $member;

    private Branch $branch;

    private Space $cospace;

    private Warehouse $kitchen;

    private SaleItem $cola;

    private SaleItem $cake;

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
        $this->cola->item->update(['name' => ['en' => 'Cola', 'ar' => 'كولا']]);
        $this->cake = SaleItem::factory()->create(['price_points' => 35]);
        $this->cake->item->update(['name' => ['en' => 'Cake', 'ar' => 'كيك']]);
        app(StockService::class)->receive($this->cola->item, $this->kitchen->asPlace(), 10, 0.5);
        app(StockService::class)->receive($this->cake->item, $this->kitchen->asPlace(), 10, 1);

        $this->member = User::factory()->create();
        $this->member->assignRole('member');

        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
    }

    private function openSession(): WalkinSession
    {
        return WalkinSession::factory()->create([
            'user_id' => $this->member->id,
            'space_id' => $this->cospace->id,
            'checked_in_at' => now()->subHour(),
        ]);
    }

    /** A pending order on a fresh session: 3 cola (60) and 1 cake (35) — 95 points. */
    private function order(): KitchenOrder
    {
        $bill = $this->openSession();
        $order = KitchenOrder::query()->create([
            'user_id' => $this->member->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->kitchen->id,
            'billable_type' => $bill->getMorphClass(),
            'billable_id' => $bill->id,
            'delivery_type' => DeliveryType::Pickup,
            'source' => OrderSource::App,
            'status' => KitchenOrderStatus::Pending,
            'total_points' => 95,
            'reached_kitchen_at' => now(),
        ]);

        foreach ([[$this->cola, 3], [$this->cake, 1]] as [$item, $quantity]) {
            $order->lines()->create([
                'sale_item_id' => $item->id,
                'inventory_item_id' => $item->inventory_item_id,
                'quantity' => $quantity,
                'ordered_quantity' => $quantity,
                'unit_price_points' => $item->price_points,
                'line_points' => $item->price_points * $quantity,
            ]);
        }

        return $order;
    }

    private function walletWith(int $points): Wallet
    {
        $wallet = Wallet::query()->firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);

        return $wallet;
    }

    private function paidFromWallet(KitchenOrder $order): void
    {
        app(PaymentService::class)->start(PaymentPurpose::KitchenOrder, $this->member, 'wallet', ['payable' => $order]);
        $this->assertNotNull($order->refresh()->paid_at);
    }

    /** @param array<string, mixed> $body */
    private function accept(KitchenOrder $order, array $body = []): TestResponse
    {
        Sanctum::actingAs($this->staff, ['dashboard']);

        return $this->withHeader('lang', 'en')->postJson("/api/v1/admin/kitchen/orders/{$order->id}/accept", $body);
    }

    private function lineOf(KitchenOrder $order, SaleItem $item): int
    {
        return $order->lines()->where('sale_item_id', $item->id)->value('id');
    }

    private function onHand(SaleItem $item): string
    {
        return (string) app(StockService::class)->onHand($item->item->refresh(), $this->kitchen->asPlace());
    }

    public function test_accepting_with_no_body_makes_the_order_as_sent(): void
    {
        $order = $this->order();

        $this->accept($order)
            ->assertOk()
            ->assertJsonPath('data.status', 'preparing')
            ->assertJsonPath('data.adjusted', false)
            ->assertJsonPath('data.total_points', 95)
            ->assertJsonPath('data.ordered_total_points', 95)
            ->assertJsonPath('data.lines.*.quantity', [3, 1])
            ->assertJsonPath('data.lines.*.ordered_quantity', [3, 1]);

        $this->assertNoticed($this->member, 'kitchen_order.accepted');
        $this->assertNotNoticed($this->member, 'kitchen_order.accepted_with_changes');
    }

    public function test_a_paid_order_accepted_with_less_gives_the_difference_back_as_points(): void
    {
        $wallet = $this->walletWith(200);
        $order = $this->order();
        $this->paidFromWallet($order);
        $colaBefore = $this->onHand($this->cola);
        $cakeBefore = $this->onHand($this->cake);

        // One cola short, and no cake at all.
        $this->accept($order, ['lines' => [
            ['id' => $this->lineOf($order, $this->cola), 'quantity' => 2],
            ['id' => $this->lineOf($order, $this->cake), 'quantity' => 0],
        ]])
            ->assertOk()
            ->assertJsonPath('data.status', 'preparing')
            ->assertJsonPath('data.adjusted', true)
            ->assertJsonPath('data.total_points', 40)
            ->assertJsonPath('data.ordered_total_points', 95)
            ->assertJsonPath('data.items_count', 2)
            ->assertJsonPath('data.lines.*.quantity', [2, 0])
            ->assertJsonPath('data.lines.*.ordered_quantity', [3, 1])
            ->assertJsonPath('data.lines.*.line_points', [40, 0]);

        $order->refresh();
        $this->assertNotNull($order->adjusted_at);
        $this->assertNotNull($order->adjustment_refund_transaction_id);
        // 200 - 95 paid + 55 back.
        $this->assertSame(160, app(WalletService::class)->generalBalance($wallet));
        // Only what is made left the store.
        $this->assertSame(2.0, (float) $colaBefore - (float) $this->onHand($this->cola));
        $this->assertSame((float) $cakeBefore, (float) $this->onHand($this->cake));

        $notice = $this->assertNoticed($this->member, 'kitchen_order.accepted_with_changes_refunded');
        $this->assertStringContainsString('55', $this->noticeText($notice, 'en')['body']);
        $this->assertStringContainsString('55', $this->noticeText($notice, 'ar')['body']);

        // The member's wallet shows the difference as a refund of that order, with its items.
        Sanctum::actingAs($this->member, ['member-app']);
        $this->withHeader('lang', 'en')->getJson('/api/v1/member/wallet/transactions')
            ->assertOk()
            ->assertJsonPath('data.transactions.0.kind', 'refund')
            ->assertJsonPath('data.transactions.0.points', '+55')
            ->assertJsonPath('data.transactions.0.title', "Refund — #{$order->id}")
            ->assertJsonPath('data.transactions.0.order.id', $order->id)
            ->assertJsonPath('data.transactions.0.order.adjusted', true)
            ->assertJsonPath('data.transactions.0.order.items.0.ordered_quantity', 3)
            ->assertJsonPath('data.transactions.0.order.items.0.quantity', 2);
    }

    public function test_an_unpaid_order_on_a_bill_simply_owes_less(): void
    {
        $order = $this->order();

        $this->accept($order, ['lines' => [['id' => $this->lineOf($order, $this->cake), 'quantity' => 0]]])
            ->assertOk()
            ->assertJsonPath('data.total_points', 60)
            ->assertJsonPath('data.lines.*.quantity', [3, 0]);

        $order->refresh();
        $this->assertNull($order->adjustment_refund_transaction_id);
        $this->assertNoticed($this->member, 'kitchen_order.accepted_with_changes');
        $this->assertNotNoticed($this->member, 'kitchen_order.accepted_with_changes_refunded');

        // The session owes what is made, and shows what was ordered beside it.
        Sanctum::actingAs($this->member, ['member-app']);
        $this->getJson('/api/v1/member/coworking-sessions/current')
            ->assertOk()
            ->assertJsonPath('data.orders.total_points', 60)
            ->assertJsonPath('data.orders.items_count', 3)
            ->assertJsonPath('data.orders.list.0.adjusted', true)
            ->assertJsonPath('data.orders.list.0.lines.1.ordered_quantity', 1)
            ->assertJsonPath('data.orders.list.0.lines.1.quantity', 0);
    }

    public function test_naming_every_line_as_ordered_is_no_change(): void
    {
        $order = $this->order();

        $this->accept($order, ['lines' => [['id' => $this->lineOf($order, $this->cola), 'quantity' => 3]]])
            ->assertOk()
            ->assertJsonPath('data.adjusted', false)
            ->assertJsonPath('data.total_points', 95);

        $this->assertNoticed($this->member, 'kitchen_order.accepted');
    }

    public function test_what_the_cafe_cannot_send_is_refused_and_nothing_changes(): void
    {
        $order = $this->order();
        $other = $this->order();

        $this->accept($order, ['lines' => [['id' => $this->lineOf($other, $this->cola), 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'One of the items sent is not on this order.');

        $this->accept($order, ['lines' => [['id' => $this->lineOf($order, $this->cola), 'quantity' => 4]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot accept more Cola than the member ordered.');

        $this->accept($order, ['lines' => [
            ['id' => $this->lineOf($order, $this->cola), 'quantity' => 0],
            ['id' => $this->lineOf($order, $this->cake), 'quantity' => 0],
        ]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'An order cannot be accepted with no item left. Reject it instead.');

        $this->accept($order, ['lines' => [['id' => $this->lineOf($order, $this->cola), 'quantity' => -1]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.quantity');

        $order->refresh();
        $this->assertSame(KitchenOrderStatus::Pending, $order->status);
        $this->assertSame(95, $order->total_points);
        $this->assertSame([3, 1], $order->lines()->orderBy('id')->pluck('quantity')->all());
    }

    public function test_the_notices_exist_in_both_languages(): void
    {
        foreach (['accepted_with_changes', 'accepted_with_changes_refunded'] as $name) {
            $this->assertTrue(NotificationText::exists("kitchen_order.{$name}"), $name);
        }
    }
}
