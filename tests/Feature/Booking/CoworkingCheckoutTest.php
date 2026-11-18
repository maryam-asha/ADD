<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SessionEndRequest;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * GET member/coworking-sessions/current/checkout: what ending now would come
 * to and how it can be paid, before the member asks to end — so they choose
 * a method knowing the amount (docs/decisions/coworking-self-service-sessions.md).
 */
class CoworkingCheckoutTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
        $this->space = $this->coworkingSpace(hourlyRatePoints: 100);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function checkout(): TestResponse
    {
        return $this->withHeader('lang', 'en')->getJson('/api/v1/member/coworking-sessions/current/checkout');
    }

    /** A walk-in on the coworking space since 08:00 — two hours by now. */
    private function walkInSince8(User $member, array $attributes = []): WalkinSession
    {
        return WalkinSession::factory()->create([
            'space_id' => $this->space->id,
            'user_id' => $member->id,
            'checked_in_at' => Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc(),
        ] + $attributes);
    }

    private function walletWith(User $member, int $points): void
    {
        $wallet = Wallet::firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        (new WalletService)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
    }

    /** @param  list<array<string, mixed>>  $methods */
    private function method(array $methods, string $key): ?array
    {
        return collect($methods)->firstWhere('key', $key);
    }

    public function test_it_shows_what_ending_now_comes_to_and_changes_nothing(): void
    {
        $member = $this->actingAsMember();
        $session = $this->walkInSince8($member);
        $this->walletWith($member, 500);

        $response = $this->checkout()
            ->assertOk()
            ->assertJsonPath('data.session.type', 'walkin')
            ->assertJsonPath('data.session.id', $session->id)
            ->assertJsonPath('data.session.duration_seconds', 7200)
            ->assertJsonPath('data.closing.points', 200)
            ->assertJsonPath('data.closing.already_paid', false)
            ->assertJsonPath('data.closing.covered_seconds', 0);

        $this->assertNotEmpty($response->json('data.lines'));
        $this->assertNotNull($response->json('data.closing.price'));
        $this->assertNotEmpty($response->json('data.checkout_token'));
        $this->assertNotNull($response->json('data.checkout_token_expires_at'));
        // The methods are GET member/payment-methods' to list, not this.
        $this->assertNull($response->json('data.payment_methods'));

        // A preview: the session runs on and nothing is asked of reception.
        $this->assertNull($session->fresh()->checked_out_at);
        $this->assertSame(0, SessionEndRequest::count());
    }

    public function test_payment_methods_lists_what_can_pay_a_session_with_the_wallet_balance(): void
    {
        $member = $this->actingAsMember();
        $this->walkInSince8($member);
        $this->walletWith($member, 500);

        $methods = $this->getJson('/api/v1/member/payment-methods?purpose=coworking_session')->assertOk()->json('data.methods');

        $this->assertSame(['wallet', 'cash', 'paymera'], collect($methods)->pluck('key')->all());
        $this->assertSame(500, $this->method($methods, 'wallet')['balance']);
    }

    public function test_a_session_the_plan_covers_owes_nothing_and_offers_no_methods(): void
    {
        $member = $this->actingAsMember();
        $membership = $this->subscribe($member, $this->planWithHours(40));
        $this->walkInSince8($member, ['membership_id' => $membership->id]);

        $this->checkout()
            ->assertOk()
            ->assertJsonPath('data.closing.points', 0)
            ->assertJsonPath('data.closing.covered_seconds', 7200);
    }

    public function test_a_booking_paid_when_made_owes_nothing_more(): void
    {
        $member = $this->actingAsMember();
        Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => $this->space->id,
            'start_at' => Carbon::parse('2026-08-17 09:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 12:00', 'Asia/Damascus')->utc(),
            'checked_in_at' => Carbon::parse('2026-08-17 09:00', 'Asia/Damascus')->utc(),
            'payment_state' => PaymentState::Paid,
            'price_points' => 300,
        ]);

        $this->checkout()
            ->assertOk()
            ->assertJsonPath('data.session.type', 'booking')
            ->assertJsonPath('data.closing.already_paid', true)
            ->assertJsonPath('data.closing.points', 0);
    }

    public function test_without_a_running_session_there_is_nothing_to_check_out(): void
    {
        $this->actingAsMember();

        $this->checkout()->assertStatus(422)->assertExactJson(['message' => 'You have no active session.']);
    }
}
