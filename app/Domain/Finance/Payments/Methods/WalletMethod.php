<?php

namespace App\Domain\Finance\Payments\Methods;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\ChoosesWallet;
use App\Domain\Finance\Payments\Exceptions\InsufficientPointsException;
use App\Domain\Finance\Payments\FulfillerRegistry;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Finance\Payments\PaymentStart;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;

/**
 * Paying with points the member (or a company they belong to) already holds.
 * Settled on the spot: there is nothing to wait for.
 *
 * Which wallet: the one named in the context, if the payer may spend from
 * it; otherwise the only one they have with any points; with several and
 * none named, the client is asked to choose (WalletChoiceRequiredException,
 * the same 422 booking has always returned). The chosen wallet must cover
 * the whole amount — this method never leaves a payment half-paid.
 */
class WalletMethod implements ChoosesWallet
{
    public const KEY = 'wallet';

    public function __construct(
        private readonly WalletService $wallets,
        private readonly FulfillerRegistry $fulfillers,
        private readonly PaymentService $payments,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('api.payment.methods.'.self::KEY);
    }

    /**
     * Anything but buying points with points.
     */
    public function supports(PaymentPurpose $purpose): bool
    {
        return $purpose !== PaymentPurpose::WalletTopUp;
    }

    /**
     * @param  array{wallet_owner_type?: OwnerType|string|null, wallet_owner_id?: int|null}  $context
     */
    public function start(Payment $payment, array $context = []): PaymentStart
    {
        $category = $this->fulfillers->for($payment->purpose)->walletCategory();

        $wallet = $this->resolveWallet(
            $payment->user,
            $category,
            (int) $payment->amount_points,
            $context['wallet_owner_type'] ?? null,
            isset($context['wallet_owner_id']) ? (int) $context['wallet_owner_id'] : null,
        );

        $payment->forceFill(['wallet_id' => $wallet->id])->save();

        $this->payments->settle($payment);

        return PaymentStart::done();
    }

    /**
     * The wallet `$payer` pays `$points` from, checked to cover all of it.
     *
     * @throws ReceptionActionException 'api.booking.wallet_not_owned' when the named wallet is not the payer's to spend
     * @throws WalletChoiceRequiredException when several wallets could pay and none was named
     * @throws InsufficientPointsException when the wallet cannot cover `$points`
     */
    public function resolveWallet(
        User $payer,
        WalletTransactionCategory $category,
        int $points,
        OwnerType|string|null $ownerType = null,
        ?int $ownerId = null,
    ): Wallet {
        $options = $this->wallets->spendOptions($payer, $category);
        $ownerType = is_string($ownerType) ? OwnerType::from($ownerType) : $ownerType;

        if ($ownerType !== null && $ownerId !== null) {
            $option = collect($options)->first(
                fn (array $o) => $o['owner_type'] === $ownerType->value && $o['owner_id'] === $ownerId,
            );

            if ($option === null) {
                // A wallet the payer may spend from but that is empty is not
                // listed; it is short, not someone else's.
                if ($this->maySpendFrom($payer, $ownerType, $ownerId)) {
                    throw InsufficientPointsException::for(0, $points);
                }

                throw new ReceptionActionException('api.booking.wallet_not_owned');
            }
        } elseif (count($options) > 1) {
            throw new WalletChoiceRequiredException($options);
        } elseif ($options === []) {
            throw InsufficientPointsException::for(0, $points);
        } else {
            $option = $options[0];
        }

        if ($option['usable_balance'] < $points) {
            throw InsufficientPointsException::for($option['usable_balance'], $points);
        }

        return Wallet::query()->findOrFail($option['wallet_id']);
    }

    private function maySpendFrom(User $payer, OwnerType $ownerType, int $ownerId): bool
    {
        return match ($ownerType) {
            OwnerType::User => $ownerId === $payer->id,
            OwnerType::Company => $payer->companies()->whereKey($ownerId)->exists(),
        };
    }
}
