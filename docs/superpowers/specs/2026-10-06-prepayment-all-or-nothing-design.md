# Design: Each Operation Pays Through Its Own Endpoint; Prepaid Is All-or-Nothing

**Status:** draft — awaiting review
**Owner:** Maryam Asha
**Date:** 2026-10-06
**Depends on:** `feat/add-point-system` (unified payments, `PaymentMethodRegistry`,
`PaymentGateway`, the payment fulfillers, `CouponApplication`).

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
behave the same way.

It also breaks the app's retry. When a card fails, the app returns to the
same confirm screen. Confirming again with another method sends the same
`POST member/bookings` — and is refused, because the member's own unpaid
booking holds the slot. Reproduced on this branch:

```
1st (paymera):           201  booking_id=1, payment=null, payment_error="Card payment is temporarily unavailable..."
2nd (cash, same slot):   422  "This space is not available for the requested time."
```

Paying is also split across two kinds of endpoint today: each operation
takes a `payment_method` when it is made (bookings, event registrations,
plans, coworking end-requests), **and** a generic `POST member/payments`
pays for things afterwards (top-ups, "pay now", a coworking session by
card, a registration approved later). Two ways to pay the same booking is
the duplication this design removes.

## Decisions (the user's)

1. **A prepaid method is all-or-nothing.** Choosing one either ends with
   the operation paid, or the operation is undone. No operation is left
   holding a slot or seat unpaid because a card failed.
2. **Scope of the all-or-nothing rule:** bookings and event registrations
   — the operations that hold a limited place (a slot, a seat) before a
   card payment completes. Plan purchases are already atomic (no
   membership exists before the card is accepted); coworking sessions are
   post-paid (the session already happened).
3. **After a failure the member picks another method on the same screen**
   — a post-paid one (cash at reception) or wallet points.
4. **The app sends the same request again, changing only
   `payment_method`.** No `booking_id`: the server recognises the
   re-submission from the values themselves.
5. **No choice in time → the booking is cancelled** (a registration:
   rejected).
6. **The window is 15 minutes** from creation, configurable, never past
   the booking's start.
7. **`POST member/payments` is removed.** Each operation pays through its
   own endpoint; the shared payment code is called by purpose, not
   repeated. Removed outright, released together with the app update (the
   app is still in development).
8. **Paying later from the app stays** — through the operation's own
   endpoint (a cash booking later paid in the app, a registration approved
   later, a coworking session paid by card after it closes).
9. **A member tops up by a prepaid method only.** Cash top-ups are
   reception's (`admin/reception/wallet-top-ups`, unchanged).
10. **Not handled** (accepted as they are today): a plan whose branch
    fills up while its card payment is open (activation fails after the
    money is taken → points stay in the wallet, `needs_review`), and a
    coupon held by a card payment left pending until it settles.

## Definitions

- **Prepaid method** — any method whose driver implements `PaymentGateway`,
  i.e. any key in `PaymentMethodRegistry::gatewayKeys()`. Today: `paymera`.
  A gateway added to `config/payments.php` later follows every rule here
  with no code change.
- **Awaiting prepayment** — a booking or registration that is unpaid and
  has a `payment_due_at` that has not passed.
- **Re-submission** — the member sending an operation's create request
  again for a record they already hold (same values), with a
  `payment_method`.

## Endpoint map

