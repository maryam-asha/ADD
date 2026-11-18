<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingPresentationService;
use App\Domain\Experience\Services\EventBrowseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A booking held for its prepayment draws its own stepper — submitted, then
 * "awaiting payment", then what it would become — and one cancelled while
 * still held says why (docs/decisions/prepayment-all-or-nothing.md).
 */
class AwaitingPaymentPresentationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{key: string, state: string}>
     */
    private function steps(Booking $booking): array
    {
        $steps = app(BookingPresentationService::class)->requestStatus($booking, 'en')['steps'];

        return array_map(fn (array $step) => ['key' => $step['key'], 'state' => $step['state']], $steps);
    }

    public function test_a_booking_held_for_its_payment_shows_it_as_the_current_step(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::AwaitingPayment, 'required_approval' => false]);

        $this->assertSame([
            ['key' => 'submitted', 'state' => 'complete'],
            ['key' => 'awaiting_payment', 'state' => 'current'],
            ['key' => 'confirmed', 'state' => 'upcoming'],
        ], $this->steps($booking));
    }

    public function test_a_held_booking_on_a_space_that_needs_approval_goes_on_to_review(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::AwaitingPayment, 'required_approval' => true]);

        $this->assertSame([
            ['key' => 'submitted', 'state' => 'complete'],
            ['key' => 'awaiting_payment', 'state' => 'current'],
            ['key' => 'pending', 'state' => 'upcoming'],
        ], $this->steps($booking));
    }

    public function test_a_booking_cancelled_while_held_ends_at_the_cancellation_and_says_why(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Cancelled, 'cancelled_at' => now()]);
        // `payment_due_at` arrives with its column; a hold that was released
        // by a cancellation is what keeps it set.
        $booking->setAttribute('payment_due_at', now());

        $this->assertTrue($booking->cancelledAwaitingPayment());
        $this->assertSame([
            ['key' => 'submitted', 'state' => 'complete'],
            ['key' => 'awaiting_payment', 'state' => 'complete'],
            ['key' => 'cancelled', 'state' => 'complete'],
        ], $this->steps($booking));
        $this->assertSame(
            'Cancelled because the payment was not completed in time.',
            app(BookingPresentationService::class)->statusNote($booking, 'en'),
        );
        $this->assertSame(
            'أُلغي لأن الدفع لم يكتمل في الوقت المحدد.',
            app(BookingPresentationService::class)->statusNote($booking, 'ar'),
        );
    }

    public function test_a_cancellation_with_no_hold_behind_it_is_not_one_for_payment(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Cancelled, 'cancelled_at' => now()]);

        $this->assertFalse($booking->cancelledAwaitingPayment());
        $this->assertNull(app(BookingPresentationService::class)->statusNote($booking, 'en'));
        $this->assertNotContains('awaiting_payment', array_column($this->steps($booking), 'key'));
    }

    public function test_the_status_has_a_label_in_both_languages(): void
    {
        $this->assertSame('Awaiting Payment', BookingStatus::AwaitingPayment->label('en'));
        $this->assertSame('بانتظار الدفع', BookingStatus::AwaitingPayment->label('ar'));

        $browse = app(EventBrowseService::class);
        $this->assertSame('Awaiting payment', $browse->translateRegistrationStatus('awaiting_payment', 'en'));
        $this->assertSame('بانتظار الدفع', $browse->translateRegistrationStatus('awaiting_payment', 'ar'));
    }
}
