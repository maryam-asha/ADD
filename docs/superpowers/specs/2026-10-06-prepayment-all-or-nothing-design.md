# Design: Prepaid Methods Are All-or-Nothing

**Status:** draft — awaiting review
**Owner:** Maryam Asha
**Date:** 2026-10-06
**Depends on:** `feat/add-point-system` (unified payments, `PaymentMethodRegistry`,
`PaymentGateway`, booking/event fulfillers).

## Context

A member confirms a booking on the app's "Confirm Your Booking" screen and
picks a payment method: wallet points, cash at reception, or Paymera (card).
With Paymera, `POST member/bookings` creates the booking **unpaid**, then
opens the card payment (`BookingController::startCardPayment()`). The
current rule, written into `BookingPaymentRouter::choose()`, is:

> A gateway pays after the booking exists: it holds the slot unpaid
> meanwhile, and a failed card leaves it for the desk.

So a failed, abandoned or expired card leaves a booking that holds its slot
for free until it is cancelled or ends as a no-show. Event registrations
behave the same way (seat confirmed unpaid, then the card opens).

It also breaks the app's retry. When a card fails, the app returns to the
same confirm screen. Confirming again with another method sends the same
`POST member/bookings` — and is refused, because the member's own unpaid
booking holds the slot. Reproduced on this branch:

```
1st (paymera):           201  booking_id=1, payment=null, payment_error="Card payment is temporarily unavailable..."
2nd (cash, same slot):   422  "This space is not available for the requested time."
```

## Decisions (the user's)

1. **A prepaid method is all-or-nothing.** Choosing one either ends with
   the operation paid, or the operation is undone. No operation is left
   holding a slot or seat unpaid because a card failed.
2. **Scope:** bookings and event registrations — the two operations that
   hold a limited place (a slot, a seat) before a card payment completes.
   Plan purchases and wallet top-ups are already atomic (nothing is created
   before the card is accepted). Coworking sessions are post-paid.
3. **After a failure the member picks another method on the same screen**:
   a post-paid one (cash at reception) or wallet points. The choice is
   stored on the same booking.
4. **The app sends the same request again, changing only
   `payment_method`.** No `booking_id`: the server recognises the retry
   from the values themselves.
5. **No choice in time → the booking is cancelled.**
6. **The window is 15 minutes** from creation, configurable, never past
   the booking's start.

## Definitions

- **Prepaid method** — any method whose driver implements `PaymentGateway`,
  i.e. any key in `PaymentMethodRegistry::gatewayKeys()`. Today: `paymera`.
  A gateway added to `config/payments.php` later follows this rule with no
  code change.
- **Awaiting prepayment** — a booking (or event registration) that is
  unpaid and has a `payment_due_at` that has not passed.

## Data model

One nullable column, on two tables:

| table | column | meaning |
|---|---|---|
| `bookings` | `payment_due_at` (timestamp, nullable, indexed) | set → awaiting prepayment until then; null → no deadline (every booking today, and every wallet/cash booking) |
| `event_attendees` | `payment_due_at` (same) | same, for a registration |

No new status. A booking awaiting prepayment keeps `confirmed` (or
`pending` when the space requires approval), in line with the project's
minimal-status rule; the app shows "awaiting payment" from the payment
block it already returns (`awaiting_payment`) plus the new
`payment_due_at`.

`payment_due_at` is **not cleared on cancellation**: a `cancelled` booking
that still has one was cancelled because its prepayment did not complete.
That is the cancellation reason, derived the same way
`Booking::lapsedUnreviewed()` derives a lapse — no reason column.

`bookings.payment_method` is **not** used to store the member's choice: it
records how a booking was settled and is read by the cash register, so
writing `cash` there before money arrives would count as cash received.
The choice is stored by state instead: paid (wallet/card), or unpaid with
no deadline (pay at reception).

Config: `config/payments.php` → `'prepayment_window_minutes' =>
(int) env('PAYMENTS_PREPAYMENT_WINDOW_MINUTES', 15)`.

