# Unified payments: every operation, every method, one ledger

**Status:** resolved 2026-09-30. **Owner:** Maryam Asha.
**Type:** design doc for a new capability. Design:
`docs/superpowers/specs/2026-09-30-unified-payments-design.md` (Arabic,
user-approved); plan: `docs/superpowers/plans/2026-09-30-unified-payments.md`.
This file describes the code as shipped, including the rulings made while
implementing it.

## What this adds

Every purchasable operation — a plan, a booking, an event registration, a
wallet top-up, and what a closed coworking session owes — can be paid by
**wallet points** (with a sufficiency check), by **cash at reception**, or
through **Paymera**. A new method is one driver class and one config entry;
no operation changes.

The user's decisions the design records:

- A booking may be confirmed unpaid.
- An event registration may be confirmed unpaid, but not checked in until it
  is paid.
- A plan bought with cash is a pending hold; nothing is activated until
  reception takes the money, and the hold expires.
- Payment state is visible on every operation.
- Reception enters the amount received; any overpayment becomes points.
- Events and plans may be paid from the same wallets as bookings (personal,
  or a company the member belongs to), with an explicit choice when several
  apply.

## The model

**Points are the only ledger.** Money arriving by any method is first
credited as points to a wallet (`WalletService::creditGeneral()`, with the
audit columns `payment_method`, `payment_currency`, `payment_amount`,
`exchange_rate_to_base`, `point_rate_usd`, `performed_by_user_id`); the
operation is then paid by debiting points. A card plan purchase and a cash
booking settlement are both "a credit and a debit".

**One `payments` table** records every payment whatever the method
(`method`: a config key), whatever it pays for (`purpose` + a polymorphic
`payable` using the morph aliases `booking`, `walkin_session`,
`event_attendee`). Links: `wallet_transaction_id` (the credit),
`debit_transaction_id` (the spend), `refund_transaction_id` + `refunded_at`,
`settled_at` (the whole settlement is done — the idempotence key), and the
reception columns `collected_via`, `received_amount`, `received_currency`,
`received_by`, `received_at`. `expires_at` is a cash hold's deadline.

**Drivers** (`App\Domain\Finance\Payments\Methods`), resolved from
`config/payments.php` by `PaymentMethodRegistry`:

| key | class | pays for | `start()` |
|---|---|---|---|
| `wallet` | `WalletMethod` | everything but a top-up | picks the wallet, checks it covers the whole amount, settles now → `done` |
| `cash` | `CashMethod` | everything but a top-up and an extension | a plan: pending hold with `expires_at`; anything else: **no row** → `pay_at_reception` |
| `paymera` | `PaymeraGateway` | everything but an extension | freezes the card charge on the row, opens the hosted page → `redirect` |

**Fulfillers** (`App\Domain\Finance\Payments\Fulfillers`), one per purpose,
registered in `PaymentServiceProvider::FULFILLERS`: `pointsDue`,
`walletCategory`, `assertPayable`, `fulfil`.

| purpose | fulfiller | due | wallet pool | fulfil |
|---|---|---|---|---|
| `wallet_top_up` | `WalletTopUpFulfiller` | 0 | — | nothing |
| `plan_purchase` | `PlanPurchaseFulfiller` | `plans.price_points`, re-read | general | activate for the wallet's owner (`charge: false`, audited `charged: true`) |
| `booking` | `BookingFulfiller` | `bookings.price_points` | space-specific | mark paid, source wallet/cash/gateway |
| `coworking_session` | `WalkinSessionFulfiller` | `walkin_sessions.price_points` (written at closure) | space-specific | mark paid |
| `event_registration` | `EventRegistrationFulfiller` | `event_attendees.price_points` | general | `payment_state = paid` |
| `booking_extension` | `BookingExtensionFulfiller` | the difference, passed in | space-specific | nothing |

**`PaymentService`** is the one path:

- `start(purpose, payer, method, context)` checks the target, records what is
  due, and hands the row to the driver.
- `settle(payment, ?Funding)` credits any funding, spends what is due, fulfils
  and accepts, in one locked transaction; only a Pending (or Accepted,
  unsettled) payment can be settled.
- `fulfilAcceptedGatewayPayment()` is the card listener's body: the credit
  stands even if the spend fails (→ `NeedsReview`).
- `settleAtReception()` is the desk.
- `refund()` gives points back to the wallet that paid.
- `quote()` prices a target without writing.

## Methods by operation

| operation | wallet | cash | paymera |
|---|---|---|---|
| plan (`POST member/memberships`) | default; the owner's wallet (the company's for `company_id`) | hold, 48 h | the member's own plan only |
| booking (`POST member/bookings`) | pay now or 422 | unpaid | unpaid, then its card payment opens |
| multi-date booking (`/batch`) | one wallet for the total, one payment per booking | unpaid | refused (validation) |
| event registration | pay now, or at approval from the stored choice | confirmed unpaid | confirmed unpaid, then its card payment opens |
| anything unpaid (`POST member/payments`) | yes | `pay_at_reception` | yes |
| top-up | — | reception only (`wallet-top-ups`) | yes |

Reception: `POST admin/reception/payments/settle` settles any of the four with
the amount received; `GET admin/reception/outstanding?user_id=` lists
everything the member owes. The door: `POST admin/events/{event}/attendees/{attendee}/check-in`
refuses an unpaid paid-event registration (409).

