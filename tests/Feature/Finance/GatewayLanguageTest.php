<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\ExchangeRate;
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
 * The card page opens in the member's language — the `lang` header every
 * request already carries — not a field of the request body.
 */
class GatewayLanguageTest extends TestCase
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
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'en',
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

        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        Sanctum::actingAs($member, ['member-app']);
    }

    private function topUp(array $headers, array $extra = [])
    {
        return $this->postJson('/api/v1/member/wallet/top-ups', [
            'payment_method' => 'paymera',
            'amount' => '10000',
        ] + $extra, $headers);
    }

    private function assertGatewayAsked(string $lang): void
    {
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/create-payment')
            && $request['lang'] === $lang);
        $this->assertSame($lang, Payment::sole()->lang);
    }

    public function test_the_arabic_header_opens_the_page_in_arabic(): void
    {
        $this->topUp(['lang' => 'ar'])->assertCreated();

        $this->assertGatewayAsked('ar');
    }

    public function test_the_english_header_opens_the_page_in_english(): void
    {
        $this->topUp(['lang' => 'en'])->assertCreated();

        $this->assertGatewayAsked('en');
    }

    public function test_a_lang_in_the_body_is_ignored(): void
    {
        $this->topUp(['lang' => 'ar'], ['lang' => 'en'])->assertCreated();

        $this->assertGatewayAsked('ar');
    }

    public function test_no_header_falls_back_to_the_app_default_arabic(): void
    {
        $this->topUp([])->assertCreated();

        $this->assertGatewayAsked('ar');
    }
}
