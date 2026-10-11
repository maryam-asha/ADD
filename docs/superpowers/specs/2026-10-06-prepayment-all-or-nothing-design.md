# Design: Each Operation Pays Through Its Own Endpoint; Prepaid Is All-or-Nothing

**Status:** draft — awaiting review
**Owner:** Maryam Asha
**Date:** 2026-10-06
**Depends on:** `feat/add-point-system` (unified payments, `PaymentMethodRegistry`,
`PaymentGateway`, the payment fulfillers, `CouponApplication`).

## Context

A member confirms a booking on the app's "Confirm Your Booking" screen and
picks a payment method: wallet points, cash at reception, or Paymera (card).
With Paymera, `POST member/bookings` creates the booking **confirmed and
unpaid**, then opens the card payment (`BookingController::startCardPayment()`).
The current rule, written into `BookingPaymentRouter::choose()`, is:

> A gateway pays after the booking exists: it holds the slot unpaid
> meanwhile, and a failed card leaves it for the desk.

So a failed, abandoned or expired card leaves a *confirmed* booking holding
its slot for free until it is cancelled or ends as a no-show — and,
because it is `confirmed`, it is treated as real everywhere: door codes,
QR unlock (`UnlockService`), reception lists. Event registrations behave
the same way.

It also breaks the app's retry. When a card fails, the app returns to the
same confirm screen; confirming again with another method sends the same
`POST member/bookings` and is refused, because the member's own unpaid
booking holds the slot. Reproduced on this branch:

```
1st (paymera):           201  booking_id=1, payment=null, payment_error="Card payment is temporarily unavailable..."
2nd (cash, same slot):   422  "This space is not available for the requested time."
```

Paying is also split across two kinds of endpoint: each operation takes a
`payment_method` when it is made, **and** a generic `POST member/payments`
pays for things afterwards (top-ups, "pay now", a coworking session by
card, a registration approved later). Two ways to pay the same booking is
the duplication this design removes.

## Decisions (the user's)

1. **A prepaid method is all-or-nothing.** Choosing one either ends with
   the operation paid, or the operation is undone. Nothing is left holding
   a slot or seat because a card failed.
2. **Scope of that rule:** bookings and event registrations — the
   operations that hold a limited place before a card completes. Plan
   purchases are already atomic (no membership exists before the card is
   accepted); coworking sessions are post-paid.
3. **After a failure the member picks another method on the same screen**
   — a post-paid one (cash at reception) or wallet points.
4. **The booking (or registration) is made first, then paid.** To change
   the method — after a failed card, or to pay later — the app sends **the
   same request again with the id of the booking it made**
   (`POST member/bookings` + `booking_id`; `POST member/events/{id}/register`
   + `ticket_id`). With an id, nothing is created: only the payment method
   of that booking changes. The app keeps the id from the first response.
5. **A booking awaiting its prepayment has its own stored status,
   `awaiting_payment`** — never `confirmed` before it is paid or switched
   to another method. Registrations alike.
6. **No choice in time → cancelled** (a registration: rejected).
7. **The window is 15 minutes** from creation, configurable, never past
   the booking's start.
8. **`POST member/payments` is removed** — removed outright, released with
   the app update (the app is still in development). Each operation pays
   through its own endpoint; the shared payment code is called by purpose.
9. **Paying later from the app stays** — a cash booking later paid in the
   app, or a registration approved later, through the same request with its
   id; a coworking session paid by card after it closes, through its own
   `pay` endpoint (there is no create request to send again).
10. **A member tops up by a prepaid method only.** Cash top-ups are
    reception's (`admin/reception/wallet-top-ups`, unchanged).
11. **Not handled** (accepted as today): a plan whose branch fills while its
    card is open (activation fails after the money is taken → points stay
    in the wallet, `needs_review`), and a coupon held by a card payment left
    pending until it settles.

## Definitions

