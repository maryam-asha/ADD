<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The general operations bookings index, and reception creating a booking
 * on a member's behalf.
 */
class AdminBookingIndexTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Building $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        $this->branch = Branch::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->building = Building::factory()->create(['branch_id' => $this->branch->id]);

        BusinessHour::factory()->create([
            'branch_id' => $this->branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);
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

    private function book(Space $space, int $hour, array $attributes = []): Booking
    {
        $start = Carbon::parse('2026-08-17', 'Asia/Damascus')->setTime($hour, 0);

        return Booking::factory()->create(array_merge([
            'space_id' => $space->id,
            'start_at' => $start->copy()->setTimezone('UTC'),
            'end_at' => $start->copy()->addHour()->setTimezone('UTC'),
        ], $attributes));
    }

    public function test_operations_sees_every_members_bookings(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $this->book($space, 10, ['user_id' => User::factory()->create()->id]);
        $this->book($space, 12, ['user_id' => User::factory()->create()->id]);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/bookings')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_bookings_can_be_filtered_by_status(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $this->book($space, 10);
        $this->book($space, 12, ['status' => BookingStatus::Pending]);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/bookings?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_bookings_can_be_filtered_by_date_range(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $this->book($space, 10);

        Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => Carbon::parse('2026-09-01 10:00', 'Asia/Damascus')->setTimezone('UTC'),
            'end_at' => Carbon::parse('2026-09-01 11:00', 'Asia/Damascus')->setTimezone('UTC'),
        ]);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/bookings?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_bookings_can_be_filtered_by_space(): void
    {
        $this->actingAsOperations();
        $wanted = $this->space();
        $other = $this->space();
        $this->book($wanted, 10);
        $this->book($other, 12);

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/bookings?space_id={$wanted->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space.id', $wanted->id);
    }

    public function test_a_search_matches_the_members_name_or_phone(): void
    {
        $this->actingAsOperations();
        $space = $this->space();

        $layla = User::factory()->create(['name' => 'Layla Haddad']);
        $omar = User::factory()->create(['name' => 'Omar Khalil']);

        $this->book($space, 10, ['user_id' => $layla->id]);
        $this->book($space, 12, ['user_id' => $omar->id]);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/bookings?q=Layla')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.member.name', 'Layla Haddad');
    }

    public function test_the_detail_carries_the_same_stepper_the_member_sees(): void
    {
        $this->actingAsOperations();
        $space = $this->space(['requires_approval' => true]);
        $booking = $this->book($space, 14, ['status' => BookingStatus::Pending]);

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.request_status.current', 'under_review')
            ->assertJsonPath('data.breakdown.total.points', 100)
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_a_member_cannot_reach_the_operations_index(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/bookings')
            ->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Reception booking on a member's behalf
    // ---------------------------------------------------------------

    public function test_reception_can_create_a_booking_for_a_member(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $member = User::factory()->create();
        $member->assignRole('member');

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/bookings', [
            'user_id' => $member->id,
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 15:00',
        ])->assertCreated();

        $booking = Booking::first();

        $this->assertSame($member->id, $booking->user_id, 'The booking belongs to the member, not the operator.');
    }

    /**
     * The point of reusing BookingCreationService rather than writing a
     * second creation path: reception cannot accidentally double-book a
     * room the member endpoint would have refused.
     */
    public function test_reception_booking_enforces_the_same_availability_rules(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $member = User::factory()->create();
        $this->book($space, 14);

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/bookings', [
            'user_id' => $member->id,
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 15:00',
        ])->assertStatus(422);

        $this->assertSame(1, Booking::count());
    }

    public function test_reception_cannot_back_date_a_booking_either(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $member = User::factory()->create();

        // now() is 08:00, so 06:00 has gone.
        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/bookings', [
            'user_id' => $member->id,
            'space_id' => $space->id,
            'start_at' => '2026-08-17 06:00',
            'end_at' => '2026-08-17 07:00',
        ])->assertStatus(422);

        $this->assertSame(0, Booking::count());
    }

    public function test_a_member_cannot_book_on_someone_elses_behalf(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $space = $this->space();

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/bookings', [
            'user_id' => User::factory()->create()->id,
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 15:00',
        ])->assertForbidden();
    }
}
