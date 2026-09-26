<?php

namespace Database\Factories;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // 100 points at the default rate of 0.10 USD = 10.00 USD. Kept
        // consistent so a test asserting on a credited balance does not have
        // to reverse-engineer a random amount.
        $points = 100;

        return [
            'payment_id' => $this->faker->uuid(),
            'callback_token' => Payment::newCallbackToken(),
            'purpose' => PaymentPurpose::WalletTopUp,
            'status' => PaymentStatus::Pending,
            'user_id' => User::factory(),
            'wallet_id' => Wallet::factory(),
            'plan_id' => null,
            'amount_minor' => 1000,
            'currency' => 'USD',
            'currency_exponent' => 2,
            'points_to_credit' => $points,
            'terminal_id' => '99990001',
            'lang' => 'en',
            'check_attempts' => 0,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Accepted]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Pending]);
    }

    /**
     * A plan purchase priced from the plan itself, so the payment and the
     * plan cannot disagree about what was bought.
     */
    public function forPlan(Plan $plan): static
    {
        return $this->state(fn () => [
            'purpose' => PaymentPurpose::PlanPurchase,
            'plan_id' => $plan->id,
            'points_to_credit' => (int) $plan->price_points,
        ]);
    }
}
