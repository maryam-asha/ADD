<?php

namespace Tests\Feature\Kitchen;

use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionReportBuilder;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderPaymentChoice;
use App\Domain\Kitchen\Enums\OrderRoute;
use App\Domain\Kitchen\Events\KitchenOrderAwaitingReception;
use App\Domain\Kitchen\Events\KitchenOrderChanged;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\KitchenOrderLine;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Kitchen\Services\KitchenOrderService;
use App\Domain\Kitchen\Services\KitchenOrderSettlement;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Retail\Models\SaleItem;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Paying for a kitchen order when it is sent (2026-11-14 spec, decisions 2
 * and 3): from a wallet in the same transaction that writes the order, by
 * card on a hold that neither the kitchen nor reception sees until it is
 * paid, in cash at reception, or on the bill as before.
 */
class PayingWhenSendingTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Branch $branch;

    private Building $building;

    private Space $cospace;

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
        $this->building = Building::factory()->create(['branch_id' => $this->branch->id]);
        $this->cospace = Space::factory()->create(['building_id' => $this->building->id]);

        Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id, 'is_primary' => true]);
        $this->kitchen = Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id]);
        KitchenStore::create(['branch_id' => $this->branch->id, 'warehouse_id' => $this->kitchen->id]);

        $this->cola = SaleItem::factory()->create(['price_points' => 20]);
        app(StockService::class)->receive($this->cola->item, $this->kitchen->asPlace(), 10, 0.5);

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        Sanctum::actingAs($this->member, ['*']);
    }

    private function personalWallet(int $points = 0): Wallet
    {
        $wallet = Wallet::query()->firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return $wallet;
    }

    private function balance(Wallet $wallet): int
    {
        return app(WalletService::class)->generalBalance($wallet);
    }

    private function walkin(): WalkinSession
    {
        return WalkinSession::factory()->create(['user_id' => $this->member->id, 'space_id' => $this->cospace->id]);
    }

    /** The member works for a company that holds an office in this branch today. */
    private function officeStaff(): OfficeLease
    {
        $company = Company::factory()->create();
        $this->member->companies()->attach($company->id, ['door_access_enabled' => true]);
        $office = Space::factory()->create(['building_id' => $this->building->id]);

        return OfficeLease::factory()->create(['company_id' => $company->id, 'space_id' => $office->id]);
    }

    private function cardOpens(): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1'],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $extra
     */
    private function send(array $payment, array $extra = [], int $quantity = 2): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/kitchen-orders', [
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => $quantity]],
            'payment' => $payment,
            ...$extra,
        ]);
    }

    /** The bank accepts the order's card payment. */
    private function cardAccepted(): void
    {
        $payment = Payment::query()->where('purpose', PaymentPurpose::KitchenOrder)->sole();
        $payment->forceFill(['status' => PaymentStatus::Accepted])->save();

        app(PaymentService::class)->fulfilAcceptedGatewayPayment($payment->refresh());
    }

    public function test_wallet_payment_is_taken_with_the_order(): void
    {
        $wallet = $this->personalWallet(100);
        $this->walkin();

        $this->send(['method' => 'wallet'], ['delivery' => ['type' => 'pickup']])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.paid', true)
            ->assertJsonPath('next', 'done')
            ->assertJsonPath('redirect_url', null)
            ->assertJsonPath('payment.amount_points', 40);

        $order = KitchenOrder::query()->sole();
        $this->assertSame(60, $this->balance($wallet));
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($order->reached_kitchen_at);
        $this->assertSame(KitchenOrderStatus::Pending, $order->status);
        $this->assertSame(OrderPaymentChoice::Wallet, $order->payment_choice);
        $this->assertSame(OrderRoute::Kitchen, $order->route);
        $this->assertSame(PaymentStatus::Accepted, Payment::query()->sole()->status);
    }

    public function test_short_wallet_writes_nothing(): void
    {
        $wallet = $this->personalWallet(10);
        $this->walkin();

        $this->send(['method' => 'wallet'], ['delivery' => ['type' => 'pickup']])->assertStatus(422);

        $this->assertSame(0, KitchenOrder::query()->count());
        $this->assertSame(0, KitchenOrderLine::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(10, $this->balance($wallet));
    }

    public function test_company_wallet_pays_for_office_staff(): void
    {
        $lease = $this->officeStaff();
        $companyWallet = Wallet::query()->create(['owner_type' => OwnerType::Company, 'owner_id' => $lease->company_id]);
        app(WalletService::class)->creditGeneral($companyWallet, 500, WalletTransactionSource::TopUp);

        $this->send([
            'method' => 'wallet',
            'wallet_owner_type' => 'company',
            'wallet_owner_id' => $lease->company_id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.paid', true);

        $this->assertSame(460, $this->balance($companyWallet));
        $this->assertSame($companyWallet->id, (int) Payment::query()->sole()->wallet_id);
        $this->assertNull(KitchenOrder::query()->sole()->billable_type);
    }

    public function test_company_wallet_after_access_revoked_is_refused(): void
    {
        $lease = $this->officeStaff();
        $companyWallet = Wallet::query()->create(['owner_type' => OwnerType::Company, 'owner_id' => $lease->company_id]);
        app(WalletService::class)->creditGeneral($companyWallet, 500, WalletTransactionSource::TopUp);
        $this->member->companies()->detach($lease->company_id);

        $this->send([
            'method' => 'wallet',
            'wallet_owner_type' => 'company',
            'wallet_owner_id' => $lease->company_id,
        ], ['delivery' => ['type' => 'pickup']])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.booking.wallet_not_owned', [], 'en'));

        $this->assertSame(0, KitchenOrder::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(500, $this->balance($companyWallet));
    }

    public function test_card_order_is_held_and_unseen(): void
    {
        $this->freezeTime();
        $this->officeStaff();
        $this->personalWallet();
        $this->cardOpens();
        Event::fake([KitchenOrderChanged::class, KitchenOrderAwaitingReception::class]);

        $this->send(['method' => 'card'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.paid', false)
            ->assertJsonPath('next', 'redirect')
            ->assertJsonPath('redirect_url', 'https://egate-t.paymera.test/start/pay-1/en');

        $order = KitchenOrder::query()->sole();
        $this->assertSame(KitchenOrderStatus::AwaitingPayment, $order->status);
        $this->assertSame(OrderPaymentChoice::Card, $order->payment_choice);
        $this->assertSame(now()->addMinutes(15)->toDateTimeString(), $order->payment_due_at?->toDateTimeString());
        $this->assertNull($order->reached_kitchen_at);
        $this->assertNull($order->reached_reception_at);
        $this->assertNull($order->paid_at);

        // Neither the kitchen board nor reception hears of it.
        Event::assertNotDispatched(KitchenOrderChanged::class);
        Event::assertNotDispatched(KitchenOrderAwaitingReception::class);

        $staff = User::factory()->create();
        $staff->assignRole('admin');
        Sanctum::actingAs($staff, ['*']);
        $this->getJson('/api/v1/admin/kitchen/orders?branch_id='.$this->branch->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_card_paid_moves_a_kitchen_route_order_to_pending(): void
    {
        $this->officeStaff();
        $this->personalWallet();
        $this->cardOpens();
        $this->send(['method' => 'card'])->assertCreated();
        Event::fake([KitchenOrderChanged::class, KitchenOrderAwaitingReception::class]);

        $this->cardAccepted();

        $order = KitchenOrder::query()->sole();
        $this->assertSame(KitchenOrderStatus::Pending, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($order->reached_kitchen_at);
        $this->assertNull($order->payment_due_at);
        Event::assertDispatched(KitchenOrderChanged::class, fn (KitchenOrderChanged $e) => $e->created && $e->orderId === $order->id && $e->status === 'pending');
        Event::assertNotDispatched(KitchenOrderAwaitingReception::class);
    }

    public function test_card_paid_moves_a_reception_route_order_to_reception(): void
    {
        $this->personalWallet();
        $this->cardOpens();
        $this->send(['method' => 'card'], ['delivery' => ['type' => 'pickup']], quantity: 1)->assertCreated();
        Event::fake([KitchenOrderChanged::class, KitchenOrderAwaitingReception::class]);

        $this->cardAccepted();

        $order = KitchenOrder::query()->sole();
        $this->assertSame(OrderRoute::Reception, $order->route);
        $this->assertSame(KitchenOrderStatus::AwaitingReception, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNull($order->reached_kitchen_at);
        $this->assertNull($order->payment_due_at);
        Event::assertNotDispatched(KitchenOrderChanged::class);
        Event::assertDispatched(KitchenOrderAwaitingReception::class, fn (KitchenOrderAwaitingReception $e) => $e->broadcastAs() === 'kitchen_order.awaiting_reception'
            && $e->broadcastOn() == [new PrivateChannel('reception')]
            && $e->broadcastWith() === ['order_id' => $order->id, 'branch_id' => $this->branch->id, 'status' => 'awaiting_reception']);
    }

    public function test_held_order_switched_to_wallet(): void
    {
        $this->officeStaff();
        $wallet = $this->personalWallet(100);
        // The card page could not be opened: the hold stands, the error beside it.
        Http::fake(['*' => Http::response('', 500)]);

        $id = $this->send(['method' => 'card'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('redirect_url', null)
            // Held and unpaid: never `done`.
            ->assertJsonPath('next', 'pay_again')
            ->assertJsonStructure(['payment_error'])
            ->json('data.id');

        // Sent again by card, the page still cannot be opened.
        $this->send(['method' => 'card'], ['order_id' => $id])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('redirect_url', null)
            ->assertJsonPath('next', 'pay_again')
            ->assertJsonStructure(['payment_error']);

        $this->send(['method' => 'wallet'], ['order_id' => $id])
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.paid', true)
            ->assertJsonPath('next', 'done');

        $order = KitchenOrder::query()->sole();
        $this->assertSame(OrderPaymentChoice::Wallet, $order->payment_choice);
        $this->assertNull($order->payment_due_at);
        $this->assertNotNull($order->reached_kitchen_at);
        $this->assertSame(60, $this->balance($wallet));
        $this->assertSame(1, KitchenOrderLine::query()->count());

        // No longer held: sending it again is refused.
        $this->send(['method' => 'wallet'], ['order_id' => $id])
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.kitchen.order_not_awaiting_payment', [], 'en'));
    }

    public function test_switching_a_held_order_cancels_its_open_card_payment(): void
    {
        $this->officeStaff();
        $wallet = $this->personalWallet(100);
        Http::fake([
            '*/api/create-payment' => Http::sequence()
                ->push(['ErrorMessage' => 'Success', 'ErrorCode' => 0, 'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1']])
                ->push(['ErrorMessage' => 'Success', 'ErrorCode' => 0, 'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-2/en', 'paymentId' => 'pay-2']]),
            '*/api/cancel-payment' => Http::response(['ErrorMessage' => 'Success', 'ErrorCode' => 0]),
        ]);

        $id = $this->send(['method' => 'card'])->assertCreated()->json('data.id');
        $first = Payment::query()->where('payment_id', 'pay-1')->sole();

        // A new card: the first is let go, the second stays open.
        $this->send(['method' => 'card'], ['order_id' => $id])
            ->assertOk()
            ->assertJsonPath('next', 'redirect')
            ->assertJsonPath('redirect_url', 'https://egate-t.paymera.test/start/pay-2/en');

        $this->assertSame(PaymentStatus::Canceled, $first->refresh()->status);
        $second = Payment::query()->where('payment_id', 'pay-2')->sole();
        $this->assertSame(PaymentStatus::Pending, $second->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/cancel-payment') && $request['payment_id'] === 'pay-1');

        // The wallet instead: the second card is let go too.
        $this->send(['method' => 'wallet'], ['order_id' => $id])->assertOk()->assertJsonPath('data.paid', true);

        $this->assertSame(PaymentStatus::Canceled, $second->refresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/cancel-payment') && $request['payment_id'] === 'pay-2');
        $this->assertSame(0, Payment::query()->where('status', PaymentStatus::Pending)->count());
        $this->assertSame(60, $this->balance($wallet));
    }

    /**
     * The switch has committed when the old card is let go, so a cache that
     * cannot be reached for the card's lock is logged and left to the
     * reconciler, as a gateway that cannot be reached is: the member still
     * hears that the order was paid, and is not told to retry a switch that
     * already happened.
     */
    public function test_a_cache_failure_letting_the_old_card_go_does_not_fail_the_switch(): void
    {
        $this->officeStaff();
        $wallet = $this->personalWallet(100);
        Http::fake([
            '*/api/create-payment' => Http::response(['ErrorMessage' => 'Success', 'ErrorCode' => 0, 'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1']]),
            '*/api/cancel-payment' => Http::response(['ErrorMessage' => 'Success', 'ErrorCode' => 0]),
        ]);
        $id = $this->send(['method' => 'card'])->assertCreated()->json('data.id');
        $card = Payment::query()->where('payment_id', 'pay-1')->sole();

        // The cache goes down for the card's lock alone.
        $store = new class("payments:payment:{$card->id}") extends ArrayStore
        {
            public function __construct(private readonly string $unreachable)
            {
                parent::__construct();
            }

            public function lock($name, $seconds = 0, $owner = null)
            {
                return $name === $this->unreachable ? throw new RuntimeException('cache down') : parent::lock($name, $seconds, $owner);
            }
        };
        Cache::extend('flaky', fn () => Cache::repository($store));
        config(['cache.stores.flaky' => ['driver' => 'flaky'], 'cache.default' => 'flaky']);
        Log::spy();

        $this->send(['method' => 'wallet'], ['order_id' => $id])->assertOk()->assertJsonPath('data.paid', true);

        $this->assertSame(PaymentStatus::Pending, $card->refresh()->status);
        $this->assertSame(60, $this->balance($wallet));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/cancel-payment'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['payment_id'] === $card->id && $context['error'] === 'cache down');
    }

    /**
     * The old card could not be canceled (the gateway refused) and the bank
     * accepts it after the order was paid from the wallet: the order is not
     * paid twice — settling re-checks it, finds it paid, and the card's
     * points stay in the member's wallet with the payment under review.
     */
    public function test_an_old_card_accepted_after_a_wallet_switch_pays_nothing_twice(): void
    {
        $this->officeStaff();
        $wallet = $this->personalWallet(100);
        Http::fake([
            '*/api/create-payment' => Http::response(['ErrorMessage' => 'Success', 'ErrorCode' => 0, 'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1']]),
            '*/api/cancel-payment' => Http::response('', 500),
        ]);

        $id = $this->send(['method' => 'card'])->assertCreated()->json('data.id');
        $card = Payment::query()->where('payment_id', 'pay-1')->sole();
        $before = $this->balance($wallet);

        $this->send(['method' => 'wallet'], ['order_id' => $id])->assertOk()->assertJsonPath('data.paid', true);
        $this->assertSame(PaymentStatus::Pending, $card->refresh()->status);
        $paidAt = KitchenOrder::query()->findOrFail($id)->paid_at;

        // The bank's answer for the old card arrives: accepted.
        $card->forceFill(['status' => PaymentStatus::Accepted])->save();
        app(PaymentService::class)->fulfilAcceptedGatewayPayment($card->refresh());

        $order = KitchenOrder::query()->findOrFail($id);
        $this->assertSame(KitchenOrderStatus::Pending, $order->status);
        $this->assertEquals($paidAt, $order->paid_at);
        $accepted = Payment::query()->forPayable($order)->where('status', PaymentStatus::Accepted)->get();
        $this->assertCount(1, $accepted);
        $this->assertSame('wallet', $accepted->sole()->method);

        $this->assertSame(PaymentStatus::NeedsReview, $card->refresh()->status);
        $this->assertNull($card->debit_transaction_id);
        $this->assertSame($before - 40 + (int) $card->points_to_credit, $this->balance($wallet));
        $this->assertSame(40, (int) $card->points_to_credit);
    }

    public function test_held_order_switched_to_cash_goes_to_reception(): void
    {
        $this->personalWallet();
        Http::fake(['*' => Http::response('', 500)]);
        $id = $this->send(['method' => 'card'], ['delivery' => ['type' => 'pickup']])->assertCreated()->json('data.id');

        $this->send(['method' => 'cash'], ['order_id' => $id])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_reception')
            ->assertJsonPath('data.paid', false)
            ->assertJsonPath('next', 'pay_at_reception');

        $this->assertNull(KitchenOrder::query()->sole()->payment_due_at);
    }

    /**
     * The member switched to cash but the old card could not be let go, and
     * the bank accepts it while the order waits at the desk: the desk's row
     * re-fetches, so it stops showing cash to collect.
     */
    public function test_a_late_card_on_a_cash_order_at_the_desk_tells_the_desk(): void
    {
        $this->personalWallet();
        Http::fake([
            '*/api/create-payment' => Http::response(['ErrorMessage' => 'Success', 'ErrorCode' => 0, 'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1']]),
            '*/api/cancel-payment' => Http::response('', 500),
        ]);
        $id = $this->send(['method' => 'card'], ['delivery' => ['type' => 'pickup']])->assertCreated()->json('data.id');
        $this->send(['method' => 'cash'], ['order_id' => $id])->assertOk()->assertJsonPath('data.status', 'awaiting_reception');
        Event::fake([KitchenOrderChanged::class, KitchenOrderAwaitingReception::class]);

        $this->cardAccepted();

        $order = KitchenOrder::query()->sole();
        $this->assertSame(KitchenOrderStatus::AwaitingReception, $order->status);
        $this->assertNotNull($order->paid_at);
        Event::assertDispatchedTimes(KitchenOrderAwaitingReception::class, 1);
        Event::assertDispatched(KitchenOrderAwaitingReception::class, fn (KitchenOrderAwaitingReception $e) => $e->broadcastWith() === [
            'order_id' => $order->id, 'branch_id' => $this->branch->id, 'status' => 'awaiting_reception',
        ]);
        Event::assertNotDispatched(KitchenOrderChanged::class);
    }

    /** Paying at send, or the desk taking the cash, is announced once, by its own path. */
    public function test_a_payment_the_desk_or_the_send_takes_is_not_announced_twice(): void
    {
        $this->personalWallet(100);
        Event::fake([KitchenOrderAwaitingReception::class]);

        $this->send(['method' => 'wallet'], ['delivery' => ['type' => 'pickup']])->assertCreated()->assertJsonPath('data.status', 'awaiting_reception');
        Event::assertDispatchedTimes(KitchenOrderAwaitingReception::class, 1);

        $cash = $this->send(['method' => 'cash'], ['delivery' => ['type' => 'pickup']])->assertCreated()->json('data.id');
        $staff = User::factory()->create();
        $staff->assignRole('admin');
        Sanctum::actingAs($staff, ['*']);
        $this->postJson("/api/v1/admin/reception/kitchen-orders/{$cash}/accept")->assertOk()->assertJsonPath('data.status', 'pending');

        // The cash order's arrival, then the desk's accept: one each.
        Event::assertDispatchedTimes(KitchenOrderAwaitingReception::class, 3);
    }

    public function test_a_hold_past_its_deadline_or_someone_elses_is_not_resent(): void
    {
        $this->officeStaff();
        $this->personalWallet(100);
        Http::fake(['*' => Http::response('', 500)]);
        $id = $this->send(['method' => 'card'])->assertCreated()->json('data.id');

        $this->travel(16)->minutes();
        $this->send(['method' => 'wallet'], ['order_id' => $id])
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.kitchen.order_not_awaiting_payment', [], 'en'));

        $this->travelBack();
        Sanctum::actingAs(User::factory()->create()->assignRole('member'), ['*']);
        $this->send(['method' => 'wallet'], ['order_id' => $id])->assertStatus(409);

        $this->assertSame(KitchenOrderStatus::AwaitingPayment, KitchenOrder::query()->sole()->status);
        $this->assertSame(0, Payment::query()->where('status', PaymentStatus::Accepted)->count());
    }

    public function test_cash_on_the_kitchen_route_is_refused(): void
    {
        $this->walkin();

        $this->send(['method' => 'cash'], ['delivery' => ['type' => 'pickup']])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.kitchen.payment_method_not_offered', [], 'en'));

        $this->assertSame(0, KitchenOrder::query()->count());
    }

    public function test_bill_is_refused_without_a_bill(): void
    {
        $this->send(['method' => 'bill'], ['delivery' => ['type' => 'pickup']])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.kitchen.payment_method_not_offered', [], 'en'));

        $this->assertSame(0, KitchenOrder::query()->count());
    }

    public function test_cash_order_waits_for_reception(): void
    {
        Event::fake([KitchenOrderChanged::class, KitchenOrderAwaitingReception::class]);

        $this->send(['method' => 'cash'], ['delivery' => ['type' => 'note', 'note' => 'At the front desk']])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_reception')
            ->assertJsonPath('data.paid', false)
            ->assertJsonPath('payment', null)
            ->assertJsonPath('next', 'pay_at_reception');

        $order = KitchenOrder::query()->sole();
        $this->assertSame(OrderRoute::Reception, $order->route);
        $this->assertSame(OrderPaymentChoice::Cash, $order->payment_choice);
        $this->assertNull($order->billable_type);
        $this->assertNull($order->reached_kitchen_at);
        $this->assertNotNull($order->reached_reception_at);
        $this->assertSame(0, Payment::query()->count());

        Event::assertNotDispatched(KitchenOrderChanged::class);
        Event::assertDispatchedTimes(KitchenOrderAwaitingReception::class, 1);
    }

    public function test_order_paid_now_still_appears_on_its_bill_as_paid(): void
    {
        $this->personalWallet(100);
        $session = $this->walkin();

        $id = $this->send(['method' => 'wallet'], ['delivery' => ['type' => 'pickup']])->assertCreated()->json('data.id');
        $order = KitchenOrder::query()->findOrFail($id);
        $this->assertSame($session->getMorphClass(), $order->billable_type);
        $this->assertSame($session->id, $order->billable_id);

        $staff = User::factory()->create();
        $staff->assignRole('admin');
        app(KitchenOrderService::class)->accept($order, $staff);

        app()->setLocale('en');
        $line = collect(app(SessionReportBuilder::class)->build($session->refresh())['lines'])->firstWhere('kind', 'order');

        $this->assertSame(40, $line['points']);
        $this->assertTrue($line['paid']);
        $this->assertFalse(app(KitchenOrderSettlement::class)->owesFor($session));
    }

    public function test_office_staff_order_defaults_to_their_office(): void
    {
        $lease = $this->officeStaff();
        $this->personalWallet(200);

        $this->send(['method' => 'wallet'])
            ->assertCreated()
            ->assertJsonPath('data.delivery.type', 'space');

        $order = KitchenOrder::query()->sole();
        $this->assertSame(DeliveryType::Space, $order->delivery_type);
        $this->assertSame($lease->space_id, $order->space_id);

        // They may name another space of the branch, or the counter.
        $this->send(['method' => 'wallet'], ['delivery' => ['type' => 'space', 'space_id' => $this->cospace->id]])->assertCreated();
        $this->assertSame($this->cospace->id, KitchenOrder::query()->latest('id')->first()->space_id);

        $this->send(['method' => 'wallet'], ['delivery' => ['type' => 'pickup']])
            ->assertCreated()
            ->assertJsonPath('data.delivery.type', 'pickup');
    }

    public function test_without_bill_or_office_a_place_must_be_named(): void
    {
        $this->personalWallet(100);

        $this->send(['method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.kitchen.delivery_required', [], 'en'));

        $this->assertSame(0, KitchenOrder::query()->count());
    }

    public function test_payment_method_is_required(): void
    {
        $this->walkin();

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/kitchen-orders', [
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 1]],
            'delivery' => ['type' => 'pickup'],
        ])->assertStatus(422)->assertJsonValidationErrors('payment.method');
    }
}