- **Prepaid method** — any method whose driver implements `PaymentGateway`,
  i.e. any key in `PaymentMethodRegistry::gatewayKeys()`. Today: `paymera`.
  A gateway added to `config/payments.php` later follows every rule here
  with no code change.
- **Awaiting payment** — a booking or registration in status
  `awaiting_payment`; it holds its slot/seat until `payment_due_at`.

## Endpoint map

| endpoint | change |
|---|---|
| `POST member/bookings` | with a prepaid method: creates `awaiting_payment`; supersedes the member's own overlapping `awaiting_payment` bookings on that space (below). **New optional `booking_id`**: pay that booking with the new `payment_method` instead of creating one |
| `POST member/events/{id}/register` | with a prepaid method on a registration approved on the spot: creates `awaiting_payment`. **New optional `ticket_id`**: pay that registration instead of registering again |
| `POST member/coworking-sessions/{type}/{id}/pay` | **new** — `{payment_method, wallet_owner_type?, wallet_owner_id?, coupon_code?}` for the member's own closed, unpaid session; answers `201` with `POST member/payments`' shape, `{data: {payment, redirect_url, next}}` (`coupon` beside `data` when a code was sent) |
| `POST member/wallet/top-ups` | **new** — `{amount, payment_method?}`; prepaid methods only (default: the first enabled gateway); answers `201` with `POST member/payments`' shape, `{data: {payment, redirect_url, next}}` |
| `POST member/memberships` | unchanged (already pays by any method) |
| `POST member/bookings/{id}/cancel` | allowed at any time for `awaiting_payment` — the cancellation window does not apply to a hold |
| `GET member/payment-methods` | unchanged contract; a top-up now lists prepaid methods only |
| `GET member/payments`, `GET member/payments/{id}` | **kept** — the history, and the status the app polls after the WebView |
| `POST member/payments` | **removed** |
| `GET member/payment-purposes` | **removed** (built earlier on this branch as a catalog for `POST member/payments`; never released) |

