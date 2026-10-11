<?php

namespace Database\Factories;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

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
            'method' => PaymeraGateway::KEY,
            'purpose' => PaymentPurpose::WalletTopUp,
            'status' => PaymentStatus::Pending,
            'user_id' => User::factory(),
            'wallet_id' => Wallet::factory(),
            'plan_id' => null,
            'amount_minor' => 1000,
            'currency' => 'USD',
            'currency_exponent' => 2,
            'points_to_credit' => $points,
            // Follows `points_to_credit` unless a test states otherwise, so
            // overriding one does not leave the two disagreeing.
            'amount_points' => fn (array $attributes) => $attributes['points_to_credit'],
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
            'amount_points' => (int) $plan->price_points,
        ]);
    }

    /**
     * A plan held for cash at the desk, as opened before plans stopped
     * taking cash: pending, at the plan's price, lapsing in `$hours`. Such a
     * hold is still settled at reception or let go by payments:expire-pending.
     */
    public function cashPlanHold(Plan $plan, int $hours = 48): static
    {
        return $this->forPlan($plan)
            ->withoutGateway('cash', (int) $plan->price_points)
            ->state(fn () => [
                'status' => PaymentStatus::Pending,
                'list_price_points' => (int) $plan->price_points,
                'expires_at' => now()->addHours($hours),
            ]);
    }

    /**
     * A payment that never touched a gateway (wallet points, cash at the
     * desk): no terminal, no callback token, no minor-unit amount, and
     * nothing to credit — its worth is `amount_points`.
     */
    public function withoutGateway(string $method, int $points): static
    {
        return $this->state(fn () => [
            'method' => $method,
            'payment_id' => null,
            'callback_token' => null,
            'terminal_id' => null,
            'amount_minor' => null,
            'currency' => null,
            'currency_exponent' => null,
            'lang' => null,
            'points_to_credit' => 0,
            'amount_points' => $points,
        ]);
    }

    /**
     * Pays for one record, stored under its morph-map alias.
     */
    public function forPayable(Model $payable): static
    {
        return $this->state(fn () => [
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
        ]);
    }
}
