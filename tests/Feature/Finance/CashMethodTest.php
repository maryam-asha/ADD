<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Cash at the reception desk (docs/superpowers/plans/2026-09-30-unified-payments.md,
 * Task 6): only a plan is held as a pending payment, and the hold lapses.
 */
class CashMethodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        return $member;
    }

    private function plan(): Plan
    {
        return Plan::factory()->create(['price_points' => 1200, 'duration_days' => 30, 'is_active' => true]);
    }

    public function test_cash_for_a_plan_writes_a_held_pending_payment(): void
    {
        $member = $this->member();

        ['payment' => $payment, 'start' => $start] = app(PaymentService::class)
            ->start(PaymentPurpose::PlanPurchase, $member, 'cash', ['plan' => $this->plan()]);

        $this->assertSame('pay_at_reception', $start->kind);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(1200, $payment->amount_points);
        $this->assertTrue($payment->expires_at->equalTo(now()->addHours(48)));
    }

    public function test_cash_for_anything_else_writes_no_row(): void
    {
        $member = $this->member();
        $booking = Booking::factory()->create(['user_id' => $member->id]);

        ['payment' => $payment, 'start' => $start] = app(PaymentService::class)
            ->start(PaymentPurpose::Booking, $member, 'cash', ['payable' => $booking]);

        $this->assertNull($payment);
        $this->assertSame('pay_at_reception', $start->kind);
        $this->assertSame(0, Payment::count());
    }

    public function test_the_hold_follows_its_setting(): void
    {
        config(['payments.cash_plan_hold_hours' => 6]);

        ['payment' => $payment] = app(PaymentService::class)
            ->start(PaymentPurpose::PlanPurchase, $this->member(), 'cash', ['plan' => $this->plan()]);

        $this->assertTrue($payment->expires_at->equalTo(now()->addHours(6)));
    }

    public function test_a_lapsed_cash_hold_expires_and_nothing_else_is_touched(): void
    {
        $member = $this->member();
        $plan = $this->plan();

        ['payment' => $lapsed] = app(PaymentService::class)
            ->start(PaymentPurpose::PlanPurchase, $member, 'cash', ['plan' => $plan]);

        $card = Payment::factory()->forPlan($plan)->create([
            'user_id' => $member->id,
            'wallet_id' => $lapsed->wallet_id,
            'expires_at' => now()->subHour(),
        ]);

        Carbon::setTestNow(now()->addHours(49));

        ['payment' => $fresh] = app(PaymentService::class)
            ->start(PaymentPurpose::PlanPurchase, $this->member(), 'cash', ['plan' => $plan]);

        $this->assertSame(0, Artisan::call('payments:expire-pending'));

        $this->assertSame(PaymentStatus::Expired, $lapsed->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $fresh->fresh()->status);
        // A gateway's payment is paymera:reconcile's to expire, never this.
        $this->assertSame(PaymentStatus::Pending, $card->fresh()->status);
        $this->assertSame(0, Membership::count());
    }
}
