<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingApprovalService;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingApprovalService $approvals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvals = app(BookingApprovalService::class);
    }

    public function test_approving_a_pending_booking_confirms_it_and_notifies_the_member(): void
    {
        $operator = User::factory()->create();
        $booking = Booking::factory()->pending()->create();

        $this->approvals->approve($booking, $operator);

        $booking->refresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame($operator->id, $booking->approved_by);
        $this->assertNotNull($booking->approved_at);
        $this->assertSame(
            1,
            NotificationLog::where('user_id', $booking->user_id)->where('template_key', 'booking.approved')->count()
        );
    }

    public function test_rejecting_a_pending_booking_requires_a_reason(): void
    {
        $operator = User::factory()->create();
        $booking = Booking::factory()->pending()->create();

        try {
            $this->approvals->reject($booking, $operator, '');
            $this->fail('Expected a ReceptionActionException for a missing rejection reason.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.rejection_reason_required', $e->messageKey);
        }

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_rejecting_a_pending_booking_with_a_reason_rejects_it_and_notifies_the_member(): void
    {
        $operator = User::factory()->create();
        $booking = Booking::factory()->pending()->create();

        $this->approvals->reject($booking, $operator, 'Space closed for maintenance that day.');

        $booking->refresh();
        $this->assertSame(BookingStatus::Rejected, $booking->status);
        $this->assertSame('Space closed for maintenance that day.', $booking->rejection_reason);
        $this->assertSame($operator->id, $booking->approved_by);
        $this->assertNotNull($booking->approved_at);
        $this->assertSame(
            1,
            NotificationLog::where('user_id', $booking->user_id)->where('template_key', 'booking.rejected')->count()
        );
    }

    public function test_approving_an_already_decided_booking_fails(): void
    {
        $operator = User::factory()->create();
        $booking = Booking::factory()->create();

        try {
            $this->approvals->approve($booking, $operator);
            $this->fail('Expected a ReceptionActionException for not pending.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.not_pending', $e->messageKey);
            $this->assertSame(409, $e->status);
        }
    }

    public function test_rejecting_an_already_cancelled_booking_fails(): void
    {
        $operator = User::factory()->create();
        $booking = Booking::factory()->cancelled()->create();

        try {
            $this->approvals->reject($booking, $operator, 'Too late.');
            $this->fail('Expected a ReceptionActionException for not pending.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.not_pending', $e->messageKey);
        }
    }

    /**
     * Reproduces true concurrency the way
     * CloseOverdueReceptionSessionsCommandTest does: two independently
     * fetched instances of the same row, one decides first, the second
     * (stale) instance must observe the committed state and reject.
     */
    public function test_a_stale_approval_attempt_after_a_concurrent_rejection_is_rejected(): void
    {
        $operator = User::factory()->create();
        $booking = Booking::factory()->pending()->create();

        $staleCopy = Booking::find($booking->id);

        $this->approvals->reject(Booking::find($booking->id), $operator, 'Already handled.');

        try {
            $this->approvals->approve($staleCopy, $operator);
            $this->fail('Expected a ReceptionActionException — approve() must not overwrite a concurrently-completed rejection.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.not_pending', $e->messageKey);
        }

        $this->assertSame(BookingStatus::Rejected, $booking->fresh()->status);
    }
}