A request with an id answers `200` with the same shape the create answers
with — `data` (the booking / registration), `payment`, `redirect_url`, plus
`next` (`done` | `redirect` | `pay_at_reception`) — so the app handles both
with one parser. The coworking `pay` and the top-up keep
`POST member/payments`' answer shape (`{data: {payment, redirect_url, next}}`,
`201`), since they create the payment rather than change a record.
Errors: 404 (not the member's), 409 `already_paid`, 422 `booking_not_payable`
(cancelled, rejected, no-show — including an `awaiting_payment` whose
window passed), 422 `booking_mismatch` (values sent that differ from the
booking), the wallet shortfall 422, the gateway 503s.

## Shared code: one service, called by purpose

Every operation that takes a `payment_method` ends the same way: pay now
from the wallet, leave it for the desk, or open a gateway payment and
answer with where to pay. Today the card part is copied three times
(`BookingController::startCardPayment`, `EventController::startCardPayment`,
`MembershipController::startCard`) and again inside `PaymentController::store`.

One service replaces all four — working name `OperationCheckout`:

```php
pay(PaymentPurpose $purpose, User $member, string $method, array $context): CheckoutResult
// $context: payable | plan | amount_minor | wallet_owner_* | coupon | lang
// CheckoutResult: payment, redirect_url, payment_error, next
```

It sits on the existing `PaymentService::start()` / `StartPayment` — no
payment logic is rewritten, only gathered. Every create, every request
sent again with an id, the coworking `pay` and the top-up call it; gateway
failures (`RespondsToGatewayFailures`) are mapped in one place.

## Data model

**A new status on each enum**, first in lifecycle order:

- `BookingStatus::AwaitingPayment = 'awaiting_payment'` — in `slotHolding()`
  (holds the slot), `payable()` and `isOpen()` (cancellable);
- `EventRegistrationStatus::AwaitingPayment = 'awaiting_payment'` — in
  `seatHolding()`; `isTerminal()` is updated so it counts as not terminal.

Because it is not `confirmed` / `approved`, everything that treats those as
real ignores it **by default** — 16 references to `BookingStatus::Confirmed`
across 12 files today, among them door codes, QR unlock (`UnlockService`),
reception lists and summary, elapsed-booking closing. None of them has to
learn about prepayment. Only the two holding lists and `payable()` do. The
app's badge is the stored status, with its label in both languages
(`api.booking.statuses.awaiting_payment`, the event equivalent).

`BookingStatus::expected()` — `pending` and `confirmed` — names what staff
see: the reception summary's expected arrivals and the admin member
profile's upcoming count read it instead of restating a status list, so a
hold is in neither until it is paid or switched.

**One nullable column on each table** — the deadline:

| table | column |
|---|---|
| `bookings` | `payment_due_at` (timestamp, nullable, indexed) |
| `event_attendees` | `payment_due_at` (same) |

Set with the status, **cleared when the hold is released** (the card is
accepted, it is paid from the wallet, or the member switches to cash) and
**kept when it is cancelled** — by the clock, by the member, or superseded.
So a booking (or registration) that still carries one was undone while it
was held: the reason is derived, like `Booking::lapsedUnreviewed()`, with no
reason column (`Booking::cancelledAwaitingPayment()` — the `status_note`
"payment not completed"). The reason it is cleared on release: a hold that
was paid and cancelled later must not read as one whose payment did not
complete.

`bookings.payment_method` is not used for the member's choice: it records
how a booking was settled and the cash register reads it.

Config: `config/payments.php` → `'prepayment_window_minutes' =>
(int) env('PAYMENTS_PREPAYMENT_WINDOW_MINUTES', 15)`.

## Lifecycle

**Booking** (a registration follows the same table, `approved` in place of
`confirmed`; it has no approval step after payment):

| event | status after | notes |
|---|---|---|
| created with a prepaid method | `awaiting_payment` | holds the slot; `payment_due_at = min(now + window, start_at)`; the card opens |
| card accepted | `confirmed` — or `pending` if the space requires approval | paid; `payment_due_at` is cleared; the operations "pending approval" notice is sent **now**, not at creation |
| card failed / canceled / expired, or never opened | `awaiting_payment` | waits within its window |
| sent again with `booking_id` + `wallet` | `confirmed` / `pending` | paid now |
| sent again with `booking_id` + `cash` | `confirmed` / `pending` | unpaid — an ordinary pay-at-reception booking |
| sent again with `booking_id` + a prepaid method | `awaiting_payment` | a new card for the same booking; the window is **not** extended |
| member cancels | `cancelled` | allowed at any time — nothing was paid, so the cancellation window does not apply |
| member creates an **overlapping** booking on the same space | the old one `cancelled` | superseded: the member went back and changed the time |
| window passes | `cancelled` | after a last gateway check (below) |

**Paying later** (sent again with the `booking_id` of a booking that is
`pending`, `confirmed` or `completed` and unpaid — a cash booking, one
owing only an extension):
`wallet` pays now; a prepaid method opens a card with **no** window and no
status change — its slot was held legitimately, so a failed card leaves it
as it was, unpaid for the desk; `cash` changes nothing (`next:
pay_at_reception`). Same for an `approved` unpaid registration.

**Registrations needing approval** are unchanged: `pending`, no card at
registration, the stored choice (`event_attendees.payment_method`) used at
approval, and paid later by sending the registration again with its
`ticket_id`.

## Sending the request again with an id

### `POST member/bookings` + `booking_id`

The **first step** of the request: with a `booking_id`, nothing is created
and **no creation rule runs** — not the start-in-the-past rule, the booking
window and `min_notice`, business hours, granularity, duration,
availability, pricing or coupon recording. The booking exists and holds
its slot; its price was fixed when it was made. So a request sent again
minutes before the start is never refused as "too soon".

1. The member's own booking (else 404), locked.
2. `space_id`, `start_at`, `end_at` are optional with an id; any that are
   sent must equal the booking's (else 422 `booking_mismatch` — a stale id
   in the app). The app may send its confirm-screen values unchanged.
