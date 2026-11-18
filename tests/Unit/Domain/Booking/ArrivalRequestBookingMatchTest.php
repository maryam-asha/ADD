<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\ArrivalRequestMatcher;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArrivalRequestBookingMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_matches_a_confirmed_booking_starting_today_for_the_member(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00', 'Asia/Damascus'));
        $member = User::factory()->create();
        $branch = Branch::factory()->create();
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => Space::factory()->room()->for(
                Building::factory()->for($branch)
            ),
            'start_at' => Carbon::parse('2026-08-23 14:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 15:00:00', 'Asia/Damascus'),
        ]);

        $matched = (new ArrivalRequestMatcher)
            ->matchBookingFor($member, $branch, now());

        $this->assertNotNull($matched);
        $this->assertTrue($matched->is($booking));
    }

    public function test_does_not_match_yesterdays_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00', 'Asia/Damascus'));
        $member = User::factory()->create();
        $branch = Branch::factory()->create();
        Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => Space::factory()->room()->for(
                Building::factory()->for($branch)
            ),
            'start_at' => Carbon::parse('2026-08-22 14:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-22 15:00:00', 'Asia/Damascus'),
        ]);

        $matched = (new ArrivalRequestMatcher)
            ->matchBookingFor($member, $branch, now());

        $this->assertNull($matched);
    }

    public function test_does_not_match_an_already_checked_in_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00', 'Asia/Damascus'));
        $member = User::factory()->create();
        $branch = Branch::factory()->create();
        Booking::factory()->checkedIn()->create([
            'user_id' => $member->id,
            'space_id' => Space::factory()->room()->for(
                Building::factory()->for($branch)
            ),
            'start_at' => Carbon::parse('2026-08-23 08:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 09:30:00', 'Asia/Damascus'),
        ]);

        $matched = (new ArrivalRequestMatcher)
            ->matchBookingFor($member, $branch, now());

        $this->assertNull($matched);
    }

    public function test_does_not_match_a_rejected_or_cancelled_booking_but_matches_pending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00', 'Asia/Damascus'));
        $member = User::factory()->create();
        $branch = Branch::factory()->create();
        $building = Building::factory()->for($branch)->create();

        Booking::factory()->cancelled()->create([
            'user_id' => $member->id,
            'space_id' => Space::factory()->room()->for($building),
            'start_at' => Carbon::parse('2026-08-23 10:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 11:00:00', 'Asia/Damascus'),
        ]);
        Booking::factory()->rejected()->create([
            'user_id' => $member->id,
            'space_id' => Space::factory()->room()->for($building),
            'start_at' => Carbon::parse('2026-08-23 12:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 13:00:00', 'Asia/Damascus'),
        ]);
        $pending = Booking::factory()->pending()->create([
            'user_id' => $member->id,
            'space_id' => Space::factory()->room()->for($building),
            'start_at' => Carbon::parse('2026-08-23 14:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 15:00:00', 'Asia/Damascus'),
        ]);

        $matched = (new ArrivalRequestMatcher)
            ->matchBookingFor($member, $branch, now());

        $this->assertNotNull($matched);
        $this->assertTrue($matched->is($pending));
        $this->assertSame(BookingStatus::Pending, $matched->status);
    }

    public function test_picks_the_soonest_starting_booking_when_multiple_match(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00', 'Asia/Damascus'));
        $member = User::factory()->create();
        $branch = Branch::factory()->create();
        $building = Building::factory()->for($branch)->create();

        $later = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => Space::factory()->room()->for($building),
            'start_at' => Carbon::parse('2026-08-23 16:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 17:00:00', 'Asia/Damascus'),
        ]);
        $sooner = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => Space::factory()->room()->for($building),
            'start_at' => Carbon::parse('2026-08-23 11:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 12:00:00', 'Asia/Damascus'),
        ]);

        $matched = (new ArrivalRequestMatcher)
            ->matchBookingFor($member, $branch, now());

        $this->assertTrue($matched->is($sooner));
        $this->assertFalse($matched->is($later));
    }

    public function test_does_not_match_a_booking_whose_window_already_ended_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 18:00:00', 'Asia/Damascus'));
        $member = User::factory()->create();
        $branch = Branch::factory()->create();
        Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => Space::factory()->room()->for(
                Building::factory()->for($branch)
            ),
            'start_at' => Carbon::parse('2026-08-23 06:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 07:00:00', 'Asia/Damascus'),
        ]);

        $matched = (new ArrivalRequestMatcher)
            ->matchBookingFor($member, $branch, now());

        $this->assertNull($matched);
    }
}
