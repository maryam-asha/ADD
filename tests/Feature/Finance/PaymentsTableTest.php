<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The `payments` table is one ledger of payment attempts for every method
 * (wallet points, cash at reception, any gateway) and every purpose. The
 * card-only columns are nullable now, and a payment can point at whatever it
 * pays for.
 *
 * What this file protects is the other direction: the rows that already
 * exist, and the shipped mobile app, must not notice.
 */
class PaymentsTableTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_03_100000_generalize_payments_table.php');
    }

    private function wallet(): Wallet
    {
        $user = User::factory()->create();

        return Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);
    }

    /**
     * A payment made without a gateway: no terminal, no token, no minor
     * amount.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function nonGatewayPayment(Wallet $wallet, array $overrides = []): Payment
    {
        return Payment::factory()->withoutGateway('wallet', 40)->create(array_merge([
            'user_id' => $wallet->owner_id,
            'wallet_id' => $wallet->id,
        ], $overrides));
    }

    public function test_rows_that_existed_before_read_paymera_and_carry_their_points(): void
    {
        $wallet = $this->wallet();

        // Put the table back the way production has it, write a row the way
        // production wrote it, and run the migration over it.
        $this->migration()->down();

        $id = DB::table('payments')->insertGetId([
            'payment_id' => 'pay-old',
            'callback_token' => str_repeat('a', 64),
            'purpose' => 'wallet_top_up',
            'status' => 'accepted',
            'user_id' => $wallet->owner_id,
            'wallet_id' => $wallet->id,
            'amount_minor' => 1000,
            'currency' => 'USD',
            'currency_exponent' => 2,
            'points_to_credit' => 250,
            'terminal_id' => '99990001',
            'lang' => 'en',
            'check_attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $payment = Payment::query()->findOrFail($id);

        $this->assertSame('paymera', $payment->method);
        $this->assertSame(250, $payment->amount_points);
        $this->assertSame(250, $payment->points_to_credit);
        $this->assertNull($payment->payable_type);
        $this->assertNull($payment->debit_transaction_id);
        $this->assertNull($payment->collected_via);
    }

    public function test_a_payment_with_no_gateway_can_be_created(): void
    {
        $payment = $this->nonGatewayPayment($this->wallet());

        $fresh = Payment::query()->findOrFail($payment->id);

        $this->assertSame('wallet', $fresh->method);
        $this->assertNull($fresh->terminal_id);
        $this->assertNull($fresh->callback_token);
        $this->assertNull($fresh->amount_minor);
        $this->assertNull($fresh->currency);
        $this->assertSame(40, $fresh->amount_points);
    }

    public function test_a_payment_still_defaults_to_the_paymera_method(): void
    {
        $payment = Payment::factory()->create();

        $this->assertSame('paymera', $payment->fresh()->method);
    }

    public function test_the_payable_resolves_a_booking_through_the_booking_alias(): void
    {
        $booking = Booking::factory()->create();

        $payment = Payment::factory()
            ->withoutGateway('wallet', 40)
            ->forPayable($booking)
            ->create(['purpose' => PaymentPurpose::Booking]);

        $fresh = Payment::query()->findOrFail($payment->id);

        $this->assertTrue($fresh->payable->is($booking));
        $this->assertSame('booking', $fresh->getRawOriginal('payable_type'));
        $this->assertSame(PaymentPurpose::Booking, $fresh->purpose);

        $this->assertTrue(Payment::query()->forPayable($booking)->whereKey($payment->id)->exists());
        $this->assertFalse(Payment::query()->forPayable(Booking::factory()->create())->exists());
    }

    public function test_the_new_purposes_are_backed_by_their_stored_strings(): void
    {
        $this->assertSame('booking', PaymentPurpose::Booking->value);
        $this->assertSame('event_registration', PaymentPurpose::EventRegistration->value);
        $this->assertSame('coworking_session', PaymentPurpose::CoworkingSession->value);
    }

    public function test_casts_and_helpers_on_the_new_columns(): void
    {
        $wallet = $this->wallet();
        $receiver = User::factory()->create();

        $payment = $this->nonGatewayPayment($wallet, [
            'method' => 'cash',
            'collected_via' => 'sham',
            'received_amount' => '12.5',
            'received_currency' => 'USD',
            'received_by' => $receiver->id,
            'received_at' => '2026-10-03 09:00:00',
            'expires_at' => '2026-10-03 12:00:00',
            'amount_points' => '40',
        ]);

        $fresh = Payment::query()->findOrFail($payment->id);

        $this->assertSame(PaymentMethod::Sham, $fresh->collected_via);
        $this->assertSame('12.50', $fresh->received_amount);
        $this->assertSame(40, $fresh->amount_points);
        $this->assertTrue($fresh->receivedBy->is($receiver));
        $this->assertSame('2026-10-03 09:00:00', $fresh->received_at->toDateTimeString());
        $this->assertSame('2026-10-03 12:00:00', $fresh->expires_at->toDateTimeString());
        $this->assertFalse($fresh->isRefunded());
        $this->assertNull($fresh->debitTransaction);
        $this->assertNull($fresh->refundTransaction);

        $fresh->forceFill(['refunded_at' => now()])->save();

        $this->assertTrue($fresh->fresh()->isRefunded());
    }

    public function test_the_payment_resource_hides_the_admin_fields_from_a_member(): void
    {
        $this->seed(RoleSeeder::class);

        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        Sanctum::actingAs($member, ['member-app']);

        $booking = Booking::factory()->create(['user_id' => $member->id]);
        $this->nonGatewayPayment($wallet, [
            'purpose' => PaymentPurpose::Booking,
            'status' => PaymentStatus::Accepted,
            'payable_type' => 'booking',
            'payable_id' => $booking->id,
            'collected_via' => 'cash',
            'received_amount' => '5.00',
            'received_currency' => 'USD',
            'received_by' => User::factory()->create()->id,
            'received_at' => now(),
            'refunded_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $row = $this->getJson('/api/v1/member/payments')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('wallet', $row['method']);
        $this->assertSame(40, $row['amount_points']);
        $this->assertSame(['type' => 'booking', 'id' => $booking->id], $row['payable']);
        $this->assertNotNull($row['expires_at']);

        foreach (['collected_via', 'received_amount', 'received_currency', 'received_by', 'received_at', 'refunded_at'] as $adminOnly) {
            $this->assertArrayNotHasKey($adminOnly, $row, "{$adminOnly} must not reach a member");
        }
    }

    public function test_the_payment_resource_shows_the_admin_fields_to_an_admin(): void
    {
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $receiver = User::factory()->create();
        $payment = $this->nonGatewayPayment($this->wallet(), [
            'method' => 'cash',
            'collected_via' => 'cash',
            'received_amount' => '5.00',
            'received_currency' => 'USD',
            'received_by' => $receiver->id,
            'received_at' => '2026-10-03 09:00:00',
            'refunded_at' => '2026-10-03 10:00:00',
        ]);

        $row = $this->getJson("/api/v1/admin/payments/{$payment->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('cash', $row['method']);
        $this->assertNull($row['payable']);
        $this->assertSame('cash', $row['collected_via']);
        $this->assertSame('5.00', $row['received_amount']);
        $this->assertSame('USD', $row['received_currency']);
        $this->assertSame($receiver->id, $row['received_by']);
        $this->assertNotNull($row['received_at']);
        $this->assertNotNull($row['refunded_at']);
    }

    public function test_a_card_payment_opened_now_is_stamped_like_the_ones_backfilled(): void
    {
        $this->seed(RoleSeeder::class);
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
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1'],
            ]),
        ]);

        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        Sanctum::actingAs($member, ['member-app']);

        $this->postJson('/api/v1/member/payments', ['purpose' => 'wallet_top_up', 'amount' => '10.00'])
            ->assertCreated()
            ->assertJsonPath('data.payment.method', 'paymera')
            ->assertJsonPath('data.payment.amount_points', 100)
            ->assertJsonPath('data.payment.payable', null);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame('paymera', $payment->method);
        $this->assertSame(100, $payment->amount_points);
        $this->assertSame($payment->points_to_credit, $payment->amount_points);
    }

    /**
     * A booking, a session or an event registration is paid by naming it.
     */
    public function test_paying_for_a_record_requires_naming_it(): void
    {
        $this->seed(RoleSeeder::class);

        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        Sanctum::actingAs($member, ['member-app']);

        foreach (['booking', 'coworking_session', 'event_registration'] as $purpose) {
            $this->postJson('/api/v1/member/payments', ['purpose' => $purpose, 'amount' => 10])
                ->assertStatus(422)
                ->assertJsonValidationErrors('payable_id');
        }
    }
}
