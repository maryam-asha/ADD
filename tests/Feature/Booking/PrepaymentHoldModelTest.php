<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrepaymentHoldModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hold_past_its_deadline_has_expired(): void
    {
        $hold = Booking::factory()->create(['status' => BookingStatus::AwaitingPayment, 'payment_due_at' => now()->subMinute()]);
        $live = Booking::factory()->create(['status' => BookingStatus::AwaitingPayment, 'payment_due_at' => now()->addMinute()]);

        $this->assertTrue($hold->awaitingPaymentExpired());
        $this->assertFalse($live->awaitingPaymentExpired());
    }

    /**
     * A released hold has no deadline left, so cancelling it later reads as
     * an ordinary cancellation — not "payment not completed".
     */
    public function test_only_a_hold_cancelled_while_held_reads_as_unpaid(): void
    {
        $cancelledWhileHeld = Booking::factory()->create(['status' => BookingStatus::Cancelled, 'payment_due_at' => now()->subMinute()]);
        $paidThenCancelled = Booking::factory()->create(['status' => BookingStatus::Cancelled, 'payment_due_at' => null]);

        $this->assertTrue($cancelledWhileHeld->cancelledAwaitingPayment());
        $this->assertFalse($paidThenCancelled->cancelledAwaitingPayment());
    }

    public function test_the_window_defaults_to_fifteen_minutes(): void
    {
        $this->assertSame(15, config('payments.prepayment_window_minutes'));
    }
}
