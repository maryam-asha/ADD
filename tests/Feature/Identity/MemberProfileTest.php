<?php

namespace Tests\Feature\Identity;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A member's whole file for the dashboard's member profile: the summary and
 * each tab, every one scoped to that member, and open to operations.
 */
class MemberProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, SettingSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus'));

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->member = User::factory()->create(['name' => 'Layla Haddad']);
        $this->member->assignRole('member');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time, string $date = '2026-08-17'): Carbon
    {
        return Carbon::parse("{$date} {$time}", 'Asia/Damascus')->utc();
    }

    private function profile(string $path, array $query = [])
    {
        return $this->withHeader('lang', 'en')->getJson("/api/v1/admin/members/{$this->member->id}/{$path}".($query ? '?'.http_build_query($query) : ''));
    }

    public function test_the_summary_shows_what_the_member_holds_owes_and_is_doing(): void
    {
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        (new WalletService)->creditGeneral($wallet, 300, WalletTransactionSource::TopUp);
        WalkinSession::factory()->create(['user_id' => $this->member->id, 'checked_in_at' => $this->at('11:00')]);
        WalkinSession::factory()->create([
            'user_id' => $this->member->id,
            'checked_in_at' => $this->at('08:00'),
            'checked_out_at' => $this->at('09:00'),
            'payment_state' => PaymentState::Unpaid,
            'price_points' => 40,
        ]);
        Booking::factory()->create(['user_id' => $this->member->id, 'start_at' => $this->at('15:00', '2026-08-20'), 'end_at' => $this->at('16:00', '2026-08-20')]);
        // Someone else's: never counted.
        Booking::factory()->create();

        $this->profile('summary')->assertOk()
            ->assertJsonPath('data.member.name', 'Layla Haddad')
            ->assertJsonPath('data.wallet.points', 300)
            ->assertJsonPath('data.outstanding.points', 40)
            ->assertJsonPath('data.open_session.type', 'walkin')
            ->assertJsonPath('data.counts.bookings', 1)
            ->assertJsonPath('data.counts.upcoming_bookings', 1)
            ->assertJsonPath('data.counts.sessions', 2)
            ->assertJsonPath('data.last_visit_at', '2026-08-17T11:00:00+03:00');
    }

    public function test_sessions_list_walk_ins_and_checked_in_bookings_newest_first_with_filters(): void
    {
        WalkinSession::factory()->create(['user_id' => $this->member->id, 'checked_in_at' => $this->at('09:00'), 'checked_out_at' => $this->at('10:00')]);
        Booking::factory()->create(['user_id' => $this->member->id, 'start_at' => $this->at('10:00'), 'end_at' => $this->at('11:00'), 'checked_in_at' => $this->at('10:05')]);
        WalkinSession::factory()->create(['user_id' => $this->member->id, 'checked_in_at' => $this->at('09:00', '2026-08-10'), 'checked_out_at' => $this->at('10:00', '2026-08-10')]);
        // A booking never checked into is not a session.
        Booking::factory()->create(['user_id' => $this->member->id, 'start_at' => $this->at('14:00'), 'end_at' => $this->at('15:00')]);

        $this->profile('sessions')->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.type', 'booking')
            ->assertJsonPath('data.1.type', 'walkin')
            ->assertJsonPath('data.1.duration_seconds', 3600);

        $this->profile('sessions', ['type' => 'walkin', 'from' => '2026-08-15'])->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_wallet_history_memberships_requests_and_events_are_the_members_own(): void
    {
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        (new WalletService)->creditGeneral($wallet, 120, WalletTransactionSource::TopUp);

        Membership::factory()->create([
            'owner_type' => OwnerType::User,
            'owner_id' => $this->member->id,
            'status' => MembershipStatus::Active,
        ]);
        Membership::factory()->create(); // someone else's

        EventAttendee::factory()->create(['user_id' => $this->member->id]);

        $this->profile('wallet-transactions')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.points', 120);
        $this->profile('memberships')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.via', 'personal');
        $this->profile('service-requests')->assertOk()->assertJsonPath('meta.total', 0);
        $this->profile('event-registrations')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_a_member_cannot_read_another_members_file(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole('member'), ['*']);

        $this->profile('summary')->assertForbidden();
    }
}