| endpoint | change |
|---|---|
| `POST member/bookings` | recognises a re-submission (below); `data.payment_due_at` in the response |
| `POST member/events/{id}/register` | recognises a re-submission instead of answering `already_registered` for an unpaid registration; `payment_due_at` in the result |
| `POST member/memberships` | unchanged (already pays by any method) |
| `POST member/wallet/top-ups` | **new** — `{amount, payment_method?}`; prepaid methods only (default: the first enabled gateway); answers `payment` + `redirect_url` |
| `POST member/coworking-sessions/{type}/{id}/pay` | **new** — `{payment_method, wallet_owner_type?, wallet_owner_id?, coupon_code?}` for the member's own closed, unpaid session |
| `POST member/bookings/{id}/cancel` | allowed inside the cancellation window when awaiting prepayment |
| `GET member/payment-methods` | unchanged contract; a top-up now lists prepaid methods only |
| `GET member/payments`, `GET member/payments/{id}` | **kept** — the history, and the status the app polls after the WebView |
| `POST member/payments` | **removed** |
| `GET member/payment-purposes` | **removed** (built earlier on this branch as a catalog for `POST member/payments`, never released) |

## Shared code: one service, called by purpose

Every operation that takes a `payment_method` ends the same way: pay it
now from the wallet, leave it for the desk, or open a gateway payment and
answer with where to pay. Today the card part is copied three times
(`BookingController::startCardPayment`, `EventController::startCardPayment`,
`MembershipController::startCard`) and once more inside
`PaymentController::store`.

One service replaces all four — working name `OperationCheckout`:

```php
pay(PaymentPurpose $purpose, User $member, string $method, array $context): CheckoutResult
// $context: payable | plan | amount_minor | wallet_owner_* | coupon | lang
// CheckoutResult: payment, redirect_url, payment_error, next (done | redirect | pay_at_reception)
```

It sits on the existing `PaymentService::start()` / `StartPayment` — no
payment logic is rewritten, only gathered. Bookings, registrations,
memberships, top-ups and coworking sessions all call it; gateway failures
(`RespondsToGatewayFailures`) are mapped in one place.

## Data model

One nullable column, on two tables:

| table | column | meaning |
|---|---|---|
| `bookings` | `payment_due_at` (timestamp, nullable, indexed) | set → awaiting prepayment until then; null → no deadline (every booking today, and every wallet/cash booking) |
| `event_attendees` | `payment_due_at` (same) | same, for a registration |

No new status. An awaiting booking keeps `confirmed` (or `pending` when the
space requires approval), per the project's minimal-status rule; the app
shows "awaiting payment" from the payment block it already returns plus
`payment_due_at`.

`payment_due_at` is **not cleared on cancellation**: a `cancelled` booking
that still has one was cancelled because its prepayment did not complete —
the reason is derived, like `Booking::lapsedUnreviewed()`, with no reason
column.

`bookings.payment_method` is **not** used for the member's choice: it
records how a booking was settled and is read by the cash register, so
writing `cash` there before money arrives would count as cash received.
The choice is stored by state: paid (wallet/card), or unpaid with no
deadline (pay at reception).

Config: `config/payments.php` → `'prepayment_window_minutes' =>
(int) env('PAYMENTS_PREPAYMENT_WINDOW_MINUTES', 15)`.

## Lifecycle (bookings; registrations alike)

| event | effect |
|---|---|
| created with a prepaid method | created, holds its slot; `payment_due_at = min(now + window, start_at)`; the card opens |
| card accepted | the fulfiller marks it paid and clears `payment_due_at` |
| card failed / canceled / expired, or never opened | nothing changes; it waits within its window |
| re-submitted with `wallet` | paid now; `payment_due_at = null` |
| re-submitted with `cash` | stays unpaid, `payment_due_at = null` — an ordinary pay-at-reception booking |
| re-submitted with a prepaid method | a new card payment opens for the same booking; an awaiting booking keeps its window (**not** extended). An ordinary unpaid booking (cash chosen earlier) paid later by card gets **no** window: its slot was held legitimately, so a failed card leaves it as it was — unpaid, for the desk |
| member cancels | cancelled at once, even inside the cancellation window, when awaiting |
| re-submitted for an **overlapping but different** window | an awaiting booking is superseded (cancelled) and the new one is made |
| checked in, or settled at reception | `payment_due_at = null` — reception now collects |
| window passes | last gateway check of any pending card payment for it: accepted → paid, kept; otherwise cancelled, slot freed, discount/coupon use given back, member notified |

