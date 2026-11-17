<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderRoute;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET member/payment-methods for something the member is about to pay
 * for: the amount, and each enabled method that can pay for it, with
 * whether it can right now and why not. (Without a purpose, and the wallet
 * balance: PaymentMethodsWalletBalanceTest.)
 * (docs/superpowers/plans/2026-09-30-unified-payments.md, Task 10).
 */
class MemberPaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function unpaidBooking(User $member, int $points = 100): Booking
    {
        return Booking::factory()->create([
            'user_id' => $member->id,
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            'price_points' => $points,
        ]);
    }

    private function methods(array $query)
    {
        return $this->withHeader('lang', 'en')->getJson('/api/v1/member/payment-methods?'.http_build_query($query));
    }

    public function test_a_booking_lists_the_wallet_with_its_balance_then_cash_then_card(): void
    {
        $member = $this->member(300);
        $booking = $this->unpaidBooking($member);

        $response = $this->methods(['purpose' => 'booking', 'payable_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('data.amount_points', 100)
            ->assertJsonPath('data.methods.0.key', 'wallet')
            ->assertJsonPath('data.methods.0.label', __('api.payment.methods.wallet', [], 'en'))
            ->assertJsonPath('data.methods.0.available', true)
            ->assertJsonPath('data.methods.0.reason', null)
            ->assertJsonPath('data.methods.0.wallets.0.owner_type', 'user')
            ->assertJsonPath('data.methods.0.wallets.0.usable_balance', 300)
            ->assertJsonPath('data.methods.0.wallets.0.sufficient', true)
            ->assertJsonPath('data.methods.1.key', 'cash')
            ->assertJsonPath('data.methods.1.available', true)
            ->assertJsonPath('data.methods.2.key', 'paymera')
            ->assertJsonPath('data.methods.2.available', true);

        $this->assertCount(3, $response->json('data.methods'));
        $this->assertNotNull($response->json('data.price'));
        $this->assertStringContainsString('100', $response->json('data.price_point'));
        // Only the wallet method carries wallets.
        $this->assertArrayNotHasKey('wallets', $response->json('data.methods.1'));
    }

    public function test_a_wallet_that_cannot_cover_it_is_shown_but_unavailable_with_a_reason(): void
    {
        $member = $this->member(30);
        $booking = $this->unpaidBooking($member);

        $this->methods(['purpose' => 'booking', 'payable_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('data.methods.0.key', 'wallet')
            ->assertJsonPath('data.methods.0.available', false)
            ->assertJsonPath('data.methods.0.reason', __('api.payment.insufficient_points', ['current' => 30, 'required' => 100, 'shortage' => 70], 'en'))
            ->assertJsonPath('data.methods.0.wallets.0.sufficient', false);
    }

    public function test_every_wallet_the_member_may_spend_is_listed(): void
    {
        $member = $this->member(50);
        $company = Company::factory()->create();
        $member->companies()->attach($company->id);
        $companyWallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        app(WalletService::class)->creditGeneral($companyWallet, 500, WalletTransactionSource::TopUp);
        $booking = $this->unpaidBooking($member);

        $wallets = $this->methods(['purpose' => 'booking', 'payable_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('data.methods.0.available', true)
            ->json('data.methods.0.wallets');

        $this->assertSame(['user', 'company'], array_column($wallets, 'owner_type'));
        $this->assertSame([false, true], array_column($wallets, 'sufficient'));
    }

    public function test_a_disabled_method_is_absent(): void
    {
        config(['payments.methods.paymera.enabled' => false]);
        $member = $this->member(300);
        $booking = $this->unpaidBooking($member);

        $keys = array_column($this->methods(['purpose' => 'booking', 'payable_id' => $booking->id])->json('data.methods'), 'key');

        $this->assertSame(['wallet', 'cash'], $keys);
    }

    public function test_a_top_up_lists_the_card_only_and_no_amount(): void
    {
        $this->member();

        $response = $this->methods(['purpose' => 'wallet_top_up'])
            ->assertOk()
            ->assertJsonPath('data.amount_points', null)
            ->assertJsonPath('data.price', null)
            ->assertJsonPath('data.methods.0.key', 'cash')
            ->assertJsonPath('data.methods.0.available', false)
            ->assertJsonPath('data.methods.0.reason', __('api.payment.reception_only'))
            ->assertJsonPath('data.methods.0.hint', __('api.payment.reception_only_hints.wallet_top_up'))
            ->assertJsonPath('data.methods.1.key', 'paymera')
            ->assertJsonPath('data.methods.1.available', true)
            ->assertJsonPath('data.methods.1.hint', null);

        // Points cannot buy points; cash for points is reception's, so the
        // app shows it only to send the member to the desk.
        $this->assertSame(['cash', 'paymera'], array_column($response->json('data.methods'), 'key'));
    }

    public function test_a_plan_is_priced_from_the_plan(): void
    {
        $this->member(5000);
        $plan = Plan::factory()->create([
            'price_points' => 1200,
            'is_active' => true,
            'is_public' => true,
            'is_subscription' => true,
        ]);

        $response = $this->methods(['purpose' => 'plan_purchase', 'plan_id' => $plan->id])
            ->assertOk()
            ->assertJsonPath('data.amount_points', 1200)
            ->assertJsonPath('data.methods.1.available', true);

        // Points or a card: there is no subscription without payment, so cash
        // is shown only to send the member to reception — and, unavailable,
        // first.
        $this->assertSame(['cash', 'wallet', 'paymera'], array_column($response->json('data.methods'), 'key'));
        $response->assertJsonPath('data.methods.0.available', false)
            ->assertJsonPath('data.methods.0.hint', __('api.payment.reception_only_hints.plan_purchase'));
        // A plan bought here is the member's own: only their wallet pays.
        $this->assertSame(['user'], array_column($response->json('data.methods.1.wallets'), 'owner_type'));
    }

    public function test_a_gateway_added_in_config_appears_with_nothing_else_changed(): void
    {
        config(['payments.methods.probe' => ['driver' => ProbeGateway::class, 'enabled' => true]]);
        $member = $this->member(300);
        $booking = $this->unpaidBooking($member);

        $keys = array_column($this->methods(['purpose' => 'booking', 'payable_id' => $booking->id])->json('data.methods'), 'key');

        $this->assertSame(['wallet', 'cash', 'paymera', 'probe'], $keys);
    }

    public function test_someone_elses_booking_is_not_found(): void
    {
        $booking = $this->unpaidBooking(User::factory()->create());
        $this->member(300);

        $this->methods(['purpose' => 'booking', 'payable_id' => $booking->id])->assertNotFound();
    }

    public function test_a_paid_booking_has_nothing_to_pay(): void
    {
        $member = $this->member(300);
        $booking = $this->unpaidBooking($member);
        $booking->forceFill(['payment_state' => PaymentState::Paid, 'payment_source' => PaymentSource::Cash])->save();

        $this->methods(['purpose' => 'booking', 'payable_id' => $booking->id])
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.payment.already_paid', [], 'en'));
    }

    /**
     * A session still running is settled at reception when it ends: cash is
     * shown unavailable, telling the member to settle at the desk.
     */
    public function test_cash_is_unavailable_while_the_booking_is_in_progress(): void
    {
        $member = $this->member(300);
        $booking = $this->unpaidBooking($member);
        $booking->forceFill(['checked_in_at' => now()->subMinutes(30), 'checked_out_at' => null])->save();

        $cash = collect($this->methods(['purpose' => 'booking', 'payable_id' => $booking->id])
            ->assertOk()->json('data.methods'))->firstWhere('key', 'cash');

        $this->assertFalse($cash['available']);
        $this->assertSame(__('api.payment.session_in_progress', [], 'en'), $cash['reason']);
        $this->assertSame(__('api.payment.session_in_progress_hint', [], 'en'), $cash['hint']);
    }

    public function test_cash_stays_available_once_the_booking_has_ended_or_before_it_starts(): void
    {
        $member = $this->member(300);
        $upcoming = $this->unpaidBooking($member);
        $ended = $this->unpaidBooking($member);
        $ended->forceFill(['checked_in_at' => now()->subHour(), 'checked_out_at' => now()->subMinute()])->save();

        foreach ([$upcoming, $ended] as $booking) {
            $cash = collect($this->methods(['purpose' => 'booking', 'payable_id' => $booking->id])
                ->assertOk()->json('data.methods'))->firstWhere('key', 'cash');

            $this->assertTrue($cash['available']);
            $this->assertNull($cash['hint']);
        }
    }

    private function kitchenOrder(User $member, array $state = []): KitchenOrder
    {
        return KitchenOrder::factory()->create([
            'user_id' => $member->id,
            'total_points' => 40,
            ...$state,
        ]);
    }

    public function test_a_kitchen_order_is_priced_from_its_total(): void
    {
        $member = $this->member(300);
        $order = $this->kitchenOrder($member, ['status' => KitchenOrderStatus::Preparing]);

        $response = $this->methods(['purpose' => 'kitchen_order', 'payable_id' => $order->id])
            ->assertOk()
            ->assertJsonPath('data.amount_points', 40)
            ->assertJsonPath('data.methods.0.key', 'wallet')
            ->assertJsonPath('data.methods.0.available', true)
            ->assertJsonPath('data.methods.0.wallets.0.usable_balance', 300);

        $this->assertSame(['wallet', 'cash', 'paymera'], array_column($response->json('data.methods'), 'key'));
    }

    public function test_a_kitchen_order_ignores_a_coupon(): void
    {
        $member = $this->member(300);
        $order = $this->kitchenOrder($member, ['status' => KitchenOrderStatus::Preparing]);

        $this->methods(['purpose' => 'kitchen_order', 'payable_id' => $order->id, 'coupon_code' => 'ANY'])
            ->assertOk()
            ->assertJsonPath('data.amount_points', 40);
    }

    public function test_cash_is_unavailable_for_an_order_held_for_its_card_on_the_kitchen_route(): void
    {
        $member = $this->member(300);
        $held = ['status' => KitchenOrderStatus::AwaitingPayment, 'payment_due_at' => now()->addMinutes(10)];
        $kitchen = $this->kitchenOrder($member, $held + ['route' => OrderRoute::Kitchen]);
        $reception = $this->kitchenOrder($member, $held + ['route' => OrderRoute::Reception]);

        $cash = fn (KitchenOrder $order) => collect($this->methods(['purpose' => 'kitchen_order', 'payable_id' => $order->id])
            ->assertOk()->json('data.methods'))->firstWhere('key', 'cash');

        $this->assertFalse($cash($kitchen)['available']);
        $this->assertSame(__('api.payment.kitchen_order_no_cash', [], 'en'), $cash($kitchen)['reason']);
        $this->assertTrue($cash($reception)['available']);
    }

    public function test_an_order_held_for_its_card_may_go_on_its_open_bill(): void
    {
        $member = $this->member(300);
        $order = $this->kitchenOrder($member, ['status' => KitchenOrderStatus::AwaitingPayment, 'payment_due_at' => now()->addMinutes(10)]);
        $order->billable->forceFill(['user_id' => $member->id, 'checked_out_at' => null])->save();

        $response = $this->methods(['purpose' => 'kitchen_order', 'payable_id' => $order->id])->assertOk();
        $bill = collect($response->json('data.methods'))->firstWhere('key', 'bill');

        $this->assertNotNull($bill);
        $this->assertTrue($bill['available']);
        $this->assertSame(__('api.payment.methods.bill', [], 'en'), $bill['label']);
        // Cash is the one unavailable (kitchen route), so it leads; the bill heads the rest.
        $this->assertSame(['cash', 'bill', 'wallet', 'paymera'], array_column($response->json('data.methods'), 'key'));
    }

    public function test_no_bill_once_it_is_closed_or_when_the_order_is_not_held(): void
    {
        $member = $this->member(300);
        $closed = $this->kitchenOrder($member, ['status' => KitchenOrderStatus::AwaitingPayment, 'payment_due_at' => now()->addMinutes(10)]);
        $closed->billable->forceFill(['checked_out_at' => now()->subMinute()])->save();
        $owed = $this->kitchenOrder($member, ['status' => KitchenOrderStatus::Preparing]);
        $noBill = KitchenOrder::factory()->withoutBill()->create([
            'user_id' => $member->id,
            'total_points' => 40,
            'status' => KitchenOrderStatus::AwaitingPayment,
            'payment_due_at' => now()->addMinutes(10),
        ]);

        foreach ([$closed, $owed, $noBill] as $order) {
            $keys = array_column($this->methods(['purpose' => 'kitchen_order', 'payable_id' => $order->id])->assertOk()->json('data.methods'), 'key');

            $this->assertNotContains('bill', $keys);
        }
    }

    public function test_no_bill_for_any_other_purpose(): void
    {
        $member = $this->member(300);
        $booking = $this->unpaidBooking($member);

        $keys = array_column($this->methods(['purpose' => 'booking', 'payable_id' => $booking->id])->json('data.methods'), 'key');

        $this->assertNotContains('bill', $keys);
    }

    public function test_someone_elses_kitchen_order_is_not_found(): void
    {
        $order = $this->kitchenOrder(User::factory()->create(), ['status' => KitchenOrderStatus::Preparing]);
        $this->member(300);

        $this->methods(['purpose' => 'kitchen_order', 'payable_id' => $order->id])->assertNotFound();
    }

    /**
     * Nothing is required: without a purpose every enabled method is listed,
     * and a plan or a record named without its id is simply not priced.
     */
    public function test_nothing_is_required(): void
    {
        $this->member(300);

        $this->methods([])->assertOk()->assertJsonPath('data.amount_points', null);
        $this->methods(['purpose' => 'booking'])->assertOk()->assertJsonPath('data.amount_points', null);
        $this->methods(['purpose' => 'plan_purchase'])->assertOk()->assertJsonPath('data.amount_points', null);
        $this->methods(['purpose' => 'not_a_purpose'])->assertStatus(422)->assertJsonValidationErrors('purpose');
    }
}

/**
 * A second gateway, registered only through config, to prove the endpoint
 * needs no change to show it.
 */
class ProbeGateway extends PaymeraGateway
{
    public function key(): string
    {
        return 'probe';
    }

    public function label(): string
    {
        return 'Probe';
    }
}
