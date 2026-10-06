<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A closed coworking session paid from the app through its own endpoint — the
 * card the member chose when ending it, or another method
 * (docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md).
 */
class CoworkingSessionPayTest extends TestCase
{
    use RefreshDatabase;

    private const TERMINAL = '99990001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc());

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => self::TERMINAL,
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'en',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeGateway(string $status = 'A', int $amountMinor = 1000): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-9/en', 'paymentId' => 'pay-9'],
            ]),
            '*/api/get-payment-status/*' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => [
                    'status' => $status,
                    'creationTimestamp' => '2026-08-17 08:00:00.000',
                    'rrn' => '000009876543',
                    'amount' => $amountMinor,
                    'terminalId' => self::TERMINAL,
                ],
            ]),
        ]);
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

    private function pay(string $type, int $id, array $body)
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/member/coworking-sessions/{$type}/{$id}/pay", $body);
    }

    public function test_an_open_session_cannot_be_paid_but_a_closed_one_can(): void
    {
        $member = $this->member(300);
        Sanctum::actingAs($member, ['*']);

        $open = WalkinSession::factory()->create(['user_id' => $member->id, 'checked_out_at' => null]);
        $this->pay('walkin', $open->id, ['payment_method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.reception.not_yet_checked_out', [], 'en'));

        $closed = WalkinSession::factory()->create([
            'user_id' => $member->id,
            'checked_out_at' => now()->subHour(),
            'price_points' => 150,
            'amount_owed' => '15.00',
            'payment_state' => PaymentState::Unpaid,
        ]);
        $this->pay('walkin', $closed->id, ['payment_method' => 'wallet'])
            ->assertCreated()
            ->assertJsonPath('data.next', 'done');

        $this->assertSame(PaymentState::Paid, $closed->refresh()->payment_state);
        $this->assertSame(150, app(WalletService::class)->generalBalance($this->walletOf($member)));
    }

    public function test_a_closed_session_is_paid_by_card(): void
    {
        $member = $this->member();
        Sanctum::actingAs($member, ['*']);
        $this->fakeGateway(amountMinor: 1500);
        $closed = WalkinSession::factory()->create([
            'user_id' => $member->id,
            'checked_out_at' => now()->subHour(),
            'price_points' => 150,
            'amount_owed' => '15.00',
            'payment_state' => PaymentState::Unpaid,
        ]);

        $this->pay('walkin', $closed->id, ['payment_method' => 'paymera'])
            ->assertCreated()
            ->assertJsonPath('data.next', 'redirect')
            ->assertJsonPath('data.redirect_url', 'https://egate-t.paymera.test/start/pay-9/en');
    }

    public function test_someone_elses_session_is_not_found(): void
    {
        Sanctum::actingAs($this->member(300), ['*']);
        $theirs = WalkinSession::factory()->create(['checked_out_at' => now()->subHour(), 'payment_state' => PaymentState::Unpaid]);

        $this->pay('walkin', $theirs->id, ['payment_method' => 'wallet'])->assertNotFound();
    }

    public function test_a_method_is_required(): void
    {
        $member = $this->member(300);
        Sanctum::actingAs($member, ['*']);
        $closed = WalkinSession::factory()->create(['user_id' => $member->id, 'checked_out_at' => now()->subHour()]);

        $this->pay('walkin', $closed->id, [])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    }
}
