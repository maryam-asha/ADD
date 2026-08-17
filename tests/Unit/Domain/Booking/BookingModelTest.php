<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_booking_can_be_created_with_defaults(): void
    {
        $space = Space::factory()->room()->create();
        $member = User::factory()->create();

        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
        ]);

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertNull($booking->payment_source);
        $this->assertNull($booking->checked_in_at);
        $this->assertTrue($booking->space->is($space));
        $this->assertTrue($booking->user->is($member));
    }

    public function test_a_booking_can_carry_a_full_settlement(): void
    {
        $operator = User::factory()->create();

        $booking = Booking::factory()->create([
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Cash,
            'payment_method' => PaymentMethod::Sham,
            'paid_by' => $operator->id,
            'paid_at' => now(),
            'amount_owed' => '12.50',
            'currency' => 'USD',
        ]);

        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(PaymentSource::Cash, $booking->payment_source);
        $this->assertSame(PaymentMethod::Sham, $booking->payment_method);
        $this->assertSame('12.50', (string) $booking->amount_owed);
    }
}
