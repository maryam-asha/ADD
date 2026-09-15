<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A booking time arrives as wall clock and is checked against wall-clock
 * business hours, so the two have to agree about what timezone means.
 *
 * The existing booking tests all send an explicit +03:00 offset, which
 * Carbon::parse handles correctly — so the gap only showed for a client
 * sending a bare time, which was read as UTC and shifted three hours. A
 * 19:00 booking in a space open until 20:00 was refused as "outside
 * business hours" because it was being compared as 22:00.
 */
class BookingTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
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

    private function openSpace(): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_a_bare_local_time_inside_business_hours_is_accepted(): void
    {
        $space = $this->openSpace();
        $this->actingAsMember();

        // 19:00-20:00 sits inside 08:00-20:00. Read as UTC it would be
        // 22:00-23:00 and refused.
        $this->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17 19:00:00',
            'end_at' => '2026-08-17 20:00:00',
            'payment_source' => 'cash',
        ])->assertCreated();
    }

    public function test_a_bare_local_time_is_stored_as_the_instant_it_means(): void
    {
        $space = $this->openSpace();
        $this->actingAsMember();

        $this->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17 10:00:00',
            'end_at' => '2026-08-17 11:00:00',
            'payment_source' => 'cash',
        ])->assertCreated();

        // 10:00 in Damascus is 07:00 UTC.
        $this->assertSame(
            '2026-08-17 07:00:00',
            Booking::firstOrFail()->start_at->utc()->toDateTimeString()
        );
    }

    public function test_a_bare_local_time_outside_business_hours_is_still_refused(): void
    {
        $space = $this->openSpace();
        $this->actingAsMember();

        // 21:00 really is past the 20:00 close — the fix must not make
        // everything pass.
        $this->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17 21:00:00',
            'end_at' => '2026-08-17 22:00:00',
            'payment_source' => 'cash',
        ])->assertStatus(422);
    }

    public function test_an_explicit_offset_is_still_taken_at_its_word(): void
    {
        $space = $this->openSpace();
        $this->actingAsMember();

        // What every existing booking test sends; it must keep working.
        $this->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_source' => 'cash',
        ])->assertCreated();

        $this->assertSame(
            '2026-08-17 07:00:00',
            Booking::firstOrFail()->start_at->utc()->toDateTimeString()
        );
    }

    public function test_a_utc_instant_from_a_client_is_not_shifted_again(): void
    {
        $space = $this->openSpace();
        $this->actingAsMember();

        // 07:00Z is 10:00 local — inside hours, and must not be re-read.
        $this->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17T07:00:00Z',
            'end_at' => '2026-08-17T08:00:00Z',
            'payment_source' => 'cash',
        ])->assertCreated();

        $this->assertSame(
            '2026-08-17 07:00:00',
            Booking::firstOrFail()->start_at->utc()->toDateTimeString()
        );
    }
}
