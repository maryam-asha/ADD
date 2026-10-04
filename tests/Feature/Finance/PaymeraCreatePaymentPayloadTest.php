<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a card payment asks Paymera for (carried from Task 2 to Task 7 and
 * left open; final review): the member comes back to our callback and
 * Paymera pings our trigger, both keyed by the payment's own token, and
 * saved cards — when switched on — are filed under our member id.
 */
class PaymeraCreatePaymentPayloadTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

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

        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1'],
            ]),
        ]);

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        Sanctum::actingAs($this->member, ['member-app']);
    }

    private function createPaymentRequest(): Request
    {
        $sent = Http::recorded(fn (Request $request) => str_contains($request->url(), 'create-payment'));
        $this->assertCount(1, $sent);

        return $sent->first()[0];
    }

    public function test_a_top_up_sends_our_callback_and_trigger_urls_and_files_saved_cards_under_the_member(): void
    {
        config(['paymera.saved_cards_enabled' => true]);

        $this->postJson('/api/v1/member/payments', ['purpose' => 'wallet_top_up', 'amount' => '10.00'])->assertCreated();

        $token = Payment::sole()->callback_token;
        $this->assertNotNull($token);

        $request = $this->createPaymentRequest();
        $this->assertSame(route('paymera.callback', ['token' => $token]), $request['callbackURL']);
        $this->assertSame(route('paymera.trigger', ['token' => $token]), $request['triggerURL']);
        $this->assertSame(1, $request['savedCards']);
        $this->assertSame((string) $this->member->id, $request['appUser']);

        Http::assertSent(fn (Request $sent) => str_contains($sent->url(), 'create-payment')
            && $sent['callbackURL'] === route('paymera.callback', ['token' => $token])
            && $sent['triggerURL'] === route('paymera.trigger', ['token' => $token])
            && $sent['savedCards'] === 1
            && $sent['appUser'] === (string) $this->member->id);
    }

    public function test_a_booking_paid_by_card_sends_the_same_urls_and_no_saved_cards_when_they_are_off(): void
    {
        config(['paymera.saved_cards_enabled' => false]);
        $booking = Booking::factory()->create([
            'user_id' => $this->member->id,
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            'price_points' => 100,
        ]);

        $this->postJson('/api/v1/member/payments', ['purpose' => 'booking', 'method' => 'paymera', 'payable_id' => $booking->id])
            ->assertCreated();

        $token = Payment::sole()->callback_token;
        $this->assertNotNull($token);

        $request = $this->createPaymentRequest();
        $this->assertSame(route('paymera.callback', ['token' => $token]), $request['callbackURL']);
        $this->assertSame(route('paymera.trigger', ['token' => $token]), $request['triggerURL']);
        $this->assertArrayNotHasKey('savedCards', $request->data());
        $this->assertArrayNotHasKey('appUser', $request->data());
    }
}
