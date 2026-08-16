<?php

namespace Database\Factories;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalkinSession>
 */
class WalkinSessionFactory extends Factory
{
    protected $model = WalkinSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'space_id' => Space::factory()->room(),
            'user_id' => User::factory(),
            'checked_in_at' => now(),
            'payment_state' => PaymentState::Unpaid,
        ];
    }
}
