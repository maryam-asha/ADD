<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Support\Facades\DB;

/**
 * Used by both the member's own extend request and reception acting on the
 * member's behalf — one service, two thin routes (see the design doc).
 * Locks the Space row (same pattern as BookingCreationService) so this
 * serializes correctly against both a concurrent new booking and a
 * concurrent second extension attempt on the same booking.
 */
class BookingExtensionService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly AmountCalculator $amounts,
        private readonly WalletService $wallets,
    ) {}

    public function extend(Booking $booking, int $additionalMinutes): void
    {
        DB::transaction(function () use ($booking, $additionalMinutes) {
            $lockedBooking = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedBooking->checked_in_at === null || $lockedBooking->checked_out_at !== null) {
                throw new ReceptionActionException('api.reception.not_checked_in');
            }

            $space = Space::query()->whereKey($lockedBooking->space_id)->lockForUpdate()->firstOrFail();

            $granularity = $space->slot_granularity_minutes ?? $this->settings->get('booking.slot_granularity_minutes', 30);
            $minDuration = (int) $this->settings->get('booking.min_duration_minutes', 60);

            if ($additionalMinutes < $minDuration || ($additionalMinutes - $minDuration) % $granularity !== 0) {
                throw new ReceptionActionException('api.booking.invalid_extension_duration');
            }

            $newEndAt = $lockedBooking->end_at->copy()->addMinutes($additionalMinutes);

            $conflict = Booking::query()
                ->where('space_id', $lockedBooking->space_id)
                ->whereKeyNot($lockedBooking->getKey())
                ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
                ->where('start_at', '<', $newEndAt)
                ->where('end_at', '>', $lockedBooking->end_at)
                ->orderBy('start_at')
                ->first();

            if ($conflict !== null) {
                throw new ReceptionActionException('api.booking.extension_conflict', 422, [
                    'latest_end_at' => $conflict->start_at->toIso8601String(),
                ]);
            }

            [$oldAmount] = $this->amounts->forRange($space, $lockedBooking->start_at, $lockedBooking->end_at);
            [$newAmount] = $this->amounts->forRange($space, $lockedBooking->start_at, $newEndAt);
            $difference = bcsub($newAmount, $oldAmount, 2);

            $paymentState = $lockedBooking->payment_state;

            if ($lockedBooking->payment_state === PaymentState::Paid && $lockedBooking->payment_source === PaymentSource::Wallet) {
                $wallet = $this->wallets->walletFor(OwnerType::User, $lockedBooking->user_id);
                $this->wallets->debit($wallet, $lockedBooking->user, WalletTransactionCategory::SpaceSpecific, $difference, "Booking #{$lockedBooking->id} extension");
            } else {
                $paymentState = PaymentState::Unpaid;
            }

            $lockedBooking->forceFill([
                'end_at' => $newEndAt,
                'payment_state' => $paymentState,
            ])->save();
        });
    }
}