## Lifecycle

| event | effect |
|---|---|
| created with a prepaid method | the booking is created and holds its slot; `payment_due_at = min(now + window, start_at)`; the card payment opens (unchanged) |
| card accepted | the fulfiller marks it paid and clears `payment_due_at` |
| card failed / canceled / expired, or never opened | nothing changes; the booking waits within its window |
| **retry** with `wallet` | paid now; `payment_due_at = null` |
| **retry** with `cash` | stays unpaid, `payment_due_at = null` — an ordinary pay-at-reception booking |
| **retry** with a prepaid method | a new card payment opens for the same booking; the window is **not** extended |
| member cancels (`POST bookings/{id}/cancel`) | cancelled at once, even inside the cancellation window (nothing was paid) |
| member re-confirms an **overlapping but different** window | the old awaiting booking is superseded (cancelled), the new one is made |
| checked in, or settled at reception | `payment_due_at = null` — reception now collects, like any unpaid booking |
| window passes | one last gateway check of any pending card payment for it: accepted → paid, kept; otherwise cancelled, slot freed, coupon/discount use given back, member notified |

## Recognising a retry (`POST member/bookings`)

Inside the existing locked transaction, **before** the availability checks:

1. Find the member's own bookings on the locked space that are awaiting
   prepayment and overlap the requested window.
2. **Exact match** (same `space_id`, `start_at`, `end_at`, deadline not
   passed) → this is a retry. Skip availability, pricing and coupon
   recording entirely — the slot is already held and the price was fixed
   at the first attempt. Apply the new `payment_method` to that booking
   (table above) and return it in the **same response shape and the same
   201** as a create, so the app's code is unchanged. `coupon_code` is
   ignored on a retry.
3. **Overlapping but not an exact live match** (the member went back and
   changed the time, or the deadline has passed and the scheduler has not
   run yet) → cancel those bookings as superseded, then continue as a
   normal create.

Why this is unambiguous: two bookings can never hold the same slot of the
same space, so an exact match is always exactly one booking.

`POST member/bookings/batch` is unaffected — it already refuses `paymera`.

## Event registrations

`POST member/events/{id}/register` today refuses a second registration
(`api.event.already_registered`). New: if the member's existing
registration for that event is awaiting prepayment, the request is a retry
— the new `payment_method` is applied to it (same table), instead of the
refusal. On expiry the registration becomes `rejected` with a reason
(precedent: `events:expire-pending-registrations` / `api.event.pending_expired`),
which frees the seat.

Only a registration approved on the spot opens its card in the same
request, so only those get a deadline. A registration that needs approval
is unchanged: no card opens at registration, and the member pays after
approval as today.

## One retry path

The switch of method on an awaiting operation lives in **one service**,
called from three places so they cannot drift:

- `POST member/bookings` (retry recognised as above),
- `POST member/events/{id}/register` (retry recognised as above),
- `POST member/payments` with `payable_id` ("pay now") on an awaiting
  booking or registration.

## Expiry: the scheduled job

`payments:cancel-expired-prepayments`, **every minute**,
`withoutOverlapping()` (an hourly job is far too coarse for a 15-minute
window). For each booking / registration past its `payment_due_at`, still
unpaid:

1. Re-check every pending gateway payment for it through
   `SyncPaymentStatus` (the same last check `expire()` does). If one is now
   accepted, it is paid; skip it.
2. Otherwise, under a row lock: booking → `cancelled` through a system
   path of `BookingCancellationService` (no cancellation-window check,
   nothing to refund, and the plan-discount and coupon use given back
   exactly as a member cancellation gives them back today); registration →
   `rejected` with the payment reason, giving its seat and coupon use back
   as a rejection does today.
3. Notify the member (`NotificationLog`, template keys
   `booking.prepayment_expired` / `event.registration_prepayment_expired`).
