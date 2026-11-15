<?php

namespace Tests\Feature\Kitchen;

use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\CancelledBy;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderPaymentChoice;
use App\Domain\Kitchen\Enums\OrderRoute;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Kitchen\Services\KitchenOrderService;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Retail\Models\SaleItem;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\TestCase;

/**
 * An order nobody acts on is cancelled by the system and gives back what it
 * took (2026-11-14 spec, decisions 3, 5 and 7): held for a card past its
 * deadline, unconfirmed by reception, unaccepted by the kitchen.
 */
class ExpireStaleOrdersTest extends TestCase
{
    use ReadsNotices;
    use RefreshDatabase;

    private User $member;

    private Branch $branch;

    private Warehouse $kitchen;

    private SaleItem $cola;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'USD',
            'paymera.default_lang' => 'en',
        ]);

        $this->branch = Branch::factory()->create();
        $building = Building::factory()->create(['branch_id' => $this->branch->id]);
        Space::factory()->create(['building_id' => $building->id]);

        Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id, 'is_primary' => true]);
        $this->kitchen = Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id]);
        KitchenStore::create(['branch_id' => $this->branch->id, 'warehouse_id' => $this->kitchen->id]);

        $this->cola = SaleItem::factory()->create(['price_points' => 20]);
        app(StockService::class)->receive($this->cola->item, $this->kitchen->asPlace(), 10, 0.5);

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        Sanctum::actingAs($this->member, ['*']);
    }

    private function wallet(int $points = 0): Wallet
    {
        $wallet = Wallet::query()->firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return $wallet;
    }

    private function balance(): int
    {
        return app(WalletService::class)->generalBalance($this->wallet());
    }

    /** A wallet-paid, no-bill order sent through the API (40 points), left where it lands. */
    private function sendPaid(string $method = 'wallet'): KitchenOrder
    {
        $this->wallet(100);
        $this->postJson('/api/v1/member/kitchen-orders', [
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 2]],
            'payment' => ['method' => $method],
            'delivery' => ['type' => 'pickup'],
        ])->assertCreated();

        return KitchenOrder::query()->latest('id')->firstOrFail();
    }

    private function fakeCard(string $status = 'A'): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response(['ErrorMessage' => 'Success', 'ErrorCode' => 0, 'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1']]),
            '*/api/cancel-payment' => Http::response(['ErrorMessage' => 'Success', 'ErrorCode' => 0]),
            '*/api/get-payment-status/*' => fn () => Http::response(['ErrorMessage' => 'Success', 'ErrorCode' => 0, 'Data' => [
                'status' => $status,
                'creationTimestamp' => now()->format('Y-m-d H:i:s.000'),
                'rrn' => '000009876543',
                'amount' => (int) Payment::query()->where('payment_id', 'pay-1')->value('charged_amount'),
                'terminalId' => '99990001',
            ]]),
        ]);
    }

    private function sweep(): void
    {
        $this->artisan('kitchen:expire-stale-orders')->assertSuccessful();
    }

    /** An order that reached the kitchen with nobody having paid on a bill. */
    private function onBill(): KitchenOrder
    {
        $session = WalkinSession::factory()->create(['user_id' => $this->member->id]);

        return KitchenOrder::factory()->create([
            'user_id' => $this->member->id,
            'billable_type' => $session->getMorphClass(),
            'billable_id' => $session->id,
            'warehouse_id' => $this->kitchen->id,
            'branch_id' => $this->branch->id,
            'status' => KitchenOrderStatus::Pending,
            'reached_kitchen_at' => now(),
        ]);
    }

    public function test_unpaid_card_hold_is_cancelled_at_its_deadline(): void
    {
        $this->wallet(100);
        $this->fakeCard('P');
        $this->postJson('/api/v1/member/kitchen-orders', [
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 2]],
            'payment' => ['method' => 'card'],
            'delivery' => ['type' => 'pickup'],
        ])->assertCreated()->assertJsonPath('data.status', 'awaiting_payment');
        $order = KitchenOrder::query()->sole();
        $card = Payment::query()->where('payment_id', 'pay-1')->sole();

        $this->travel(5)->minutes();
        $this->sweep();
        $this->assertSame(KitchenOrderStatus::AwaitingPayment, $order->refresh()->status);

        $this->travel(config('payments.prepayment_window_minutes') + 1)->minutes();
        $this->sweep();

        $order->refresh();
        $this->assertSame(KitchenOrderStatus::Cancelled, $order->status);
        $this->assertSame(CancelledBy::System, $order->cancelled_by);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame(PaymentStatus::Canceled, $card->refresh()->status);
        $this->assertSame(1, $this->notices($this->member, 'kitchen_order.expired_payment')->count());
    }

    public function test_card_paid_at_the_deadline_is_not_cancelled(): void
    {
        $this->wallet(100);
        $this->fakeCard('A');
        $this->postJson('/api/v1/member/kitchen-orders', [
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 2]],
            'payment' => ['method' => 'card'],
            'delivery' => ['type' => 'pickup'],
        ])->assertCreated()->assertJsonPath('data.status', 'awaiting_payment');
        $order = KitchenOrder::query()->sole();

        $this->travel(config('payments.prepayment_window_minutes') + 1)->minutes();
        $this->sweep();

        $order->refresh();
        $this->assertNotSame(KitchenOrderStatus::Cancelled, $order->status);
        $this->assertNotSame(KitchenOrderStatus::AwaitingPayment, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(0, $this->notices($this->member, 'kitchen_order.expired_payment')->count());
    }

    public function test_unanswered_reception_order_is_cancelled_and_refunded_after_15_minutes(): void
    {
        $order = $this->sendPaid();
        $order->forceFill(['status' => KitchenOrderStatus::AwaitingReception, 'route' => OrderRoute::Reception, 'reached_kitchen_at' => null, 'reached_reception_at' => now()])->save();
        $this->assertSame(60, $this->balance());

        $this->travel(14)->minutes();
        $this->sweep();
        $this->assertSame(KitchenOrderStatus::AwaitingReception, $order->refresh()->status);

        $this->travel(2)->minutes();
        $this->sweep();

        $order->refresh();
        $this->assertSame(KitchenOrderStatus::Cancelled, $order->status);
        $this->assertSame(CancelledBy::System, $order->cancelled_by);
        $this->assertSame(100, $this->balance());
        $this->assertSame(1, $this->notices($this->member, 'kitchen_order.expired_reception')->count());
    }

    public function test_a_card_order_is_measured_from_when_it_reached_reception(): void
    {
        $order = $this->sendPaid();
        $order->forceFill([
            'status' => KitchenOrderStatus::AwaitingReception,
            'route' => OrderRoute::Reception,
            'reached_kitchen_at' => null,
            'payment_choice' => OrderPaymentChoice::Card,
            'created_at' => now()->subMinutes(30),
            'paid_at' => now()->subMinutes(5),
            'reached_reception_at' => now()->subMinutes(5),
        ])->save();

        $this->sweep();

        $this->assertSame(KitchenOrderStatus::AwaitingReception, $order->refresh()->status);
    }

    public function test_a_card_hold_switched_to_cash_gets_the_whole_reception_window(): void
    {
        $this->wallet();
        $this->fakeCard('P');
        $this->postJson('/api/v1/member/kitchen-orders', [
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 2]],
            'payment' => ['method' => 'card'],
            'delivery' => ['type' => 'pickup'],
        ])->assertCreated()->assertJsonPath('data.status', 'awaiting_payment');
        $order = KitchenOrder::query()->sole();

        // The member gives up on the card at minute 12 and pays cash at the desk instead.
        $this->travel(12)->minutes();
        $this->postJson('/api/v1/member/kitchen-orders', [
            'order_id' => $order->id,
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 2]],
            'payment' => ['method' => 'cash'],
            'delivery' => ['type' => 'pickup'],
        ])->assertOk()->assertJsonPath('data.status', 'awaiting_reception');
        $this->assertSame(now()->toDateTimeString(), $order->refresh()->reached_reception_at?->toDateTimeString());

        $this->travel(14)->minutes();
        $this->sweep();
        $this->assertSame(KitchenOrderStatus::AwaitingReception, $order->refresh()->status);

        $this->travel(2)->minutes();
        $this->sweep();

        $this->assertSame(KitchenOrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(1, $this->notices($this->member, 'kitchen_order.expired_reception')->count());
    }

    public function test_kitchen_unaccepted_order_is_cancelled_after_15_minutes(): void
    {
        $noBill = $this->sendPaid();
        $noBill->forceFill(['status' => KitchenOrderStatus::Pending, 'route' => OrderRoute::Kitchen, 'reached_kitchen_at' => now()])->save();
        $bill = $this->onBill();

        $this->travel(14)->minutes();
        $this->sweep();
        $this->assertSame(KitchenOrderStatus::Pending, $noBill->refresh()->status);
        $this->assertSame(KitchenOrderStatus::Pending, $bill->refresh()->status);

        $this->travel(2)->minutes();
        $this->sweep();

        $this->assertSame(KitchenOrderStatus::Cancelled, $noBill->refresh()->status);
        $this->assertSame(KitchenOrderStatus::Cancelled, $bill->refresh()->status);
        $this->assertSame(CancelledBy::System, $bill->cancelled_by);
        $this->assertSame(100, $this->balance());
        $this->assertSame(2, $this->notices($this->member, 'kitchen_order.expired_kitchen')->count());
    }

    public function test_reception_accept_and_expiry_race_one_wins(): void
    {
        $order = $this->sendPaid();
        $order->forceFill(['status' => KitchenOrderStatus::AwaitingReception, 'route' => OrderRoute::Reception, 'reached_kitchen_at' => null, 'reached_reception_at' => now()])->save();
        $stale = KitchenOrder::query()->findOrFail($order->id);
        $this->travel(16)->minutes();

        // Reception lets it through just before the sweep gets to it.
        $order->forceFill(['status' => KitchenOrderStatus::Pending, 'reached_kitchen_at' => now()])->save();

        $this->assertFalse(app(KitchenOrderService::class)->expire($stale, 'reception'));
        $this->sweep();

        $this->assertSame(KitchenOrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(60, $this->balance());
        $this->assertSame(0, $this->notices($this->member, 'kitchen_order.expired_reception')->count());
    }

    public function test_windows_come_from_config(): void
    {
        config(['kitchen.reception_window_minutes' => 5, 'kitchen.accept_window_minutes' => 30]);
        $waiting = $this->sendPaid();
        $waiting->forceFill(['status' => KitchenOrderStatus::AwaitingReception, 'route' => OrderRoute::Reception, 'reached_kitchen_at' => null, 'reached_reception_at' => now()])->save();
        $pending = $this->sendPaid();
        $pending->forceFill(['status' => KitchenOrderStatus::Pending, 'route' => OrderRoute::Kitchen, 'reached_kitchen_at' => now()])->save();

        $this->travel(6)->minutes();
        $this->sweep();

        $this->assertSame(KitchenOrderStatus::Cancelled, $waiting->refresh()->status);
        $this->assertSame(KitchenOrderStatus::Pending, $pending->refresh()->status);

        $this->travel(25)->minutes();
        $this->sweep();

        $this->assertSame(KitchenOrderStatus::Cancelled, $pending->refresh()->status);
    }

    public function test_it_runs_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'kitchen:expire-stale-orders'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
