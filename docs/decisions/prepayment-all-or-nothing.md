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
  a booking or registration made with a prepaid method (any gateway key in
  `config/payments.php`; today Paymera) is a **hold**, stored as
  `awaiting_payment`, and is cancelled if it is not paid or switched to
  another method in time.
  - **The deadline.** `payment_due_at = min(now + window, start)`; the
    window is `PAYMENTS_PREPAYMENT_WINDOW_MINUTES`, 15 by default. A
    registration is held only when it is approved on the spot for a paid
    event that a coupon did not make free; a registration needing approval
    stays `pending`, with no card at registration, as before.
  - **A hold is not confirmed.** It holds the slot or seat, but door codes,
    QR unlock and the reception lists and counts ignore it
    (`BookingStatus::expected()` — pending and confirmed — is what staff
    see). Reception does not check a held booking in: 409
    `api.booking.check_in_awaiting_payment`, take the payment first; the
    kiosk and coworking scan answers `api.coworking.booking_awaiting_payment`.
  - **Release.** The hold ends as what the operation would have been
    without it when the card is accepted, when it is paid from the wallet,
    or when the member switches to cash by sending the request again with
    `booking_id` / `ticket_id`: a booking becomes `confirmed` (`pending` if
    the space needs approval — the operations "pending approval" notice is
    sent **then**, not at creation), a registration `approved`.
    `PrepaymentHold::release()` does it under a row lock, so a stale copy
    can never bring back what the expiry job cancelled, and it **clears
    `payment_due_at`**: a booking paid and cancelled later must not read as
    one whose payment did not complete.
  - **Past the deadline.** Sending the request again with the id of a hold
    whose deadline passed answers 422 `booking_not_payable` /
    `registration_not_payable`; a card already in flight that the bank
    accepts still pays it.
  - **The member may cancel a hold at any time**, even inside the
    cancellation window. Going back and creating a booking for another time
    on the same space cancels the member's own overlapping hold
    (superseded); a real booking still blocks.
  - **The job.** `payments:cancel-expired-prepayments` runs every minute.
    After one last gateway check — a card accepted meanwhile pays the hold
    instead — an expired booking hold becomes `cancelled` and an expired
    registration hold `rejected` (`api.event.prepayment_expired`); the
    member is told. A cancelled hold **keeps `payment_due_at`**, which is
    how its `status_note` reads "payment not completed"
    (`api.booking.status_notes.payment_not_completed`) with no reason
    column. A card accepted after the cancellation becomes points in the
    wallet and the payment `needs_review`, as for any late success.

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
- Phase 2: `BookingStatus::AwaitingPayment` and
  `EventRegistrationStatus::AwaitingPayment` (`BookingStatus::expected()`
  for what staff see); the migration adding `payment_due_at` to `bookings`
  and `event_attendees`; `App\Domain\Finance\Payments\PrepaymentHold`
  (`dueAt()`, `release()`); `App\Domain\Booking\Services\PendingBookingNotice`
  (the approval notice, sent on release); the
  `App\Console\Commands\CancelExpiredPrepayments` command, scheduled in
  `routes/console.php`; `BookingCancellationService::releaseUnpaidHold()`;
  `EventRegistrationService` (the held registration); the check-in refusal
  in reception and the coworking scan