4. Door access needs nothing new: `access:revoke-grants-on-booking-cancellation`
   (every five minutes) already revokes grants of cancelled bookings — and
   with the issuance change below, an awaiting booking never had one.

## Edge cases

| case | handling |
|---|---|
| card still `pending` at the deadline (member on the OTP page) | last gateway check first; if still pending, cancel; the payment keeps being reconciled |
| card accepted **after** the booking was cancelled, or after it was paid another way | existing behaviour: the money is credited as points to the member's wallet and the payment is marked `needs_review` (`PaymentService::spendGatewayCredit`). No automatic bank reversal (Paymera decision 3); an admin can reverse it from the dashboard. Paymera cannot void a pending payment, only reverse an accepted one |
| door access | `access:issue-grants-on-cancellation-window-close` must **skip** bookings with `payment_due_at` set — today it issues a code for any `confirmed` booking on its day, including one whose card has not gone through |
| space requires approval | the card still opens at creation; the operations "pending approval" notification is sent **when the deadline clears** (paid, or switched to cash/wallet), not at creation, so staff never review a booking that may vanish in 15 minutes. Rejection after payment refunds to the wallet (unchanged) |
| member arrives at reception while awaiting | check-in or reception settlement clears `payment_due_at` |
| member cancels while awaiting | always allowed, inside the cancellation window too |

## API surface

| endpoint | change |
|---|---|
| `POST member/bookings` | retry recognition (above); `data.payment_due_at` in the response |
| `POST member/events/{id}/register` | retry recognition instead of `already_registered` for an awaiting registration; `payment_due_at` in the result |
| `POST member/payments` | on an awaiting booking/registration, uses the shared retry service (clears the deadline for wallet/cash) |
| `POST member/bookings/{id}/cancel` | allowed inside the cancellation window when awaiting prepayment |
| `GET member/bookings`, `GET member/bookings/{id}` | `payment_due_at`; a cancelled booking that still has one shows the reason "payment not completed" |

New translation keys (`lang/{en,ar}/api.php`): the derived cancellation
reason, and the two notification templates.

## Testing

Feature tests, written first:

- **Bookings:** paymera creation sets `payment_due_at` (capped at
  `start_at`); card accepted clears it; retry with `wallet` / `cash` /
  `paymera` on the same values (201, same booking id, no second booking,
  coupon not recorded twice, price unchanged); overlapping different window
  supersedes the old booking; the reproduced 422 above now succeeds.
- **Expiry job:** cancels an expired awaiting booking (slot free again,
  discount/coupon use voided, member notified); keeps one whose pending card
  turns out accepted on the last check; leaves wallet/cash bookings and
  not-yet-expired ones alone.
- **Late card success** after cancellation: points in the wallet, payment
  `needs_review`, booking stays cancelled.
- **Access grants:** not issued while awaiting; issued once cleared.
- **Approval:** operations notified on clearing, not on creation.
- **Cancel** inside the window succeeds while awaiting.
- **Reception:** check-in and settlement clear the deadline.
- **Events:** the same set for registrations (retry instead of
  `already_registered`, expiry → `rejected` with reason, seat freed).
- **"Pay now"** (`POST member/payments`) on an awaiting booking behaves
  exactly like the retry.

## Documentation

- `docs/decisions/prepayment-all-or-nothing.md` — new decision doc: the
  reversal of "a failed card leaves it for the desk", and why.
- `docs/decisions/unified-payments.md` — the methods table (booking and
  event rows) and a pointer to the new decision.
- `docs/paymera-mobile-api.md` §11 — the app flow: retry by re-confirming
  with another `payment_method`, `payment_due_at`, cancellation reason.
- Postman — Create Booking and Register for Event descriptions.

## Out of scope

- Multi-date bookings (`/batch`) — already refuse a prepaid method.
- Plan purchases and wallet top-ups — already atomic.
- Automatically reversing a late card success at the bank.
- Voiding a pending Paymera payment — the gateway API cannot.
