<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Events\CoworkingPaymentAtReception;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Membership\Enums\OwnerType;
use App\Services\Realtime\RealtimeBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a booking still owes once it closes — an extension, an overstay,
 * or all of it for one booked to be paid at the desk — is paid by the
 * method the member booked with (`chosen_payment_method`), so they are not
 * asked to choose again (product owner, 2026-11-03):
 *
 * - `wallet`: the member's own points, at once — never a company's they
 *   can spend, as for an extension (BookingExtensionService). Short, it
 *   stays owed and is paid from the app or at the desk; the booking closes
 *   either way.
 * - `cash`: the desk is told to collect it.
 * - a card, or no method kept: the member pays from the app.
 *
 * What is owed is the booking's price less what was paid for it
 * (Booking::pointsPaid) — the one figure anything added to a booking later
 * must add to.
 */
class ClosedBookingSettlement
{
    public function __construct(private readonly RealtimeBroadcaster $realtime) {}

    /** Called inside the closure's transaction, once the closed row is saved. */
    public function settle(Booking $booking): void
    {
        if ($booking->payment_state === PaymentState::Paid
            || max(0, (int) $booking->price_points - $booking->pointsPaid()) === 0) {
            return;
        }

        match ($booking->chosen_payment_method) {
            WalletMethod::KEY => $this->payFromWallet($booking),
            'cash' => $this->realtime->send(new CoworkingPaymentAtReception($booking)),
            default => null,
        };
    }

    private function payFromWallet(Booking $booking): void
    {
        try {
            // A savepoint: a wallet that falls short undoes only the payment,
            // never the closure around it.
            DB::transaction(fn () => app(PaymentService::class)->start(PaymentPurpose::Booking, $booking->user, WalletMethod::KEY, [
                'payable' => $booking,
                'wallet_owner_type' => OwnerType::User,
                'wallet_owner_id' => $booking->user_id,
            ]));
        } catch (Throwable $e) {
            Log::info('[Bookings] a closed booking could not be paid from the wallet', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        $booking->refresh();
    }
}
