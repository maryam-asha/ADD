<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalkInCapacityServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalkInCapacityService $capacity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capacity = app(WalkInCapacityService::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function openSpace(?int $capacity): Space
    {
        $space = Space::factory()->room()->create(['capacity' => $capacity]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_a_walk_in_starts_successfully_when_capacity_is_available(): void
    {
        $space = $this->openSpace(2);
        $member = User::factory()->create();

        $session = $this->capacity->start($space, $member);

        $this->assertInstanceOf(WalkinSession::class, $session);
        $this->assertTrue($session->checked_in_at->equalTo(now()));
        $this->assertTrue($session->space->is($space));
        $this->assertTrue($session->user->is($member));
    }

    public function test_a_null_capacity_space_is_treated_as_unlimited(): void
    {
        $space = $this->openSpace(null);

        $this->capacity->start($space, User::factory()->create());
        $second = $this->capacity->start($space, User::factory()->create());

        $this->assertInstanceOf(WalkinSession::class, $second);
    }

    public function test_a_second_walk_in_is_rejected_once_the_last_unit_of_capacity_is_taken(): void
    {
        $space = $this->openSpace(1);
        $first = User::factory()->create();
        $second = User::factory()->create();

        // True multi-connection concurrency isn't reproducible against this
        // suite's in-memory SQLite (each connection would get its own
        // separate database — see this plan's Global Constraints). This
        // proves the property that actually matters once `lockForUpdate()`
        // serializes two real concurrent requests on MySQL: the second
        // arrival, evaluated only after the first's commit, never sees
        // stale capacity and is correctly rejected.
        $this->capacity->start($space, $first);

        try {
            $this->capacity->start($space, $second);
            $this->fail('Expected a ReceptionActionException for no capacity.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.no_capacity', $e->messageKey);
            $this->assertSame(422, $e->status);
        }

        $this->assertSame(1, WalkinSession::where('space_id', $space->id)->count());
    }

    public function test_an_existing_checked_in_booking_counts_toward_capacity(): void
    {
        $space = $this->openSpace(1);
        Booking::factory()->checkedIn()->create(['space_id' => $space->id]);

        $this->expectException(ReceptionActionException::class);
        $this->capacity->start($space, User::factory()->create());
    }

    public function test_a_checked_out_booking_does_not_count_toward_capacity(): void
    {
        $space = $this->openSpace(1);
        Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_out_at' => now()->subMinutes(10),
        ]);

        $session = $this->capacity->start($space, User::factory()->create());

        $this->assertInstanceOf(WalkinSession::class, $session);
    }

    public function test_starting_a_walk_in_outside_business_hours_fails(): void
    {
        $space = $this->openSpace(2);
        Carbon::setTestNow(Carbon::parse('2026-08-17 23:00:00', 'Asia/Damascus'));

        try {
            $this->capacity->start($space, User::factory()->create());
            $this->fail('Expected a ReceptionActionException for outside business hours.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.outside_business_hours', $e->messageKey);
        }
    }
}
