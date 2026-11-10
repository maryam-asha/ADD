<?php

namespace Tests\Feature\Kitchen;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Exceptions\InsufficientPointsException;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Services\KitchenOrderSettlement;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An accepted order is owed beside its session or booking but paid as its
 * own payable (spec decision 11): from the member's general points, never
 * the points granted for a space, and recorded as a kitchen sale.
 */
class KitchenOrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->operator = User::factory()->create();
        $this->operator->assignRole('operations');
        Sanctum::actingAs($this->operator, ['*']);

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
    }

    private function wallet(): Wallet
    {
        return Wallet::where('owner_type', OwnerType::User)->where('owner_id', $this->member->id)->firstOrFail();
    }

    private function credit(int $general, int $spaceSpecific = 0): void
    {
        $wallets = app(WalletService::class);

        if ($general > 0) {
            $wallets->creditGeneral($this->wallet(), $general, WalletTransactionSource::TopUp, 'test');
        }

        if ($spaceSpecific > 0) {
            $wallets->creditCategorized($this->wallet(), WalletTransactionCategory::SpaceSpecific, $spaceSpecific, WalletTransactionSource::SubscriptionGrant, now()->addMonth(), null, [], 'grant');
        }
    }

    /** @return array{general: int, space_specific: int} */
    private function balances(): array
    {
        $balances = app(WalletService::class)->balances($this->wallet(), $this->member);

        return [
            'general' => $balances[WalletTransactionCategory::General->value],
            'space_specific' => $balances[WalletTransactionCategory::SpaceSpecific->value],
        ];
    }

    private function walkIn(bool $closed = false): WalkinSession
    {
        return WalkinSession::factory()->create([
            'user_id' => $this->member->id,
            'checked_out_at' => $closed ? now()->subMinutes(5) : null,
        ]);
    }

    private function order(WalkinSession|Booking $bill, int $points = 40, KitchenOrderStatus $status = KitchenOrderStatus::Preparing): KitchenOrder
    {
        return KitchenOrder::factory()->create([
            'user_id' => $this->member->id,
            'billable_type' => $bill->getMorphClass(),
            'billable_id' => $bill->id,
            'status' => $status,
            'total_points' => $points,
            'accepted_at' => $status->isOwed() ? now() : null,
        ]);
    }

    private function payFromWallet(KitchenOrder $order): void
    {
        app(PaymentService::class)->start(PaymentPurpose::KitchenOrder, $this->member, WalletMethod::KEY, [
            'payable' => $order,
            'wallet_owner_type' => OwnerType::User,
            'wallet_owner_id' => $this->member->id,
        ]);
    }

    public function test_wallet_payment_spends_general_not_space_specific(): void
    {
        $this->credit(general: 50, spaceSpecific: 100);
        $order = $this->order($this->walkIn(), 40);

        $this->payFromWallet($order);

        $this->assertSame(['general' => 10, 'space_specific' => 100], $this->balances());
        $this->assertNotNull($order->refresh()->paid_at);

        $payment = Payment::sole();
        $this->assertSame(PaymentPurpose::KitchenOrder, $payment->purpose);
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertSame(40, (int) $payment->amount_points);
        $this->assertSame('kitchen_order', $payment->payable_type);
        $this->assertSame($order->id, (int) $payment->payable_id);
    }

    public function test_a_short_general_balance_is_not_made_up_from_space_specific_points(): void
    {
        $this->credit(general: 10, spaceSpecific: 100);
        $order = $this->order($this->walkIn(), 40);

        try {
            $this->payFromWallet($order);
            $this->fail('A 40-point order was paid from 10 general points.');
        } catch (InsufficientPointsException) {
            // Refused: the space's points never pay for the kitchen.
        }

        $this->assertSame(['general' => 10, 'space_specific' => 100], $this->balances());
        $this->assertNull($order->refresh()->paid_at);
        $this->assertSame(0, Payment::query()->where('status', PaymentStatus::Accepted)->count());
    }

    public function test_paying_twice_is_409(): void
    {
        $this->credit(general: 100);
        $order = $this->order($this->walkIn(), 40);

        $this->payFromWallet($order);

        try {
            $this->payFromWallet($order);
            $this->fail('An order was paid twice.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.payment.already_paid', $e->messageKey);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(60, $this->balances()['general']);

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/payments/settle', [
            'purpose' => 'kitchen_order',
            'payable_id' => $order->id,
            'collected_via' => 'cash',
        ])->assertStatus(409)->assertJsonPath('message', 'This has already been paid.');
    }

    public function test_desk_settles_an_order_by_purpose_and_id(): void
    {
        $order = $this->order($this->walkIn(closed: true), 40);

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/payments/settle', [
            'purpose' => 'kitchen_order',
            'payable_id' => $order->id,
            'collected_via' => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('data.points_credited', 40)
            ->assertJsonPath('data.points_charged', 40)
            ->assertJsonPath('data.change_points', 0)
            ->assertJsonPath('data.payment.method', 'cash');

        $this->assertNotNull($order->refresh()->paid_at);

        $payment = Payment::sole();
        $this->assertSame(PaymentPurpose::KitchenOrder, $payment->purpose);
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertSame($this->operator->id, $payment->received_by);
        $this->assertSame(0, $this->balances()['general']);
    }

    public function test_outstanding_items_list_orders_of_closed_bills_only(): void
    {
        $closed = $this->walkIn(closed: true);
        $owed = $this->order($closed, 40);
        // Not on the desk's list: the bill is still open, already paid,
        // never accepted, someone else's.
        $this->order($this->walkIn(), 30);
        $this->order($closed, 25)->forceFill(['paid_at' => now()])->save();
        $this->order($closed, 15, KitchenOrderStatus::Pending);
        $this->order($closed, 15, KitchenOrderStatus::Rejected);
        $theirs = WalkinSession::factory()->create(['checked_out_at' => now()->subMinutes(5)]);
        KitchenOrder::factory()->create([
            'user_id' => $theirs->user_id,
            'billable_type' => $theirs->getMorphClass(),
            'billable_id' => $theirs->id,
            'status' => KitchenOrderStatus::Delivered,
            'total_points' => 50,
        ]);

        $booking = Booking::factory()->create([
            'user_id' => $this->member->id,
            'checked_in_at' => now()->subHours(2),
            'checked_out_at' => now()->subHour(),
            'price_points' => 0,
            'payment_state' => PaymentState::Paid,
        ]);
        $onBooking = $this->order($booking, 20, KitchenOrderStatus::Delivered);

        $items = collect($this->getJson("/api/v1/admin/reception/outstanding?user_id={$this->member->id}")
            ->assertOk()
            ->json('data.items'))
            ->where('purpose', 'kitchen_order')
            ->values();

        $this->assertSame([[$owed->id, 40], [$onBooking->id, 20]], $items->map(fn (array $i) => [$i['payable_id'], $i['amount_points']])->all());
        $this->assertSame($this->member->id, $items[0]['member']['id']);
        $this->assertArrayHasKey('title', $items[0]);
        $this->assertArrayHasKey('starts_at', $items[0]);
        $this->assertArrayHasKey('amount', $items[0]);
    }

    public function test_pending_or_rejected_orders_are_not_payable(): void
    {
        $this->credit(general: 100);
        $bill = $this->walkIn(closed: true);

        foreach ([KitchenOrderStatus::Pending, KitchenOrderStatus::Rejected, KitchenOrderStatus::Cancelled] as $status) {
            $order = $this->order($bill, 40, $status);

            try {
                $this->payFromWallet($order);
                $this->fail("A {$status->value} order was paid.");
            } catch (ReceptionActionException $e) {
                $this->assertSame('api.kitchen_order.not_payable', $e->messageKey);
            }

            $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/payments/settle', [
                'purpose' => 'kitchen_order',
                'payable_id' => $order->id,
                'collected_via' => 'cash',
            ])->assertStatus(422)->assertJsonPath('message', 'Only an order the kitchen has accepted can be paid.');

            $this->assertNull($order->refresh()->paid_at);
        }

        $this->assertSame(100, $this->balances()['general']);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_settlement_helper_finds_and_settles_a_bills_unpaid_orders(): void
    {
        $bill = $this->walkIn(closed: true);
        $second = $this->order($bill, 30);
        $second->forceFill(['created_at' => now()->subMinute()])->save();
        $first = $this->order($bill, 20);
        $first->forceFill(['created_at' => now()->subMinutes(10)])->save();
        $this->order($bill, 15, KitchenOrderStatus::Pending);
        $this->order($bill, 10)->forceFill(['paid_at' => now()])->save();
        $this->order($this->walkIn(closed: true), 99);

        $settlement = app(KitchenOrderSettlement::class);

        $this->assertSame([$first->id, $second->id], $settlement->unpaidFor($bill)->pluck('id')->all());

        $settlement->settleAtReception($bill, PaymentMethod::Cash, $this->operator);

        $this->assertNotNull($first->refresh()->paid_at);
        $this->assertNotNull($second->refresh()->paid_at);
        $this->assertTrue($settlement->unpaidFor($bill)->isEmpty());
        $this->assertSame(2, Payment::query()->where('purpose', PaymentPurpose::KitchenOrder)->where('status', PaymentStatus::Accepted)->count());
    }

    public function test_paying_from_the_wallet_pays_what_it_covers_and_leaves_the_rest_owed(): void
    {
        $this->credit(general: 30, spaceSpecific: 100);
        $bill = $this->walkIn(closed: true);
        $first = $this->order($bill, 20);
        $first->forceFill(['created_at' => now()->subMinutes(10)])->save();
        $second = $this->order($bill, 20);

        app(KitchenOrderSettlement::class)->payFromWallet($bill);

        $this->assertNotNull($first->refresh()->paid_at);
        $this->assertNull($second->refresh()->paid_at);
        $this->assertSame(['general' => 10, 'space_specific' => 100], $this->balances());
        $this->assertSame([$second->id], app(KitchenOrderSettlement::class)->unpaidFor($bill)->pluck('id')->all());
    }
}
