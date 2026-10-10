<?php

namespace Tests\Feature\Leasing;

use App\Domain\Booking\Enums\SlotState;
use App\Domain\Booking\Exceptions\BatchUnavailableException;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\AvailabilityService;
use App\Domain\Booking\Services\BookingBatchCreationService;
use App\Domain\Booking\Services\BookingCalendarService;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A leased office is the tenant's for whole days: no booking, walk-in or
 * free slot on a lease day, on any surface (docs/decisions/office-leases.md).
 * Monday 2026-08-17 is free, the lease holds Tuesday 18th only, Wednesday
 * 19th is free again.
 */
class LeasedOfficeIsNotBookableTest extends TestCase
{
    use RefreshDatabase;

    private Space $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->seed(CurrencySeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        $this->office = Space::factory()->ofType(SpaceType::Business)->create(['hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);

        foreach ([DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday] as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $this->office->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }

        OfficeLease::factory()->create([
            'space_id' => $this->office->id,
            'starts_on' => '2026-08-18',
            'ends_on' => '2026-08-18',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(string $date, int $hour = 10): array
    {
        $start = Carbon::parse("{$date} {$hour}:00", 'Asia/Damascus');

        return [$start, $start->copy()->addHour()];
    }

    public function test_a_booking_on_a_lease_day_is_refused(): void
    {
        [$start, $end] = $this->window('2026-08-18');

        try {
            app(BookingCreationService::class)->create($this->office, User::factory()->create(), $start, $end);
            $this->fail('A leased office was booked.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.office_leased', $e->messageKey);
        }

        $this->assertSame(0, Booking::query()->count());
    }

    public function test_the_office_books_normally_before_and_after_the_lease(): void
    {
        foreach (['2026-08-17', '2026-08-19'] as $date) {
            [$start, $end] = $this->window($date);
            app(BookingCreationService::class)->create($this->office, User::factory()->create(), $start, $end);
        }

        $this->assertSame(2, Booking::query()->count());
    }

    public function test_a_terminated_lease_frees_the_office_from_its_termination_date(): void
    {
        OfficeLease::query()->update(['status' => OfficeLeaseStatus::Terminated, 'terminated_on' => '2026-08-18']);
        [$start, $end] = $this->window('2026-08-18');

        app(BookingCreationService::class)->create($this->office, User::factory()->create(), $start, $end);

        $this->assertSame(1, Booking::query()->count());
    }

    public function test_a_batch_naming_a_lease_day_is_refused_for_that_window(): void
    {
        [$free] = $this->window('2026-08-17');
        [$leased] = $this->window('2026-08-18');

        try {
            app(BookingBatchCreationService::class)->createMany([
                ['space_id' => $this->office->id, 'start_at' => $free->copy()->utc()->toDateTimeString(), 'end_at' => $free->copy()->addHour()->utc()->toDateTimeString()],
                ['space_id' => $this->office->id, 'start_at' => $leased->copy()->utc()->toDateTimeString(), 'end_at' => $leased->copy()->addHour()->utc()->toDateTimeString()],
            ], User::factory()->create());
            $this->fail('A leased office was booked in a batch.');
        } catch (BatchUnavailableException $e) {
            $this->assertSame([1], array_column($e->failures, 'index'));
            $this->assertSame('office_leased', $e->failures[0]['reason']);
        }

        $this->assertSame(0, Booking::query()->count());
    }

    public function test_the_preview_says_the_office_is_leased(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/preview', [
            'space_id' => $this->office->id,
            'start_at' => '2026-08-18 10:00',
            'end_at' => '2026-08-18 11:00',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.unavailable_reason', __('api.booking.office_leased', [], 'en'));
    }

    public function test_slots_are_leased_on_lease_days_only(): void
    {
        $availability = app(AvailabilityService::class);
        $states = fn (string $date) => collect($availability->slotsForDay($this->office, CarbonImmutable::parse($date, 'Asia/Damascus')))
            ->map(fn ($slot) => $slot->state)
            ->unique()
            ->values()
            ->all();

        $this->assertSame([SlotState::Leased], $states('2026-08-18'));
        $this->assertContains(SlotState::Available, $states('2026-08-17'));
        $this->assertContains(SlotState::Available, $states('2026-08-19'));

        $days = $availability->daysInRange($this->office, CarbonImmutable::parse('2026-08-17'), CarbonImmutable::parse('2026-08-19'));
        $this->assertSame(['available', 'full', 'available'], array_map(fn ($day) => $day->state->value, $days));
    }

    public function test_the_reception_calendar_marks_lease_days(): void
    {
        $calendar = app(BookingCalendarService::class)->build([
            'view' => 'week',
            'date' => '2026-08-17',
            'branch_id' => $this->office->building->branch_id,
            'space_id' => $this->office->id,
        ], 'en');

        $states = collect($calendar['resources'][0]['days'])->pluck('state', 'date');

        $this->assertSame('open', $states['2026-08-17']);
        $this->assertSame('leased', $states['2026-08-18']);
        $this->assertSame('open', $states['2026-08-19']);
    }

    public function test_a_walk_in_is_refused_on_a_lease_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-18 10:00:00', 'Asia/Damascus'));

        try {
            app(WalkInCapacityService::class)->start($this->office, User::factory()->create());
            $this->fail('A walk-in was let into a leased office.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.office_leased', $e->messageKey);
        }
    }
}
