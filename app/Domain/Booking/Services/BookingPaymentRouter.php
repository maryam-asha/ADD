<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Exceptions\InsufficientPointsException;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Services\WalletService;

/**
 * How a new booking is paid, for BookingCreationService and
 * BookingBatchCreationService alike: first which wallet pays (or none, and
 * the booking is left unpaid), then the payment itself, one per booking,
 * through PaymentService — so every debit has a payment row saying which
 * wallet paid for which booking.
 *
 * Deciding comes before the bookings exist (a refusal must stop them being
 * made); paying comes after (a payment names what it paid for).
 */
class BookingPaymentRouter
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WalletMethod $walletMethod,
        private readonly PaymentService $payments,
        private readonly PaymentMethodRegistry $methods,
    ) {}

    /**
     * The wallet that pays `$points`, as [owner type, owner id], or null to
     * leave the booking unpaid.
     *
     * - No method (the app before payment methods existed): exactly the old
     *   routing — the only wallet that can cover it pays, several ask for a
     *   choice, and anything short leaves the booking unpaid, silently.
     * - `wallet`: pay now or refuse — short is a 422 with the numbers.
     * - `cash`: unpaid, to be settled at the desk.
     *
     * @return array{0: OwnerType, 1: int}|null
     *
     * @throws ReceptionActionException
     * @throws WalletChoiceRequiredException
     * @throws InsufficientPointsException
     */
    public function choose(User $member, int $points, ?string $method, ?OwnerType $ownerType, ?int $ownerId): ?array
    {
        if ($points <= 0) {
            return null;
        }

        return match ($method) {
            null => $this->legacyChoice($member, $points, $ownerType, $ownerId),
            WalletMethod::KEY => $this->strictChoice($member, $points, $ownerType, $ownerId),
            'cash' => null,
            default => in_array($method, $this->methods->gatewayKeys(), true)
                // A gateway pays after the booking exists: it holds the slot
                // unpaid meanwhile, and a failed card leaves it for the desk.
                ? null
                : throw new ReceptionActionException('api.payment.method_not_supported'),
        };
    }

    /**
     * Pays `$booking` from `$wallet` and brings the model up to date.
     *
     * @param  array{0: OwnerType, 1: int}  $wallet
     */
    public function pay(Booking $booking, User $member, array $wallet): void
    {
        $this->payments->start(PaymentPurpose::Booking, $member, WalletMethod::KEY, [
            'payable' => $booking,
            'wallet_owner_type' => $wallet[0],
            'wallet_owner_id' => $wallet[1],
        ]);

        $booking->refresh();
    }

    /**
     * @return array{0: OwnerType, 1: int}|null
     */
    private function legacyChoice(User $member, int $points, ?OwnerType $ownerType, ?int $ownerId): ?array
    {
        $options = $this->wallets->spendOptions($member, WalletTransactionCategory::SpaceSpecific);

        if ($ownerType !== null && $ownerId !== null) {
            $matched = collect($options)->first(
                fn (array $option) => $option['owner_type'] === $ownerType->value && $option['owner_id'] === $ownerId
            );

            if ($matched === null) {
                throw new ReceptionActionException('api.booking.wallet_not_owned');
            }

            return $matched['usable_balance'] < $points ? null : [$ownerType, $ownerId];
        }

        if (count($options) > 1) {
            throw new WalletChoiceRequiredException($options);
        }

        if (count($options) === 1 && $options[0]['usable_balance'] >= $points) {
            return [OwnerType::from($options[0]['owner_type']), $options[0]['owner_id']];
        }

        return null;
    }

    /**
     * @return array{0: OwnerType, 1: int}
     */
    private function strictChoice(User $member, int $points, ?OwnerType $ownerType, ?int $ownerId): array
    {
        $wallet = $this->walletMethod->resolveWallet(
            $member,
            WalletTransactionCategory::SpaceSpecific,
            $points,
            $ownerType,
            $ownerId,
        );

        return [$wallet->owner_type, (int) $wallet->owner_id];
    }
}
