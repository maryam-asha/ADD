<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingApprovalColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_space_can_carry_granularity_and_buffer_overrides(): void
    {
        $space = Space::factory()->room()->create([
            'slot_granularity_minutes' => 15,
            'buffer_minutes' => 10,
        ]);

        $space->refresh();
        $this->assertSame(15, $space->slot_granularity_minutes);
        $this->assertSame(10, $space->buffer_minutes);
    }

    public function test_a_space_without_overrides_has_null_granularity_and_buffer(): void
    {
        $space = Space::factory()->room()->create();

        $this->assertNull($space->fresh()->slot_granularity_minutes);
        $this->assertNull($space->fresh()->buffer_minutes);
    }

    public function test_a_space_does_not_require_approval_by_default(): void
    {
        $space = Space::factory()->room()->create();

        $this->assertFalse($space->fresh()->requires_approval);
    }

    public function test_a_space_can_be_flagged_to_require_approval(): void
    {
        $space = Space::factory()->room()->requiresApproval()->create();

        $this->assertTrue($space->fresh()->requires_approval);
    }

    public function test_a_booking_can_carry_an_approval_decision(): void
    {
        $operator = User::factory()->create();
        $booking = Booking::factory()->rejected()->create(['approved_by' => $operator->id, 'approved_at' => now()]);

        $booking->refresh();
        $this->assertSame(BookingStatus::Rejected, $booking->status);
        $this->assertNotNull($booking->rejection_reason);
        $this->assertSame($operator->id, $booking->approved_by);
        $this->assertNotNull($booking->approved_at);
    }

    public function test_a_pending_booking_has_no_approval_decision_yet(): void
    {
        $booking = Booking::factory()->pending()->create();

        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertNull($booking->rejection_reason);
        $this->assertNull($booking->approved_by);
        $this->assertNull($booking->approved_at);
    }
}
