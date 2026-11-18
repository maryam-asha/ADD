<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class ReceptionActiveSessionsDetailTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(string $type, int $id): array
    {
        return collect($this->getJson('/api/v1/admin/reception/sessions/active')->assertOk()->json('data'))
            ->first(fn (array $row) => $row['type'] === $type && $row['id'] === $id);
    }

    public function test_a_scan_started_session_shows_who_their_plan_and_their_end_request(): void
    {
        $space = $this->coworkingSpace();
        [$kiosk] = $this->pairedKiosk($space);
        $member = User::factory()->create(['name' => 'Lina Haddad']);
        $this->subscribe($member, $this->planWithHours(40));
        $session = app(WalkInCapacityService::class)->start($space, $member, SessionStartSource::Scan, $kiosk)->session;
        $session->endRequests()->create(['user_id' => $member->id, 'requested_at' => now(), 'status' => SessionEndRequestStatus::Pending]);
        $this->actingAsOperations();

        $row = $this->row('walkin', $session->id);

        $this->assertSame('scan', $row['started_via']);
        $this->assertSame('Lina Haddad', $row['member']['name']);
        $this->assertSame($member->phone, $row['member']['phone']);
        $this->assertSame('covered', $row['plan']['status']);
        $this->assertSame(40, $row['plan']['hours_remaining']);
        $this->assertFalse($row['accepted_uncovered']);
        $this->assertSame('pending', $row['end_request']['status']);
    }

    public function test_a_desk_checked_in_booking_reads_as_reception_with_no_plan_block(): void
    {
        $booking = Booking::factory()->checkedIn()->create(['space_id' => $this->coworkingSpace()->id]);
        $this->actingAsOperations();

        $row = $this->row('booking', $booking->id);

        $this->assertSame('reception', $row['started_via']);
        $this->assertNull($row['plan']);
        $this->assertNull($row['end_request']);
        $this->assertSame($booking->user_id, $row['member']['id']);
    }
}