3. Payable and unpaid (else `booking_not_payable` / `already_paid`).
4. The new `payment_method` goes through `OperationCheckout` (purpose
   `Booking`) and the status moves as in the lifecycle table.

`FormRequest` side: with `booking_id`, the creation fields are not
required.

### `POST member/events/{id}/register` + `ticket_id`

Same shape: the member's own seat-holding registration for that event
(else 404), first step, before the registration window, the full flag,
capacity **and the registration-form rules** — the form is not sent again.

- `awaiting_payment` or **approved and unpaid** → the new `payment_method`
  is applied (purpose `EventRegistration`);
- **pending approval** → the stored choice (`event_attendees.payment_method`)
  is updated; nothing is charged until approval, as today;
- **paid** → `already_paid`.

Without a `ticket_id`, registering again for an event the member already
holds a seat in still answers `already_registered`, as today.

### Coworking sessions: `POST member/coworking-sessions/{type}/{id}/pay`

Replaces "a card is paid by the member in the app" through
`POST member/payments` (`SessionEndService`). There is no create request to
send again, so it has its own endpoint. The member's own closed, unpaid
session (else 404). Purpose `Booking` or `CoworkingSession` by `{type}`.
No window and no cancellation: the session already happened, a failed card
leaves it owed.

### Coupons

`coupon_code` sent with an id (or to the coworking `pay`): applied through
`CouponApplication` when the record carries none — as pay now does today;
the code it already carries is ignored (the app may resend what it sent at
creation); a different one → 422 `already_applied`.

## Superseding on create

Inside `POST member/bookings`' locked transaction, **before** the
availability checks: the member's own `awaiting_payment` bookings on the
locked space that overlap the requested window are cancelled as
superseded, then creation continues. This is what lets a member go back,
change the time and confirm again without being refused by their own hold.
Only `awaiting_payment` is ever superseded — a real (`confirmed` /
`pending`) booking still makes the slot unavailable, as today.

`POST member/bookings/batch` is unaffected — it already refuses `paymera`.

## Wallet top-up

`POST member/wallet/top-ups` takes over `POST member/payments` with
`purpose: wallet_top_up`: the amount rules move from `StartPaymentRequest`
unchanged (plain decimal, fits the gateway currency, at least the minimum
top-up). Prepaid methods only: `CashMethod::supports()` excludes
`WalletTopUp`, so `GET member/payment-methods?purpose=wallet_top_up` stops
offering cash and its "go to reception" hint. Reception top-ups do not go
through `supports()` (`WalletTopUpController` writes its own row) and are
unaffected.

## Expiry: the scheduled job

`payments:cancel-expired-prepayments`, **every minute**,
`withoutOverlapping()`. For each `awaiting_payment` booking / registration
past its `payment_due_at`:

1. Re-check every pending gateway payment for it through
   `SyncPaymentStatus` (the same last check `expire()` does). Accepted now
   → it moves on as paid; skip it.
2. Otherwise, under a row lock: booking → `cancelled` through a system path
   of `BookingCancellationService` (no window check, nothing to refund,
   discount and coupon use given back as a member cancellation does);
   registration → `rejected` with the payment reason, seat and coupon use
   given back as a rejection does.
3. Notify the member (`NotificationLog`; templates
   `booking.prepayment_expired`, `event.registration_prepayment_expired`).

## Edge cases

| case | handling |
|---|---|
| card still `pending` at the deadline | last gateway check first; still pending → cancel; the payment keeps being reconciled |
| card accepted after the booking was cancelled, or after it was paid another way | existing behaviour: credited as points to the wallet, payment `needs_review` (`PaymentService::spendGatewayCredit`); no automatic bank reversal (Paymera decision 3) — an admin can reverse it. Paymera cannot void a pending payment |
| card accepted while `awaiting_payment` but after the window passed and before the job ran | the fulfiller pays it and moves it to `confirmed`/`pending` — paid in full, nothing to undo |
| member at reception while `awaiting_payment` | reception settles it (cash) → `confirmed`, paid; check-in of an `awaiting_payment` booking is refused until it is paid or switched |
| door access | nothing to change: grants and QR unlock already look at `confirmed` only |

