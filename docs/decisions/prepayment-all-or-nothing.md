# Prepaid methods are all-or-nothing; each operation pays through its own endpoint

**Status:** resolved 2026-10-06. **Owner:** Maryam Asha.

## Decision

- A booking or event registration is made first, then paid. To pay it
  another way — after a failed card, or later — the app sends the same
  create request again with the record's id (`booking_id`, `ticket_id`):
  nothing is created and no creation rule runs; only how it is paid
  changes.
- A closed coworking session is paid through
  `POST member/coworking-sessions/{type}/{id}/pay`; a member top-up through
  `POST member/wallet/top-ups`, by a prepaid method only (cash for points
  is reception's).
- `POST member/payments` is removed. `GET member/payments` and
  `GET member/payments/{id}` stay.
- Phase 2 (`docs/superpowers/plans/2026-10-06-prepayment-all-or-nothing.md`):
  a booking or registration made with a prepaid method is
  `awaiting_payment` for 15 minutes and is cancelled if it is not paid or
  switched to another method in time.

## Why

Two ways to pay the same booking (its own endpoint at creation, the
generic one afterwards) is duplication the app had to know about; the
confirm screen's retry with another method was refused because the
member's own unpaid booking held the slot; and a failed card left a
booking holding its slot for free. Reverses unified-payments' "a failed
card leaves it for the desk" (`BookingPaymentRouter::choose()`).

## What this changed in code

- `App\Domain\Finance\Payments\{PayableCheckout,CheckoutOutcome}`,
  `App\Http\Controllers\Concerns\AnswersPayments`
- `BookingController::store` (`booking_id`), `EventController::register`
  (`ticket_id`), `CoworkingSessionController::pay`, `TopUpController`
- `CashMethod::supports()` (no member top-up)
- removed: `PaymentController::store`, `StartPaymentRequest`
