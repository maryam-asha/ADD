<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Jobs\SyncPaymeraPaymentJob;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Paymera integration's behaviour, end to end, with Http::fake() —
 * nothing here reaches the real gateway.
 *
 * The tests that matter most are the ones asserting what must NOT happen:
 * an amount mismatch must not credit, a double sync must not credit twice,
 * and the API key must never appear in a log.
 */
class PaymeraPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key-must-never-be-logged';

    private const TERMINAL = '99990001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => self::API_KEY,
            'paymera.terminal_id' => self::TERMINAL,
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'en',
            'paymera.pending_expiry_minutes' => 60,
        ]);
    }

    private function member(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        Sanctum::actingAs($member, ['member-app']);

        return $member;
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

    /**
     * @param  array<string, mixed>  $statusData
     */
    private function fakeGateway(array $statusData = [], string $paymentId = 'pay-123'): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => [
                    'url' => "https://egate-t.paymera.test/start/{$paymentId}/en",
                    'paymentId' => $paymentId,
                ],
            ]),
            '*/api/get-payment-status/*' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => array_merge([
                    'status' => 'A',
                    'creationTimestamp' => '2026-01-26 12:06:52.547',
                    'rrn' => '000009876543',
                    'amount' => 1000,
                    'terminalId' => self::TERMINAL,
                ], $statusData),
            ]),
            '*/api/cancel-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
            ]),
        ]);
    }

    // ---------------------------------------------------------------
    // 1. Creating a payment
    // ---------------------------------------------------------------

    public function test_starting_a_top_up_stores_the_payment_id_and_returns_the_redirect_url(): void
    {
        $this->member();
        $this->fakeGateway();

        $response = $this->postJson('/api/v1/member/wallet/top-ups', [
            'payment_method' => 'paymera',
            'amount' => '10.00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.redirect_url', 'https://egate-t.paymera.test/start/pay-123/en')
            ->assertJsonPath('data.payment.status', 'pending');

        $payment = Payment::first();
        $this->assertSame('pay-123', $payment->payment_id);
        $this->assertSame(1000, $payment->amount_minor);
        $this->assertSame(100, $payment->points_to_credit);

        // The amount reaching Paymera is a bare integer — no decimals, no
        // separators — and carries our terminal, not anything client-supplied.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'create-payment')) {
                return false;
            }

            return $request['amount'] === 1000
                && $request['terminalId'] === self::TERMINAL
                && is_int($request['amount']);
        });
    }

    public function test_the_charged_amount_comes_from_the_plan_not_the_request(): void
    {
        $this->member();
        $plan = $this->plan(pricePoints: 250);
        $this->fakeGateway();

        // The client tries to name its own price. It must be ignored.
        $this->postJson('/api/v1/member/memberships', [
            'plan_id' => $plan->id,
            'payment_method' => 'paymera',
            'amount' => '0.01',
        ])->assertCreated();

        $payment = Payment::first();

        // 250 points x 0.10 USD = 25.00 USD = 2500 minor units.
        $this->assertSame(250, $payment->points_to_credit);
        $this->assertSame(2500, $payment->amount_minor);
    }

    // ---------------------------------------------------------------
    // 2. Unauthorized (ErrorCode 1)
    // ---------------------------------------------------------------

    /**
     * Verified against the live test gateway on 2026-09-27: a bad or empty
     * API key does NOT come back as the documented ErrorCode 1. It answers
     * ErrorCode 100 — the generic failure code — with the real cause only
     * in ErrorMessage ("Invalid API key format").
     *
     * That must still read as a configuration fault (503, "try shortly"),
     * not as the generic 502 "could not start", because the two need
     * opposite responses: one needs someone to fix .env, the other is worth
     * retrying.
     */
    public function test_a_credential_fault_reported_as_error_code_100_is_still_treated_as_unauthorized(): void
    {
        $this->member();

        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Invalid API key format',
                'ErrorCode' => 100,
            ]),
        ]);

        $this->postJson('/api/v1/member/wallet/top-ups', [
            'payment_method' => 'paymera',
            'amount' => '10.00',
        ])->assertStatus(503);

        $this->assertSame(PaymentStatus::Failed, Payment::first()->status);
    }

    /**
     * The counterpart: a genuine payment-side failure with the same
     * ErrorCode 100 must NOT be mistaken for a credential problem, or a
     * real outage would be reported as a misconfiguration.
     */
    public function test_a_genuine_vendor_failure_is_not_mistaken_for_a_credential_fault(): void
    {
        $this->member();

        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Error, some info are missing',
                'ErrorCode' => 100,
            ]),
        ]);

        // 502, not 503 — this one is worth retrying.
        $this->postJson('/api/v1/member/wallet/top-ups', [
            'payment_method' => 'paymera',
            'amount' => '10.00',
        ])->assertStatus(502);
    }

    public function test_error_code_one_surfaces_as_a_service_unavailable_and_not_a_declined_card(): void
    {
        $this->member();

        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Unauthorized',
                'ErrorCode' => 1,
            ]),
        ]);

        $response = $this->postJson('/api/v1/member/wallet/top-ups', [
            'payment_method' => 'paymera',
            'amount' => '10.00',
        ]);

        // 503, not 402/422: a rejected key or an unregistered server IP is
        // our fault and must not read to the member as a card problem.
        $response->assertStatus(503);

        // The local row is closed out rather than left pending forever.
        $this->assertSame(PaymentStatus::Failed, Payment::first()->status);

        // The key must not leak into the response body under any path.
        $this->assertStringNotContainsString(self::API_KEY, $response->getContent());
    }

    // ---------------------------------------------------------------
    // 3-6. Status handling
    // ---------------------------------------------------------------

    public function test_accepted_with_a_matching_amount_credits_the_wallet(): void
    {
        $member = $this->member();
        $this->fakeGateway();

        $payment = Payment::factory()->create([
            'user_id' => $member->id,
            'wallet_id' => Wallet::where('owner_id', $member->id)->first()->id,
            'amount_minor' => 1000,
            'points_to_credit' => 100,
            'terminal_id' => self::TERMINAL,
        ]);

        app(SyncPaymentStatus::class)->handle($payment);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertSame('000009876543', $payment->rrn);
        $this->assertNotNull($payment->wallet_transaction_id);

        $wallet = Wallet::where('owner_id', $member->id)->first();
        $this->assertSame(100, app(WalletService::class)->generalBalance($wallet));

        // The credit records how the money arrived, like a till top-up does.
        $this->assertSame(PaymentMethod::Paymera, $payment->walletTransaction->payment_method);
        $this->assertNull($payment->walletTransaction->performed_by_user_id);
    }

    public function test_accepted_with_a_mismatched_amount_does_not_credit_and_is_flagged_for_review(): void
    {
        $member = $this->member();

        // The gateway reports a different amount from the one we recorded.
        $this->fakeGateway(['amount' => 5000]);

        $payment = Payment::factory()->create([
            'user_id' => $member->id,
            'wallet_id' => Wallet::where('owner_id', $member->id)->first()->id,
            'amount_minor' => 1000,
            'points_to_credit' => 100,
            'terminal_id' => self::TERMINAL,
        ]);

        app(SyncPaymentStatus::class)->handle($payment);

        $payment->refresh();
        $this->assertSame(PaymentStatus::NeedsReview, $payment->status);
        $this->assertStringContainsString('amount mismatch', $payment->review_reason);

        // Nothing credited.
        $this->assertNull($payment->wallet_transaction_id);
        $wallet = Wallet::where('owner_id', $member->id)->first();
        $this->assertSame(0, app(WalletService::class)->generalBalance($wallet));
    }

    public function test_accepted_with_a_mismatched_terminal_does_not_credit(): void
    {
        $member = $this->member();
        $this->fakeGateway(['terminalId' => '11110000']);

        $payment = Payment::factory()->create([
            'user_id' => $member->id,
            'wallet_id' => Wallet::where('owner_id', $member->id)->first()->id,
            'amount_minor' => 1000,
            'terminal_id' => self::TERMINAL,
        ]);

        app(SyncPaymentStatus::class)->handle($payment);

        $payment->refresh();
        $this->assertSame(PaymentStatus::NeedsReview, $payment->status);
        $this->assertStringContainsString('terminal mismatch', $payment->review_reason);
        $this->assertNull($payment->wallet_transaction_id);
    }

    /**
     * Paymera's docs type terminalId as a number in some examples and a
     * string in others. A numeric-vs-string difference must not read as
     * tampering.
     */
    public function test_a_numeric_terminal_id_still_matches_the_stored_string(): void
    {
        $member = $this->member();
        $this->fakeGateway(['terminalId' => 99990001]);

        $payment = Payment::factory()->create([
            'user_id' => $member->id,
            'wallet_id' => Wallet::where('owner_id', $member->id)->first()->id,
            'amount_minor' => 1000,
            'terminal_id' => self::TERMINAL,
        ]);

        app(SyncPaymentStatus::class)->handle($payment);

        $this->assertSame(PaymentStatus::Accepted, $payment->refresh()->status);
    }

    public static function unsuccessfulStatusProvider(): array
    {
        return [
            'failed' => ['F', PaymentStatus::Failed],
            'canceled' => ['C', PaymentStatus::Canceled],
        ];
    }

    #[DataProvider('unsuccessfulStatusProvider')]
    public function test_failed_and_canceled_are_recorded_without_crediting(string $gatewayStatus, PaymentStatus $expected): void
    {
        $member = $this->member();
        $this->fakeGateway(['status' => $gatewayStatus]);

        $payment = Payment::factory()->create([
            'user_id' => $member->id,
            'wallet_id' => Wallet::where('owner_id', $member->id)->first()->id,
        ]);

        app(SyncPaymentStatus::class)->handle($payment);

        $this->assertSame($expected, $payment->refresh()->status);
        $this->assertNull($payment->wallet_transaction_id);

        $wallet = Wallet::where('owner_id', $member->id)->first();
        $this->assertSame(0, app(WalletService::class)->generalBalance($wallet));
    }

    public function test_pending_leaves_the_payment_pending(): void
    {
        $member = $this->member();
        $this->fakeGateway(['status' => 'P']);

        $payment = Payment::factory()->create([
            'user_id' => $member->id,
            'wallet_id' => Wallet::where('owner_id', $member->id)->first()->id,
        ]);

        app(SyncPaymentStatus::class)->handle($payment);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(1, $payment->check_attempts);
        $this->assertNotNull($payment->last_checked_at);
    }

    // ---------------------------------------------------------------
    // 7. Double processing
    // ---------------------------------------------------------------

    public function test_syncing_twice_credits_only_once(): void
    {
        $member = $this->member();
        $this->fakeGateway();

        $payment = Payment::factory()->create([
            'user_id' => $member->id,
            'wallet_id' => Wallet::where('owner_id', $member->id)->first()->id,
            'amount_minor' => 1000,
            'points_to_credit' => 100,
            'terminal_id' => self::TERMINAL,
        ]);

        $sync = app(SyncPaymentStatus::class);
        $sync->handle($payment);
        $sync->handle($payment->refresh());
        $sync->handle($payment->refresh());

        $wallet = Wallet::where('owner_id', $member->id)->first();

        // Credited once, not three times.
        $this->assertSame(100, app(WalletService::class)->generalBalance($wallet));
        $this->assertSame(1, $wallet->transactions()->count());
    }

    public function test_the_callback_and_the_trigger_together_credit_only_once(): void
    {
        $member = $this->member();
        $this->fakeGateway();

        $payment = Payment::factory()->create([
            'user_id' => $member->id,
            'wallet_id' => Wallet::where('owner_id', $member->id)->first()->id,
            'amount_minor' => 1000,
            'points_to_credit' => 100,
            'terminal_id' => self::TERMINAL,
        ]);

        // The member returns, and Paymera's trigger job runs too.
        $this->getJson("/api/v1/paymera/callback/{$payment->callback_token}")->assertOk();
        (new SyncPaymeraPaymentJob($payment->id))->handle(app(SyncPaymentStatus::class));

        $wallet = Wallet::where('owner_id', $member->id)->first();
        $this->assertSame(100, app(WalletService::class)->generalBalance($wallet));
        $this->assertSame(1, $wallet->transactions()->count());
    }

    // ---------------------------------------------------------------
    // 8. The trigger endpoint
    // ---------------------------------------------------------------

    public function test_the_trigger_endpoint_queues_a_job_and_answers_immediately_without_auth(): void
    {
        Queue::fake();
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $payment = Payment::factory()->create([
            'user_id' => $member->id,
            'wallet_id' => $wallet->id,
        ]);

        // No session, no token, no CSRF — exactly as Paymera calls it.
        $this->postJson("/api/v1/paymera/trigger/{$payment->callback_token}")
            ->assertOk()
            ->assertJson(['message' => 'ok']);

        Queue::assertPushed(SyncPaymeraPaymentJob::class,
            fn ($job) => $job->paymentId === $payment->id);
    }

    public function test_the_trigger_endpoint_also_accepts_get(): void
    {
        Queue::fake();
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $payment = Payment::factory()->create(['user_id' => $member->id, 'wallet_id' => $wallet->id]);

        $this->getJson("/api/v1/paymera/trigger/{$payment->callback_token}")->assertOk();

        Queue::assertPushed(SyncPaymeraPaymentJob::class);
    }

    public function test_an_unknown_callback_token_is_a_404(): void
    {
        $this->getJson('/api/v1/paymera/callback/'.str_repeat('x', 64))->assertNotFound();
    }

    /**
     * The public routes are keyed on a random token precisely so they
     * cannot be walked by payment id.
     */
    public function test_the_callback_route_cannot_be_reached_with_a_payment_id(): void
    {
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        $payment = Payment::factory()->create(['user_id' => $member->id, 'wallet_id' => $wallet->id]);

        $this->getJson("/api/v1/paymera/callback/{$payment->id}")->assertNotFound();
    }

    // ---------------------------------------------------------------
    // 11. Authorization
    // ---------------------------------------------------------------

    public function test_a_member_cannot_read_another_members_payment(): void
    {
        $other = User::factory()->create();
        $otherWallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $other->id]);
        $payment = Payment::factory()->create(['user_id' => $other->id, 'wallet_id' => $otherWallet->id]);

        $this->member();

        // 404 rather than 403, so this cannot be used to probe which ids exist.
        $this->getJson("/api/v1/member/payments/{$payment->id}")->assertNotFound();
    }

    public function test_an_inactive_plan_cannot_be_paid_for(): void
    {
        $this->member();
        $plan = $this->plan();
        $plan->update(['is_active' => false]);

        $this->postJson('/api/v1/member/memberships', [
            'plan_id' => $plan->id,
            'payment_method' => 'paymera',
        ])->assertStatus(422)->assertJsonValidationErrors('plan_id');

        $this->assertSame(0, Payment::count());
    }

    public function test_a_private_plan_cannot_be_paid_for_by_guessing_its_id(): void
    {
        $this->member();
        $plan = $this->plan();
        $plan->update(['is_public' => false]);

        $this->postJson('/api/v1/member/memberships', [
            'plan_id' => $plan->id,
            'payment_method' => 'paymera',
        ])->assertStatus(422)->assertJsonValidationErrors('plan_id');
    }

    public function test_an_amount_worth_less_than_one_point_is_refused(): void
    {
        $this->member();

        // At 0.10 USD per point, 0.05 buys nothing.
        $this->postJson('/api/v1/member/wallet/top-ups', [
            'payment_method' => 'paymera',
            'amount' => '0.05',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame(0, Payment::count());
    }

    // ---------------------------------------------------------------
    // Plan purchase fulfilment
    // ---------------------------------------------------------------

    public function test_a_successful_plan_purchase_credits_points_and_activates_the_membership(): void
    {
        $member = $this->member();
        $plan = $this->plan(pricePoints: 100);
        $this->fakeGateway();

        $payment = Payment::factory()->forPlan($plan)->create([
            'user_id' => $member->id,
            'wallet_id' => Wallet::where('owner_id', $member->id)->first()->id,
            'amount_minor' => 1000,
            'terminal_id' => self::TERMINAL,
        ]);

        app(SyncPaymentStatus::class)->handle($payment);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertNotNull($payment->membership_id);

        $this->assertDatabaseHas('memberships', [
            'id' => $payment->membership_id,
            'plan_id' => $plan->id,
            'owner_id' => $member->id,
        ]);

        // Credited 100 and spent 100 on the plan — net zero, by design.
        $wallet = Wallet::where('owner_id', $member->id)->first();
        $this->assertSame(0, app(WalletService::class)->generalBalance($wallet));
    }

    /**
     * Decision 3: the member's money is never silently absorbed.
     */
    public function test_a_failed_activation_leaves_the_points_with_the_member_and_flags_review(): void
    {
        $member = $this->member();
        $plan = $this->plan(pricePoints: 100);
        $this->fakeGateway();

        $payment = Payment::factory()->forPlan($plan)->create([
            'user_id' => $member->id,
            'wallet_id' => Wallet::where('owner_id', $member->id)->first()->id,
            'amount_minor' => 1000,
            'terminal_id' => self::TERMINAL,
        ]);

        // The plan is withdrawn between starting the payment and returning.
        $plan->update(['is_active' => false]);

        app(SyncPaymentStatus::class)->handle($payment);

        $payment->refresh();
        $this->assertSame(PaymentStatus::NeedsReview, $payment->status);
        $this->assertStringContainsString('activation failed', $payment->review_reason);

        // The points are still the member's.
        $wallet = Wallet::where('owner_id', $member->id)->first();
        $this->assertSame(100, app(WalletService::class)->generalBalance($wallet));
        $this->assertNotNull($payment->wallet_transaction_id);
        $this->assertSame(0, Membership::count());
    }
}
