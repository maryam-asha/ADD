<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Support\Enums\EventRegistrationStatus;
use PHPUnit\Framework\TestCase;

/**
 * A booking or registration held for its prepayment holds its place and can
 * be paid or cancelled — but is never confirmed/approved, so everything that
 * treats those as real ignores it by default.
 */
class AwaitingPaymentStatusTest extends TestCase
{
    public function test_a_booking_awaiting_payment_holds_its_slot_and_can_be_paid_and_cancelled(): void
    {
        $this->assertSame('awaiting_payment', BookingStatus::AwaitingPayment->value);
        $this->assertSame(BookingStatus::AwaitingPayment, BookingStatus::cases()[0]);
        $this->assertContains(BookingStatus::AwaitingPayment, BookingStatus::slotHolding());
        $this->assertTrue(BookingStatus::AwaitingPayment->isPayable());
        $this->assertTrue(BookingStatus::AwaitingPayment->isOpen());
    }

    public function test_a_registration_awaiting_payment_holds_its_seat_and_is_not_decided(): void
    {
        $this->assertSame('awaiting_payment', EventRegistrationStatus::AwaitingPayment->value);
        $this->assertContains(EventRegistrationStatus::AwaitingPayment, EventRegistrationStatus::seatHolding());
        $this->assertTrue(EventRegistrationStatus::AwaitingPayment->holdsSeat());
        $this->assertFalse(EventRegistrationStatus::AwaitingPayment->isTerminal());
    }
}
