<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET member/payment-methods — the payment methods the member may use, the
 * wallet method carrying how many points they have.
 */
class PaymentMethodsWalletBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function memberWithToken(int $points): array
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return [$member, $member->createToken('app', ['member-app'])->plainTextToken];
    }

    private function methods(array $query = [], ?string $token = null)
    {
        $headers = ['lang' => 'en'];

        if ($token !== null) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $this->withHeaders($headers)->getJson('/api/v1/member/payment-methods'.($query ? '?'.http_build_query($query) : ''));
    }

    public function test_it_needs_a_members_token(): void
    {
        $this->methods()->assertUnauthorized();
    }

    public function test_it_lists_the_enabled_methods_in_config_order(): void
    {
        [, $token] = $this->memberWithToken(100);

        $response = $this->methods([], $token)->assertOk()->assertJsonPath('data.amount_points', null);

        $this->assertSame(['wallet', 'cash', 'paymera'], array_column($response->json('data.methods'), 'key'));
        $response->assertJsonPath('data.methods.0.label', __('api.payment.methods.wallet', [], 'en'));
    }

    public function test_with_a_members_token_the_wallet_shows_its_balance(): void
    {
        [, $token] = $this->memberWithToken(350);

        $this->methods([], $token)
            ->assertOk()
            ->assertJsonPath('data.methods.0.key', 'wallet')
            ->assertJsonPath('data.methods.0.balance', 350)
            ->assertJsonPath('data.methods.0.available', true)
            ->assertJsonPath('data.methods.0.reason', null)
            ->assertJsonPath('data.methods.0.wallets.0.usable_balance', 350);
    }

    public function test_an_empty_wallet_is_shown_unavailable_with_a_reason(): void
    {
        [, $token] = $this->memberWithToken(0);

        $this->methods([], $token)
            ->assertOk()
            ->assertJsonPath('data.methods.0.balance', 0)
            ->assertJsonPath('data.methods.0.available', false)
            ->assertJsonPath('data.methods.0.reason', __('api.payment.no_balance', [], 'en'));
    }

    public function test_for_a_booking_it_prices_it_and_checks_the_balance_covers_it(): void
    {
        [$member, $token] = $this->memberWithToken(50);
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            'price_points' => 100,
        ]);

        $this->methods(['purpose' => 'booking', 'payable_id' => $booking->id], $token)
            ->assertOk()
            ->assertJsonPath('data.amount_points', 100)
            ->assertJsonPath('data.methods.0.balance', 50)
            ->assertJsonPath('data.methods.0.available', false)
            ->assertJsonPath('data.methods.0.wallets.0.sufficient', false);
    }

    public function test_a_top_up_offers_only_the_card(): void
    {
        [, $token] = $this->memberWithToken(0);

        $this->assertSame(
            ['paymera'],
            array_column($this->methods(['purpose' => 'wallet_top_up'], $token)->assertOk()->json('data.methods'), 'key'),
        );
    }

    public function test_a_disabled_method_is_absent(): void
    {
        config(['payments.methods.cash.enabled' => false]);
        [, $token] = $this->memberWithToken(0);

        $this->assertSame(['wallet', 'paymera'], array_column($this->methods([], $token)->json('data.methods'), 'key'));
    }

    public function test_a_staff_token_is_refused(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        $token = $operator->createToken('dash', ['dashboard'])->plainTextToken;

        $this->methods([], $token)->assertForbidden();
    }
}
