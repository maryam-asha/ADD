<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Exceptions\InsufficientPointsException;
use App\Domain\Finance\Payments\FulfillerRegistry;
use App\Domain\Finance\Payments\Fulfillers\PlanPurchaseFulfiller;
use App\Domain\Finance\Payments\Fulfillers\WalletTopUpFulfiller;
use App\Domain\Finance\Payments\Funding;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\DedicatedDeskService;
use App\Domain\Membership\Services\MembershipActivationService;
use App\Domain\Membership\Services\WalletService;
use App\Notifications\GeneralNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * One service turns every payment into points and spends them on what was
 * bought (docs/superpowers/plans/2026-09-30-unified-payments.md, Task 3).
 *
 * The card flow is the existing behaviour it has to reproduce exactly: the
 * same credit row with the same audit values, the same net-zero plan
 * purchase, and the same "points stay with the member" rule when the
 * purchase cannot be completed after the money was taken.
 */
class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TERMINAL = '99990001';

    private string $paymeraLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        // A private file for the `paymera` channel, so the alert a failed
        // fulfilment raises can be read back.
        $this->paymeraLog = tempnam(sys_get_temp_dir(), 'paymera-service-test-');

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => self::TERMINAL,
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'en',
            'logging.channels.paymera' => [
                'driver' => 'single',
                'path' => $this->paymeraLog,
                'level' => 'debug',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->paymeraLog)) {
            unlink($this->paymeraLog);
        }

        parent::tearDown();
    }

    private function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function member(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        return $member;
    }

    private function walletOf(User $member): Wallet
    {
        return Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail();
    }

    private function plan(int $pricePoints = 100): Plan
    {
        return Plan::factory()->create([
            'price_points' => $pricePoints,
            'duration_days' => 30,
            'is_active' => true,
            'is_public' => true,
        ]);
    }

    private function fakeGateway(int $amountMinor = 1000): void
    {
        Http::fake([
            '*/api/get-payment-status/*' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => [
                    'status' => 'A',
                    'creationTimestamp' => '2026-01-26 12:06:52.547',
                    'rrn' => '000009876543',
                    'amount' => $amountMinor,
                    'terminalId' => self::TERMINAL,
                ],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function cardPayment(User $member, array $attributes = [], ?Plan $plan = null): Payment
    {
        $factory = $plan !== null ? Payment::factory()->forPlan($plan) : Payment::factory();

        return $factory->create(array_merge([
            'payment_id' => 'pay-123',
            'user_id' => $member->id,
            'wallet_id' => $this->walletOf($member)->id,
            'amount_minor' => 1000,
            'terminal_id' => self::TERMINAL,
        ], $attributes));
    }

    private function paymeraLogContents(): string
    {
        // The `single` driver writes each line straight through.
        return (string) file_get_contents($this->paymeraLog);
    }

    // ---------------------------------------------------------------
    // Card top-up: exactly the ledger row it wrote before
    // ---------------------------------------------------------------

    public function test_a_gateway_top_up_writes_the_same_ledger_row_as_before(): void
    {
        $member = $this->member();
        $this->fakeGateway();
        $payment = $this->cardPayment($member);

        app(SyncPaymentStatus::class)->handle($payment);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Accepted, $payment->status);

        $credit = WalletTransaction::sole();
        $this->assertSame($credit->id, $payment->wallet_transaction_id);
        $this->assertSame(100, (int) $credit->amount);
        $this->assertSame(WalletTransactionCategory::General, $credit->category);
        $this->assertSame(WalletTransactionSource::TopUp, $credit->source);
        $this->assertNull($credit->expires_at);
        $this->assertSame(__('api.payment.credit_description', ['reference' => 'pay-123']), $credit->description);
        $this->assertSame(PaymentMethod::Paymera, $credit->payment_method);
        $this->assertSame('USD', $credit->payment_currency);
        $this->assertSame('10.00', (string) $credit->payment_amount);
        $this->assertNull($credit->exchange_rate_to_base);
        $this->assertNull($credit->point_rate_usd);
        $this->assertNull($credit->performed_by_user_id);

        // A top-up spends nothing.
        $this->assertNull($payment->debit_transaction_id);
        $this->assertSame(100, $payment->amount_points);
    }

    public function test_the_service_credits_a_gateway_top_up_once_however_often_it_is_asked(): void
    {
        $member = $this->member();
        $payment = $this->cardPayment($member, ['status' => PaymentStatus::Accepted]);

        $this->service()->fulfilAcceptedGatewayPayment($payment);
        $this->service()->fulfilAcceptedGatewayPayment($payment->refresh());

        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame(100, app(WalletService::class)->generalBalance($this->walletOf($member)));
        $this->assertSame(PaymentStatus::Accepted, $payment->refresh()->status);
    }

    public function test_a_syrian_gateway_top_up_records_the_charged_amount_and_rate(): void
    {
        config(['paymera.currency' => 'SYP']);
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272']);

        $member = $this->member();
        $payment = $this->cardPayment($member, [
            'status' => PaymentStatus::Accepted,
            'amount_minor' => 147_000_000,
            'currency' => 'SYP',
            'points_to_credit' => 1000,
        ]);

        $this->service()->fulfilAcceptedGatewayPayment($payment);

        $credit = $payment->refresh()->walletTransaction;
        $this->assertSame(1000, (int) $credit->amount);
        $this->assertSame('SYP', $credit->payment_currency);
        $this->assertSame('1470000.00', (string) $credit->payment_amount);
        $this->assertSame('0.0000680272', (string) $credit->exchange_rate_to_base);
        $this->assertNull($credit->point_rate_usd);
    }

    /**
     * The credit's `payment_method` is whatever the gateway says it is, not
     * a hard-coded Paymera.
     */
    public function test_the_credit_records_the_ledger_method_the_gateway_names(): void
    {
        config(['payments.methods.probe' => ['driver' => LedgerProbeGateway::class, 'enabled' => true]]);

        $member = $this->member();
        $payment = $this->cardPayment($member, [
            'method' => 'probe',
            'status' => PaymentStatus::Accepted,
        ]);

        $this->service()->fulfilAcceptedGatewayPayment($payment);

        $this->assertSame(PaymentMethod::Sham, $payment->refresh()->walletTransaction->payment_method);
    }

    // ---------------------------------------------------------------
    // Card plan purchase: credit, then spend, net zero
    // ---------------------------------------------------------------

    public function test_a_gateway_plan_purchase_credits_then_spends_the_points_net_zero(): void
    {
        $member = $this->member();
        $plan = $this->plan(100);
        $this->fakeGateway();
        $payment = $this->cardPayment($member, [], $plan);

        app(SyncPaymentStatus::class)->handle($payment);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Accepted, $payment->status);

        // One credit and one debit, net zero.
        $rows = WalletTransaction::orderBy('id')->get();
        $this->assertCount(2, $rows);
        [$credit, $debit] = $rows;

        $this->assertSame($credit->id, $payment->wallet_transaction_id);
        $this->assertSame(100, (int) $credit->amount);
        $this->assertSame(PaymentMethod::Paymera, $credit->payment_method);

        $this->assertSame($debit->id, $payment->debit_transaction_id);
        $this->assertSame(-100, (int) $debit->amount);
        $this->assertSame(WalletTransactionCategory::General, $debit->category);
        $this->assertSame("Membership purchase: plan #{$plan->id}", $debit->description);
        $this->assertSame(100, $payment->amount_points);
        $this->assertSame(0, app(WalletService::class)->generalBalance($this->walletOf($member)));

        // The membership is active and linked to the payment.
        $membership = Membership::sole();
        $this->assertSame($membership->id, $payment->membership_id);
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertSame($plan->id, $membership->plan_id);
        $this->assertSame(OwnerType::User, $membership->owner_type);
        $this->assertSame($member->id, $membership->owner_id);

        // Audited as a card purchase, and traceable to the payment.
        $activity = Activity::where('description', 'membership_purchased_by_card')->sole();
        // assertEquals: SQLite hands the morph id back as a string.
        $this->assertEquals($membership->id, $activity->subject_id);
        $this->assertSame($payment->id, $activity->properties['payment_id']);
    }

    /**
     * Decision 3 of the Paymera integration, now for any purpose: the money
     * was taken, so the points stay and a human looks.
     */
    public function test_a_plan_repriced_upward_before_acceptance_needs_review_and_keeps_the_points(): void
    {
        $member = $this->member();
        $plan = $this->plan(100);
        $this->fakeGateway();
        $payment = $this->cardPayment($member, [], $plan);

        // Repriced while the member was on the card page.
        $plan->update(['price_points' => 150]);

        app(SyncPaymentStatus::class)->handle($payment);

        $payment->refresh();
        $this->assertSame(PaymentStatus::NeedsReview, $payment->status);
        $this->assertStringContainsString('activation failed', $payment->review_reason);

        // The points are the member's: credited, not spent.
        $this->assertNotNull($payment->wallet_transaction_id);
        $this->assertNull($payment->debit_transaction_id);
        $this->assertNull($payment->membership_id);
        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame(100, app(WalletService::class)->generalBalance($this->walletOf($member)));
        $this->assertSame(0, Membership::count());

        $log = $this->paymeraLogContents();
        $this->assertStringContainsString('ALERT', $log);
        $this->assertStringContainsString('"payment_id":'.$payment->id, $log);
    }

    /**
     * A NeedsReview payment belongs to a human. Asking again must neither
     * re-credit it nor quietly retry the purchase.
     */
    public function test_a_payment_under_review_is_not_fulfilled_again(): void
    {
        $member = $this->member();
        $plan = $this->plan(100);
        $payment = $this->cardPayment($member, ['status' => PaymentStatus::Accepted], $plan);
        $plan->update(['price_points' => 150]);

        $this->service()->fulfilAcceptedGatewayPayment($payment);
        $this->assertSame(PaymentStatus::NeedsReview, $payment->refresh()->status);

        // The plan is repriced back; a replay must still not buy it.
        $plan->update(['price_points' => 100]);
        $this->service()->fulfilAcceptedGatewayPayment($payment->refresh());

        $this->assertSame(PaymentStatus::NeedsReview, $payment->refresh()->status);
        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame(0, Membership::count());
    }

    /**
     * Activation no longer charges (the service already did), which must not
     * lose the one side effect that hung off charging: a Dedicated Desk plan
     * bought in the app arrives without a desk, so staff are told.
     */
    public function test_a_dedicated_desk_plan_bought_by_card_still_tells_staff(): void
    {
        Notification::fake();
        $operator = User::factory()->create();
        $operator->assignRole('operations');

        $member = $this->member();
        $plan = Plan::factory()->dedicatedDesk()->create(['price_points' => 100, 'is_active' => true]);
        $this->fakeGateway();
        $payment = $this->cardPayment($member, [], $plan);

        app(SyncPaymentStatus::class)->handle($payment);

        $this->assertSame(PaymentStatus::Accepted, $payment->refresh()->status);
        Notification::assertSentTo(
            $operator,
            GeneralNotification::class,
            fn (GeneralNotification $n) => $n->toArray($operator)['template_key'] === 'membership.desk_needed',
        );
    }

    // ---------------------------------------------------------------
    // settle(): every other way of paying
    // ---------------------------------------------------------------

    public function test_settling_twice_writes_one_credit_and_one_debit(): void
    {
        $member = $this->member();
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        $plan = $this->plan(100);

        $payment = Payment::factory()->forPlan($plan)->withoutGateway('cash', 100)->create([
            'user_id' => $member->id,
            'wallet_id' => $this->walletOf($member)->id,
            'collected_via' => PaymentMethod::Cash,
            'received_by' => $operator->id,
        ]);

        // $12 handed over for a 100-point plan: 120 points in, 100 out.
        $funding = new Funding(
            points: 120,
            method: PaymentMethod::Cash,
            currency: 'USD',
            amount: '12.00',
            rateToBase: null,
            pointRateUsd: '0.10',
            performedBy: $operator->id,
        );

        $this->service()->settle($payment, $funding);
        $this->service()->settle($payment->refresh(), $funding);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Accepted, $payment->status);

        $rows = WalletTransaction::orderBy('id')->get();
        $this->assertCount(2, $rows);
        [$credit, $debit] = $rows;

        $this->assertSame($credit->id, $payment->wallet_transaction_id);
        $this->assertSame(120, (int) $credit->amount);
        $this->assertSame(PaymentMethod::Cash, $credit->payment_method);
        $this->assertSame('USD', $credit->payment_currency);
        $this->assertSame('12.00', (string) $credit->payment_amount);
        $this->assertNull($credit->exchange_rate_to_base);
        $this->assertSame('0.1000', (string) $credit->point_rate_usd);
        $this->assertSame($operator->id, $credit->performed_by_user_id);

        $this->assertSame($debit->id, $payment->debit_transaction_id);
        $this->assertSame(-100, (int) $debit->amount);
        $this->assertSame(100, $payment->amount_points);

        // The overpayment stays in the wallet as points.
        $this->assertSame(20, app(WalletService::class)->generalBalance($this->walletOf($member)));
        $this->assertSame(1, Membership::count());
        $this->assertSame(Membership::sole()->id, $payment->membership_id);
        $this->assertSame(1, Activity::where('description', 'membership_purchased_at_reception')->count());
    }

    public function test_settling_a_top_up_only_credits_and_only_once(): void
    {
        $member = $this->member();
        $payment = Payment::factory()->withoutGateway('cash', 0)->create([
            'user_id' => $member->id,
            'wallet_id' => $this->walletOf($member)->id,
        ]);

        $funding = new Funding(
            points: 250,
            method: PaymentMethod::Cash,
            currency: 'USD',
            amount: '25.00',
            rateToBase: null,
            pointRateUsd: '0.10',
            performedBy: null,
        );

        $this->service()->settle($payment, $funding);
        $this->service()->settle($payment->refresh(), $funding);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertSame(WalletTransaction::sole()->id, $payment->wallet_transaction_id);
        $this->assertNull($payment->debit_transaction_id);
        $this->assertSame(250, app(WalletService::class)->generalBalance($this->walletOf($member)));
    }

    public function test_a_plan_paid_with_points_spends_them_and_is_audited_as_a_purchase(): void
    {
        $member = $this->member();
        $plan = $this->plan(100);
        app(WalletService::class)->creditGeneral($this->walletOf($member), 150, WalletTransactionSource::TopUp);

        $payment = Payment::factory()->forPlan($plan)->withoutGateway('wallet', 100)->create([
            'user_id' => $member->id,
            'wallet_id' => $this->walletOf($member)->id,
        ]);

        $this->service()->settle($payment);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertNull($payment->wallet_transaction_id);
        $this->assertNotNull($payment->debit_transaction_id);
        $this->assertSame(50, app(WalletService::class)->generalBalance($this->walletOf($member)));
        $this->assertSame(1, Activity::where('description', 'membership_purchased')->count());
    }

    /**
     * The plan belongs to whoever owns the wallet that paid for it — the
     * company, when a company admin buys for the company.
     */
    public function test_a_plan_paid_from_a_company_wallet_is_the_companys(): void
    {
        $company = Company::factory()->create();
        Space::factory()->create([
            'building_id' => Building::factory()->create(['branch_id' => $company->branch_id])->id,
            'capacity' => 10,
        ]);
        $companyWallet = Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        app(WalletService::class)->creditGeneral($companyWallet, 500, WalletTransactionSource::TopUp);

        $buyer = $this->member();
        $plan = $this->plan(100);

        $payment = Payment::factory()->forPlan($plan)->withoutGateway('wallet', 100)->create([
            'user_id' => $buyer->id,
            'wallet_id' => $companyWallet->id,
        ]);

        $this->service()->settle($payment);

        $membership = Membership::sole();
        $this->assertSame(OwnerType::Company, $membership->owner_type);
        $this->assertSame($company->id, $membership->owner_id);
        $this->assertSame(400, app(WalletService::class)->generalBalance($companyWallet));
        $this->assertSame(0, app(WalletService::class)->generalBalance($this->walletOf($buyer)));
    }

    public function test_settling_with_too_few_points_throws_and_changes_nothing(): void
    {
        $member = $this->member();
        $plan = $this->plan(100);
        app(WalletService::class)->creditGeneral($this->walletOf($member), 30, WalletTransactionSource::TopUp);

        $payment = Payment::factory()->forPlan($plan)->withoutGateway('wallet', 100)->create([
            'user_id' => $member->id,
            'wallet_id' => $this->walletOf($member)->id,
        ]);

        try {
            $this->service()->settle($payment);
            $this->fail('Expected InsufficientPointsException.');
        } catch (InsufficientPointsException $e) {
            $this->assertSame(30, $e->current);
            $this->assertSame(100, $e->required);
            $this->assertSame(70, $e->shortage);
        }

        $payment->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertNull($payment->debit_transaction_id);
        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame(0, Membership::count());
    }

    public function test_insufficient_points_render_as_a_422_with_the_numbers(): void
    {
        Route::get('/api/v1/_test/insufficient-points', function () {
            throw new InsufficientPointsException(current: 30, required: 100, shortage: 70);
        });

        foreach (['en', 'ar'] as $locale) {
            $expected = __('api.payment.insufficient_points', ['current' => 30, 'required' => 100, 'shortage' => 70], $locale);
            $this->assertNotSame('api.payment.insufficient_points', $expected);

            $this->getJson('/api/v1/_test/insufficient-points', ['lang' => $locale])
                ->assertStatus(422)
                ->assertExactJson([
                    'message' => $expected,
                    'current' => 30,
                    'required' => 100,
                    'shortage' => 70,
                ]);
        }
    }

    // ---------------------------------------------------------------
    // What settle() refuses, and what it never does twice
    // ---------------------------------------------------------------

    /**
     * A payment that failed, expired, was cancelled, reversed or handed to a
     * human is over. Settling it would spend the member's points on a
     * purchase nobody is waiting for, or flip a reversed payment back.
     */
    public function test_a_payment_in_a_final_state_cannot_be_settled(): void
    {
        $member = $this->member();
        $plan = $this->plan(100);
        app(WalletService::class)->creditGeneral($this->walletOf($member), 500, WalletTransactionSource::TopUp);

        foreach ([PaymentStatus::Failed, PaymentStatus::Canceled, PaymentStatus::Reversed, PaymentStatus::Expired, PaymentStatus::NeedsReview] as $status) {
            $payment = Payment::factory()->forPlan($plan)->withoutGateway('wallet', 100)->create([
                'user_id' => $member->id,
                'wallet_id' => $this->walletOf($member)->id,
                'status' => $status,
            ]);

            try {
                $this->service()->settle($payment);
                $this->fail("Settled a {$status->value} payment.");
            } catch (ReceptionActionException $e) {
                $this->assertSame('api.payment.not_settleable', $e->messageKey);
                $this->assertSame(409, $e->status);
            }

            $this->assertSame($status, $payment->refresh()->status);
        }

        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame(0, Membership::count());
    }

    /**
     * The card paid for the plan, so the audit entry says it was charged —
     * as it did before PaymentService took the charge out of activation.
     */
    public function test_a_card_plan_purchase_is_audited_as_charged(): void
    {
        $member = $this->member();
        $plan = $this->plan(100);
        $this->fakeGateway();
        $payment = $this->cardPayment($member, [], $plan);

        app(SyncPaymentStatus::class)->handle($payment);

        $activity = Activity::where('description', 'membership_purchased_by_card')->sole();
        $this->assertTrue($activity->properties['charged']);
        $this->assertSame($payment->id, $activity->properties['payment_id']);
    }

    /**
     * Nothing to spend writes no debit row, so the debit link cannot be what
     * says the payment is done — a second settle would buy the plan again.
     */
    public function test_a_free_purchase_is_settled_once(): void
    {
        $member = $this->member();
        $plan = $this->plan(0);

        $payment = Payment::factory()->forPlan($plan)->withoutGateway('wallet', 0)->create([
            'user_id' => $member->id,
            'wallet_id' => $this->walletOf($member)->id,
        ]);

        $this->service()->settle($payment);
        $this->service()->settle($payment->refresh());

        $payment->refresh();
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertNotNull($payment->settled_at);
        $this->assertSame(1, Membership::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    /**
     * A top-up buys points with money. Without the money there is nothing to
     * accept.
     */
    public function test_a_top_up_cannot_be_settled_without_money(): void
    {
        $member = $this->member();
        $payment = Payment::factory()->withoutGateway('cash', 0)->create([
            'user_id' => $member->id,
            'wallet_id' => $this->walletOf($member)->id,
        ]);

        try {
            $this->service()->settle($payment);
            $this->fail('Settled a top-up with no money.');
        } catch (\LogicException) {
        }

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(0, WalletTransaction::count());
    }

    /**
     * A deadlock or a dropped connection is not "the purchase failed": the
     * database already rolled the credit back with it. Marking the payment
     * NeedsReview ("credited") would be a lie and stop the reconciler; the
     * error must reach the sync so it rolls back and is retried.
     */
    public function test_a_database_failure_during_the_spend_is_retried_not_reviewed(): void
    {
        $member = $this->member();
        $plan = $this->plan(100);
        $payment = $this->cardPayment($member, ['status' => PaymentStatus::Accepted], $plan);

        $this->app->bind(PlanPurchaseFulfiller::class, fn ($app) => new class($app->make(MembershipActivationService::class), $app->make(DedicatedDeskService::class), $app->make(PaymentMethodRegistry::class)) extends PlanPurchaseFulfiller
        {
            public function fulfil(Payment $payment): void
            {
                throw new QueryException('mysql', 'insert into memberships', [], new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'));
            }
        });

        try {
            $this->service()->fulfilAcceptedGatewayPayment($payment);
            $this->fail('The deadlock was swallowed.');
        } catch (QueryException|DeadlockException) {
            // Inside a transaction Laravel re-raises it as a DeadlockException.
        }

        $this->assertNotSame(PaymentStatus::NeedsReview, $payment->refresh()->status);
        $this->assertNull($payment->review_reason);
    }

    public function test_each_money_in_purpose_has_its_fulfiller(): void
    {
        $registry = app(FulfillerRegistry::class);

        $this->assertInstanceOf(WalletTopUpFulfiller::class, $registry->for(PaymentPurpose::WalletTopUp));
        $this->assertInstanceOf(PlanPurchaseFulfiller::class, $registry->for(PaymentPurpose::PlanPurchase));
    }
}

/**
 * A gateway that records its credits under a different ledger method, to
 * prove the credit asks the gateway rather than assuming Paymera.
 */
class LedgerProbeGateway extends PaymeraGateway
{
    public function key(): string
    {
        return 'probe';
    }

    public function ledgerMethod(): PaymentMethod
    {
        return PaymentMethod::Sham;
    }
}
