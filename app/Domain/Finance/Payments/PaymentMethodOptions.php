<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Methods\CashMethod;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\WalletService;
use Illuminate\Database\Eloquent\Model;

/**
 * What the app shows before a member pays for something: how much it costs
 * in points, and every enabled method that can pay for it — in config order,
 * so a gateway added to config/payments.php appears here with no other
 * change — each saying whether it can pay right now and, if not, why, and
 * `hint`: what the member does next, when the label alone does not say.
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
     * `$purpose` null lists every enabled method, for a screen that has not
     * decided what is being paid for yet. `$member` null (no login) lists the
     * methods without any balance: nothing is priced for a guest but a plan,
     * whose price is public.
     *
     * @return array{amount_points: int|null, methods: list<array<string, mixed>>}
     */
    public function for(?PaymentPurpose $purpose, ?User $member, ?Model $payable = null, ?Plan $plan = null, ?int $points = null): array
    {
        // `$points`: a price the caller already quoted — with a coupon the
        // member is about to use (CouponPreview).
        $points ??= $this->pointsDue($purpose, $member, $payable, $plan);
        $drivers = $purpose === null ? $this->methods->enabled() : $this->methods->availableFor($purpose);

        $methods = [];

        foreach ($drivers as $key => $driver) {
            $option = ['key' => $key, 'label' => $driver->label(), 'available' => true, 'reason' => null, 'hint' => null];

            if ($key === WalletMethod::KEY) {
                $option = $this->walletOption($option, $purpose, $member, $points);
            }

            // Cash for a top-up starts nothing: it tells the member where to go.
            if ($key === CashMethod::KEY && $purpose === PaymentPurpose::WalletTopUp) {
                $option['hint'] = __('api.payment.hints.cash_top_up');
            }

            $methods[] = $option;
        }

        return ['amount_points' => $points, 'methods' => $methods];
    }

    private function pointsDue(?PaymentPurpose $purpose, ?User $member, ?Model $payable, ?Plan $plan): ?int
    {
        // A top-up has no price: the member names the amount.
        if ($purpose === null || $purpose === PaymentPurpose::WalletTopUp) {
            return null;
        }

        // A record is only priced for the member it belongs to.
        if ($payable === null && $plan === null) {
            return null;
        }

        if ($payable !== null && $member === null) {
            return null;
        }

        return $this->payments->quote($purpose, $payable, $plan);
    }

    /**
     * The wallet's balance — the most the member can spend from any wallet
     * they may use, in the purpose's pool (general when none) — and, with
     * an amount, whether it covers it. Without a member: no balance.
     *
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>
     */
    private function walletOption(array $option, ?PaymentPurpose $purpose, ?User $member, ?int $points): array
    {
        if ($member === null) {
            return $option + ['balance' => null];
        }

        $category = $purpose === null ? WalletTransactionCategory::General : $this->fulfillers->for($purpose)->walletCategory();

        $wallets = collect($this->wallets->spendOptions($member, $category))
            // A plan paid from a wallet goes to that wallet's owner; bought
            // from the app it is the member's own.
            ->when($purpose === PaymentPurpose::PlanPurchase, fn ($c) => $c->where('owner_type', OwnerType::User->value))
            ->map(fn (array $wallet) => [
                'owner_type' => $wallet['owner_type'],
                'owner_id' => $wallet['owner_id'],
                'owner_label' => $wallet['owner_label'],
                'usable_balance' => $wallet['usable_balance'],
                'sufficient' => $wallet['usable_balance'] >= (int) $points,
            ])
            ->values()
            ->all();

        $best = (int) max(array_column($wallets, 'usable_balance') ?: [0]);

        if ($best <= 0) {
            $option['available'] = false;
            $option['reason'] = __('api.payment.no_balance');
        } elseif ($points !== null && $best < $points) {
            $option['available'] = false;
            $option['reason'] = __('api.payment.insufficient_points', [
                'current' => $best,
                'required' => $points,
                'shortage' => $points - $best,
            ]);
        }

        return $option + ['balance' => $best, 'wallets' => $wallets];
    }
}
