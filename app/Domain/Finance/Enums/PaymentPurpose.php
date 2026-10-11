<?php

namespace App\Domain\Finance\Enums;

/**
 * What the payment is for.
 *
 * `WalletTopUp` and `PlanPurchase` bring money in: the wallet is credited
 * with points (card money has to become points on the way in, exactly as
 * cash does at the reception till), and `PlanPurchase` additionally
 * activates the plan recorded on the payment.
 *
 * If that activation fails after the money has been taken, the points stay
 * in the member's wallet and the payment is flagged NeedsReview. The
 * charge is never silently swallowed and the bank is never called
 * automatically (docs/paymera-integration-plan.md, decision 3).
 *
 * `Booking`, `EventRegistration` and `CoworkingSession` pay for an
 * operation: the payment points at it (`payable`) and, when it is paid with
 * points, the wallet is debited rather than credited.
 *
 * `BookingExtension` pays the difference when a checked-in booking is
 * made longer. Its payable is the booking itself, which is already paid,
 * so it cannot share `Booking`'s "not already paid" check; refunds for a
 * booking return every payment made for it, whatever its purpose.
 */
enum PaymentPurpose: string
{
    case WalletTopUp = 'wallet_top_up';
    case PlanPurchase = 'plan_purchase';
    case Booking = 'booking';
    case EventRegistration = 'event_registration';
    case CoworkingSession = 'coworking_session';
    case BookingExtension = 'booking_extension';
}
