<?php

namespace Database\Factories;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArrivalRequest>
 */
class ArrivalRequestFactory extends Factory
{
    protected $model = ArrivalRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'requested_at' => now(),
            'matched_booking_id' => null,
            'status' => ArrivalRequestStatus::Pending,
        ];
    }

    public function matched(): static
    {
        return $this->state(fn () => ['matched_booking_id' => Booking::factory()]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => ArrivalRequestStatus::Confirmed,
            'confirmed_by_user_id' => User::factory(),
            'confirmed_space_id' => Space::factory()->room(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => ArrivalRequestStatus::Rejected]);
    }

    public function expired(): static
    {
        return $this->state(['status' => ArrivalRequestStatus::Expired]);
    }
}
