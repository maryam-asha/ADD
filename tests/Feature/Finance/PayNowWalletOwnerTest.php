<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Whose wallet paying may touch — a booking sent again to
 * `POST member/bookings` with its id, a plan bought through
 * `POST member/memberships` (final review C1, docs/decisions/unified-payments.md).
 *
 * Only the wallet method spends from a wallet the member names, and it
 * checks the member may spend from it. Card and cash money always lands in
 * the payer's own wallet first, whatever the request names. A company plan
 * is bought by the company's admin through `POST member/memberships` with
 * `company_id`, and by nobody else.
 */
class PayNowWalletOwnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc());

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'en',
        ]);

        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-9/en', 'paymentId' => 'pay-9'],
            ]),
        ]);
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
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return $member;
    }

    /**
     * @return array{0: Company, 1: Wallet}
     */
    private function company(int $points = 5000): array
    {
        $company = Company::factory()->create();
        Space::factory()->create([
            'building_id' => Building::factory()->create(['branch_id' => $company->branch_id])->id,
            'capacity' => 10,
        ]);
        $wallet = Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return [$company, $wallet];
    }

    private function plan(array $attributes = []): Plan
    {
        return Plan::factory()->create(array_merge([
            'price_points' => 1200,
            'duration_days' => 30,
            'is_active' => true,
            'is_public' => true,
            'is_subscription' => true,
        ], $attributes));
    }

    private function unpaidBooking(User $member): Booking
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => Carbon::parse('2026-08-17 12:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 13:00', 'Asia/Damascus')->utc(),
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            'price_points' => 100,
        ]);
    }

    private function balance(Wallet $wallet): int
    {
        return app(WalletService::class)->generalBalance($wallet->fresh());
    }

    private function payBooking(Booking $booking, array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', ['booking_id' => $booking->id] + $body);
    }

    private function buyPlan(array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/memberships', $body);
    }

    /** Probe A. */
    public function test_a_company_member_cannot_buy_a_company_plan_from_the_company_wallet_here(): void
    {
        [$company, $companyWallet] = $this->company();
        $member = $this->member();
        $company->members()->attach($member->id, ['is_admin' => false]);
        Sanctum::actingAs($member, ['*']);

        $this->buyPlan([
            'payment_method' => 'wallet',
            'plan_id' => $this->plan()->id,
            'company_id' => $company->id,
        ])->assertForbidden();

        $this->assertSame(0, Membership::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(5000, $this->balance($companyWallet));
    }

    /** Probe B. */
    public function test_a_card_pay_now_naming_a_strangers_wallet_lands_in_the_payers_own_wallet(): void
    {
        [$stranger] = $this->company(0);
        $member = $this->member();
        $booking = $this->unpaidBooking($member);
        Sanctum::actingAs($member, ['*']);

        $this->payBooking($booking, [
            'payment_method' => 'paymera',
            'wallet_owner_type' => 'company',
            'wallet_owner_id' => $stranger->id,
        ])->assertOk()->assertJsonPath('next', 'redirect');

        $personal = Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->value('id');
        $this->assertSame($personal, Payment::sole()->wallet_id);
    }

    /** Probe C. */
    public function test_a_cash_plan_hold_cannot_be_opened_on_a_foreign_company(): void
    {
        [$stranger, $strangerWallet] = $this->company(0);
        $member = $this->member();
        Sanctum::actingAs($member, ['*']);

        $this->buyPlan([
            'payment_method' => 'cash',
            'plan_id' => $this->plan()->id,
            'company_id' => $stranger->id,
        ])->assertForbidden();

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Payment::where('wallet_id', $strangerWallet->id)->count());
    }

    public function test_a_cash_plan_hold_opened_here_is_the_members_own(): void
    {
        $member = $this->member();
        Sanctum::actingAs($member, ['*']);

        $this->buyPlan(['payment_method' => 'cash', 'plan_id' => $this->plan()->id])
            ->assertCreated()
            ->assertJsonPath('data.pending_purchase.method', 'cash');

        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->value('id'), $payment->wallet_id);
    }

    /**
     * The choice the member has always had for a booking: their own wallet
     * or one of a company they belong to — checked by the wallet method.
     */
    public function test_a_booking_can_still_be_paid_from_a_company_the_member_belongs_to_but_not_a_strangers(): void
    {
        [$company, $companyWallet] = $this->company(500);
        [$stranger] = $this->company(500);
        $member = $this->member();
        $company->members()->attach($member->id, ['is_admin' => false]);
        $booking = $this->unpaidBooking($member);
        Sanctum::actingAs($member, ['*']);

        $this->payBooking($booking, [
            'payment_method' => 'wallet',
            'wallet_owner_type' => 'company',
            'wallet_owner_id' => $stranger->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.booking.wallet_not_owned', [], 'en'));

        $this->payBooking($booking, [
            'payment_method' => 'wallet',
            'wallet_owner_type' => 'company',
            'wallet_owner_id' => $company->id,
        ])->assertOk()->assertJsonPath('next', 'done');

        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
        $this->assertSame($companyWallet->id, Payment::sole()->wallet_id);
        $this->assertSame(400, $this->balance($companyWallet));
    }

    public function test_a_one_time_package_cannot_be_bought_as_a_plan_here(): void
    {
        $member = $this->member(5000);
        Sanctum::actingAs($member, ['*']);
        $package = $this->plan(['is_subscription' => false]);

        foreach (['wallet', 'cash', 'paymera'] as $method) {
            $this->buyPlan(array_filter(['payment_method' => $method, 'plan_id' => $package->id]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('plan_id');
        }

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Membership::count());
    }

    public function test_a_plan_whose_branch_is_full_is_a_422_and_charges_nothing(): void
    {
        Cache::forget('default_branch_id');
        $branch = Branch::factory()->create(['is_default' => true]);
        Space::factory()->create([
            'building_id' => Building::factory()->create(['branch_id' => $branch->id])->id,
            'capacity' => 1,
        ]);
        Membership::factory()->create(['branch_id' => $branch->id]);
        $member = $this->member(5000);
        Sanctum::actingAs($member, ['*']);

        $this->buyPlan(['payment_method' => 'wallet', 'plan_id' => $this->plan()->id])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.membership.seat_pool_full', [], 'en'))
            ->assertJsonStructure(['message', 'first_shortfall_at']);

        $this->assertSame(5000, $this->balance(Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail()));
        $this->assertSame(0, Membership::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->count());
        $this->assertSame(0, Payment::count());
    }

    /**
     * The one path a company plan is bought by: its admin, through
     * memberships. A cash hold there still opens on the company's wallet.
     */
    public function test_a_company_admin_still_holds_a_company_plan_for_cash_through_memberships(): void
    {
        [$company, $companyWallet] = $this->company(0);
        $admin = $this->member();
        $company->members()->attach($admin->id, ['is_admin' => true]);
        $member = $this->member();
        $company->members()->attach($member->id, ['is_admin' => false]);
        $plan = $this->plan();

        Sanctum::actingAs($member, ['*']);
        $this->postJson('/api/v1/member/memberships', ['plan_id' => $plan->id, 'company_id' => $company->id, 'payment_method' => 'cash'])
            ->assertForbidden();

        Sanctum::actingAs($admin, ['*']);
        $this->postJson('/api/v1/member/memberships', ['plan_id' => $plan->id, 'company_id' => $company->id, 'payment_method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.pending_purchase.method', 'cash');

        $this->assertSame($companyWallet->id, Payment::sole()->wallet_id);
    }
}
