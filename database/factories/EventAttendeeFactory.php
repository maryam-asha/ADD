<?php

namespace Database\Factories;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Models\User;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventAttendee>
 */
class EventAttendeeFactory extends Factory
{
    protected $model = EventAttendee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09'.fake()->numerify('########'),
            // Matches the column default: a registration is approved
            // unless its event asks for review.
            'status' => EventRegistrationStatus::Approved,
            'attended' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => EventRegistrationStatus::Pending]);
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => EventRegistrationStatus::Approved]);
    }

    public function rejected(string $reason = 'Not eligible'): static
    {
        return $this->state(fn () => [
            'status' => EventRegistrationStatus::Rejected,
            'rejection_reason' => $reason,
            'approved_at' => now(),
        ]);
    }

    /** A walk-in RSVP with no account behind it. */
    public function guest(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }
}
