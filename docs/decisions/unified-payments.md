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
  apply. For plans the user later ruled narrower: a company wallet pays only
  for a company plan, bought by the company's admin (see Rulings, "Company
  wallets pay for a company plan only").

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
| `booking` | `BookingFulfiller` | `bookings.price_points` less what was already paid for it | space-specific | mark paid, source wallet/cash/gateway |
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
| anything unpaid (`POST member/payments`) | yes (a plan: the member's own only) | `pay_at_reception` (a plan: a hold on the member's own wallet) | yes, into the member's own wallet |
| top-up | — | reception only (`wallet-top-ups`) | yes |

Reception: `POST admin/reception/payments/settle` settles any of the four with
the amount received; `GET admin/reception/outstanding?user_id=` lists
everything the member owes. The door: `POST admin/events/{event}/attendees/{attendee}/check-in`
refuses an unpaid paid-event registration (409).

The app asks `GET member/payment-methods?purpose=…` for the amount and each
enabled method with `available` / `reason`, and reads a `payment` block
(`PaymentStatePresenter`) on bookings, sessions, the session report and
event registrations. On the member's event detail and "my events" it is the
presenter's block itself — `{state, method, amount_points, price,
price_point, paid_at, payment_id}`, `state` null for a free event, the whole
block null when the member has no registration (final review I3). The
earlier `price_points`/`price_label` keys there were dropped rather than
kept beside the new ones: they were added on this branch (052feee) and never
reached a released app.

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
- **What a booking owes is its price less what was paid for it** (final
  review C2): `price_points` minus every accepted, settled, unrefunded
  payment whose payable is the booking, whatever its purpose
  (`Booking::pointsPaid()`). `BookingFulfiller::pointsDue` (pay-now, the
  desk, `payment-methods`), `OutstandingItems` and the `payment` block all
  read it. Extending a paid booking grows `price_points` by exactly the
  extension: a booking paid from the wallet still pays it from the member's
  own wallet when that covers it (a `booking_extension` row); any other
  paid booking (a card, the desk), or one the wallet cannot cover, becomes
  unpaid owing only the extension — never the whole booking again. At
  check-out an unpaid booking is repriced for the time used, never below
  what was already paid, and is paid once its payments cover it.
- **A card start never leaves a payment pending** (final review I2): nothing
  due (0 points) is refused with 422 `api.payment.nothing_due` before any
  row is written — `BookingFulfiller::assertPayable` (every method, as the
  session and registration fulfillers already did) and `PaymentService::start`
  for any gateway. Once the row exists, `PaymeraGateway::start` prices the
  charge inside the guard that marks it `failed`, so a missing exchange rate
  fails the row like a call that never came back. `RespondsToGatewayFailures`
  maps that pricing failure to 503 `gateway_unavailable`; a booking or a
  registration made with a card therefore still answers 201 with
  `payment: null` and `payment_error` (also `nothing_due` for a free
  booking), and `POST member/payments` answers 503.
- **A money amount is a plain decimal** (final review M4). `amount` on
  reception settle, the reception top-up (and its preview) and the member's
  card top-up is `numeric` plus `regex:/^\d+(\.\d{1,6})?$/`: an exponent
  (`1e0`, `1.0E+2`) passes `numeric` but breaks bcmath, and is now a 422 on
  `amount`. Padding never arrives (TrimStrings makes ` 5` the same money as
  `5`). Behind the rules, `ReceivedMoneyConverter` accepts only a plain
  decimal and refuses anything else with `InvalidMoneyAmountException`,
  rendered 422 `api.payment.invalid_amount` — no spelling of an amount is a
  500. (It used to rewrite such spellings through a float.)
- **A plan discount is used up by every booking it priced**, paid at
  creation or later (final review I1): the `PlanDiscountRedemption` is
  written when the booking is made (single and batch), and voided on cancel
  or reject as before, so `usage_limit` cannot be bypassed by paying later.
  Check-out prices the time used at the booking's own discount, so the
  discounted price quoted at booking is what the desk collects after
  check-out (`docs/decisions/plan-discounts-design.md`).
- **The cash register counts money, not points**, in the base currency
  (other currencies at the rate stored on the row); the session has no
  currency of its own.
- **`settled_at`**, not `debit_transaction_id`, says a payment is done: a
  free purchase writes no debit.
- **Reception opens a member's wallet** when they never had one; the old
  settle endpoints never needed one.
- **A company plan cannot be bought by card from the app** yet: card money
  lands in the member's own wallet.
- **Whose wallet a payment touches** (final review C1; the user's ruling on
  the spec's "plans paid from the same wallets as bookings"). Only a method
  that picks the wallet itself and checks the payer may spend from it
  (`ChoosesWallet`: `WalletMethod` — the member's own, or a company they
  belong to) is given the `wallet_owner_type`/`wallet_owner_id` a client
  sends. Every other method opens on the payer's **personal** wallet: card
  and cash money always land in the payer's own wallet first, whatever the
  request named. The one exception is a wallet the caller has already
  authorized (`authorized_wallet` in the context, never built from request
  input): `POST member/memberships` with `company_id` opens a company's cash
  hold on the company's wallet after `CompanyPolicy::manageMembers`.
- **Company wallets pay for a company plan only, bought by the company's
  admin through `POST member/memberships` with `company_id`.** On
  `POST member/payments` a `plan_purchase` is always the member's own plan: a
  non-user `wallet_owner_type` is refused with 422
  `api.payment.company_plan_not_allowed`, for every method. The endpoint
  applies the plan rules memberships applies: a one-time package
  (`is_subscription = false`) is a 422 on `plan_id`, and a full branch is
  422 `api.membership.seat_pool_full` with `first_shortfall_at`, never a 500.
  Bookings, sessions and events keep the full wallet choice (personal or a
  company the member belongs to), checked by `WalletMethod`.

## Deploying

- Migrations `2026_10_03_100000` … `100200`: generalize `payments`, add
  `settled_at` (backfilled), `price_points` on bookings and walk-ins, the
  payment columns on `event_attendees`. Take a backup first.
- Run `php artisan queue:restart` right after the code swap: the payment lock
  key changed from `paymera:payment:{id}` to `payments:payment:{id}`, and an
  old worker would take the other one.
- Schedule is already declared in `routes/console.php`.

## Explicitly not done here

- A booking paid before payment rows existed (before this deploy) has no
  row saying it was paid; extended after the deploy beyond what the
  personal wallet covers, it owes its whole new price. Only a booking
  checked in across the deploy can reach this.
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
- Final-review fixes: `PayNowWalletOwnerTest` (C1),
  `Booking/BookingExtensionOwedTest` (C2), `Membership/PlanDiscountUsageTest`
  (I1), `CardStartFailureTest` (I2), `Experience/EventPaymentBlockTest`
  (I3), `MoneyAmountSpellingTest` (M4), and
  `PaymeraCreatePaymentPayloadTest`, which pins what a card payment asks
  Paymera for: `callbackURL`/`triggerURL` from `route('paymera.callback'|
  'paymera.trigger', token)`, and `savedCards`/`appUser` only when
  `paymera.saved_cards_enabled` is on.
