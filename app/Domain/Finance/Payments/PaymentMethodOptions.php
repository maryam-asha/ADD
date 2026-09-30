<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\WalletService;
use Illuminate\Database\Eloquent\Model;

/**
 * What the app shows before a member pays for something: how much it costs
 * in points, and every enabled method that can pay for it — in config order,
 * so a gateway added to config/payments.php appears here with no other
 * change — each saying whether it can pay right now and, if not, why.
 *
 * The wallet method also lists the wallets the member may spend from, each
 * with its usable balance and whether it covers the amount, so the app can
 * offer the choice WalletChoiceRequiredException would otherwise ask for.
 */
class PaymentMethodOptions
{
    public function __construct(
        private readonly PaymentMethodRegistry $methods,
        private readonly FulfillerRegistry $fulfillers,
        private readonly PaymentService $payments,
        private readonly WalletService $wallets,
    ) {}

    /**
     * @return array{amount_points: int|null, methods: list<array<string, mixed>>}
     */
    public function for(PaymentPurpose $purpose, User $member, ?Model $payable = null, ?Plan $plan = null): array
    {
        // A top-up has no price: the member names the amount.
        $points = $purpose === PaymentPurpose::WalletTopUp ? null : $this->payments->quote($purpose, $payable, $plan);

        $methods = [];

        foreach ($this->methods->availableFor($purpose) as $key => $driver) {
            $option = ['key' => $key, 'label' => $driver->label(), 'available' => true, 'reason' => null];

            if ($key === WalletMethod::KEY) {
                $option = $this->walletOption($option, $purpose, $member, (int) $points);
            }

            $methods[] = $option;
        }

        return ['amount_points' => $points, 'methods' => $methods];
    }

    /**
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>
     */
    private function walletOption(array $option, PaymentPurpose $purpose, User $member, int $points): array
    {
        $category = $this->fulfillers->for($purpose)->walletCategory();

        $wallets = collect($this->wallets->spendOptions($member, $category))
            // A plan paid from a wallet goes to that wallet's owner; bought
            // from the app it is the member's own.
            ->when($purpose === PaymentPurpose::PlanPurchase, fn ($c) => $c->where('owner_type', OwnerType::User->value))
            ->map(fn (array $wallet) => [
                'owner_type' => $wallet['owner_type'],
                'owner_id' => $wallet['owner_id'],
                'owner_label' => $wallet['owner_label'],
                'usable_balance' => $wallet['usable_balance'],
                'sufficient' => $wallet['usable_balance'] >= $points,
            ])
            ->values()
            ->all();

        $best = (int) max(array_column($wallets, 'usable_balance') ?: [0]);

        if ($best < $points) {
            $option['available'] = false;
            $option['reason'] = __('api.payment.insufficient_points', [
                'current' => $best,
                'required' => $points,
                'shortage' => $points - $best,
            ]);
        }

        return $option + ['wallets' => $wallets];
    }
}
