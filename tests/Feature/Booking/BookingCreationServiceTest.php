<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingCreationService $creations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->creations = app(BookingCreationService::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function openSpace(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'hourly_rate' => '10.00',
            'pricing_currency' => 'USD',
        ], $attributes));

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function slot(int $hour, int $durationMinutes = 60): array
    {
        $start = Carbon::parse('2026-08-17', 'Asia/Damascus')->setTime($hour, 0);

        return [$start, $start->copy()->addMinutes($durationMinutes)];
    }

    public function test_a_member_can_create_a_confirmed_unpaid_booking(): void
    {
        $space = $this->openSpace();
        $member = User::factory()->create();
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, $member, $start, $end);

        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertTrue($booking->start_at->equalTo($start));
        $this->assertTrue($booking->end_at->equalTo($end));
    }

    public function test_creation_fails_outside_business_hours(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(21);

        try {
            $this->creations->create($space, User::factory()->create(), $start, $end);
            $this->fail('Expected a ReceptionActionException for outside business hours.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.outside_business_hours', $e->messageKey);
        }
    }

    public function test_creation_fails_when_the_window_spans_a_closed_gap_between_two_periods(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
        BusinessHour::factory()->create(['branch_id' => $space->building->branch_id, 'day_of_week' => DayOfWeek::Monday, 'open_time' => '08:00', 'close_time' => '12:00']);
        BusinessHour::factory()->create(['branch_id' => $space->building->branch_id, 'day_of_week' => DayOfWeek::Monday, 'open_time' => '13:00', 'close_time' => '20:00']);
        $start = Carbon::parse('2026-08-17 11:30:00', 'Asia/Damascus');

        try {
            $this->creations->create($space, User::factory()->create(), $start, $start->copy()->addHour());
            $this->fail('Expected a ReceptionActionException — the window crosses the 12:00-13:00 closed gap.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.outside_business_hours', $e->messageKey);
        }
    }

    public function test_creation_fails_when_the_start_time_does_not_match_granularity(): void
    {
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);
        $start = Carbon::parse('2026-08-17 10:15:00', 'Asia/Damascus');

        try {
            $this->creations->create($space, User::factory()->create(), $start, $start->copy()->addMinutes(60));
            $this->fail('Expected a ReceptionActionException for invalid start time.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.invalid_start_time', $e->messageKey);
        }
    }

    public function test_creation_fails_when_duration_is_below_the_minimum(): void
    {
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);
        [$start] = $this->slot(10);

        try {
            $this->creations->create($space, User::factory()->create(), $start, $start->copy()->addMinutes(45));
            $this->fail('Expected a ReceptionActionException for duration too short.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.duration_too_short', $e->messageKey);
        }
    }

    public function test_creation_fails_when_duration_does_not_match_granularity_above_the_minimum(): void
    {
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);
        [$start] = $this->slot(10);

        try {
            $this->creations->create($space, User::factory()->create(), $start, $start->copy()->addMinutes(70));
            $this->fail('Expected a ReceptionActionException for invalid duration granularity.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.duration_invalid_granularity', $e->messageKey);
        }
    }

    public function test_valid_durations_at_30_minute_granularity_are_accepted(): void
    {
        foreach ([60, 90, 120] as $minutes) {
            $space = $this->openSpace(['slot_granularity_minutes' => 30]);
            [$start] = $this->slot(10);

            $booking = $this->creations->create($space, User::factory()->create(), $start, $start->copy()->addMinutes($minutes));

            $this->assertSame($minutes, (int) $start->diffInMinutes($booking->end_at));
        }
    }

    public function test_creation_fails_when_the_slot_overlaps_a_confirmed_booking(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(10);
        Booking::factory()->create(['space_id' => $space->id, 'start_at' => $start->copy()->setTimezone('UTC'), 'end_at' => $end->copy()->setTimezone('UTC')]);

        try {
            $this->creations->create($space, User::factory()->create(), $start->copy()->addMinutes(30), $end->copy()->addMinutes(30));
            $this->fail('Expected a ReceptionActionException for slot unavailable.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.slot_unavailable', $e->messageKey);
        }
    }

    public function test_creation_fails_when_within_the_buffer_of_an_adjacent_booking(): void
    {
        $space = $this->openSpace(['slot_granularity_minutes' => 5, 'buffer_minutes' => 15]);
        [$start, $end] = $this->slot(10);
        Booking::factory()->create(['space_id' => $space->id, 'start_at' => $start->copy()->setTimezone('UTC'), 'end_at' => $end->copy()->setTimezone('UTC')]);
        $newStart = $end->copy()->addMinutes(10);

        try {
            $this->creations->create($space, User::factory()->create(), $newStart, $newStart->copy()->addHour());
            $this->fail('Expected a ReceptionActionException for buffer conflict.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.buffer_conflict', $e->messageKey);
        }
    }

    public function test_creation_succeeds_exactly_at_the_buffer_boundary(): void
    {
        $space = $this->openSpace(['slot_granularity_minutes' => 5, 'buffer_minutes' => 15]);
        [$start, $end] = $this->slot(10);
        Booking::factory()->create(['space_id' => $space->id, 'start_at' => $start->copy()->setTimezone('UTC'), 'end_at' => $end->copy()->setTimezone('UTC')]);
        $newStart = $end->copy()->addMinutes(15);

        $booking = $this->creations->create($space, User::factory()->create(), $newStart, $newStart->copy()->addHour());

        $this->assertInstanceOf(Booking::class, $booking);
    }

    public function test_creation_succeeds_when_a_buffer_is_configured_but_no_adjacent_booking_exists(): void
    {
        $space = $this->openSpace(['buffer_minutes' => 15]);
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, User::factory()->create(), $start, $end);

        $this->assertInstanceOf(Booking::class, $booking);
    }

    public function test_creation_fails_when_current_occupancy_is_already_at_capacity(): void
    {
        $space = $this->openSpace(['capacity' => 1]);
        Booking::factory()->checkedIn()->create(['space_id' => $space->id]);
        [$start, $end] = $this->slot(14);

        try {
            $this->creations->create($space, User::factory()->create(), $start, $end);
            $this->fail('Expected a ReceptionActionException for no capacity.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.no_capacity', $e->messageKey);
        }
    }

    public function test_creation_succeeds_when_capacity_is_unlimited(): void
    {
        $space = $this->openSpace(['capacity' => null]);
        Booking::factory()->checkedIn()->create(['space_id' => $space->id]);
        [$start, $end] = $this->slot(14);

        $booking = $this->creations->create($space, User::factory()->create(), $start, $end);

        $this->assertInstanceOf(Booking::class, $booking);
    }

    public function test_creation_debits_the_single_available_wallet_and_marks_paid(): void
    {
        $space = $this->openSpace();
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        (new WalletService)->creditGeneral($wallet, '50.00', WalletTransactionSource::TopUp);
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, $member, $start, $end);

        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(PaymentSource::Wallet, $booking->payment_source);
        $this->assertSame(1, $wallet->transactions()->where('amount', '-10.00')->count());
    }

    public function test_creation_stays_unpaid_when_no_balance_covers_the_cost(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, User::factory()->create(), $start, $end);

        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertNull($booking->payment_source);
    }

    public function test_creation_requires_an_explicit_wallet_choice_when_multiple_balances_apply(): void
    {
        $space = $this->openSpace();
        $member = User::factory()->create();
        $personalWallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        (new WalletService)->creditGeneral($personalWallet, '50.00', WalletTransactionSource::TopUp);
        $company = Company::factory()->create();
        $member->companies()->attach($company->id);
        $companyWallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        (new WalletService)->creditGeneral($companyWallet, '50.00', WalletTransactionSource::TopUp);
        [$start, $end] = $this->slot(10);

        try {
            $this->creations->create($space, $member, $start, $end);
            $this->fail('Expected a WalletChoiceRequiredException.');
        } catch (WalletChoiceRequiredException $e) {
            $this->assertCount(2, $e->options);
        }

        $this->assertSame(0, Booking::where('user_id', $member->id)->count());
    }

    public function test_creation_debits_the_explicitly_chosen_company_wallet_when_provided(): void
    {
        $space = $this->openSpace();
        $member = User::factory()->create();
        $company = Company::factory()->create();
        $member->companies()->attach($company->id);
        $companyWallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        (new WalletService)->creditGeneral($companyWallet, '50.00', WalletTransactionSource::TopUp);
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, $member, $start, $end, OwnerType::Company, $company->id);

        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(1, $companyWallet->transactions()->where('amount', '-10.00')->count());
    }

    public function test_creation_rejects_an_explicit_wallet_the_member_does_not_own(): void
    {
        $space = $this->openSpace();
        $member = User::factory()->create();
        $otherCompany = Company::factory()->create();
        $otherCompanyWallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $otherCompany->id]);
        (new WalletService)->creditGeneral($otherCompanyWallet, '50.00', WalletTransactionSource::TopUp);
        [$start, $end] = $this->slot(10);

        try {
            $this->creations->create($space, $member, $start, $end, OwnerType::Company, $otherCompany->id);
            $this->fail('Expected a ReceptionActionException for an unowned wallet.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.wallet_not_owned', $e->messageKey);
        }

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, $otherCompanyWallet->transactions()->where('amount', '<', 0)->count());
    }

    public function test_creation_stays_unpaid_when_the_computed_amount_is_zero(): void
    {
        $space = $this->openSpace(['hourly_rate' => '0.00']);
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        (new WalletService)->creditGeneral($wallet, '50.00', WalletTransactionSource::TopUp);
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, $member, $start, $end);

        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertSame(0, $wallet->transactions()->where('amount', '<', 0)->count());
    }

    public function test_creation_creates_a_pending_booking_when_the_space_requires_approval(): void
    {
        $space = $this->openSpace(['requires_approval' => true]);
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, User::factory()->create(), $start, $end);

        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame(
            1,
            NotificationLog::where('user_id', $operator->id)->where('template_key', 'booking.pending_approval')->count()
        );
    }

    public function test_a_pending_booking_blocks_a_second_request_for_the_same_slot(): void
    {
        $space = $this->openSpace(['requires_approval' => true]);
        [$start, $end] = $this->slot(10);
        $this->creations->create($space, User::factory()->create(), $start, $end);

        try {
            $this->creations->create($space, User::factory()->create(), $start, $end);
            $this->fail('Expected a ReceptionActionException for slot unavailable.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.slot_unavailable', $e->messageKey);
        }
    }

    public function test_creation_stays_confirmed_when_the_space_does_not_require_approval(): void
    {
        $space = $this->openSpace(['requires_approval' => false]);
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, User::factory()->create(), $start, $end);

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(0, NotificationLog::count());
    }
}
