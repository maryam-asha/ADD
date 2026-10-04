<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Exceptions\InvalidMoneyAmountException;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A money amount on a payment endpoint is a plain decimal, and anything
 * else is a 422, never a 500 (final review M4): exponent spellings pass
 * `numeric` but break bcmath, so the request rules refuse them and the
 * converter refuses anything that is not a plain decimal with a domain
 * error rendered as 422.
 *
 * Surrounding spaces never reach either: the TrimStrings middleware makes
 * ' 5' the same money as '5'.
 */
class MoneyAmountSpellingTest extends TestCase
{
    use RefreshDatabase;

    private const EXPONENTS = ['1e0', '1.0E+2'];

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'en',
        ]);

        $this->operator = User::factory()->create();
        $this->operator->assignRole('operations');
    }

    private function member(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        return $member;
    }

    private function unpaidBooking(User $member): Booking
    {
        return Booking::factory()->create([
            'user_id' => $member->id,
            'checked_in_at' => now()->subHours(2),
            'checked_out_at' => now()->subHour(),
            'amount_owed' => '10.00',
            'price_points' => 100,
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
        ]);
    }

    public function test_reception_settle_refuses_an_exponent_amount_and_reads_a_padded_one_as_money(): void
    {
        $booking = $this->unpaidBooking($this->member());
        Sanctum::actingAs($this->operator, ['*']);

        foreach (self::EXPONENTS as $amount) {
            $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/payments/settle', [
                'purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => 'cash', 'amount' => $amount,
            ])->assertStatus(422)->assertJsonValidationErrors('amount');
        }

        // Probe E's path: too little, spelled with a space — the shortage,
        // not a 500.
        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/payments/settle', [
            'purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => 'cash', 'amount' => ' 5',
        ])
            ->assertStatus(422)
            ->assertJsonPath('shortage', '5.00');

        $this->assertSame(0, Payment::count());
    }

    public function test_reception_top_up_and_its_preview_refuse_an_exponent_amount(): void
    {
        $member = $this->member();
        Sanctum::actingAs($this->operator, ['*']);

        foreach (['reception/wallet-top-ups/preview', 'reception/wallet-top-ups'] as $path) {
            foreach (self::EXPONENTS as $amount) {
                $this->withHeader('lang', 'en')->postJson("/api/v1/admin/{$path}", [
                    'user_id' => $member->id, 'payment_method' => 'cash', 'amount' => $amount,
                ])->assertStatus(422)->assertJsonValidationErrors('amount');
            }
        }

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id, 'payment_method' => 'cash', 'amount' => ' 5',
        ])->assertCreated();
    }

    public function test_a_member_card_top_up_refuses_an_exponent_amount(): void
    {
        Sanctum::actingAs($this->member(), ['*']);
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-9/en', 'paymentId' => 'pay-9'],
            ]),
        ]);

        foreach (self::EXPONENTS as $amount) {
            $this->withHeader('lang', 'en')->postJson('/api/v1/member/payments', ['purpose' => 'wallet_top_up', 'amount' => $amount])
                ->assertStatus(422)
                ->assertJsonValidationErrors('amount');
        }

        $this->assertSame(0, Payment::count());

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/payments', ['purpose' => 'wallet_top_up', 'amount' => ' 5'])
            ->assertCreated();
        $this->assertSame(500, Payment::sole()->amount_minor);
    }

    /**
     * Past the request rules (a caller that skipped them), the converter
     * itself refuses: a domain error, not bcmath's ValueError.
     */
    public function test_the_settlement_refuses_a_non_decimal_amount_with_a_domain_error(): void
    {
        $booking = $this->unpaidBooking($this->member());

        foreach (['1e0', ' 5', '1.0E+2'] as $amount) {
            try {
                app(PaymentService::class)->settleAtReception(PaymentPurpose::Booking, $booking, PaymentMethod::Cash, $amount, null, $this->operator);
                $this->fail("[{$amount}] was accepted.");
            } catch (InvalidMoneyAmountException) {
                // Refused before anything was written.
            }
        }

        $this->assertSame(0, Payment::count());
    }

    public function test_the_domain_error_renders_as_a_422(): void
    {
        $response = app(ExceptionHandler::class)->render(
            Request::create('/api/v1/admin/reception/payments/settle', 'POST', server: ['HTTP_ACCEPT' => 'application/json']),
            InvalidMoneyAmountException::for('1e0'),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(__('api.payment.invalid_amount'), json_decode($response->getContent(), true)['message']);
    }
}
