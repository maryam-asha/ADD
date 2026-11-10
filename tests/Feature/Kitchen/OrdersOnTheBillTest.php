<?php

namespace Tests\Feature\Kitchen;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Events\PendingPaymentsChanged;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\CurrentBookingLocator;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Booking\Services\SessionReportBuilder;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\OutstandingItems;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * Accepted orders are owed beside the session or booking they were sent
 * from, as their own payables (kitchen orders spec, decision 11 and "The
 * bill"): quoted, settled, reported and shown as owing with it — and the
 * bill's own price_points, amount_owed, pointsPaid and payment_state are
 * never changed by an order.
 */
class OrdersOnTheBillTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow($this->at('10:00'));
        $this->space = $this->coworkingSpace(hourlyRatePoints: 100);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse("2026-08-17 {$time}:00", 'Asia/Damascus')->utc();
    }

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return $member;
    }

    private function balanceOf(User $member): int
    {
        return app(WalletService::class)->generalBalance(
            Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail()
        );
    }

    /** A walk-in on the coworking space since 08:00 — 200 points by 10:00. */
    private function walkIn(User $member, array $attributes = []): WalkinSession
    {
        return WalkinSession::factory()->create([
            'space_id' => $this->space->id,
            'user_id' => $member->id,
            'checked_in_at' => $this->at('08:00'),
        ] + $attributes);
    }

    private function roomSpace(): Space
    {
        $space = Space::factory()->create(['space_type' => SpaceType::Room, 'hourly_rate_points' => 100]);

        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $space->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }

        return $space;
    }

    /** A room 08:00–10:00 at 200 points, checked in at 08:00, paid in full from the wallet when it was booked. */
    private function paidRoom(User $member): Booking
    {
        $booking = Booking::factory()->create([
            'space_id' => $this->roomSpace()->id,
            'user_id' => $member->id,
            'start_at' => $this->at('08:00'),
            'end_at' => $this->at('10:00'),
            'checked_in_at' => $this->at('08:00'),
            'price_points' => 200,
            'amount_owed' => '20.00',
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
            'paid_at' => $this->at('07:00'),
            'chosen_payment_method' => 'wallet',
        ]);

        Payment::factory()->forPayable($booking)->create([
            'purpose' => PaymentPurpose::Booking,
            'status' => PaymentStatus::Accepted,
            'settled_at' => $this->at('07:00'),
            'amount_points' => 200,
            'user_id' => $member->id,
        ]);

        return $booking;
    }

    private function order(Booking|WalkinSession $bill, int $points, KitchenOrderStatus $status = KitchenOrderStatus::Preparing, bool $paid = false): KitchenOrder
    {
        return KitchenOrder::factory()->create([
            'user_id' => $bill->user_id,
            'billable_type' => $bill->getMorphClass(),
            'billable_id' => $bill->id,
            'status' => $status,
            'total_points' => $points,
            'accepted_at' => $status->isOwed() ? now() : null,
            'paid_at' => $paid ? now() : null,
        ]);
    }

    private function closures(): SessionClosureService
    {
        return app(SessionClosureService::class);
    }

    private function orderPayments(): int
    {
        return Payment::query()->where('purpose', PaymentPurpose::KitchenOrder)->where('status', PaymentStatus::Accepted)->count();
    }

    public function test_desk_settlement_pays_session_and_its_orders(): void
    {
        $member = $this->member();
        $session = $this->walkIn($member);
        $first = $this->order($session, 30);
        $second = $this->order($session, 20);
        $pending = $this->order($session, 99, KitchenOrderStatus::Pending);
        $this->closures()->closeOut($session, $this->at('10:00'));

        $operator = $this->actingAsOperations();
        $this->withHeader('lang', 'en')
            ->postJson("/api/v1/admin/reception/walk-ins/{$session->id}/settle-payment", ['payment_method' => 'cash'])
            ->assertOk();

        $session->refresh();
        $this->assertSame(PaymentState::Paid, $session->payment_state);
        $this->assertSame(200, (int) $session->price_points);
        $this->assertSame(200, (int) Payment::query()->forPayable($session)->sole()->amount_points);

        $this->assertNotNull($first->refresh()->paid_at);
        $this->assertNotNull($second->refresh()->paid_at);
        $this->assertNull($pending->refresh()->paid_at);

        $payments = Payment::query()->where('purpose', PaymentPurpose::KitchenOrder)->orderBy('id')->get();
        $this->assertSame([[$first->id, 30], [$second->id, 20]], $payments->map(fn (Payment $p) => [(int) $p->payable_id, (int) $p->amount_points])->all());
        $this->assertTrue($payments->every(fn (Payment $p) => $p->status === PaymentStatus::Accepted && $p->received_by === $operator->id && $p->method === 'cash'));
    }

    public function test_desk_settlement_is_all_or_nothing(): void
    {
        $member = $this->member();
        $session = $this->walkIn($member);
        $first = $this->order($session, 30);
        $second = $this->order($session, 20);
        $this->closures()->closeOut($session, $this->at('10:00'));

        // The second order's payment is refused, after the session and the
        // first order went through.
        $real = app(PaymentService::class);
        $calls = 0;
        $payments = Mockery::mock(PaymentService::class);
        $payments->shouldReceive('settleAtReception')->andReturnUsing(function (...$args) use ($real, &$calls) {
            if (++$calls === 3) {
                throw new RuntimeException('refused');
            }

            return $real->settleAtReception(...$args);
        });
        $this->app->instance(PaymentService::class, $payments);

        $refused = null;

        try {
            $this->closures()->settlePayment($session, PaymentMethod::Cash, User::factory()->create());
        } catch (RuntimeException $e) {
            $refused = $e->getMessage();
        }

        $this->assertSame('refused', $refused);

        $this->assertSame(3, $calls);
        $this->assertSame(PaymentState::Unpaid, $session->refresh()->payment_state);
        $this->assertNull($first->refresh()->paid_at);
        $this->assertNull($second->refresh()->paid_at);
        $this->assertSame(0, Payment::query()->where('status', PaymentStatus::Accepted)->count());
    }

    public function test_checkout_quote_shows_orders_separately(): void
    {
        $member = $this->actingAsMember();
        $session = $this->walkIn($member);
        $without = $this->closures()->quote($session, now());

        $this->order($session, 30);
        $this->order($session, 20, KitchenOrderStatus::Delivered);
        $this->order($session, 15, KitchenOrderStatus::Ready, paid: true);
        $this->order($session, 99, KitchenOrderStatus::Pending);
        $this->order($session, 77, KitchenOrderStatus::Rejected);

        $quote = $this->closures()->quote($session, now());

        $this->assertSame(200, $quote['points']);
        $this->assertSame($without['points'], $quote['points']);
        $this->assertSame(50, $quote['orders_points']);

        $this->withHeader('lang', 'en')->getJson('/api/v1/member/coworking-sessions/current/checkout')
            ->assertOk()
            ->assertJsonPath('data.closing.points', 200)
            ->assertJsonPath('data.closing.orders_points', 50)
            ->assertJsonPath('data.closing.total_points', 250)
            ->assertJsonPath('data.orders', ['count' => 3, 'total_points' => 65, 'unpaid_points' => 50]);

        // The desk sees the same two figures before it ends the session.
        $this->actingAsOperations();
        $this->withHeader('lang', 'en')->getJson("/api/v1/admin/reception/sessions/walkin/{$session->id}/report")
            ->assertOk()
            ->assertJsonPath('data.closing.points', 200)
            ->assertJsonPath('data.closing.orders_points', 50);
    }

    public function test_report_lists_orders_as_order_lines(): void
    {
        $member = $this->member();
        $session = $this->walkIn($member);
        $unpaid = $this->order($session, 30);
        $paid = $this->order($session, 20, KitchenOrderStatus::Delivered, paid: true);
        $this->order($session, 99, KitchenOrderStatus::Pending);
        $this->order($session, 77, KitchenOrderStatus::Cancelled);
        $this->closures()->closeOut($session, $this->at('10:00'));

        app()->setLocale('en');
        $lines = collect(app(SessionReportBuilder::class)->build($session->refresh())['lines'])->where('kind', 'order')->values();

        $this->assertSame(
            [
                ["Kitchen order #{$unpaid->id}", 30, false],
                ["Kitchen order #{$paid->id}", 20, true],
            ],
            $lines->map(fn (array $line) => [$line['label'], $line['points'], $line['paid']])->all(),
        );

        // The session's own line is untouched by them.
        $this->assertSame(200, collect(app(SessionReportBuilder::class)->build($session)['lines'])->firstWhere('kind', 'time')['points']);
    }

    public function test_plan_covered_booking_with_unpaid_order_awaits_payment(): void
    {
        $member = $this->member();
        Sanctum::actingAs($member, ['*']);
        $booking = Booking::factory()->create([
            'space_id' => $this->roomSpace()->id,
            'user_id' => $member->id,
            'start_at' => $this->at('08:00'),
            'end_at' => $this->at('09:00'),
            'checked_in_at' => $this->at('08:00'),
            'checked_out_at' => $this->at('09:00'),
            'status' => BookingStatus::Completed,
            'price_points' => 0,
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Plan,
            'paid_at' => $this->at('09:00'),
        ]);
        $locator = app(CurrentBookingLocator::class);

        $this->assertNull($locator->stateOf($booking));

        $order = $this->order($booking, 30);

        $this->assertSame('awaiting_payment', $locator->stateOf($booking));
        $this->withHeader('lang', 'en')->getJson('/api/v1/member/home')
            ->assertOk()
            ->assertJsonPath('data.active_booking.0.id', $booking->id)
            ->assertJsonPath('data.active_booking.0.state', 'awaiting_payment')
            ->assertJsonPath('data.active_booking.0.orders', ['count' => 1, 'total_points' => 30, 'unpaid_points' => 30]);

        $order->forceFill(['paid_at' => now()])->save();

        $this->assertNull($locator->stateOf($booking));
        $this->getJson('/api/v1/member/home')->assertOk()->assertJsonPath('data.active_booking', []);
    }

    public function test_plan_covered_session_with_unpaid_order_is_still_owed_on_the_home_card(): void
    {
        $member = $this->member();
        Sanctum::actingAs($member, ['*']);
        $session = $this->walkIn($member, [
            'checked_out_at' => $this->at('09:00'),
            'price_points' => 0,
            'amount_owed' => '0.00',
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Plan,
        ]);

        $this->getJson('/api/v1/member/home')->assertOk()->assertJsonPath('data.active_session', null);

        $this->order($session, 30);

        $this->withHeader('lang', 'en')->getJson('/api/v1/member/home')
            ->assertOk()
            ->assertJsonPath('data.active_session.state', 'awaiting_payment')
            ->assertJsonPath('data.active_session.id', $session->id)
            ->assertJsonPath('data.active_session.orders', ['count' => 1, 'total_points' => 30, 'unpaid_points' => 30]);

        $this->assertSame([$session->id], app(OutstandingItems::class)->closedFor($member->id)->pluck('id')->all());
    }

    public function test_wallet_paid_booking_close_pays_its_orders(): void
    {
        $member = $this->member(100);
        $booking = $this->paidRoom($member);
        $first = $this->order($booking, 30);
        $second = $this->order($booking, 20);

        $this->closures()->closeOut($booking, $this->at('10:00'));

        $booking->refresh();
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(BookingStatus::Completed, $booking->status);
        $this->assertSame(200, (int) $booking->price_points);
        $this->assertSame(0, bccomp((string) $booking->amount_owed, '20', 2));
        $this->assertSame(200, $booking->pointsPaid());

        $this->assertNotNull($first->refresh()->paid_at);
        $this->assertNotNull($second->refresh()->paid_at);
        $this->assertSame(2, $this->orderPayments());
        $this->assertSame(50, $this->balanceOf($member));
    }

    public function test_wallet_short_leaves_orders_outstanding(): void
    {
        $member = $this->member(10);
        $booking = $this->paidRoom($member);
        $first = $this->order($booking, 30);
        $second = $this->order($booking, 20);

        $this->closures()->closeOut($booking, $this->at('10:00'));

        // The booking closes either way.
        $booking->refresh();
        $this->assertNotNull($booking->checked_out_at);
        $this->assertSame(BookingStatus::Completed, $booking->status);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);

        $this->assertNull($first->refresh()->paid_at);
        $this->assertNull($second->refresh()->paid_at);
        $this->assertSame(0, $this->orderPayments());
        $this->assertSame(10, $this->balanceOf($member));

        // On the desk's outstanding items, each its own payable.
        $items = app(OutstandingItems::class)->for($member->id, 'USD')['items'];
        $this->assertSame(
            [['kitchen_order', $first->id, 30], ['kitchen_order', $second->id, 20]],
            array_map(fn (array $item) => [$item['purpose'], $item['payable_id'], $item['amount_points']], $items),
        );

        // And the member's card still asks for them.
        $this->assertSame('awaiting_payment', app(CurrentBookingLocator::class)->stateOf($booking));
        $this->assertSame([$booking->id], app(OutstandingItems::class)->closedFor($member->id)->pluck('id')->all());
    }

    public function test_orders_never_change_price_points_or_amount_owed(): void
    {
        $withOrders = $this->walkIn($this->member());
        $plain = $this->walkIn($this->member());
        $this->order($withOrders, 30);
        $this->order($withOrders, 20, KitchenOrderStatus::Delivered);

        $this->closures()->closeOut($withOrders, $this->at('10:00'));
        $this->closures()->closeOut($plain, $this->at('10:00'));
        $withOrders->refresh();
        $plain->refresh();

        $this->assertSame(200, (int) $withOrders->price_points);
        $this->assertSame((int) $plain->price_points, (int) $withOrders->price_points);
        $this->assertSame((string) $plain->amount_owed, (string) $withOrders->amount_owed);
        $this->assertSame(PaymentState::Unpaid, $withOrders->payment_state);

        $this->closures()->settlePayment($withOrders, PaymentMethod::Cash, User::factory()->create());
        $withOrders->refresh();

        // Its own payment is exactly its own price; the orders are paid apart.
        $this->assertSame(200, (int) $withOrders->price_points);
        $this->assertSame((string) $plain->amount_owed, (string) $withOrders->amount_owed);
        $this->assertSame([200], Payment::query()->forPayable($withOrders)->pluck('amount_points')->map(fn ($p) => (int) $p)->all());
        $this->assertSame(2, $this->orderPayments());
    }

    public function test_paying_a_session_from_the_wallet_pays_its_orders(): void
    {
        $member = $this->member(300);
        $session = $this->walkIn($member);
        $first = $this->order($session, 30);
        $second = $this->order($session, 20);
        $this->closures()->closeOut($session, $this->at('10:00'));

        Sanctum::actingAs($member, ['*']);
        $this->withHeader('lang', 'en')
            ->postJson("/api/v1/member/coworking-sessions/walkin/{$session->id}/pay", ['payment_method' => 'wallet'])
            ->assertCreated();

        $this->assertSame(PaymentState::Paid, $session->refresh()->payment_state);
        $this->assertSame(200, (int) $session->price_points);
        $this->assertNotNull($first->refresh()->paid_at);
        $this->assertNotNull($second->refresh()->paid_at);
        $this->assertSame(50, $this->balanceOf($member));
    }

    public function test_paying_a_booking_from_the_wallet_pays_its_orders(): void
    {
        $member = $this->member(300);
        $booking = Booking::factory()->create([
            'space_id' => $this->roomSpace()->id,
            'user_id' => $member->id,
            'start_at' => $this->at('08:00'),
            'end_at' => $this->at('10:00'),
            'checked_in_at' => $this->at('08:00'),
            'price_points' => 200,
            'payment_state' => PaymentState::Unpaid,
            'chosen_payment_method' => 'cash',
        ]);
        $order = $this->order($booking, 30);
        $this->closures()->closeOut($booking, $this->at('10:00'));
        $this->assertNull($order->refresh()->paid_at);

        Sanctum::actingAs($member, ['*']);
        $this->withHeader('lang', 'en')
            ->postJson("/api/v1/member/bookings/{$booking->id}/pay", ['payment_method' => 'wallet'])
            ->assertCreated();

        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
        $this->assertSame(200, $booking->pointsPaid());
        $this->assertNotNull($order->refresh()->paid_at);
        $this->assertSame(70, $this->balanceOf($member));
    }

    public function test_paying_at_the_desk_by_cash_from_the_app_leaves_orders_for_the_desk(): void
    {
        $member = $this->member(300);
        $session = $this->walkIn($member);
        $order = $this->order($session, 30);
        $this->closures()->closeOut($session, $this->at('10:00'));

        Sanctum::actingAs($member, ['*']);
        $this->withHeader('lang', 'en')
            ->postJson("/api/v1/member/coworking-sessions/walkin/{$session->id}/pay", ['payment_method' => 'cash'])
            ->assertCreated();

        $this->assertNull($order->refresh()->paid_at);
        $this->assertSame(300, $this->balanceOf($member));
    }

    public function test_a_bill_closing_owing_nothing_itself_but_with_unpaid_orders_tells_the_desk(): void
    {
        $member = $this->member();
        $owing = $this->walkIn($member, ['price_points' => 0, 'payment_state' => PaymentState::Unpaid]);
        $quiet = $this->walkIn($this->member(), ['price_points' => 0, 'payment_state' => PaymentState::Unpaid]);
        $this->order($owing, 30);
        Event::fake([PendingPaymentsChanged::class]);

        // Covered in full by the plan: the session owes nothing, its order does.
        $quiet->forceFill(['checked_out_at' => now(), 'payment_state' => PaymentState::Paid, 'payment_source' => PaymentSource::Plan])->save();
        Event::assertNotDispatched(PendingPaymentsChanged::class);

        $owing->forceFill(['checked_out_at' => now(), 'payment_state' => PaymentState::Paid, 'payment_source' => PaymentSource::Plan])->save();
        Event::assertDispatched(PendingPaymentsChanged::class, fn (PendingPaymentsChanged $e) => $e->memberId === $member->id);
        Event::assertDispatchedTimes(PendingPaymentsChanged::class, 1);
    }

    public function test_session_card_shows_its_orders(): void
    {
        $member = $this->actingAsMember();
        $session = $this->walkIn($member, ['checked_out_at' => $this->at('09:00'), 'price_points' => 100, 'amount_owed' => '10.00']);
        $this->order($session, 30);
        $this->order($session, 20, KitchenOrderStatus::Delivered, paid: true);

        $this->getJson('/api/v1/member/coworking-sessions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $session->id)
            ->assertJsonPath('data.0.orders', ['count' => 2, 'total_points' => 50, 'unpaid_points' => 30]);
    }
}
