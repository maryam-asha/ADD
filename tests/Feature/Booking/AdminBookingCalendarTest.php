<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\BusinessHourException;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The operations calendar. The property worth protecting is that its
 * notion of "open" is the same one the member calendar uses — a room that
 * reads 60% full here must not be offered as free there.
 */
class AdminBookingCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Building $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        $this->branch = Branch::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->building = Building::factory()->create(['branch_id' => $this->branch->id]);

        foreach ([DayOfWeek::Sunday, DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday] as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $this->branch->id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function space(array $attributes = []): Space
    {
        return Space::factory()->room()->create(array_merge([
            'building_id' => $this->building->id,
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
        ], $attributes));
    }

    private function book(Space $space, string $date, int $hour, int $minutes = 60, array $attributes = []): Booking
    {
        $start = Carbon::parse($date, 'Asia/Damascus')->setTime($hour, 0);

        return Booking::factory()->create(array_merge([
            'space_id' => $space->id,
            'start_at' => $start->copy()->setTimezone('UTC'),
            'end_at' => $start->copy()->addMinutes($minutes)->setTimezone('UTC'),
        ], $attributes));
    }

    public function test_the_week_view_returns_seven_days_for_each_space(): void
    {
        $this->actingAsOperations();
        $this->space();
        $this->space();

        $response = $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar?view=week&date=2026-08-17');

        $response->assertOk()
            ->assertJsonPath('view', 'week')
            ->assertJsonCount(2, 'resources')
            ->assertJsonCount(7, 'resources.0.days')
            ->assertJsonPath('totals.spaces', 2);
    }

    public function test_the_day_view_returns_a_single_day(): void
    {
        $this->actingAsOperations();
        $this->space();

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar?view=day&date=2026-08-17')
            ->assertOk()
            ->assertJsonCount(1, 'resources.0.days')
            ->assertJsonPath('resources.0.days.0.date', '2026-08-17');
    }

    public function test_a_booking_appears_on_its_day_with_the_member_who_made_it(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $member = User::factory()->create(['name' => 'Layla Haddad']);
        $this->book($space, '2026-08-17', 10, 60, ['user_id' => $member->id]);

        $response = $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar?view=day&date=2026-08-17');

        $response->assertOk()
            ->assertJsonCount(1, 'resources.0.days.0.bookings')
            ->assertJsonPath('resources.0.days.0.bookings.0.member.name', 'Layla Haddad')
            ->assertJsonPath('resources.0.days.0.bookings.0.start_time', '10:00 AM')
            ->assertJsonPath('resources.0.days.0.bookings.0.status', 'confirmed');
    }

    public function test_a_closed_day_is_marked_closed_and_reports_no_occupancy(): void
    {
        $this->actingAsOperations();
        $this->space();
        BusinessHourException::factory()->create([
            'branch_id' => $this->branch->id,
            'date' => '2026-08-17',
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
            'reason' => 'Public holiday',
        ]);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar?view=day&date=2026-08-17')
            ->assertOk()
            ->assertJsonPath('resources.0.days.0.state', 'closed')
            ->assertJsonPath('resources.0.days.0.occupancy.open_minutes', 0)
            ->assertJsonPath('resources.0.days.0.occupancy.percent', 0);
    }

    /**
     * 08:00-20:00 is 720 open minutes; a 3-hour booking is 180 of them,
     * which is 25%.
     */
    public function test_occupancy_is_booked_minutes_over_open_minutes(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $this->book($space, '2026-08-17', 10, 180);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar?view=day&date=2026-08-17')
            ->assertOk()
            ->assertJsonPath('resources.0.days.0.occupancy.open_minutes', 720)
            ->assertJsonPath('resources.0.days.0.occupancy.booked_minutes', 180)
            ->assertJsonPath('resources.0.days.0.occupancy.percent', 25);
    }

    /**
     * A co_space sells several seats at once, so its capacity-minutes
     * denominator is the ceiling — otherwise twenty concurrent hot-desk
     * bookings would read as 2000% full.
     */
    public function test_a_co_space_is_measured_against_its_concurrency_ceiling(): void
    {
        $this->actingAsOperations();
        $space = $this->space([
            'space_type' => SpaceType::CoSpace,
            'is_lockable' => false,
            'capacity' => 4,
        ]);

        // Four concurrent 3-hour bookings: 720 booked minutes against
        // 720 x 4 = 2880 capacity-minutes, so 25%.
        foreach (range(1, 4) as $ignored) {
            $this->book($space, '2026-08-17', 10, 180);
        }

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar?view=day&date=2026-08-17')
            ->assertOk()
            ->assertJsonPath('resources.0.space.concurrency_ceiling', 4)
            ->assertJsonPath('resources.0.days.0.occupancy.percent', 25);
    }

    public function test_a_booking_running_past_closing_is_clipped_to_the_open_window(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        // 19:00 for three hours would end at 22:00, two hours past closing.
        $this->book($space, '2026-08-17', 19, 180);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar?view=day&date=2026-08-17')
            ->assertOk()
            // Only the 19:00-20:00 hour counts.
            ->assertJsonPath('resources.0.days.0.occupancy.booked_minutes', 60);
    }

    public function test_a_cancelled_booking_does_not_appear_on_the_calendar(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $this->book($space, '2026-08-17', 10, 60, ['status' => BookingStatus::Cancelled]);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar?view=day&date=2026-08-17')
            ->assertOk()
            ->assertJsonCount(0, 'resources.0.days.0.bookings')
            ->assertJsonPath('resources.0.days.0.occupancy.booked_minutes', 0);
    }

    public function test_the_calendar_can_be_narrowed_to_one_space_type(): void
    {
        $this->actingAsOperations();
        $this->space();
        $this->space(['space_type' => SpaceType::CoSpace, 'is_lockable' => false, 'capacity' => 10]);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar?view=day&date=2026-08-17&space_type=co_space')
            ->assertOk()
            ->assertJsonCount(1, 'resources')
            ->assertJsonPath('resources.0.space.space_type', 'co_space');
    }

    /**
     * The whole reason the queries are shaped the way they are: a month of
     * twenty spaces must not cost a query per space, nor per day.
     */
    public function test_a_month_of_twenty_spaces_costs_a_bounded_number_of_queries(): void
    {
        $this->actingAsOperations();

        foreach (range(1, 20) as $ignored) {
            $this->space();
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar?view=month&date=2026-08-17')
            ->assertOk();

        // 20 spaces x 31 days is 620 cells; anything proportional to that
        // would be hundreds of queries.
        $this->assertLessThanOrEqual(
            20,
            $queries,
            "A month of 20 spaces issued {$queries} queries; it must not query per space or per day."
        );
    }

    public function test_the_occupancy_view_drops_the_per_booking_detail(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $this->book($space, '2026-08-17', 10, 180);

        $response = $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar/occupancy?view=day&date=2026-08-17');

        $response->assertOk()
            ->assertJsonPath('data.0.days.0.booking_count', 1)
            ->assertJsonPath('data.0.days.0.occupancy.percent', 25)
            ->assertJsonMissingPath('data.0.days.0.bookings');
    }

    public function test_a_member_cannot_reach_the_operations_calendar(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar?view=day&date=2026-08-17')
            ->assertForbidden();
    }

    public function test_the_calendar_is_closed_to_an_unauthenticated_caller(): void
    {
        $this->getJson('/api/v1/admin/calendar')->assertUnauthorized();
    }
}
