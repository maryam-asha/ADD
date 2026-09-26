<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Events\Finance\PaymentAccepted;
use App\Events\Finance\PaymentReversed;
use App\Services\Permissions\PermissionSyncService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin reversal of an accepted payment, and the permission that gates it.
 */
class PaymeraReversalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        // Branches-style permission gating means the permission has to
        // exist before anyone — admin included — can reverse anything.
        app(PermissionSyncService::class)->sync();

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-key',
            'paymera.terminal_id' => '99990001',
        ]);
    }

    private function acceptedPayment(): Payment
    {
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
        ]);

        // A real accepted payment has already credited its points, and
        // links the transaction that did it.
        $transaction = app(WalletService::class)->creditGeneral(
            $wallet,
            100,
            WalletTransactionSource::TopUp,
            'Card payment'
        );

        return Payment::factory()->accepted()->create([
            'user_id' => $member->id,
            'wallet_id' => $wallet->id,
            'points_to_credit' => 100,
            'wallet_transaction_id' => $transaction->id,
        ]);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    private function fakeCancelSuccess(): void
    {
        Http::fake([
            '*/api/cancel-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
            ]),
        ]);
    }

    /**
     * The field name here is the whole test: `payment_id` with an
     * underscore, unlike `paymentId` everywhere else in this API.
     */
    public function test_reversal_sends_payment_id_in_paymeras_snake_case_form(): void
    {
        $this->actingAsAdmin();
        $payment = $this->acceptedPayment();
        $this->fakeCancelSuccess();

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reverse")->assertOk();

        Http::assertSent(function ($request) use ($payment) {
            if (! str_contains($request->url(), 'cancel-payment')) {
                return false;
            }

            return array_key_exists('payment_id', $request->data())
                && $request['payment_id'] === $payment->payment_id
                && ! array_key_exists('paymentId', $request->data());
        });
    }

    public function test_a_successful_reversal_updates_the_status_and_records_who_did_it(): void
    {
        $admin = $this->actingAsAdmin();
        $payment = $this->acceptedPayment();
        $this->fakeCancelSuccess();

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reverse")
            ->assertOk()
            ->assertJsonStructure(['message']);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Reversed, $payment->status);
        $this->assertSame($admin->id, $payment->reversed_by);
        $this->assertNotNull($payment->reversed_at);
    }

    public function test_a_reversal_claws_the_points_back_out_of_the_wallet(): void
    {
        $this->actingAsAdmin();
        $payment = $this->acceptedPayment();
        $this->fakeCancelSuccess();

        $wallet = $payment->wallet;
        $this->assertSame(100, app(WalletService::class)->generalBalance($wallet));

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reverse")->assertOk();

        // Back to zero, via a compensating debit rather than a deletion —
        // the original credit stays in the member's history.
        $this->assertSame(0, app(WalletService::class)->generalBalance($wallet));
        $this->assertSame(2, $wallet->transactions()->count());
    }

    /**
     * Regression: FulfilAcceptedPayment and this reversal listener were
     * each registered twice — once by Laravel 12's auto-discovery (which
     * reads handle()'s parameter type) and once by an explicit
     * Event::listen() in AppServiceProvider. A single reversal therefore
     * wrote TWO clawback rows and took double the points off the member.
     * The credit side hid the same double-fire behind its idempotence
     * check, so only the reversal surfaced it.
     */
    public function test_each_payment_listener_is_registered_exactly_once(): void
    {
        $dispatcher = app('events');

        $this->assertCount(
            1,
            $dispatcher->getListeners(PaymentReversed::class),
            'PaymentReversed must have exactly one listener — a second registration double-debits the member.'
        );

        $this->assertCount(
            1,
            $dispatcher->getListeners(PaymentAccepted::class),
            'PaymentAccepted must have exactly one listener.'
        );
    }

    public function test_a_pending_payment_cannot_be_reversed(): void
    {
        $this->actingAsAdmin();

        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        $payment = Payment::factory()->pending()->create([
            'user_id' => $member->id,
            'wallet_id' => $wallet->id,
        ]);

        Http::fake();

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reverse")->assertStatus(422);

        // The bank is never called for a payment that was never accepted.
        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function test_an_already_reversed_payment_cannot_be_reversed_again(): void
    {
        $this->actingAsAdmin();
        $payment = $this->acceptedPayment();
        $this->fakeCancelSuccess();

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reverse")->assertOk();
        $this->postJson("/api/v1/admin/payments/{$payment->id}/reverse")->assertStatus(422);

        // One clawback, not two.
        $this->assertSame(0, app(WalletService::class)->generalBalance($payment->wallet));
        $this->assertSame(2, $payment->wallet->transactions()->count());
    }

    /**
     * A gateway that refuses the reversal must not leave the payment
     * reading as reversed — the cardholder would never have been refunded.
     */
    public function test_a_gateway_rejection_leaves_the_payment_accepted(): void
    {
        $this->actingAsAdmin();
        $payment = $this->acceptedPayment();

        Http::fake([
            '*/api/cancel-payment' => Http::response([
                'ErrorMessage' => 'Failure',
                'ErrorCode' => 100,
            ]),
        ]);

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reverse")->assertStatus(502);

        $this->assertSame(PaymentStatus::Accepted, $payment->refresh()->status);
        $this->assertNull($payment->reversed_at);

        // And the points are untouched.
        $this->assertSame(100, app(WalletService::class)->generalBalance($payment->wallet));
    }

    public function test_an_operations_user_without_the_permission_cannot_reverse(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        // The sync gives `operations` no payments.reverse grant by default.
        $operator->revokePermissionTo('payments.reverse');
        $operator->roles()->first()->revokePermissionTo('payments.reverse');
        Sanctum::actingAs($operator, ['*']);

        $payment = $this->acceptedPayment();
        Http::fake();

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reverse")->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Accepted, $payment->refresh()->status);
    }

    public function test_a_member_cannot_reach_the_reversal_endpoint(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $payment = $this->acceptedPayment();
        Http::fake();

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reverse")->assertForbidden();

        Http::assertNothingSent();
    }
}