## Recognising a re-submission

### `POST member/bookings`

**First step of the request — before every creation rule** (start in the
past, booking window and `min_notice`, business hours, granularity,
duration, availability). A booking re-submitted 15 minutes later must not
be refused as "too soon" for a slot it already holds.

1. Look for the member's own booking with exactly this `space_id`,
   `start_at`, `end_at`, in a payable status (`pending`, `confirmed`,
   `completed`), re-read under the space lock.
2. **Found and unpaid** → no booking is made. No availability, pricing or
   recording runs: the slot is held and the price was fixed when it was
   made. The new `payment_method` is applied to it through
   `OperationCheckout` (lifecycle table above), and it is returned in the
   **same response shape and the same 201** as a create — the app's code
   does not change. This covers both the retry after a failed card
   (awaiting prepayment) and paying later (an ordinary unpaid booking,
   including one owing only an extension).
3. **Found and already paid** → 422 `api.payment.already_paid`.
4. **Not found**, but the member has **awaiting** bookings on the space
   that overlap the window (they went back and changed the time, or a
   window passed before the scheduler ran) → those are cancelled as
   superseded, then the request continues as a normal create. An ordinary
   unpaid booking is never superseded.

Two bookings can never hold the same slot of the same space, so a match is
always exactly one booking.

`coupon_code` on a re-submission: the same code the booking already
carries is ignored (the app re-sends what it sent the first time); a code
on a booking that has none is applied through `CouponApplication`, as pay
now does today; a different code than the one applied → 422
`already_applied`.

`POST member/bookings/batch` is unaffected — it already refuses `paymera`.

### `POST member/events/{id}/register`

Recognition also runs **first** — before the registration window, the
full flag and capacity, which must not stop paying for a seat already held
— and, in the request, before the registration-form rules: a re-submission
does not have to send the form again.

- the member's seat-holding registration exists, **approved and unpaid** →
  the new `payment_method` is applied to it (same table);
- **pending approval** → the stored choice (`event_attendees.payment_method`)
  is updated; nothing is charged until approval, as today;
- **paid** → `already_registered`, as today.

Only a registration approved on the spot opens its card in the same
request, so only those get a deadline. One approved later is paid later by
re-submission, with no window.

On expiry a registration becomes `rejected` with a reason (precedent:
`events:expire-pending-registrations`), which frees the seat.

### Coworking sessions

`POST member/coworking-sessions/{type}/{id}/pay` replaces "a card is paid
by the member in the app" through `POST member/payments`
(`SessionEndService`). The session is the member's own (else 404), closed
and unpaid. `wallet` pays now, a prepaid method opens a card, `cash` leaves
it for the desk; `coupon_code` goes through `CouponApplication`. No window
and no cancellation: the session already happened, so a failed card leaves
it owed, as today.

### Wallet top-up

`POST member/wallet/top-ups` takes over `POST member/payments` with
`purpose: wallet_top_up`: the amount rules move from `StartPaymentRequest`
unchanged (plain decimal, fits the gateway currency, at least the minimum
top-up). Only prepaid methods: `CashMethod::supports()` excludes
`WalletTopUp`, so `GET member/payment-methods?purpose=wallet_top_up`
stops offering cash and its "go to reception" hint. Reception top-ups do
not use `supports()` (`WalletTopUpController` writes its own row) and are
unaffected.

## Expiry: the scheduled job

`payments:cancel-expired-prepayments`, **every minute**,
`withoutOverlapping()` (hourly is far too coarse for 15 minutes). For each
booking / registration past its `payment_due_at`, still unpaid:

1. Re-check every pending gateway payment for it through
   `SyncPaymentStatus` (the same last check `expire()` does). Accepted now
   → it is paid; skip it.
