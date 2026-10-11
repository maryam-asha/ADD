<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Exceptions\InsufficientPointsException;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paying with points through the payment service
 * (docs/superpowers/plans/2026-09-30-unified-payments.md, Task 4).
 */
class WalletMethodTest extends TestCase
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

        return $member;
    }

    private function walletOf(User $member): Wallet
    {
        return Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail();
    }

    private function closedWalkIn(User $member): WalkinSession
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00'),
        ]);

        app(SessionClosureService::class)->closeOut($session, Carbon::parse('2026-08-17 11:30:00'));

        return $session->refresh();
    }

    public function test_the_wallet_method_is_enabled_and_pays_for_anything_but_a_top_up(): void
    {
        $wallet = app(PaymentMethodRegistry::class)->get('wallet');

        $this->assertTrue($wallet->supports(PaymentPurpose::Booking));
        $this->assertTrue($wallet->supports(PaymentPurpose::PlanPurchase));
        $this->assertTrue($wallet->supports(PaymentPurpose::CoworkingSession));
        $this->assertFalse($wallet->supports(PaymentPurpose::WalletTopUp));
    }

    public function test_a_closed_walk_in_stores_its_price_in_points(): void
    {
        $session = $this->closedWalkIn($this->member());

        // 2.5 hours at 100 points an hour.
        $this->assertSame(250, $session->price_points);
        $this->assertSame('25.00', (string) $session->amount_owed);
    }

    public function test_a_closed_walk_in_is_paid_from_the_wallet(): void
    {
        $member = $this->member(300);
        $session = $this->closedWalkIn($member);

        ['payment' => $payment, 'start' => $start] = app(PaymentService::class)
            ->start(PaymentPurpose::CoworkingSession, $member, 'wallet', ['payable' => $session]);

        $this->assertSame('done', $start->kind);
        $this->assertSame(250, $payment->amount_points);
        $this->assertSame('walkin_session', $payment->payable_type);

        $session->refresh();
        $this->assertSame(PaymentState::Paid, $session->payment_state);
        $this->assertSame(PaymentSource::Wallet, $session->payment_source);
        $this->assertNull($session->payment_method);
        $this->assertSame(50, app(WalletService::class)->generalBalance($this->walletOf($member)));
    }

    public function test_an_open_walk_in_cannot_be_paid(): void
    {
        $member = $this->member(300);
        $session = WalkinSession::factory()->create(['user_id' => $member->id, 'checked_out_at' => null]);

        try {
            app(PaymentService::class)->start(PaymentPurpose::CoworkingSession, $member, 'wallet', ['payable' => $session]);
            $this->fail('Paid for an open session.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.not_yet_checked_out', $e->messageKey);
        }

        $this->assertSame(0, Payment::count());
    }

    public function test_a_short_wallet_leaves_no_payment_row(): void
    {
        $member = $this->member(100);
        $session = $this->closedWalkIn($member);

        try {
            app(PaymentService::class)->start(PaymentPurpose::CoworkingSession, $member, 'wallet', ['payable' => $session]);
            $this->fail('Paid with too few points.');
        } catch (InsufficientPointsException $e) {
            $this->assertSame(100, $e->current);
            $this->assertSame(250, $e->required);
        }

        $this->assertSame(0, Payment::count());
        $this->assertSame(PaymentState::Unpaid, $session->refresh()->payment_state);
    }

    public function test_a_top_up_cannot_be_paid_with_points(): void
    {
        $member = $this->member(300);

        $this->expectException(ReceptionActionException::class);
        app(PaymentService::class)->start(PaymentPurpose::WalletTopUp, $member, 'wallet');
    }

    /**
     * debit() falls back to general when the category pool is short, so the
     * balance a member can spend on a space is the larger of the two.
     */
    public function test_the_usable_balance_is_what_a_debit_can_actually_spend(): void
    {
        $member = $this->member(100);
        app(WalletService::class)->creditCategorized(
            $this->walletOf($member),
            WalletTransactionCategory::SpaceSpecific,
            5,
            WalletTransactionSource::SubscriptionGrant,
            now()->addMonth(),
            null,
            [],
            'grant',
        );

        $options = app(WalletService::class)->spendOptions($member, WalletTransactionCategory::SpaceSpecific);

        $this->assertCount(1, $options);
        $this->assertSame(5, $options[0]['category_balance']);
        $this->assertSame(100, $options[0]['usable_balance']);
    }
}