The app asks `GET member/payment-methods?purpose=…` for the amount and each
enabled method with `available` / `reason`, and reads a `payment` block
(`PaymentStatePresenter`) on bookings, sessions, the session report and
event registrations.

## Adding a gateway

1. A class implementing `PaymentGateway` (`createPayment`, `fetchStatus`,
   `cancel`, `callbackRouteName`, `ledgerMethod`, plus the driver methods;
   `supports()` says which purposes it may pay for).
2. A line under `methods` in `config/payments.php`.

Nothing else: `payment-methods` lists it, `POST member/payments` accepts it,
the reconciler polls its pending payments, and its accepted payments are
credited and spent through `fulfilAcceptedGatewayPayment()`
(`PaymentMethodRegistryTest`, `MemberPaymentMethodsTest` register a fake one
through config alone). `enabled: false` stops new payments only; payments
already open on it still sync and reverse.

## Backward compatibility

- `POST member/payments` with no `method` is the card, with the same request
  and body; `next` is added.
- `/api/v1/paymera/callback/{token}` and `/trigger/{token}` are unchanged;
  `paymera:reconcile` keeps its name and schedule.
- `POST member/bookings` and `/batch` with no `payment_method` route exactly
  as before (the one wallet that covers it pays; several ask for a choice;
  short = unpaid), except R1 below; every debit now has a payment row.
- Event registration with no `payment_method` pays from the member's own
  wallet, with the same `points` validation error when short.
- `POST member/memberships` with no `payment_method` returns the membership
  (201) as before, with the same 422s.
- `POST reception/{bookings|walk-ins}/{id}/settle-payment` takes just
  `{payment_method}` and answers as before; it now goes through the ledger.
- The booking `payment` block keeps `state`/`source`/`label`; new keys are
  added beside them.

## Rulings

- **R1 `usable_balance` = max(category, general)** — what `debit()` can
  actually spend. The legacy router therefore now pays from general when the
  category pool is short but general covers it (before: unpaid).
- **A multi-date booking writes one payment and one debit per booking**, not
  one for the total; still all or nothing.
- **A card accepted after the target was paid another way** (at the desk
  while the member was on the card page) keeps its points in the wallet:
  `NeedsReview`, `review_reason = 'payable already settled — points kept in
  wallet'`. Nothing is lost, and a human decides.
- **Refunds are points, to the wallet that paid**, from the payment rows
  (booking plus extensions). A booking paid before payment rows existed keeps
  the old refund (recomputed price less discount, to the member's wallet).
- **Money paid for something is spent from general**, never from a plan
  allowance: the money bought general points.
- **The cash hold is 48 hours** (`PAYMENTS_CASH_PLAN_HOLD_HOURS`);
  `payments:expire-pending` (hourly) lets it lapse and never touches gateway
  payments.
- **Cash for a booking, session or registration writes no row**; the target's
  own unpaid state is the record until reception takes the money.
- **An extension is `booking_extension`**, a purpose of its own, because its
  payable is an already-paid booking.
- **The cash register counts money, not points**, in the base currency
  (other currencies at the rate stored on the row); the session has no
  currency of its own.
- **`settled_at`**, not `debit_transaction_id`, says a payment is done: a
  free purchase writes no debit.
- **Reception opens a member's wallet** when they never had one; the old
  settle endpoints never needed one.
- **A company plan cannot be bought by card from the app** yet: card money
  lands in the member's own wallet.

## Deploying

- Migrations `2026_10_03_100000` … `100200`: generalize `payments`, add
  `settled_at` (backfilled), `price_points` on bookings and walk-ins, the
  payment columns on `event_attendees`. Take a backup first.
- Run `php artisan queue:restart` right after the code swap: the payment lock
  key changed from `paymera:payment:{id}` to `payments:payment:{id}`, and an
  old worker would take the other one.
- Schedule is already declared in `routes/console.php`.

## Explicitly not done here

- The booking extension still flips a paid booking to unpaid when the
  personal wallet cannot cover the difference; settling it then charges the
  whole price again. A follow-up.
- Refunds for a cancelled paid event.
- Card refunds back to the card: refunds are always points.
- Café orders.
- A card purchase of a company plan.

## Guards and tests

- `EnumColumnsHaveBackedEnumCastsTest` registers `Payment` (`status`,
  `purpose`, `collected_via`) and `EventAttendee::payment_state`.
- `DomainLayerBoundaryTest` stays green: payment code for events lives in
  `App\Domain\Finance` and `App\Services\Experience`; `EventAttendee` uses
  only `App\Support\Enums\EventPaymentState`.
- Feature tests: `PaymentsTableTest`, `PaymentMethodRegistryTest`,
  `PaymentServiceTest`, `WalletMethodTest`, `CashMethodTest`, `PayNowTest`,
  `MemberPaymentMethodsTest`, `PaymentStateBlockTest`,
  `ReceptionSettlementTest`, `OutstandingTest`, `PaymentNotificationsTest`,
  `Booking/BookingPaymentMethodTest`, `Booking/BookingRefundToPayingWalletTest`,
  `Membership/PlanPurchasePaymentMethodTest`, `Experience/EventPaymentTest`,
  `Experience/EventCheckInTest`; unit `ReceivedMoneyConverterTest`.
