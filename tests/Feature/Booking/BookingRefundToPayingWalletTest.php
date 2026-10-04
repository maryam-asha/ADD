<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingApprovalService;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A booking's points go back where they came from
 * (docs/superpowers/plans/2026-09-30-unified-payments.md, Task 5): the
 * refund follows the payment rows, so a booking a company paid for refunds
 * the company, and an extension's points come back with the rest.
 */
class BookingRefundToPayingWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // A Monday, two hours before the bookings below.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    private function companyWalletFor(User $member, int $points): Wallet
    {
        $company = Company::factory()->create();
        $member->companies()->attach($company->id);
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);

        return $wallet;
    }

    private function walletOf(User $member): Wallet
    {
        return Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail();
    }

    private function balance(Wallet $wallet): int
    {
        return app(WalletService::class)->generalBalance($wallet->fresh());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function space(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
        ], $attributes));

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    private function bookFrom(Space $space, User $member, ?Wallet $wallet = null): Booking
    {
        return app(BookingCreationService::class)->create(
            $space,
            $member,
            Carbon::parse('2026-08-17 10:00', 'Asia/Damascus'),
            Carbon::parse('2026-08-17 11:00', 'Asia/Damascus'),
            $wallet?->owner_type,
            $wallet === null ? null : (int) $wallet->owner_id,
            null,
            'wallet',
        );
    }

    public function test_a_booking_a_company_paid_for_is_refunded_to_the_company(): void
    {
        $member = $this->member(500);
        $companyWallet = $this->companyWalletFor($member, 500);
        $booking = $this->bookFrom($this->space(), $member, $companyWallet);
        $this->assertSame(400, $this->balance($companyWallet));

        app(BookingCancellationService::class)->cancel($booking);

        $this->assertSame(500, $this->balance($companyWallet));
        $this->assertSame(500, $this->balance($this->walletOf($member)));

        $payment = Payment::sole();
        $this->assertNotNull($payment->refunded_at);
        $refund = WalletTransaction::findOrFail($payment->refund_transaction_id);
        $this->assertSame(100, (int) $refund->amount);
        $this->assertSame(WalletTransactionSource::Refund, $refund->source);
        $this->assertSame($companyWallet->id, $refund->wallet_id);
    }

    public function test_rejecting_a_paid_pending_booking_refunds_the_wallet_that_paid(): void
    {
        $member = $this->member(500);
        $companyWallet = $this->companyWalletFor($member, 500);
        $operator = User::factory()->create();
        $operator->assignRole('operations');

        $booking = $this->bookFrom($this->space(['requires_approval' => true]), $member, $companyWallet);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);

        app(BookingApprovalService::class)->reject($booking, $operator, 'Room closed for cleaning');

        $this->assertSame(500, $this->balance($companyWallet));
        $this->assertSame(500, $this->balance($this->walletOf($member)));
        $this->assertNotNull(Payment::sole()->refunded_at);
    }

    public function test_a_payment_is_refunded_once_however_often_it_is_asked(): void
    {
        $member = $this->member(500);
        $this->bookFrom($this->space(), $member);
        $payment = Payment::sole();

        app(PaymentService::class)->refund($payment, 'Booking cancellation refund');
        app(PaymentService::class)->refund($payment->fresh(), 'Booking cancellation refund');

        $this->assertSame(1, WalletTransaction::where('source', WalletTransactionSource::Refund)->count());
        $this->assertSame(500, $this->balance($this->walletOf($member)));
    }

    public function test_an_extension_paid_by_wallet_leaves_a_payment_row_for_the_difference(): void
    {
        $member = $this->member(500);
        $booking = $this->bookFrom($this->space(), $member);
        // A booking extends only while it runs, its member inside
        // (docs/decisions/booking-outcomes-and-extension.md).
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus')->utc());
        $booking->forceFill(['checked_in_at' => now()])->save();

        app(BookingExtensionService::class)->extend($booking, 60);

        $extension = Payment::where('purpose', PaymentPurpose::BookingExtension)->sole();
        $this->assertSame('booking', $extension->payable_type);
        $this->assertSame($booking->id, $extension->payable_id);
        $this->assertSame(100, $extension->amount_points);
        $this->assertSame($this->walletOf($member)->id, $extension->wallet_id);
        $this->assertNotNull($extension->debit_transaction_id);

        $booking->refresh();
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(PaymentSource::Wallet, $booking->payment_source);
        $this->assertSame(200, $booking->price_points);
        $this->assertSame(300, $this->balance($this->walletOf($member)));
    }

    /**
     * Extension needs a check-in and cancellation forbids one, so the two
     * cannot meet on one booking through the services; the refund is proven
     * against a booking that simply carries two payments.
     */
    public function test_cancelling_refunds_every_payment_made_for_the_booking(): void
    {
        $member = $this->member(500);
        $booking = $this->bookFrom($this->space(), $member);

        app(PaymentService::class)->start(PaymentPurpose::BookingExtension, $member, 'wallet', [
            'payable' => $booking,
            'points' => 50,
            'wallet_owner_type' => OwnerType::User,
            'wallet_owner_id' => $member->id,
        ]);
        $this->assertSame(350, $this->balance($this->walletOf($member)));

        app(BookingCancellationService::class)->cancel($booking->fresh());

        $this->assertSame(500, $this->balance($this->walletOf($member)));
        $this->assertSame(2, Payment::whereNotNull('refunded_at')->count());
    }

    /**
     * Paid before payment rows existed: refunded exactly as before, from the
     * recomputed price, to the member's own wallet.
     */
    public function test_a_booking_with_no_payment_rows_keeps_the_old_refund(): void
    {
        $member = $this->member(0);
        $space = $this->space();
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 11:00', 'Asia/Damascus')->utc(),
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
        ]);

        app(BookingCancellationService::class)->cancel($booking);

        $refund = WalletTransaction::sole();
        $this->assertSame(100, (int) $refund->amount);
        $this->assertSame('Booking cancellation refund', $refund->description);
        $this->assertSame($this->walletOf($member)->id, $refund->wallet_id);
    }
}
