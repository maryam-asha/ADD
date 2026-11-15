<?php

namespace Tests\Feature\Kitchen;

use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\CancelledBy;
use App\Domain\Kitchen\Enums\CashReturn;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Exceptions\KitchenOrderException;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Kitchen\Services\KitchenOrderRefunder;
use App\Domain\Kitchen\Services\KitchenOrderService;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Retail\Models\SaleItem;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An order that is not made gives back what it took (2026-11-14 spec,
 * decision 4): points as points to the wallet that paid, a card payment too
 * (it became points when accepted), and cash as cash still owed.
 */
class RefundingUnmadeOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Branch $branch;

    private Building $building;

    private Space $cospace;

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
        $kitchen = Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id]);
        KitchenStore::create(['branch_id' => $this->branch->id, 'warehouse_id' => $kitchen->id]);

        $this->cola = SaleItem::factory()->create(['price_points' => 20]);
        app(StockService::class)->receive($this->cola->item, $kitchen->asPlace(), 10, 0.5);

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

    /**
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $extra
     */
    private function send(array $payment, array $extra = []): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/kitchen-orders', [
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 2]],
            'payment' => $payment,
            ...$extra,
        ]);
    }

    /** A wallet-paid order the kitchen has not yet accepted (40 points). */
    private function walletPaidOrder(): KitchenOrder
    {
        $this->walkin();
        $this->send(['method' => 'wallet'], ['delivery' => ['type' => 'pickup']])->assertCreated();

        return KitchenOrder::query()->sole();
    }

    private function staff(): User
    {
        return User::factory()->create();
    }

    private function cancel(KitchenOrder $order): void
    {
        app(KitchenOrderService::class)->cancelByMember($order);
    }

    /** The member works for a company holding an office here: the order has no bill. */
    private function officeStaffMember(): Company
    {
        $company = Company::factory()->create();
        $this->member->companies()->attach($company->id, ['door_access_enabled' => true]);
        OfficeLease::factory()->create([
            'company_id' => $company->id,
            'space_id' => Space::factory()->create(['building_id' => $this->building->id])->id,
        ]);

        return $company;
    }

    private function cardOpens(): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response(['ErrorMessage' => 'Success', 'ErrorCode' => 0, 'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1']]),
            '*/api/cancel-payment' => Http::response(['ErrorMessage' => 'Success', 'ErrorCode' => 0]),
        ]);
    }

    public function test_member_cancelling_a_wallet_paid_order_gets_the_points_back(): void
    {
        $wallet = $this->personalWallet(100);
        $order = $this->walletPaidOrder();
        $this->assertSame(60, $this->balance($wallet));

        $this->cancel($order);

        $this->assertSame(KitchenOrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(CancelledBy::Member, $order->cancelled_by);
        $this->assertSame(100, $this->balance($wallet));
        $payment = Payment::query()->forPayable($order)->sole();
        $this->assertNotNull($payment->refunded_at);
        $this->assertNotNull($payment->refund_transaction_id);
        $this->assertNull($order->cash_return);
    }

    public function test_member_may_cancel_while_awaiting_reception_or_payment(): void
    {
        $this->personalWallet(100);
        $this->officeStaffMember();
        $this->cardOpens();

        // Held for its card: the open gateway payment is cancelled with it.
        $held = $this->send(['method' => 'card'])->assertCreated()->assertJsonPath('data.status', 'awaiting_payment')->json('data.id');
        $card = Payment::query()->where('payment_id', 'pay-1')->sole();

        $this->cancel(KitchenOrder::query()->findOrFail($held));

        $this->assertSame(KitchenOrderStatus::Cancelled, KitchenOrder::query()->findOrFail($held)->status);
        $this->assertSame(PaymentStatus::Canceled, $card->refresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/cancel-payment') && $request['payment_id'] === 'pay-1');

        // Waiting for reception.
        $waiting = KitchenOrder::factory()->create([
            'user_id' => $this->member->id,
            'status' => KitchenOrderStatus::AwaitingReception,
        ]);
        $this->cancel($waiting);
        $this->assertSame(KitchenOrderStatus::Cancelled, $waiting->refresh()->status);
        $this->assertSame(CancelledBy::Member, $waiting->cancelled_by);
    }

    public function test_member_cannot_cancel_an_order_past_pending(): void
    {
        $order = KitchenOrder::factory()->create(['user_id' => $this->member->id, 'status' => KitchenOrderStatus::Preparing]);

        try {
            $this->cancel($order);
            $this->fail('Expected a 409.');
        } catch (KitchenOrderException $e) {
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(KitchenOrderStatus::Preparing, $order->refresh()->status);
    }

    public function test_kitchen_rejecting_a_card_paid_order_returns_points_not_money(): void
    {
        $wallet = $this->personalWallet();
        $this->officeStaffMember();
        $this->cardOpens();
        $this->send(['method' => 'card'])->assertCreated();
        $card = Payment::query()->where('purpose', PaymentPurpose::KitchenOrder)->sole();
        $card->forceFill(['status' => PaymentStatus::Accepted])->save();
        app(PaymentService::class)->fulfilAcceptedGatewayPayment($card->refresh());
        $order = KitchenOrder::query()->sole();
        $this->assertSame(KitchenOrderStatus::Pending, $order->status);
        $this->assertSame(0, $this->balance($wallet));

        Http::fake();
        app(KitchenOrderService::class)->reject($order, $this->staff(), 'Out of cola');

        $this->assertSame(KitchenOrderStatus::Rejected, $order->refresh()->status);
        $this->assertSame(40, $this->balance($wallet));
        $this->assertNotNull($card->refresh()->refunded_at);
        Http::assertNothingSent();
    }

    public function test_company_wallet_payment_returns_to_the_company(): void
    {
        $company = $this->officeStaffMember();
        $companyWallet = Wallet::query()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        app(WalletService::class)->creditGeneral($companyWallet, 500, WalletTransactionSource::TopUp);
        $personal = $this->personalWallet();

        $this->send(['method' => 'wallet', 'wallet_owner_type' => 'company', 'wallet_owner_id' => $company->id])->assertCreated();
        $order = KitchenOrder::query()->sole();
        $this->assertSame(460, $this->balance($companyWallet));

        app(KitchenOrderService::class)->reject($order, $this->staff(), 'Closed');

        $this->assertSame(500, $this->balance($companyWallet));
        $this->assertSame(0, $this->balance($personal));
    }

    public function test_bill_closing_refunds_a_prepaid_pending_order(): void
    {
        $wallet = $this->personalWallet(100);
        $order = $this->walletPaidOrder();
        $session = WalkinSession::query()->sole();
        $this->assertSame($session->id, (int) $order->billable_id);
        $this->assertSame(60, $this->balance($wallet));

        DB::transaction(fn () => app(KitchenOrderService::class)->cancelPendingFor($session));

        $order->refresh();
        $this->assertSame(KitchenOrderStatus::Cancelled, $order->status);
        $this->assertSame(CancelledBy::System, $order->cancelled_by);
        $this->assertSame(100, $this->balance($wallet));
    }

    public function test_bill_closing_takes_orders_awaiting_reception_and_payment_and_lets_go_of_the_card(): void
    {
        $this->personalWallet(100);
        $session = $this->walkin();
        $this->cardOpens();
        $held = $this->send(['method' => 'card'], ['delivery' => ['type' => 'pickup']])->assertCreated()->assertJsonPath('data.status', 'awaiting_payment')->json('data.id');
        $card = Payment::query()->where('payment_id', 'pay-1')->sole();
        $waiting = KitchenOrder::factory()->create([
            'user_id' => $this->member->id,
            'billable_type' => $session->getMorphClass(),
            'billable_id' => $session->id,
            'status' => KitchenOrderStatus::AwaitingReception,
        ]);
        $this->assertSame($session->id, (int) KitchenOrder::query()->findOrFail($held)->billable_id);

        DB::transaction(function () use ($session) {
            app(KitchenOrderService::class)->cancelPendingFor($session);

            // No gateway call inside the transaction.
            Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/cancel-payment'));
        });

        $this->assertSame(KitchenOrderStatus::Cancelled, KitchenOrder::query()->findOrFail($held)->status);
        $this->assertSame(KitchenOrderStatus::Cancelled, $waiting->refresh()->status);
        $this->assertSame(PaymentStatus::Canceled, $card->refresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/cancel-payment') && $request['payment_id'] === 'pay-1');
    }

    public function test_cash_order_rejected_is_owed_in_cash_not_points(): void
    {
        $wallet = $this->personalWallet();
        $order = KitchenOrder::factory()->create([
            'user_id' => $this->member->id,
            'status' => KitchenOrderStatus::AwaitingReception,
            'total_points' => 40,
        ]);
        app(PaymentService::class)->settleAtReception(PaymentPurpose::KitchenOrder, $order, PaymentMethod::Cash, null, null, $this->staff());
        $payment = Payment::query()->forPayable($order)->sole();
        $this->assertSame(PaymentMethod::Cash, $payment->collected_via);
        $before = $this->balance($wallet);

        $order->forceFill(['status' => KitchenOrderStatus::Pending])->save();
        app(KitchenOrderService::class)->reject($order, $this->staff(), 'Closed');

        $order->refresh();
        $this->assertSame(CashReturn::Owed, $order->cash_return);
        $this->assertSame($before, $this->balance($wallet));
        $this->assertNull($payment->refresh()->refunded_at);
    }

    public function test_refunding_twice_changes_nothing(): void
    {
        $wallet = $this->personalWallet(100);
        $order = $this->walletPaidOrder();
        $refunder = app(KitchenOrderRefunder::class);

        DB::transaction(fn () => $refunder->refund($order, 'once'));
        $this->assertSame(100, $this->balance($wallet));
        DB::transaction(fn () => $refunder->refund($order, 'twice'));
        $this->assertSame(100, $this->balance($wallet));

        $cash = KitchenOrder::factory()->create(['user_id' => $this->member->id, 'status' => KitchenOrderStatus::Pending, 'total_points' => 40]);
        app(PaymentService::class)->settleAtReception(PaymentPurpose::KitchenOrder, $cash, PaymentMethod::Cash, null, null, $this->staff());
        DB::transaction(fn () => $refunder->refund($cash, 'once'));
        $cash->forceFill(['cash_return' => CashReturn::ReturnedInCash])->save();
        DB::transaction(fn () => $refunder->refund($cash, 'twice'));

        $this->assertSame(CashReturn::ReturnedInCash, $cash->refresh()->cash_return);
    }

    public function test_an_order_with_no_payment_refunds_nothing(): void
    {
        $order = KitchenOrder::factory()->create(['user_id' => $this->member->id, 'status' => KitchenOrderStatus::Pending]);

        DB::transaction(fn () => app(KitchenOrderRefunder::class)->refund($order, 'nothing'));

        $this->assertNull($order->refresh()->cash_return);
    }

    public function test_an_accepted_order_is_never_refunded(): void
    {
        $wallet = $this->personalWallet(100);
        $order = $this->walletPaidOrder();
        app(KitchenOrderService::class)->accept($order, $this->staff());

        try {
            app(KitchenOrderService::class)->reject($order, $this->staff(), 'Too late');
            $this->fail('Expected a 409.');
        } catch (KitchenOrderException $e) {
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(60, $this->balance($wallet));
        $this->assertNull(Payment::query()->forPayable($order)->sole()->refunded_at);
    }
}
