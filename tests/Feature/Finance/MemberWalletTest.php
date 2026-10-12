<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\WalletService;
use App\Support\Time\LocalTime;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The member's own wallet: GET member/wallet, the history under
 * member/wallet/transactions, and one row as a receipt — including the
 * credit a card top-up (POST member/wallet/top-ups) leaves once accepted.
 *
 * Card rates as in MinimumTopUpTest: 1 point = 0.10 USD and
 * 1 USD = 10,000 SYP, so 10 points cost 10,000.00 SYP.
 */
class MemberWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-key',
            'paymera.terminal_id' => '14740460',
            'paymera.currency' => 'SYP',
            'payments.min_top_up_points' => 10,
        ]);

        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0001']);

        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/ar', 'paymentId' => 'pay-1'],
            ]),
        ]);

        Notification::fake();
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

    private function actingAsMember(User $member): void
    {
        Sanctum::actingAs($member, ['member-app']);
        $this->withHeader('lang', 'en');
    }

    /**
     * A walk-in of 2.5 hours at 100 points an hour, paid from the wallet:
     * 250 points spent.
     */
    private function paidWalkIn(User $member): Payment
    {
        $space = Space::factory()->room()->create([
            'name' => ['en' => 'Meeting Room A', 'ar' => 'غرفة الاجتماعات أ'],
            'hourly_rate_points' => 100,
        ]);
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

        return app(PaymentService::class)
            ->start(PaymentPurpose::CoworkingSession, $member, 'wallet', ['payable' => $session->refresh()])['payment'];
    }

    public function test_the_wallet_needs_a_members_token(): void
    {
        $this->getJson('/api/v1/member/wallet')->assertUnauthorized();
        $this->getJson('/api/v1/member/wallet/transactions')->assertUnauthorized();
        $this->getJson('/api/v1/member/wallet/transactions/1')->assertUnauthorized();
    }

    public function test_the_wallet_shows_its_balance_in_every_category_general_first(): void
    {
        $member = $this->member(300);
        app(WalletService::class)->creditCategorized(
            $this->walletOf($member),
            WalletTransactionCategory::Cafe,
            50,
            WalletTransactionSource::SubscriptionGrant,
            now()->addMonth(),
        );
        $this->actingAsMember($member);

        $response = $this->getJson('/api/v1/member/wallet')
            ->assertOk()
            ->assertJsonPath('data.balance.points', 300)
            ->assertJsonPath('data.categories.0.category', 'general')
            ->assertJsonPath('data.categories.0.label', 'General balance')
            ->assertJsonPath('data.categories.0.points', 300);

        $this->assertSame(
            ['general' => 300, 'cafe' => 50, 'printing_internet' => 0, 'space_specific' => 0],
            array_column($response->json('data.categories'), 'points', 'category'),
        );
    }

    public function test_a_member_without_a_wallet_has_nothing_in_it(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $this->actingAsMember($member);

        $this->getJson('/api/v1/member/wallet')
            ->assertOk()
            ->assertJsonPath('data.balance.points', 0);

        $this->getJson('/api/v1/member/wallet/transactions')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data.points_label', '0 pts');

        $this->getJson('/api/v1/member/profile')
            ->assertOk()
            ->assertJsonPath('data.points_label', '0 pts');
    }

    public function test_the_history_and_profile_carry_the_general_points_only(): void
    {
        $member = $this->member(300);
        app(WalletService::class)->creditCategorized(
            $this->walletOf($member),
            WalletTransactionCategory::Cafe,
            50,
            WalletTransactionSource::SubscriptionGrant,
            now()->addMonth(),
        );
        $this->actingAsMember($member);

        // Narrowing the list to cafe rows doesn't narrow the balance.
        $this->getJson('/api/v1/member/wallet/transactions?category=cafe')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.points_label', '300 pts');

        $this->getJson('/api/v1/member/profile')
            ->assertOk()
            ->assertJsonPath('data.points_label', '300 pts');
    }

    public function test_the_history_is_the_members_own_rows_newest_first(): void
    {
        $member = $this->member(300);
        $this->member(500);
        app(WalletService::class)->creditGeneral($this->walletOf($member), 20, WalletTransactionSource::Gift);
        $this->actingAsMember($member);

        $this->getJson('/api/v1/member/wallet/transactions')
            ->assertOk()
            ->assertJsonCount(2, 'data.transactions')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.transactions.0.kind', 'gift')
            ->assertJsonPath('data.transactions.0.title', 'Gift points')
            ->assertJsonPath('data.transactions.0.type', 'credit')
            ->assertJsonPath('data.transactions.0.points', '+20')
            ->assertJsonPath('data.transactions.1.kind', 'top_up')
            ->assertJsonPath('data.transactions.1.points', '+300');
    }

    public function test_a_spend_is_named_by_what_it_paid_for(): void
    {
        $member = $this->member(300);
        $this->paidWalkIn($member);
        $this->actingAsMember($member);

        $this->getJson('/api/v1/member/wallet/transactions')
            ->assertOk()
            ->assertJsonPath('data.transactions.0.type', 'debit')
            ->assertJsonPath('data.transactions.0.kind', 'coworking_session')
            ->assertJsonPath('data.transactions.0.title', 'Coworking session — Meeting Room A')
            ->assertJsonPath('data.transactions.0.points', '-250')
            ->assertJsonPath('data.transactions.0.amount.points', 250)
            ->assertJsonPath('data.transactions.0.method', 'wallet')
            ->assertJsonPath('data.transactions.0.method_label', 'Wallet points')
            // Points spent, no money handed over: nothing was paid.
            ->assertJsonPath('data.transactions.0.paid', null)
            // No space-specific points, so the spend fell back to general.
            ->assertJsonPath('data.transactions.0.category', 'general');

        $this->withHeader('lang', 'ar')
            ->getJson('/api/v1/member/wallet/transactions')
            ->assertJsonPath('data.transactions.0.title', 'جلسة عمل مشترك — غرفة الاجتماعات أ')
            ->assertJsonPath('data.transactions.0.category_label', 'الرصيد العام');
    }

    public function test_the_receipt_shows_the_balance_either_side_and_the_payment(): void
    {
        $member = $this->member(300);
        $payment = $this->paidWalkIn($member);
        $this->actingAsMember($member);

        $this->getJson("/api/v1/member/wallet/transactions/{$payment->debit_transaction_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $payment->debit_transaction_id)
            ->assertJsonPath('data.kind', 'coworking_session')
            ->assertJsonPath('data.category_label', 'General balance')
            ->assertJsonPath('data.balance.before.points', 300)
            ->assertJsonPath('data.balance.after.points', 50)
            ->assertJsonPath('data.paid', null)
            ->assertJsonPath('data.points', '-250')
            ->assertJsonPath('data.paid_at', app(LocalTime::class)->toLocal($payment->refresh()->settled_at)->toIso8601String());
    }

    public function test_an_earlier_row_shows_the_balance_as_it_was_then(): void
    {
        $member = $this->member(300);
        $first = WalletTransaction::sole();
        $this->paidWalkIn($member);
        $this->actingAsMember($member);

        $this->getJson("/api/v1/member/wallet/transactions/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.balance.before.points', 0)
            ->assertJsonPath('data.balance.after.points', 300);
    }

    public function test_the_receipt_carries_the_rows_stored_reference(): void
    {
        $member = $this->member(300);
        $payment = $this->paidWalkIn($member);
        $this->actingAsMember($member);

        $year = now()->format('Y');
        $first = WalletTransaction::orderBy('id')->first();
        $debit = WalletTransaction::findOrFail($payment->debit_transaction_id);

        // One series per year, numbered in the order rows were written.
        $this->assertSame("TRX-{$year}-00001", $first->reference);
        $this->assertSame("TRX-{$year}-00002", $debit->reference);

        $this->getJson("/api/v1/member/wallet/transactions/{$debit->id}")
            ->assertOk()
            ->assertJsonPath('data.reference', $debit->reference);
    }

    public function test_a_payment_names_its_row_in_the_history(): void
    {
        $member = $this->member(300);
        $payment = $this->paidWalkIn($member);
        $this->actingAsMember($member);

        $this->getJson("/api/v1/member/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.transaction_id', $payment->debit_transaction_id);
    }

    public function test_the_history_filters_by_type_category_and_day(): void
    {
        $member = $this->member(300);
        $this->paidWalkIn($member);
        app(WalletService::class)->creditCategorized(
            $this->walletOf($member),
            WalletTransactionCategory::Cafe,
            50,
            WalletTransactionSource::SubscriptionGrant,
            now()->addMonth(),
        );
        $this->actingAsMember($member);

        $this->getJson('/api/v1/member/wallet/transactions?type=debit')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.transactions.0.type', 'debit');

        $this->getJson('/api/v1/member/wallet/transactions?type=credit')
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/member/wallet/transactions?category=cafe')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.transactions.0.kind', 'subscription_grant');

        $today = now()->setTimezone('Asia/Damascus')->format('Y-m-d');
        $tomorrow = now()->setTimezone('Asia/Damascus')->addDay()->format('Y-m-d');

        $this->getJson("/api/v1/member/wallet/transactions?from={$today}&to={$today}")
            ->assertJsonPath('meta.total', 3);

        $this->getJson("/api/v1/member/wallet/transactions?from={$tomorrow}")
            ->assertJsonPath('meta.total', 0);

        $this->getJson("/api/v1/member/wallet/transactions?from={$tomorrow}&to={$today}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson('/api/v1/member/wallet/transactions?type=refund')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_the_history_is_paginated(): void
    {
        $member = $this->member(300);
        app(WalletService::class)->creditGeneral($this->walletOf($member), 20, WalletTransactionSource::Gift);
        app(WalletService::class)->creditGeneral($this->walletOf($member), 30, WalletTransactionSource::Gift);
        $this->actingAsMember($member);

        $this->getJson('/api/v1/member/wallet/transactions?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.transactions')
            ->assertJsonPath('data.transactions.0.points', '+300')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_another_members_row_is_not_found(): void
    {
        $other = $this->member(500);
        $theirs = WalletTransaction::query()->where('wallet_id', $this->walletOf($other)->id)->sole();
        $this->actingAsMember($this->member(300));

        $this->getJson("/api/v1/member/wallet/transactions/{$theirs->id}")->assertNotFound();
    }

    public function test_a_company_wallet_row_is_not_in_the_members_history(): void
    {
        $member = $this->member();
        $company = Company::factory()->create();
        $member->companies()->attach($company->id, ['is_admin' => true]);
        $companyWallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $row = app(WalletService::class)->creditGeneral($companyWallet, 100, WalletTransactionSource::TopUp);
        $this->actingAsMember($member);

        $this->getJson('/api/v1/member/wallet/transactions')->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/member/wallet/transactions/{$row->id}")->assertNotFound();
    }

    public function test_a_card_top_up_still_needs_an_amount(): void
    {
        $this->actingAsMember($this->member());

        $this->postJson('/api/v1/member/wallet/top-ups', ['payment_method' => 'paymera'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        // The method is always named.
        $this->postJson('/api/v1/member/wallet/top-ups', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method', 'amount']);
    }

    public function test_an_accepted_card_top_up_is_a_top_up_row_with_the_money_paid(): void
    {
        $member = $this->member();
        $this->actingAsMember($member);

        // A top-up is an ordinary card payment; until it settles it has no
        // row in the history.
        $this->postJson('/api/v1/member/wallet/top-ups', [
            'payment_method' => 'paymera',
            'amount' => '10000',
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment.transaction_id', null);

        $payment = Payment::sole();
        $payment->forceFill(['status' => PaymentStatus::Accepted])->save();
        app(PaymentService::class)->fulfilAcceptedGatewayPayment($payment);
        $payment->refresh();

        $this->getJson("/api/v1/member/payments/{$payment->id}")
            ->assertJsonPath('data.transaction_id', $payment->wallet_transaction_id);

        $this->getJson("/api/v1/member/wallet/transactions/{$payment->wallet_transaction_id}")
            ->assertOk()
            ->assertJsonPath('data.kind', 'top_up')
            ->assertJsonPath('data.title', 'Wallet top-up')
            ->assertJsonPath('data.points', '+10')
            ->assertJsonPath('data.method', 'paymera')
            ->assertJsonPath('data.paid.amount', '10000')
            ->assertJsonPath('data.paid.currency', 'SYP')
            ->assertJsonPath('data.balance.before.points', 0)
            ->assertJsonPath('data.balance.after.points', 10);

        // The list shows the money as it was paid, not re-priced at today's
        // rate — the receipt and the row agree.
        $this->getJson('/api/v1/member/wallet/transactions')
            ->assertOk()
            ->assertJsonPath('data.transactions.0.paid.amount', '10000')
            ->assertJsonPath('data.transactions.0.paid.currency', 'SYP');

        $this->getJson('/api/v1/member/wallet')->assertJsonPath('data.balance.points', 10);
    }
}
