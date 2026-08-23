<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArrivalRequestModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unmatched_arrival_request_can_be_created_with_defaults(): void
    {
        $member = User::factory()->create();

        $request = ArrivalRequest::factory()->create(['user_id' => $member->id]);

        $this->assertSame(ArrivalRequestStatus::Pending, $request->status);
        $this->assertNull($request->matched_booking_id);
        $this->assertTrue($request->user->is($member));
    }

    public function test_a_matched_arrival_request_resolves_its_booking_relation(): void
    {
        $booking = Booking::factory()->create();

        $request = ArrivalRequest::factory()->matched()->create();

        $this->assertNotNull($request->matched_booking_id);
        $this->assertInstanceOf(Booking::class, $request->matchedBooking);
    }

    public function test_status_casts_to_a_backed_enum(): void
    {
        $request = ArrivalRequest::factory()->confirmed()->create();

        $this->assertSame(ArrivalRequestStatus::Confirmed, $request->fresh()->status);
        $this->assertNotNull($request->confirmed_by_user_id);
        $this->assertNotNull($request->confirmed_space_id);
    }
}
