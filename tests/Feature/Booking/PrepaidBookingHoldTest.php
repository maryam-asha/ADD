<?php

namespace Tests\Feature\Booking;

use App\Domain\Access\Services\TTLockClient;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A booking made with a prepaid method is a hold: `awaiting_payment` with a
 * deadline, until its card goes through, the member chooses another method,
 * or the deadline passes (docs/decisions/prepayment-all-or-nothing.md).
 */
class PrepaidBookingHoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => '99990001',
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

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function openSpace(): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    private function book(array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', $body);
    }

    public function test_a_card_booking_is_held_until_its_deadline(): void
    {
        $this->member();
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);

        $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'paymera',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')
            // BookingResource renders times in UTC, as start_at/end_at.
            ->assertJsonPath('data.payment_due_at', Carbon::parse('2026-08-17 08:15:00', 'Asia/Damascus')->utc()->toIso8601String());
    }

    /**
     * A window longer than the time left before the start ends at the
     * start: made at 08:00 for 10:00 with a three-hour window.
     */
    public function test_the_deadline_never_passes_the_start(): void
    {
        $this->member();
        $space = $this->openSpace();
        config(['payments.prepayment_window_minutes' => 180]);
        Http::fake(['*' => Http::response('', 500)]);

        $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'paymera',
        ])->assertJsonPath('data.payment_due_at', Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc()->toIso8601String());
    }

    /**
     * Door codes go to confirmed bookings only: a hold on its own day gets
     * none (access:issue-grants-on-cancellation-window-close).
     */
    public function test_a_hold_gets_no_door_code(): void
    {
        $this->member();
        $space = $this->openSpace();
        $space->update(['allocation_model' => AllocationModel::BookingHourly]);
        // A lock the vendor would answer for: a confirmed booking here would
        // be issued a code, so no grant can only mean the hold was skipped.
        Device::factory()->create(['space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);
        $this->mock(TTLockClient::class, fn ($mock) => $mock
            ->shouldReceive('addPeriodPasscode')
            ->andReturn(['passcode' => '112233', 'vendor_passcode_id' => 5]));
        Http::fake(['*' => Http::response('', 500)]);
        $id = $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'paymera',
        ])->json('data.id');

        $this->artisan('access:issue-grants-on-cancellation-window-close')->assertSuccessful();

        $this->assertSame(0, Booking::findOrFail($id)->accessGrants()->count());
    }

    public function test_wallet_and_cash_bookings_are_not_held(): void
    {
        $this->member(500);
        $space = $this->openSpace();

        $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'cash',
        ])->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.payment_due_at', null);
    }

    public function test_going_back_and_choosing_another_time_lets_the_members_own_hold_go(): void
    {
        $this->member();
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);
        $first = $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T12:00:00+03:00',
            'payment_method' => 'paymera',
        ])->json('data.id');

        $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T11:00:00+03:00',
            'end_at' => '2026-08-17T12:00:00+03:00',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertSame(BookingStatus::Cancelled, Booking::findOrFail($first)->status);
        $this->assertTrue(Booking::findOrFail($first)->cancelledAwaitingPayment());
    }

    public function test_another_members_hold_is_never_superseded(): void
    {
        $space = $this->openSpace();
        $other = $this->member();
        Http::fake(['*' => Http::response('', 500)]);
        $theirs = $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T12:00:00+03:00',
            'payment_method' => 'paymera',
        ])->json('data.id');

        $this->member(500);
        $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T11:00:00+03:00',
            'end_at' => '2026-08-17T12:00:00+03:00',
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $this->assertSame(BookingStatus::AwaitingPayment, Booking::findOrFail($theirs)->status);
        $this->assertSame($other->id, Booking::findOrFail($theirs)->user_id);
    }

    public function test_a_hold_is_not_cancelled_when_nothing_overlaps_it(): void
    {
        $this->member(500);
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);
        $first = $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'paymera',
        ])->json('data.id');

        $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T15:00:00+03:00',
            'end_at' => '2026-08-17T16:00:00+03:00',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertSame(BookingStatus::AwaitingPayment, Booking::findOrFail($first)->status);
    }

    /**
     * Nothing was paid for a hold, so the cancellation window — a 3-hour one
     * is already closed on a start two hours away — does not stop the
     * member backing out of it; a confirmed booking is held to the window.
     */
    public function test_a_hold_can_be_cancelled_inside_the_cancellation_window(): void
    {
        $this->member(500);
        $space = $this->openSpace();
        $space->update(['cancellation_window_minutes' => 180]);
        Http::fake(['*' => Http::response('', 500)]);
        $hold = $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'paymera',
        ])->json('data.id');
        $confirmed = $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T13:00:00+03:00',
            'end_at' => '2026-08-17T14:00:00+03:00',
            'payment_method' => 'cash',
        ])->json('data.id');

        $this->assertTrue(app(BookingCancellationService::class)->canCancel(Booking::findOrFail($hold)));

        $this->postJson("/api/v1/member/bookings/{$hold}/cancel")->assertOk();

        $this->assertSame(BookingStatus::Cancelled, Booking::findOrFail($hold)->status);
        $this->assertTrue(Booking::findOrFail($hold)->cancelledAwaitingPayment());

        // The confirmed one, 5 hours out against a 3-hour window, is still
        // cancellable; move the clock inside its window and it is not.
        Carbon::setTestNow(Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus'));
        $this->assertFalse(app(BookingCancellationService::class)->canCancel(Booking::findOrFail($confirmed)));
        $this->postJson("/api/v1/member/bookings/{$confirmed}/cancel")->assertStatus(422);
    }

    public function test_releasing_a_hold_leaves_anything_else_alone(): void
    {
        $this->member(500);
        $space = $this->openSpace();
        $id = $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'cash',
        ])->json('data.id');

        $this->assertFalse(app(BookingCancellationService::class)->releaseUnpaidHold(Booking::findOrFail($id)));
        $this->assertSame(BookingStatus::Confirmed, Booking::findOrFail($id)->status);
    }

    public function test_the_members_own_real_booking_is_never_superseded(): void
    {
        $this->member(500);
        $space = $this->openSpace();
        $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T12:00:00+03:00',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T11:00:00+03:00',
            'end_at' => '2026-08-17T12:00:00+03:00',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    /**
     * The member's views read times in the district's offset, as every other
     * time on them (`checked_in_at`), so the countdown's deadline is local.
     */
    public function test_the_member_sees_the_hold_its_deadline_and_why_it_was_cancelled(): void
    {
        $this->member();
        $space = $this->openSpace();
        Http::fake(['*' => Http::response('', 500)]);
        $id = $this->book([
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'paymera',
        ])->json('data.id');
        $due = Carbon::parse('2026-08-17 08:15:00', 'Asia/Damascus')->toIso8601String();

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.payment_due_at', $due);

        // The list card carries the same deadline for its countdown.
        $this->withHeader('lang', 'en')->getJson('/api/v1/member/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'awaiting_payment')
            ->assertJsonPath('data.0.payment_due_at', $due);

        app(BookingCancellationService::class)->releaseUnpaidHold(Booking::findOrFail($id));

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$id}")
            ->assertJsonPath('data.status_note', __('api.booking.status_notes.payment_not_completed', [], 'en'));
    }
}
