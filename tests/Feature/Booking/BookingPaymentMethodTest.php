<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A member choosing how to pay for a new booking
 * (docs/superpowers/plans/2026-09-30-unified-payments.md, Task 4): points
 * now or refused, cash at the desk, or — for an app that does not send a
 * method — exactly the routing it always had. Every debit now has a payment
 * row naming the booking and the wallet that paid.
 */
class BookingPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsMember(int $points = 0): User
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

    private function openSpace(): Space
    {
        $space = Space::factory()->room()->create([
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
        ]);

        foreach ([DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday] as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $space->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }

        return $space;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function book(Space $space, array $extra = [])
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', array_merge([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
        ], $extra));
    }

    public function test_paying_by_wallet_debits_the_only_wallet_and_records_the_payment(): void
    {
        $member = $this->actingAsMember(500);
        $space = $this->openSpace();

        $this->book($space, ['payment_method' => 'wallet'])->assertCreated();

        $booking = Booking::sole();
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(PaymentSource::Wallet, $booking->payment_source);
        $this->assertSame(100, $booking->price_points);
        $this->assertNotNull($booking->paid_at);

        $payment = Payment::sole();
        $this->assertSame('wallet', $payment->method);
        $this->assertSame(PaymentPurpose::Booking, $payment->purpose);
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertSame('booking', $payment->payable_type);
        $this->assertSame($booking->id, $payment->payable_id);
        $this->assertSame($this->walletOf($member)->id, $payment->wallet_id);
        $this->assertSame(100, $payment->amount_points);
        $this->assertSame(-100, (int) WalletTransaction::find($payment->debit_transaction_id)->amount);
        $this->assertSame(400, app(WalletService::class)->generalBalance($this->walletOf($member)));
    }

    public function test_paying_by_wallet_with_too_few_points_is_refused_and_books_nothing(): void
    {
        $this->actingAsMember(30);
        $space = $this->openSpace();

        $this->book($space, ['payment_method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonPath('current', 30)
            ->assertJsonPath('required', 100)
            ->assertJsonPath('shortage', 70);

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_paying_by_wallet_with_two_wallets_and_none_named_asks_for_a_choice(): void
    {
        $member = $this->actingAsMember(500);
        $this->companyWalletFor($member, 500);
        $space = $this->openSpace();

        $response = $this->book($space, ['payment_method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.booking.wallet_choice_required'));

        $this->assertCount(2, $response->json('wallet_options'));
        $this->assertSame(0, Booking::count());
    }

    public function test_a_named_company_wallet_pays_and_the_payment_says_so(): void
    {
        $member = $this->actingAsMember(500);
        $companyWallet = $this->companyWalletFor($member, 500);
        $space = $this->openSpace();

        $this->book($space, [
            'payment_method' => 'wallet',
            'wallet_owner_type' => 'company',
            'wallet_owner_id' => $companyWallet->owner_id,
        ])->assertCreated();

        $this->assertSame(PaymentState::Paid, Booking::sole()->payment_state);
        $this->assertSame($companyWallet->id, Payment::sole()->wallet_id);
        $this->assertSame(400, app(WalletService::class)->generalBalance($companyWallet));
        $this->assertSame(500, app(WalletService::class)->generalBalance($this->walletOf($member)));
    }

    public function test_cash_leaves_the_booking_unpaid_with_no_payment_row(): void
    {
        $member = $this->actingAsMember(500);
        $space = $this->openSpace();

        $this->book($space, ['payment_method' => 'cash'])->assertCreated();

        $booking = Booking::sole();
        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertSame(100, $booking->price_points);
        $this->assertSame(0, Payment::count());
        $this->assertSame(500, app(WalletService::class)->generalBalance($this->walletOf($member)));
    }

    /**
     * Several bookings cannot share one card page (a single booking by card
     * is covered in PayNowTest).
     */
    public function test_a_batch_cannot_be_paid_by_card(): void
    {
        $this->actingAsMember(500);
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'paymera',
            'windows' => [
                ['space_id' => $space->id, 'start_at' => '2026-08-17 10:00', 'end_at' => '2026-08-17 11:00'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Booking::count());
    }

    public function test_an_unknown_method_fails_validation(): void
    {
        $this->actingAsMember(500);
        $space = $this->openSpace();

        $this->book($space, ['payment_method' => 'barter'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    /**
     * An app that predates payment methods: the one wallet that can pay,
     * pays — and now leaves a payment row behind.
     */
    public function test_a_booking_without_a_method_is_refused_and_nothing_is_booked(): void
    {
        $this->actingAsMember(500);
        $space = $this->openSpace();

        $this->book($space)->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_a_batch_without_a_method_is_refused_and_nothing_is_booked(): void
    {
        $this->actingAsMember(500);
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'windows' => [
                ['space_id' => $space->id, 'start_at' => '2026-08-17 10:00', 'end_at' => '2026-08-17 11:00'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Booking::count());
    }

    public function test_a_batch_paid_by_wallet_writes_one_payment_per_booking(): void
    {
        $member = $this->actingAsMember(500);
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'wallet',
            'windows' => [
                ['space_id' => $space->id, 'start_at' => '2026-08-17 10:00', 'end_at' => '2026-08-17 11:00'],
                ['space_id' => $space->id, 'start_at' => '2026-08-18 10:00', 'end_at' => '2026-08-18 11:00'],
                ['space_id' => $space->id, 'start_at' => '2026-08-19 10:00', 'end_at' => '2026-08-19 11:00'],
            ],
        ])->assertCreated();

        $this->assertSame(3, Payment::count());
        $this->assertSame(
            Booking::orderBy('id')->pluck('id')->all(),
            Payment::orderBy('id')->pluck('payable_id')->all(),
        );
        $this->assertSame(3, Booking::where('payment_state', PaymentState::Paid)->count());
        $this->assertSame(200, app(WalletService::class)->generalBalance($this->walletOf($member)));
    }

    public function test_a_batch_that_cannot_be_paid_in_full_books_and_charges_nothing(): void
    {
        $member = $this->actingAsMember(250);
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'wallet',
            'windows' => [
                ['space_id' => $space->id, 'start_at' => '2026-08-17 10:00', 'end_at' => '2026-08-17 11:00'],
                ['space_id' => $space->id, 'start_at' => '2026-08-18 10:00', 'end_at' => '2026-08-18 11:00'],
                ['space_id' => $space->id, 'start_at' => '2026-08-19 10:00', 'end_at' => '2026-08-19 11:00'],
            ],
        ])->assertStatus(422)->assertJsonPath('required', 300)->assertJsonPath('shortage', 50);

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(250, app(WalletService::class)->generalBalance($this->walletOf($member)));
    }

    public function test_a_batch_rolls_back_every_payment_when_one_window_is_taken(): void
    {
        $member = $this->actingAsMember(500);
        $space = $this->openSpace();

        Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => Carbon::parse('2026-08-18 10:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-18 11:00', 'Asia/Damascus')->utc(),
        ]);

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'wallet',
            'windows' => [
                ['space_id' => $space->id, 'start_at' => '2026-08-17 10:00', 'end_at' => '2026-08-17 11:00'],
                ['space_id' => $space->id, 'start_at' => '2026-08-18 10:00', 'end_at' => '2026-08-18 11:00'],
            ],
        ])->assertStatus(422);

        $this->assertSame(1, Booking::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(500, app(WalletService::class)->generalBalance($this->walletOf($member)));
    }
}
