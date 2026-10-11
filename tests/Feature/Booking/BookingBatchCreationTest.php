<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SpaceCoworkingWindow;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The multi-select date strip. The property that matters most is
 * atomicity: a member who picks four dates and loses one must end up with
 * nothing booked and nothing charged, not a partial booking they have to
 * untangle.
 */
class BookingBatchCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // 2026-08-17 is a Monday; the week below is Mon-Thu.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsMember(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openSpace(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
        ], $attributes));

        foreach ([DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday] as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $space->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }

        return $space;
    }

    /**
     * @return array{space_id: int, start_at: string, end_at: string}
     */
    private function window(Space $space, string $date, int $hour = 10, int $minutes = 60): array
    {
        $start = Carbon::parse($date, 'Asia/Damascus')->setTime($hour, 0);

        return [
            'space_id' => $space->id,
            'start_at' => $start->format('Y-m-d H:i'),
            'end_at' => $start->copy()->addMinutes($minutes)->format('Y-m-d H:i'),
        ];
    }

    public function test_a_member_books_three_dates_in_one_request(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'cash',
            'windows' => [
                $this->window($space, '2026-08-17'),
                $this->window($space, '2026-08-18'),
                $this->window($space, '2026-08-19'),
            ],
        ]);

        $response->assertCreated()->assertJsonCount(3, 'data');
        $this->assertSame(3, Booking::count());
    }

    public function test_the_whole_batch_rolls_back_when_one_window_is_unavailable(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        // Someone else already holds the middle date.
        Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => Carbon::parse('2026-08-18 10:00', 'Asia/Damascus')->setTimezone('UTC'),
            'end_at' => Carbon::parse('2026-08-18 11:00', 'Asia/Damascus')->setTimezone('UTC'),
        ]);

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'wallet',
            'windows' => [
                $this->window($space, '2026-08-17'),
                $this->window($space, '2026-08-18'),
                $this->window($space, '2026-08-19'),
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.index', 1)
            ->assertJsonPath('errors.0.reason', 'slot_unavailable');

        // Only the pre-existing booking survives; none of the three were
        // created, not even the two that were individually fine.
        $this->assertSame(1, Booking::count());
    }

    /** A co_space date whose branch pool is held full by subscriptions fails the batch. */
    public function test_a_co_space_date_with_no_seat_left_in_the_pool_fails_the_batch(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace(['space_type' => SpaceType::CoSpace, 'capacity' => 1, 'is_lockable' => false]);
        Membership::factory()->create(['branch_id' => $space->building->branch_id]);

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'wallet',
            'windows' => [$this->window($space, '2026-08-17')],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.reason', 'slot_unavailable');
    }

    public function test_a_room_lent_to_co_working_fails_the_batch(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        SpaceCoworkingWindow::factory()->create([
            'space_id' => $space->id,
            'starts_at' => Carbon::parse('2026-08-18 08:00', 'Asia/Damascus')->utc(),
            'ends_at' => Carbon::parse('2026-08-18 20:00', 'Asia/Damascus')->utc(),
        ]);

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'wallet',
            'windows' => [$this->window($space, '2026-08-17'), $this->window($space, '2026-08-18')],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.index', 1)
            ->assertJsonPath('errors.0.reason', 'room_in_coworking_mode');
    }

    public function test_no_wallet_is_debited_when_a_batch_fails(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();

        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        app(WalletService::class)->creditGeneral($wallet, 1000, WalletTransactionSource::TopUp, 'Seed');
        $transactionsBefore = WalletTransaction::count();

        Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => Carbon::parse('2026-08-19 10:00', 'Asia/Damascus')->setTimezone('UTC'),
            'end_at' => Carbon::parse('2026-08-19 11:00', 'Asia/Damascus')->setTimezone('UTC'),
        ]);

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'wallet',
            'windows' => [
                $this->window($space, '2026-08-17'),
                $this->window($space, '2026-08-19'),
            ],
        ])->assertStatus(422);

        $this->assertSame($transactionsBefore, WalletTransaction::count(), 'A failed batch must not move points.');
        $this->assertSame(1000, app(WalletService::class)->generalBalance($wallet->fresh()));
    }

    /**
     * The hazard a naive implementation misses: neither window is in the
     * database yet, so each looks free when checked against stored rows
     * alone.
     */
    public function test_two_windows_that_overlap_each_other_inside_one_batch_are_rejected(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'wallet',
            'windows' => [
                $this->window($space, '2026-08-17', 10, 120),
                $this->window($space, '2026-08-17', 11, 60),
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('errors.0.index', 1);
        $this->assertSame(0, Booking::count());
    }

    /**
     * One wallet is chosen for the summed total, then each booking is paid
     * on its own — one payment row and one debit each
     * (docs/superpowers/plans/2026-09-30-unified-payments.md, Task 4).
     */
    public function test_a_batch_debits_the_wallet_once_per_booking_for_the_summed_total(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();

        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        app(WalletService::class)->creditGeneral($wallet, 1000, WalletTransactionSource::TopUp, 'Seed');

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'wallet',
            'windows' => [
                $this->window($space, '2026-08-17'),
                $this->window($space, '2026-08-18'),
                $this->window($space, '2026-08-19'),
            ],
        ])->assertCreated();

        $debits = WalletTransaction::where('wallet_id', $wallet->id)->where('amount', '<', 0)->get();

        $this->assertCount(3, $debits, 'Each booking has its own debit.');
        $this->assertSame(-300, (int) $debits->sum('amount'));
        $this->assertSame(700, app(WalletService::class)->generalBalance($wallet->fresh()));
    }

    public function test_a_batch_larger_than_the_cap_is_rejected_by_validation(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $windows = [];
        for ($i = 0; $i < 11; $i++) {
            $windows[] = $this->window($space, '2026-08-17', 8 + $i);
        }

        $this->withHeader('lang', 'en')
            ->postJson('/api/v1/member/bookings/batch', ['windows' => $windows, 'payment_method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('windows');
    }

    public function test_a_batch_on_a_space_requiring_approval_creates_pending_rows(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace(['requires_approval' => true]);

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'cash',
            'windows' => [
                $this->window($space, '2026-08-17'),
                $this->window($space, '2026-08-18'),
            ],
        ])->assertCreated();

        $this->assertSame(2, Booking::where('status', BookingStatus::Pending)->count());
    }

    public function test_a_window_in_the_past_fails_the_batch_naming_its_index(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'wallet',
            'windows' => [
                $this->window($space, '2026-08-17', 14),
                // now() is 08:00, so a 06:00 start has gone.
                $this->window($space, '2026-08-17', 6),
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.index', 1)
            ->assertJsonPath('errors.0.reason', 'start_in_the_past');

        $this->assertSame(0, Booking::count());
    }

    public function test_every_created_booking_carries_the_shared_note(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'cash',
            'windows' => [
                $this->window($space, '2026-08-17'),
                $this->window($space, '2026-08-18'),
            ],
            'note' => 'Weekly standup',
        ])->assertCreated();

        $this->assertSame(2, Booking::where('note', 'Weekly standup')->count());
    }

    public function test_the_batch_is_closed_to_an_unauthenticated_caller(): void
    {
        $space = $this->openSpace();

        $this->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'wallet',
            'windows' => [$this->window($space, '2026-08-17')],
        ])->assertUnauthorized();
    }

    public function test_a_window_on_a_space_that_is_not_active_fails_the_batch(): void
    {
        $this->actingAsMember();
        $open = $this->openSpace();
        $closed = $this->openSpace(['status' => OperationalStatus::Maintenance]);

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'wallet',
            'windows' => [
                $this->window($open, '2026-08-17'),
                $this->window($closed, '2026-08-17'),
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.index', 1)
            ->assertJsonPath('errors.0.reason', 'space_not_bookable');

        $this->assertSame(0, Booking::count());
    }
}
