<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Notifications\GeneralNotification;
use App\Services\Experience\EventApprovalService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Telling members about payments they did not just make in the app
 * (docs/superpowers/plans/2026-09-30-unified-payments.md, Task 12).
 */
class PaymentNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Notification::fake();
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

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');

        return $operator;
    }

    private function assertTold(User $member, string $template): void
    {
        Notification::assertSentTo(
            $member,
            GeneralNotification::class,
            fn (GeneralNotification $n) => $n->toArray($member)['template_key'] === $template,
        );
    }

    private function assertNotTold(User $member, string $template): void
    {
        Notification::assertNotSentTo(
            $member,
            GeneralNotification::class,
            fn (GeneralNotification $n) => $n->toArray($member)['template_key'] === $template,
        );
    }

    public function test_money_taken_at_reception_is_announced(): void
    {
        $member = $this->member();
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'checked_out_at' => now()->subHour(),
            'amount_owed' => '10.00',
            'price_points' => 100,
            'payment_state' => PaymentState::Unpaid,
        ]);
        Sanctum::actingAs($this->operator(), ['*']);

        $this->postJson('/api/v1/admin/reception/payments/settle', [
            'purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => 'cash',
        ])->assertCreated();

        $this->assertTold($member, 'payment.received');
        $notification = Notification::sent($member, GeneralNotification::class)->first();
        $data = $notification->toArray($member);
        $this->assertSame(Payment::sole()->id, $data['data']['payment_id'] ?? $data['payment_id'] ?? null);
    }

    public function test_a_wallet_topped_up_at_reception_is_announced(): void
    {
        $member = $this->member();
        Sanctum::actingAs($this->operator(), ['*']);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id, 'amount' => '25.00', 'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertTold($member, 'payment.received');
        $data = Notification::sent($member, GeneralNotification::class)->first()->toArray($member);
        $this->assertSame(Payment::sole()->id, $data['data']['payment_id'] ?? $data['payment_id'] ?? null);
    }

    public function test_a_company_wallet_topped_up_at_reception_tells_nobody(): void
    {
        $company = Company::factory()->create();
        Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $operator = $this->operator();
        Sanctum::actingAs($operator, ['*']);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'company_id' => $company->id, 'amount' => '50.00', 'payment_method' => 'cash',
        ])->assertCreated();

        // The payer recorded for a company is whoever took the money.
        $this->assertNotTold($operator, 'payment.received');
    }

    public function test_a_card_payment_accepted_by_the_gateway_is_announced(): void
    {
        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'k',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
        ]);
        Http::fake(['*/api/get-payment-status/*' => Http::response([
            'ErrorMessage' => 'Success', 'ErrorCode' => 0,
            'Data' => ['status' => 'A', 'creationTimestamp' => '2026-01-26 12:06:52.547', 'rrn' => '1', 'amount' => 1000, 'terminalId' => '99990001'],
        ])]);
        $member = $this->member();
        $payment = Payment::factory()->create([
            'payment_id' => 'pay-1',
            'user_id' => $member->id,
            'wallet_id' => Wallet::first()->id,
            'amount_minor' => 1000,
            'points_to_credit' => 100,
            'terminal_id' => '99990001',
        ]);

        app(SyncPaymentStatus::class)->handle($payment);

        $this->assertTold($member, 'payment.received');
    }

    public function test_paying_from_the_wallet_in_the_app_is_not_announced(): void
    {
        $member = $this->member(300);
        $booking = Booking::factory()->create(['user_id' => $member->id, 'payment_state' => PaymentState::Unpaid, 'payment_source' => null, 'price_points' => 100]);
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/member/payments', ['purpose' => 'booking', 'method' => 'wallet', 'payable_id' => $booking->id])
            ->assertCreated();

        $this->assertNotTold($member, 'payment.received');
    }

    public function test_a_lapsed_cash_hold_is_announced(): void
    {
        $member = $this->member();
        $plan = Plan::factory()->create(['price_points' => 1200, 'is_active' => true]);
        app(PaymentService::class)->start(PaymentPurpose::PlanPurchase, $member, 'cash', ['plan' => $plan]);

        Carbon::setTestNow(now()->addHours(49));
        Artisan::call('payments:expire-pending');
        Carbon::setTestNow();

        $this->assertTold($member, 'payment.expired');
    }

    public function test_a_confirmed_but_unpaid_registration_is_told_it_owes(): void
    {
        $member = $this->member();
        $event = Event::create([
            'title' => ['en' => 'Talk', 'ar' => 'محاضرة'],
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHour(),
            'location' => 'Hall',
            'is_free' => false,
            'price_points' => 80,
            'status' => 'published',
            'requires_approval' => true,
        ]);
        Sanctum::actingAs($member, ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register", ['payment_method' => 'cash'])->assertCreated();
        $this->assertNotTold($member, 'event.payment_due');

        app(EventApprovalService::class)->approve(EventAttendee::sole(), $this->operator());

        $this->assertTold($member, 'event.payment_due');
    }
}