## Testing

Feature tests, written first:

- **Statuses:** `awaiting_payment` holds the slot/seat, is cancellable and
  payable, and is invisible to door codes, QR unlock and reception lists.
- **Bookings:** a prepaid creation is `awaiting_payment` with
  `payment_due_at` capped at `start_at`; card accepted → `confirmed` (or
  `pending`, notice sent then); sent again with `booking_id` and `wallet` /
  `cash` / `paymera` (200, same booking, nothing created, price
  unchanged); the reproduced 422 is gone (create, card fails, send again
  with the id and cash → 200); sent again minutes before the start is not
  refused as too soon; values that differ from the booking →
  `booking_mismatch`; a member going back and creating an overlapping
  booking (no id) supersedes the hold, never a real booking; paying later
  a cash booking and one owing only an extension; `already_paid`; another
  member's booking → 404.
- **Expiry job:** cancels an expired hold (slot free, discount/coupon use
  given back, member notified); keeps one whose card turns out accepted;
  ignores everything else.
- **Late card success** after cancellation: points in the wallet,
  `needs_review`, booking stays cancelled.
- **Events:** the same set for registrations with `ticket_id` — without
  the form, after registration has closed; a pending one updates the
  stored choice; without an id, `already_registered` as today;
  approval-required registrations otherwise unchanged.
- **Coworking pay** and **wallet top-ups:** the cases their
  `POST member/payments` tests cover today; a cash top-up from the app is
  refused, a reception top-up still works.
- **Removal:** `POST member/payments` and `GET member/payment-purposes` are
  gone; `GET member/payments` and `GET member/payments/{id}` still work.

Every existing test that calls `POST member/payments` (about twenty files:
`PaymeraPaymentTest`, `MemberWalletTest`, `PayNowTest`, `EventPaymentTest`,
`BookingExtensionOwedTest`, `CouponApplicationTest`, ...) moves to the
operation's endpoint **keeping its assertion** — the behaviour each one
pins is preserved, only the door changes.

## Documentation

- `docs/decisions/prepayment-all-or-nothing.md` — new: the reversal of "a
  failed card leaves it for the desk", the `awaiting_payment` status, the
  removal of `POST member/payments`, and why.
- `docs/decisions/unified-payments.md` — the methods table and the
  "anything unpaid (`POST member/payments`)" row point to the new decision.
- `docs/paymera-mobile-api.md` — §2 (top-up through
  `POST member/wallet/top-ups`), §11 (the confirm screen's retry by sending
  the request again with `booking_id` / `ticket_id`, paying later the same
  way, the coworking `pay`, `awaiting_payment` and `payment_due_at`, the
  cancellation reason).
- Postman — remove Start a Payment and Get Payment Purposes; add Pay
  Coworking Session and Top Up Wallet; add the `booking_id` / `ticket_id`
  cases to Create Booking and Register for Event.

## Delivery

Two phases, each releasable on its own:

1. **One way to pay** — `OperationCheckout`; `booking_id` / `ticket_id`
   on create and register; the coworking `pay` and the top-up endpoints;
   tests moved off `POST member/payments`, which is then removed; cash
   refused for app top-ups.
2. **All-or-nothing** — `awaiting_payment`, `payment_due_at`, superseding,
   the expiry job, the approval-notice timing.

## Out of scope

- Multi-date bookings (`/batch`) — already refuse a prepaid method.
- The two plan edge cases in decision 11.
- Automatically reversing a late card success at the bank.
- Voiding a pending Paymera payment — the gateway API cannot.
