<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class WalkInStartSourceTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_reception_walk_in_records_its_source_and_the_members_coverage(): void
    {
        $member = User::factory()->create();
        $this->subscribe($member, $this->planWithHours(40));

        $session = app(WalkInCapacityService::class)->start($this->coworkingSpace(), $member)->session->fresh();

        $this->assertSame(SessionStartSource::Reception, $session->started_via);
        $this->assertNull($session->kiosk_id);
        $this->assertFalse($session->accepted_uncovered);
        $this->assertSame('covered', $session->coverage_snapshot['status']);
        $this->assertSame('walkin', $session->coworkingType());
    }

    public function test_a_scan_walk_in_records_the_kiosk_and_the_acceptance(): void
    {
        $space = $this->coworkingSpace();
        [$kiosk] = $this->pairedKiosk($space);

        $session = app(WalkInCapacityService::class)
            ->start($space, User::factory()->create(), SessionStartSource::Scan, $kiosk, true)
            ->session->fresh();

        $this->assertSame(SessionStartSource::Scan, $session->started_via);
        $this->assertSame($kiosk->id, $session->kiosk_id);
        $this->assertTrue($session->accepted_uncovered);
        $this->assertSame('no_plan', $session->coverage_snapshot['reason']);
    }

    public function test_a_booking_checked_in_before_this_feature_reads_as_reception(): void
    {
        $booking = Booking::factory()->checkedIn()->create();

        $this->assertSame(SessionStartSource::Reception, $booking->startSource());
        $this->assertSame('booking', $booking->coworkingType());
    }
}
