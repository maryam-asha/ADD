<?php

namespace Tests\Feature\Kitchen;

use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderPaymentChoice;
use App\Domain\Kitchen\Enums\OrderRoute;
use App\Domain\Kitchen\Events\KitchenOrderAwaitingReception;
use App\Domain\Kitchen\Events\KitchenOrderChanged;
use App\Domain\Kitchen\Events\MemberKitchenOrderUpdated;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Retail\Models\SaleItem;
use App\Notifications\GeneralNotification;
use App\Support\PlainAmount;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reception checks that a member who sent an order without a bill is in the
 * building, then lets it through to the kitchen or turns it down (2026-11-14
 * spec, decision 5).
 */
class ReceptionReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $member;

    private Branch $branch;

    private Warehouse $kitchen;

    private SaleItem $cola;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->branch = Branch::factory()->create();
        Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id, 'is_primary' => true]);
        $this->kitchen = Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id]);
        KitchenStore::create(['branch_id' => $this->branch->id, 'warehouse_id' => $this->kitchen->id]);

        $this->cola = SaleItem::factory()->create(['price_points' => 20]);
        app(StockService::class)->receive($this->cola->item, $this->kitchen->asPlace(), 10, 0.5);

        $this->member = User::factory()->create();
        $this->member->assignRole('member');

        Role::create(['name' => 'desk', 'guard_name' => 'web'])->givePermissionTo('kitchen-orders.review');
        $this->staff = User::factory()->create();
        $this->staff->assignRole('desk');
        Sanctum::actingAs($this->staff, ['*']);
    }

    /** @param array<string, mixed> $state */
    private function waiting(array $state = [], ?Branch $branch = null): KitchenOrder
    {
        $branch ??= $this->branch;

        return KitchenOrder::factory()->withoutBill()->create($state + [
            'user_id' => $this->member->id,
            'branch_id' => $branch->id,
            'warehouse_id' => $this->kitchen->id,
            'status' => KitchenOrderStatus::AwaitingReception,
            'route' => OrderRoute::Reception,
            'payment_choice' => OrderPaymentChoice::Cash,
            'total_points' => 40,
            'reached_kitchen_at' => null,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function act(KitchenOrder $order, string $action, array $body = []): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/kitchen-orders/{$order->id}/{$action}", $body);
    }

    public function test_queue_lists_only_orders_awaiting_reception_in_the_branch(): void
    {
        $this->travelTo(now()->subMinutes(10));
        $oldest = $this->waiting();
        $this->travelBack();
        $newest = $this->waiting();
        $this->waiting(['status' => KitchenOrderStatus::AwaitingPayment]);
        $this->waiting(['status' => KitchenOrderStatus::Pending]);
        $other = Branch::factory()->create();
        $elsewhere = $this->waiting([], $other);

        $ids = fn (string $query) => array_column($this->getJson("/api/v1/admin/reception/kitchen-orders{$query}")->assertOk()->json('data'), 'id');

        $this->assertSame([$oldest->id, $newest->id], $ids("?branch_id={$this->branch->id}"));
        $this->assertSame([$elsewhere->id], $ids("?branch_id={$other->id}"));
        $this->assertCount(3, $ids(''));

        $this->getJson("/api/v1/admin/reception/kitchen-orders?branch_id={$this->branch->id}")
            ->assertJsonPath('data.0.route', 'reception')
            ->assertJsonPath('data.0.payment.choice', 'cash')
            ->assertJsonPath('data.0.payment.paid', false)
            ->assertJsonPath('data.0.payment.wallet_owner_type', null)
            ->assertJsonPath('data.0.cash_due_points', 40)
            ->assertJsonPath('data.0.bill', null)
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_a_cash_order_says_what_to_collect_in_money_and_a_paid_one_does_not(): void
    {
        $this->waiting();
        $this->waiting(['payment_choice' => OrderPaymentChoice::Wallet, 'paid_at' => now()]);

        $rows = collect($this->getJson('/api/v1/admin/reception/kitchen-orders')->assertOk()->json('data'))->keyBy(fn (array $row) => $row['payment']['choice']);

        $amounts = $rows['cash']['cash_due_amounts'];
        $this->assertSame('USD', $amounts[0]['currency']);
        $this->assertSame('4', PlainAmount::of($amounts[0]['value']));
        // A currency with no rate yet is left out rather than refused.
        $this->assertNotContains('SYP', array_column($amounts, 'currency'));

        $this->assertNull($rows['wallet']['cash_due_points']);
        $this->assertNull($rows['wallet']['cash_due_amounts']);
    }

    public function test_a_page_of_cash_orders_reads_the_currencies_once_and_prices_each_total_once(): void
    {
        $queries = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/admin/reception/kitchen-orders')->assertOk();
            DB::disableQueryLog();

            return collect(DB::getQueryLog())->pluck('query');
        };

        $this->waiting();
        $this->waiting();
        $this->getJson('/api/v1/admin/reception/kitchen-orders')->assertOk(); // warms the permission cache
        $few = $queries();
        for ($i = 0; $i < 5; $i++) {
            $this->waiting();
        }
        $many = $queries();

        // Seven orders of the same total cost what two do.
        $this->assertSame($few->count(), $many->count());
        // The active currencies are read at most once a request (none here:
        // the warm-up request in this same app already read them).
        $this->assertLessThanOrEqual(1, $many->filter(fn (string $sql) => str_contains($sql, 'is_active'))->count());
    }

    public function test_accepting_sends_it_to_the_kitchen_board(): void
    {
        $order = $this->waiting(['payment_choice' => OrderPaymentChoice::Wallet, 'paid_at' => now()]);
        Event::fake([KitchenOrderChanged::class, KitchenOrderAwaitingReception::class]);
        Notification::fake();

        $this->act($order, 'accept')->assertOk()->assertJsonPath('data.status', 'pending');

        $order->refresh();
        $this->assertSame(KitchenOrderStatus::Pending, $order->status);
        $this->assertNotNull($order->reached_kitchen_at);
        $this->assertSame($this->staff->id, $order->reception_decided_by);
        $this->assertNotNull($order->reception_decided_at);
        Event::assertDispatched(KitchenOrderChanged::class, fn (KitchenOrderChanged $e) => $e->broadcastWith()['status'] === 'pending'
            && $e->broadcastWith()['order_id'] === $order->id);
        Event::assertDispatched(KitchenOrderAwaitingReception::class);
        Notification::assertSentTo($this->member, GeneralNotification::class, fn (GeneralNotification $n) => $n->toArray($this->member)['template_key'] === 'kitchen_order.accepted_by_reception');
    }

    public function test_accepting_a_cash_order_records_the_cash(): void
    {
        $order = $this->waiting();

        $this->act($order, 'accept')->assertOk()->assertJsonPath('data.payment.paid', true)->assertJsonPath('data.cash_due_points', null);

        $payment = Payment::query()->forPayable($order)->sole();
        $this->assertSame('cash', $payment->collected_via->value);
        $this->assertSame($this->staff->id, $payment->received_by);
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertNotNull($order->refresh()->paid_at);
        $this->assertSame(KitchenOrderStatus::Pending, $order->status);
    }

    public function test_a_cash_order_already_paid_collects_nothing_more(): void
    {
        $order = $this->waiting(['paid_at' => now()]);

        $this->act($order, 'accept')->assertOk();

        $this->assertSame(KitchenOrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(0, Payment::query()->forPayable($order)->count());
    }

    public function test_the_desk_takes_exactly_what_is_due_and_a_refused_order_gives_back_no_more(): void
    {
        $order = $this->waiting();
        $wallet = Wallet::query()->firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);

        // An amount sent along is not read: the desk takes exactly what is
        // due, so no surplus is left in the wallet as points.
        $this->act($order, 'accept', ['amount' => '10', 'currency' => 'USD'])->assertOk();

        $payment = Payment::query()->forPayable($order)->sole();
        $this->assertSame('4', PlainAmount::of((string) $payment->received_amount));
        $this->assertSame('USD', $payment->received_currency);
        $this->assertSame(40, (int) $payment->points_to_credit);
        $this->assertSame(0, app(WalletService::class)->generalBalance($wallet));

        // The kitchen does not make it: the cash goes back, and it is all
        // that goes back.
        Role::findByName('desk', 'web')->givePermissionTo('kitchen-orders.manage');
        $this->staff->forgetCachedPermissions();
        $this->postJson("/api/v1/admin/kitchen/orders/{$order->id}/reject", ['reason' => 'Out of cola'])->assertOk();

        $row = $this->getJson("/api/v1/admin/reception/kitchen-cash-returns?branch_id={$this->branch->id}")
            ->assertOk()
            ->assertJsonPath('data.0.order.id', $order->id)
            ->assertJsonPath('data.0.points', 40)
            ->assertJsonPath('data.0.received_currency', 'USD')
            ->json('data.0');
        $this->assertSame('4', PlainAmount::of((string) $row['received_amount']));
        $this->assertSame(0, app(WalletService::class)->generalBalance($wallet));
    }

    public function test_rejecting_refunds_and_tells_the_member(): void
    {
        $wallet = Wallet::query()->firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        app(WalletService::class)->creditGeneral($wallet, 100, WalletTransactionSource::TopUp);

        // The member sends a wallet-paid order with no bill: it waits at the desk.
        Sanctum::actingAs($this->member, ['*']);
        $this->postJson('/api/v1/member/kitchen-orders', [
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 2]],
            'delivery' => ['type' => 'pickup'],
            'payment' => ['method' => 'wallet'],
        ])->assertCreated()->assertJsonPath('data.status', 'awaiting_reception');
        $order = KitchenOrder::query()->sole();
        $this->assertSame(60, app(WalletService::class)->generalBalance($wallet));

        Sanctum::actingAs($this->staff, ['*']);
        Event::fake([KitchenOrderAwaitingReception::class, MemberKitchenOrderUpdated::class]);
        Notification::fake();

        $this->act($order, 'reject', ['reason' => 'Nobody is at the desk'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Nobody is at the desk')
            ->assertJsonPath('data.payment.wallet_owner_type', 'user');

        // The member's app hears the rejection over the socket.
        Event::assertDispatched(MemberKitchenOrderUpdated::class, fn (MemberKitchenOrderUpdated $e) => $e->broadcastAs() === 'kitchen_order.updated'
            && $e->broadcastWith()['order_id'] === $order->id
            && $e->broadcastWith()['status'] === 'rejected');

        $order->refresh();
        $this->assertSame(KitchenOrderStatus::Rejected, $order->status);
        $this->assertSame($this->staff->id, $order->reception_decided_by);
        $this->assertNotNull($order->reception_decided_at);
        $this->assertSame(100, app(WalletService::class)->generalBalance($wallet));
        Event::assertDispatched(KitchenOrderAwaitingReception::class);
        Notification::assertSentTo($this->member, GeneralNotification::class, fn (GeneralNotification $n) => $n->toArray($this->member)['template_key'] === 'kitchen_order.rejected_by_reception');
    }

    public function test_the_reason_is_required_and_short(): void
    {
        $order = $this->waiting();

        $this->act($order, 'reject')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->act($order, 'reject', ['reason' => str_repeat('a', 301)])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame(KitchenOrderStatus::AwaitingReception, $order->refresh()->status);
    }

    public function test_accepting_twice_is_409(): void
    {
        $order = $this->waiting(['payment_choice' => OrderPaymentChoice::Wallet, 'paid_at' => now()]);

        $this->act($order, 'accept')->assertOk();
        $this->act($order, 'accept')
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.kitchen.order_not_awaiting_reception', [], 'en'));
        $this->act($order, 'reject', ['reason' => 'Late'])->assertStatus(409);
    }

    public function test_kitchen_manage_permission_alone_cannot_review(): void
    {
        Role::create(['name' => 'cook', 'guard_name' => 'web'])->givePermissionTo('kitchen-orders.manage', 'kitchen-orders.view');
        $cook = User::factory()->create();
        $cook->assignRole('cook');
        Sanctum::actingAs($cook, ['*']);
        $order = $this->waiting();

        $this->getJson('/api/v1/admin/reception/kitchen-orders')->assertForbidden();
        $this->act($order, 'accept')->assertForbidden();
        $this->act($order, 'reject', ['reason' => 'No'])->assertForbidden();
        $this->assertSame(KitchenOrderStatus::AwaitingReception, $order->refresh()->status);
    }

    public function test_taking_cash_is_logged_as_the_desk_logs_cash_and_a_paid_order_logs_nothing(): void
    {
        $order = $this->waiting();
        $paid = $this->waiting(['paid_at' => now()]);

        $this->act($paid, 'accept')->assertOk();
        $this->assertSame(0, Activity::where('description', 'payment_settled_at_reception')->count());

        $this->act($order, 'accept')->assertOk();

        $entry = Activity::where('description', 'payment_settled_at_reception')->sole();
        $this->assertSame($this->staff->id, (int) $entry->causer_id);
        $this->assertSame(Payment::query()->forPayable($order)->sole()->id, (int) $entry->subject_id);
        $this->assertSame('kitchen_order', $entry->properties['purpose']);
        $this->assertSame('cash', $entry->properties['collected_via']);
        $this->assertSame(40, $entry->properties['points_charged']);
        $this->assertArrayHasKey('received_amount', $entry->properties->toArray());
        $this->assertArrayHasKey('received_currency', $entry->properties->toArray());
    }

    public function test_a_currency_with_no_rate_is_a_422_and_nothing_is_written(): void
    {
        $order = $this->waiting();

        // SYP exists but has no exchange rate.
        $this->act($order, 'accept', ['currency' => 'SYP'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.payment.currency_unavailable', [], 'en'));

        $order->refresh();
        $this->assertSame(KitchenOrderStatus::AwaitingReception, $order->status);
        $this->assertSame(0, Payment::query()->forPayable($order)->count());
        $this->assertSame(0, Activity::where('description', 'payment_settled_at_reception')->count());
        $this->getJson('/api/v1/admin/reception/kitchen-orders')->assertJsonCount(1, 'data');
    }

    public function test_a_page_of_orders_costs_the_same_queries_however_many_there_are(): void
    {
        $wallet = Wallet::query()->firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        $make = function (int $count) use ($wallet) {
            for ($i = 0; $i < $count; $i++) {
                $order = $this->waiting(['payment_choice' => OrderPaymentChoice::Wallet, 'paid_at' => now()]);
                Payment::factory()->accepted()->forPayable($order)->create(['wallet_id' => $wallet->id, 'user_id' => $this->member->id]);
            }
        };
        $queries = function (string $url) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->getJson($url)->assertOk();
            DB::disableQueryLog();

            return [count(DB::getQueryLog()), $response];
        };

        $make(2);
        $this->getJson('/api/v1/admin/reception/kitchen-orders')->assertOk(); // warms the permission cache
        [$few] = $queries('/api/v1/admin/reception/kitchen-orders');
        $make(5);
        [$many, $response] = $queries('/api/v1/admin/reception/kitchen-orders');

        $response->assertJsonCount(7, 'data')->assertJsonPath('data.6.payment.wallet_owner_type', 'user');
        $this->assertSame($few, $many);

        // The kitchen board's list goes through the same resource.
        Role::findByName('desk', 'web')->givePermissionTo('kitchen-orders.view');
        $this->staff->forgetCachedPermissions();
        KitchenOrder::query()->update(['status' => KitchenOrderStatus::Pending]);
        $url = "/api/v1/admin/kitchen/orders?branch_id={$this->branch->id}";
        $this->getJson($url)->assertOk();
        [$boardMany] = $queries($url);
        KitchenOrder::query()->limit(5)->delete();
        [$boardFew] = $queries($url);
        $this->assertSame($boardFew, $boardMany);
    }

    public function test_a_member_cancelling_an_order_at_the_desk_drops_its_row(): void
    {
        $order = $this->waiting(['payment_choice' => OrderPaymentChoice::Wallet]);
        Event::fake([KitchenOrderChanged::class, KitchenOrderAwaitingReception::class]);

        Sanctum::actingAs($this->member, ['*']);
        $this->postJson("/api/v1/member/kitchen-orders/{$order->id}/cancel")->assertOk();

        Event::assertDispatched(KitchenOrderAwaitingReception::class, fn (KitchenOrderAwaitingReception $e) => $e->broadcastWith() === [
            'order_id' => $order->id, 'branch_id' => $this->branch->id, 'status' => 'cancelled',
        ]);
        Event::assertDispatched(KitchenOrderChanged::class);
    }

    public function test_a_later_payment_that_is_not_accepted_does_not_hide_the_wallet_that_paid(): void
    {
        $wallet = Wallet::query()->firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        $order = $this->waiting(['payment_choice' => OrderPaymentChoice::Wallet, 'paid_at' => now()]);
        Payment::factory()->accepted()->forPayable($order)->create(['wallet_id' => $wallet->id, 'user_id' => $this->member->id]);
        Payment::factory()->forPayable($order)->create(['status' => PaymentStatus::NeedsReview, 'wallet_id' => $wallet->id, 'user_id' => $this->member->id]);

        $this->getJson('/api/v1/admin/reception/kitchen-orders')
            ->assertOk()
            ->assertJsonPath('data.0.payment.wallet_owner_type', 'user')
            ->assertJsonPath('data.0.payment.paid', true);
    }
}
