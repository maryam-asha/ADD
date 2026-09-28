<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\BusinessHourException;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpaceAvailabilityControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // 2026-08-17 is a Monday.
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
    private function openSpace(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'name' => ['en' => 'Meeting Room A', 'ar' => 'غرفة اجتماعات أ'],
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
        ], $attributes));

        foreach ([DayOfWeek::Sunday, DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday] as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $space->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }

        return $space;
    }

    private function book(Space $space, int $hour, int $minutes = 60): Booking
    {
        $start = Carbon::parse('2026-08-17', 'Asia/Damascus')->setTime($hour, 0);

        return Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => $start->copy()->setTimezone('UTC'),
            'end_at' => $start->copy()->addMinutes($minutes)->setTimezone('UTC'),
        ]);
    }

    /**
     * The admin slots body, renamed the way the member route presents it:
     * the slot list under `slots` rather than `data`, every other key and
     * its position unchanged.
     *
     * @param  array<string, mixed>  $adminBody
     * @return array<string, mixed>
     */
    private function inMemberShape(array $adminBody): array
    {
        $shaped = [];

        foreach ($adminBody as $key => $value) {
            $shaped[$key === 'data' ? 'slots' : $key] = $value;
        }

        return $shaped;
    }

    /**
     * The member app reads one object: everything about the day inside
     * `data`, with the slot list under `slots`.
     */
    public function test_the_member_day_response_is_one_data_object_with_the_slots_inside(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk();

        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(
            ['date', 'state', 'closed_banner', 'branch_hours', 'rules', 'legend', 'slots', 'counts', 'meta'],
            array_keys($response->json('data')),
        );
        $this->assertCount(12, $response->json('data.slots'));
    }

    public function test_a_member_sees_a_month_of_day_states_for_a_space(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-17&to=2026-08-23");

        $response->assertOk()
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.timezone', 'Asia/Damascus')
            ->assertJsonPath('data.0.date', '2026-08-17')
            ->assertJsonPath('data.0.weekday', 'MON')
            ->assertJsonPath('data.0.month', 'AUG')
            ->assertJsonPath('data.0.state', 'available')
            ->assertJsonPath('data.0.is_selectable', true)
            ->assertJsonPath('space.title', 'Meeting Room A');
    }

    public function test_the_branch_hours_card_carries_the_weekday_range_and_a_derived_timezone_label(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-17&to=2026-08-18");

        $response->assertOk()
            ->assertJsonPath('branch_hours.hours', '8:00 AM – 8:00 PM')
            ->assertJsonPath('branch_hours.days_label', 'Sunday – Thursday')
            ->assertJsonPath('branch_hours.open_time', '08:00');

        // Derived from the zone at request time, never hardcoded — Damascus
        // observes DST, so a fixed "GMT+3" would be wrong half the year.
        $this->assertStringStartsWith('GMT+', $response->json('branch_hours.timezone_label'));
        $this->assertStringContainsString(
            $response->json('branch_hours.timezone_label'),
            $response->json('branch_hours.timezone_note')
        );
    }

    public function test_a_closing_exception_returns_a_closed_banner_carrying_its_reason(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        BusinessHourException::factory()->create([
            'branch_id' => $space->building->branch_id,
            'date' => '2026-08-19',
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
            'reason' => 'Public holiday',
        ]);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-19&to=2026-08-19");

        $response->assertOk()
            ->assertJsonPath('data.0.state', 'closed')
            ->assertJsonPath('data.0.is_selectable', false)
            ->assertJsonPath('data.0.closed_banner.reason', 'Public holiday')
            ->assertJsonPath('data.0.closed_banner.severity', 'error');

        $this->assertStringContainsString('closed on this date', $response->json('data.0.closed_banner.text'));
    }

    /**
     * The same closure list the operations calendar carries, so the app's
     * date picker can grey out a holiday in a month it has not loaded yet.
     */
    public function test_the_hours_card_lists_upcoming_closures_on_both_the_month_and_the_day(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        BusinessHourException::factory()->create([
            'branch_id' => $space->building->branch_id,
            'date' => '2026-10-06',
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
            'reason' => 'Public holiday',
        ]);
        $expected = [['date' => '2026-10-06', 'reason' => 'Public holiday']];

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-17&to=2026-08-23")
            ->assertOk()
            ->assertJsonPath('branch_hours.upcoming_closures', $expected);

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->assertJsonPath('data.branch_hours.upcoming_closures', $expected);
    }

    public function test_a_weekday_with_no_schedule_is_closed_with_no_reason_to_give(): void
    {
        // Saturday has no BusinessHour rows at all. That is closed, but
        // there is no exception row to explain it — the distinction the
        // BusinessHoursService docblock calls out.
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-22&to=2026-08-22");

        $response->assertOk()
            ->assertJsonPath('data.0.state', 'closed')
            ->assertJsonPath('data.0.closed_banner.reason', null);
    }

    public function test_a_day_whose_every_slot_is_taken_is_full_not_closed(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $this->book($space, 8, 12 * 60);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-17&to=2026-08-17");

        $response->assertOk()
            ->assertJsonPath('data.0.state', 'full')
            ->assertJsonPath('data.0.available_slot_count', 0)
            // A full day is open, so it must not show the closed banner.
            ->assertJsonPath('data.0.closed_banner', null);

        $this->assertGreaterThan(0, $response->json('data.0.total_slot_count'));
    }

    public function test_slots_for_a_day_return_available_booked_and_past_states(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $this->book($space, 10);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17");

        $response->assertOk()
            ->assertJsonPath('data.state', 'available')
            ->assertJsonPath('data.counts.total', 12)
            ->assertJsonPath('data.meta.granularity_minutes', 60);

        $byLabel = collect($response->json('data.slots'))->keyBy('label');

        $this->assertSame('booked', $byLabel['10:00 AM']['state']);
        $this->assertFalse($byLabel['10:00 AM']['is_available']);
        $this->assertSame('available', $byLabel['11:00 AM']['state']);
        $this->assertNull($byLabel['11:00 AM']['reason']);
    }

    /**
     * The whole point of one shared engine: the sentence on the greyed-out
     * chip and the sentence in the 422 are the same sentence. If these ever
     * diverge, the calendar is lying to the member about why.
     */
    public function test_a_booked_slots_reason_is_the_exact_message_the_post_endpoint_returns(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $this->book($space, 14);

        $chipReason = collect(
            $this->withHeader('lang', 'en')
                ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
                ->json('data.slots')
        )->firstWhere('label', '02:00 PM')['reason'];

        $postMessage = $this->withHeader('lang', 'en')
            ->postJson('/api/v1/member/bookings', [
                'space_id' => $space->id,
                'start_at' => '2026-08-17 14:00',
                'end_at' => '2026-08-17 15:00',
            ])->assertStatus(422)->json('message');

        $this->assertSame($postMessage, $chipReason);
    }

    public function test_the_quote_returns_the_configured_duration_ladder_with_end_times_and_points(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/quote?start_at=2026-08-17 10:00");

        $response->assertOk()
            ->assertJsonPath('hourly_rate_points', 100)
            ->assertJsonCount(4, 'durations')
            ->assertJsonPath('durations.0.minutes', 60)
            ->assertJsonPath('durations.0.label', '1h')
            ->assertJsonPath('durations.0.points', 100)
            ->assertJsonPath('durations.0.end_time', '11:00 AM')
            ->assertJsonPath('durations.1.label', '1.5h')
            ->assertJsonPath('durations.1.points', 150)
            // The fourth chip is 3h, matching the design — derived from
            // min+granularity it would have been 2.5h.
            ->assertJsonPath('durations.3.minutes', 180)
            ->assertJsonPath('durations.3.label', '3h');
    }

    public function test_a_duration_running_past_closing_time_is_offered_but_marked_unavailable(): void
    {
        // The app greys the chip out rather than hiding it, so the member
        // can see the option exists and why it cannot be taken.
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/quote?start_at=2026-08-17 19:00");

        $response->assertOk()
            ->assertJsonPath('durations.0.available', true)
            ->assertJsonPath('durations.1.available', false);

        $this->assertNotNull($response->json('durations.1.reason'));
    }

    public function test_a_duration_overlapping_an_existing_booking_is_marked_unavailable(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $this->book($space, 11);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/quote?start_at=2026-08-17 10:00");

        $response->assertOk()
            ->assertJsonPath('durations.0.available', true)
            ->assertJsonPath('durations.1.available', false);
    }

    public function test_a_co_space_offers_a_slot_until_its_capacity_is_reached(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace([
            'space_type' => SpaceType::CoSpace,
            'is_lockable' => false,
            'capacity' => 2,
        ]);
        $this->book($space, 10);

        $slots = fn () => collect(
            $this->withHeader('lang', 'en')
                ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
                ->json('data.slots')
        )->firstWhere('label', '10:00 AM');

        $this->assertSame('available', $slots()['state'], 'One of two desks taken is still bookable.');

        $this->book($space, 10);

        $this->assertSame('at_capacity', $slots()['state']);
    }

    public function test_the_calendar_rejects_a_range_longer_than_the_cap(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-01&to=2026-12-31")
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_the_availability_endpoints_are_closed_to_an_unauthenticated_caller(): void
    {
        $space = $this->openSpace();

        $this->getJson("/api/v1/member/spaces/{$space->id}/availability")->assertUnauthorized();
    }

    public function test_a_non_active_space_is_not_found(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace(['status' => OperationalStatus::Maintenance]);

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-17&to=2026-08-17")
            ->assertNotFound();
    }

    public function test_the_arabic_locale_returns_an_arabic_legend_and_closed_banner(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        BusinessHourException::factory()->create([
            'branch_id' => $space->building->branch_id,
            'date' => '2026-08-19',
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
            'reason' => 'عطلة رسمية',
        ]);

        $response = $this->withHeader('lang', 'ar')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-19&to=2026-08-19");

        $response->assertOk()
            ->assertJsonPath('legend.0.label', 'متاح')
            ->assertJsonPath('legend.1.label', 'محجوز')
            ->assertJsonPath('data.0.closed_banner.reason', 'عطلة رسمية');

        $this->assertStringContainsString('مغلق', $response->json('data.0.closed_banner.text'));
    }

    public function test_a_space_requiring_approval_says_so_in_its_rules(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace(['requires_approval' => true]);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-17&to=2026-08-17");

        $response->assertOk()
            ->assertJsonPath('rules.requires_approval', true)
            ->assertJsonPath('rules.max_selectable_dates', 10);

        // Two lang keys written for this screen that had no consumer until
        // now.
        $this->assertNotNull($response->json('rules.approval_notice'));
        $this->assertStringContainsString('Min. 1h', $response->json('rules.min_duration_note'));
    }

    /**
     * Reception books through the same BookingCreationService a member does,
     * so the desk has to be able to see the same slots. These assertions are
     * deliberately identical in shape to the member ones above: if the two
     * surfaces ever diverge, one of the pairs fails.
     */
    public function test_operations_reads_the_same_slot_grid_as_a_member(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $this->book($space, 14);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17");

        $response->assertOk()
            ->assertJsonPath('meta.granularity_minutes', 60)
            ->assertJsonPath('meta.timezone', 'Asia/Damascus');

        $slots = collect($response->json('data'));
        $booked = $slots->firstWhere('start_time', '14:00');

        $this->assertSame('booked', $booked['state']);
        $this->assertFalse($booked['is_available']);
        $this->assertNotNull($booked['reason'], 'a refused slot must say why');
    }

    public function test_the_admin_and_member_slot_grids_are_byte_identical(): void
    {
        $space = $this->openSpace();
        $this->book($space, 14);

        $this->actingAsMember();
        $asMember = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->json();

        $this->actingAsOperations();
        $asOperations = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->json();

        // The member route wraps the same payload in `data` and names the
        // slot list `slots`; that envelope is the only permitted difference.
        $this->assertSame($asMember['data'], $this->inMemberShape($asOperations));
    }

    public function test_operations_reads_the_duration_ladder_with_each_option_priced(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/quote?start_at=2026-08-17 10:00");

        $response->assertOk()
            ->assertJsonPath('meta.granularity_minutes', 60)
            ->assertJsonPath('durations.0.minutes', 60);

        // 90 minutes against 60-minute granularity: returned disabled WITH a
        // reason, never hidden, so the desk can say why it is refused.
        $ninety = collect($response->json('durations'))->firstWhere('minutes', 90);
        $this->assertNotNull($ninety, '90 minutes must be listed even when it cannot be booked');
        $this->assertFalse($ninety['available']);
        $this->assertNotNull($ninety['reason']);
    }

    /**
     * The admin routes sit inside the file's own admin|operations group. A
     * member reaching them would not be a leak -- the same data is on their
     * own route -- but it would mean the group stopped applying, which is
     * worth catching here rather than in whatever gets added next to it.
     */
    public function test_a_member_cannot_reach_the_admin_availability_routes(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $this->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertForbidden();
        $this->getJson("/api/v1/admin/spaces/{$space->id}/availability/quote?start_at=2026-08-17 10:00")
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Duration first: the app picks a length, then sees where it fits
    // ------------------------------------------------------------------

    public function test_slots_for_a_duration_are_whole_bookable_windows_each_priced(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $this->book($space, 10);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17&duration=120");

        $response->assertOk()
            ->assertJsonPath('data.meta.duration_minutes', 120)
            ->assertJsonPath('data.slots.0.start_time', '08:00')
            ->assertJsonPath('data.slots.0.end_time', '10:00')
            ->assertJsonPath('data.slots.0.is_available', true)
            ->assertJsonPath('data.slots.0.duration_minutes', 120)
            ->assertJsonPath('data.slots.0.points', 200)
            // 09:00–11:00 runs into the 10:00 booking.
            ->assertJsonPath('data.slots.1.start_time', '09:00')
            ->assertJsonPath('data.slots.1.state', 'booked')
            ->assertJsonPath('data.slots.1.reason', __('api.booking.slot_unavailable', [], 'en'));

        // The last start that still ends by 20:00.
        $this->assertSame('18:00', collect($response->json('data.slots'))->last()['start_time']);
    }

    public function test_slots_without_a_duration_keep_their_cell_shape_and_carry_no_price(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17");

        $response->assertOk()
            ->assertJsonPath('data.meta.duration_minutes', null)
            ->assertJsonPath('data.slots.0.end_time', '09:00')
            ->assertJsonMissingPath('data.slots.0.points');
    }

    public function test_a_duration_this_space_does_not_accept_is_a_validation_error(): void
    {
        $this->actingAsMember();
        // 60-minute granularity: 90 minutes is not a legal length here.
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17&duration=90")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['duration']);
    }

    public function test_a_duration_above_the_maximum_is_a_validation_error(): void
    {
        app(SettingService::class)->set('booking.max_duration_minutes', 120);
        $this->actingAsMember();
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17&duration=180")
            ->assertStatus(422)
            ->assertJsonPath('errors.duration.0', __('api.booking.duration_too_long', [], 'en'));
    }

    public function test_the_month_for_a_duration_marks_a_day_full_when_no_gap_is_long_enough(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        // Free: 08–10, 11–13, 14–16, 17–20. Nothing holds four hours.
        $this->book($space, 10);
        $this->book($space, 13);
        $this->book($space, 16);

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-17&to=2026-08-17&duration=240")
            ->assertOk()
            ->assertJsonPath('data.0.state', 'full')
            ->assertJsonPath('meta.duration_minutes', 240);

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-17&to=2026-08-17&duration=180")
            ->assertOk()
            ->assertJsonPath('data.0.state', 'available');
    }

    public function test_days_beyond_the_advance_horizon_are_out_of_range_and_not_selectable(): void
    {
        app(SettingService::class)->set('booking.max_advance_days', 7);
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-24&to=2026-08-25");

        $response->assertOk()
            ->assertJsonPath('data.0.state', 'available')
            ->assertJsonPath('data.1.state', 'out_of_range')
            ->assertJsonPath('data.1.is_selectable', false)
            ->assertJsonPath('data.1.closed_banner', null);
    }

    public function test_the_rules_block_carries_every_duration_and_window_constraint(): void
    {
        app(SettingService::class)->set('booking.max_duration_minutes', 180);
        app(SettingService::class)->set('booking.min_advance_minutes', 30);
        app(SettingService::class)->set('booking.max_advance_days', 30);
        $this->actingAsMember();
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->assertJsonPath('data.rules.min_duration_minutes', 60)
            ->assertJsonPath('data.rules.max_duration_minutes', 180)
            ->assertJsonPath('data.rules.min_advance_minutes', 30)
            ->assertJsonPath('data.rules.max_advance_days', 30)
            // 90 is not a legal length at 60-minute granularity, so the
            // ladder offers only what this space accepts.
            ->assertJsonPath('data.rules.duration_options_minutes', [60, 120, 180]);
    }

    public function test_a_quote_option_above_the_maximum_is_unavailable_with_that_reason(): void
    {
        app(SettingService::class)->set('booking.max_duration_minutes', 120);
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/quote?start_at=2026-08-17 10:00");

        $response->assertOk()
            ->assertJsonPath('durations.3.minutes', 180)
            ->assertJsonPath('durations.3.available', false)
            ->assertJsonPath('durations.3.reason', __('api.booking.duration_too_long', [], 'en'));
    }

    public function test_the_admin_and_member_duration_slot_grids_are_byte_identical(): void
    {
        $space = $this->openSpace();
        $this->book($space, 14);

        $this->actingAsMember();
        $asMember = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17&duration=120")
            ->assertOk()
            ->json();

        $this->actingAsOperations();
        $asOperations = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17&duration=120")
            ->assertOk()
            ->json();

        // The member route wraps the same payload in `data` and names the
        // slot list `slots`; that envelope is the only permitted difference.
        $this->assertSame($asMember['data'], $this->inMemberShape($asOperations));
    }
}
