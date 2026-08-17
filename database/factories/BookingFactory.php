<?php

namespace Database\Factories;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addDay()->setTime(10, 0);

        return [
            'space_id' => Space::factory()->room(),
            'user_id' => User::factory(),
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
            'status' => BookingStatus::Confirmed,
            'payment_state' => PaymentState::Unpaid,
        ];
    }

    public function checkedIn(): static
    {
        return $this->state(fn () => ['checked_in_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