2. Otherwise, under a row lock: booking → `cancelled` through a system path
   of `BookingCancellationService` (no window check, nothing to refund,
   discount and coupon use given back as a member cancellation does);
   registration → `rejected` with the payment reason, seat and coupon use
   given back as a rejection does.
3. Notify the member (`NotificationLog`; templates
   `booking.prepayment_expired`, `event.registration_prepayment_expired`).
4. Door access: `access:revoke-grants-on-booking-cancellation` already
   revokes a cancelled booking's grants — and with the issuance change
   below, an awaiting booking never had one.

## Edge cases

| case | handling |
|---|---|
| card still `pending` at the deadline | last gateway check first; still pending → cancel; the payment keeps being reconciled |
| card accepted after the booking was cancelled, or after it was paid another way | existing behaviour: credited as points to the wallet, payment `needs_review` (`PaymentService::spendGatewayCredit`); no automatic bank reversal (Paymera decision 3) — an admin can reverse it. Paymera cannot void a pending payment |
| door access | `access:issue-grants-on-cancellation-window-close` **skips** bookings with `payment_due_at` set (today it would issue a code on the day for a booking whose card has not gone through) |
| space requires approval | the card still opens at creation; the operations "pending approval" notice is sent **when the deadline clears**, not at creation, so staff never review a booking that may vanish in 15 minutes |
| member arrives while awaiting | check-in or reception settlement clears `payment_due_at` |

## Testing

Feature tests, written first:

- **Bookings:** a prepaid creation sets `payment_due_at` (capped at
  `start_at`); card accepted clears it; re-submission with `wallet` /
  `cash` / `paymera` (201, same id, no second booking, price unchanged,
  coupon not recorded twice); re-submission near the start is not refused
  as too soon; paying later re-submits an ordinary unpaid booking, and one
  owing only an extension; a paid one → `already_paid`; an overlapping
  different window supersedes an awaiting booking only; the reproduced 422
  now succeeds.
- **Expiry job:** cancels an expired awaiting booking (slot free,
  discount/coupon use given back, member notified); keeps one whose card
  turns out accepted; ignores wallet/cash and not-yet-expired ones.
- **Late card success** after cancellation: points in the wallet,
  `needs_review`, booking stays cancelled.
- **Access grants**, **approval notice**, **cancel inside the window**,
  **reception check-in/settlement** — as in the table above.
- **Events:** the same set for registrations, plus a re-submission without
  the form; a pending one updates the stored choice.
- **Coworking pay** and **wallet top-ups:** the cases their
  `POST member/payments` tests cover today.
- **Removal:** `POST member/payments` and `GET member/payment-purposes`
  are 404/405; `GET member/payments` and `GET member/payments/{id}` still
  work.

Every existing test that calls `POST member/payments` (about twenty files:
`PaymeraPaymentTest`, `MemberWalletTest`, `PayNowTest`,
`EventPaymentTest`, `BookingExtensionOwedTest`, `CouponApplicationTest`,
...) moves to the operation's endpoint **keeping its assertion** — the
behaviour each one pins is preserved, only the door changes.

## Documentation

- `docs/decisions/prepayment-all-or-nothing.md` — new: the reversal of "a
  failed card leaves it for the desk", the removal of `POST member/payments`,
  and why.
- `docs/decisions/unified-payments.md` — the methods table and the
  "anything unpaid (`POST member/payments`)" row point to the new decision.
- `docs/paymera-mobile-api.md` — §2 (top-up through
  `POST member/wallet/top-ups`), §11 (paying by re-submission,
  `payment_due_at`, the cancellation reason, coworking pay).
- Postman — remove Start a Payment and Get Payment Purposes; add Top Up
  Wallet and Pay Coworking Session; update Create Booking and Register for
  Event.

## Out of scope

- Multi-date bookings (`/batch`) — already refuse a prepaid method.
- The two plan edge cases in decision 10.
- Automatically reversing a late card success at the bank.
- Voiding a pending Paymera payment — the gateway API cannot.
