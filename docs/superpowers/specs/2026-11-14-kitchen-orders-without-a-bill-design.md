# Kitchen orders without a bill, and paying when sending — design

**Date:** 2026-11-14 · **Status:** approved by the product owner, 2026-11-14
(builder check done; its questions answered as decisions 4 (cash), 7 and the
office-staff test in decision 1)
**Amends:** `2026-11-10-kitchen-orders-design.md` (decisions 8 and 9, the first
non-goal, the "refund flow" anti-goal, open question 1). The reasoning there is
kept; what this changes, and what it gives up, is recorded here and noted at the
end of that spec.
**Scope:** ADD Core (API) + ADD OS dashboard (the reception queue). The member
app's cart screen is the app team's. This spec recommends its layout (§ The cart)
and the API gives it everything that layout needs.

## Intent

Today only a member with an open coworking session or a checked-in booking can
order from the kitchen, and the order always joins that bill and is paid at
checkout. Two kinds of people in the building are shut out:

- **a member with no session or booking**: a guest, someone between bookings,
  someone who walked in to meet a member;
- **staff of a company that rents a private office.** They work in the building
  every day but never check in, so they never have a bill.

The product owner wants both to be able to order. The first kind is checked by
reception, which confirms the person is actually in the building before the
kitchen sees the order. The second kind goes straight to the kitchen. With no
bill to put the order on, these orders are paid when they are sent, by the
payment methods ADD already has. Members who do have a bill get the same
choice: pay now, or put it on the bill as today.

**Success:** anyone signed in to the app can send a kitchen order. Office staff
and members with a bill reach the kitchen at once. Everyone else reaches
reception first. No order reaches the kitchen unless it is paid or sits on an
open bill. Any points taken for an order that is not made come back
automatically.

## Decisions made with the product owner (2026-11-14)

1. **Three routes, decided when the order is sent.**

   | The member | Route |
   |---|---|
   | has an open coworking session or a checked-in booking (today's rule) | kitchen |
   | belongs to a company with an office lease holding today, in the order's branch | kitchen |
   | anyone else | reception first, then the kitchen |

   *Why:* reception's check is about presence. A member with a bill was already
   checked in. An office tenant's staff are in the building by contract. The rest
   could be ordering from home.
   - "Office staff" means the same test the office door uses (`UnlockService`):
     a `company_user` row with `door_access_enabled = true`, for a company
     whose lease is `holdingOn(today)` and whose office space is in the
     order's branch. *(My call, after the builder check. A company member
     without door access, such as a remote employee, cannot be in the office
     either, so they take the reception route.)*
   - A member who has a bill **and** is office staff takes the bill route.
     Nothing about them changes.

2. **The member chooses how to pay, from the methods ADD already has.** *(Product
   owner, 2026-11-14: "let them choose from the payment methods we already
   have".)*

   | Choice | Offered when | Notes |
   |---|---|---|
   | **On my bill** (paid at checkout) | the member has a bill | today's behaviour, unchanged |
   | **Wallet**: personal, or a company wallet they may spend from | always | `WalletService::spendOptions`, category `general` (the fulfiller's, unchanged) |
   | **Card** (Paymera) | always | the card buys points, which pay for the order |
   | **Cash at reception** | the reception route only | *(My call:* only reception can take cash. On the kitchen route there is no one to collect it, and nothing guarantees payment.*)* |

   - Office staff paying from the company wallet is what settles the old open
     question 1 ("who pays for private-office employees"). The rights to spend a
     company wallet are the ones it already has. Nothing new is granted.
   - "Pay later" means "on my bill" and exists only where there is a bill.

3. **Paid when sent.** *(Product owner, 2026-11-14, choosing between "at send"
   and "at acceptance".)*
   - **Wallet:** the points are taken in the same transaction that writes the
     order. A wallet that is short refuses the send (422 with the shortage), and
     nothing is written.
   - **Card:** the order is written in a new status `awaiting_payment` and the
     card payment is opened. Until the card goes through, neither the kitchen
     nor reception sees it. When it is paid, it moves on to its route. If it is
     still unpaid at its deadline, the system cancels it. *(My call:* the same
     hold as a prepaid booking (`docs/decisions/prepayment-all-or-nothing.md`):
     the window is `PAYMENTS_PREPAYMENT_WINDOW_MINUTES`, 15 by default, and the
     member may switch the held order to another method by resending with its
     id.*)*
   - **Cash:** nothing is taken at send. Reception takes it when it accepts the
     order (decision 5).
   - **On the bill:** as today. Nothing is paid until checkout.
   - *Why at send:* it is the only rule that works for all three methods. A card
     needs the member in front of the screen, so it cannot be charged later at
     acceptance. *Given up:* charging only for orders that are certain to be made,
     which needed no refund (the "refund flow" anti-goal of 2026-11-10).

4. **An order that is not made gives its points back.** A paid order that is
   rejected (by reception or the kitchen), cancelled by the member while still
   pending, or cancelled by the system is refunded through
   `PaymentService::refund`, as points, to the wallet that paid it. Money never
   goes back to a card: the card bought points, and the points are what come
   back. Once the kitchen has accepted an order, it is not refunded, as stock
   was drawn (unchanged from decision 7 of 2026-11-10).
   - **Cash comes back as cash** *(product owner, 2026-11-14)*. A cash order
     that reception accepted and the kitchen then rejected, or that was
     cancelled before the kitchen accepted it, is owed back to the member in
     cash. It joins a reception list, "cash to return". Reception marks it
     returned when the member collects it. This records who returned it and
     when on the payment and marks it refunded with no wallet credit. The
     member is told to collect it at reception. *(Found while planning:* ADD
     has no cash-drawer record. The cash it takes is counted from payment rows
     (`FinanceOverview`), so returned cash must leave that count.*)* *Why:* someone paying cash is
     often not a regular member, and points may be of no use to them. *Given
     up:* the single refund path through `PaymentService::refund`.
     - *Correction 2026-11-15:* the "no cash-drawer record" note above was
       wrong. `CashRegisterService::closeSession` expects the drawer to hold
       the opening balance plus the operator's cash wallet credits in the
       shift, so a hand-back would leave that shift short. Hand-backs now
       reconcile: `payments.refunded_by` records who handed it back, and the
       close subtracts the cash that operator handed back during the shift
       (`refund_transaction_id` null), in base currency at the rate stored on
       the original cash credit. Cash credited as points leaves the drawer
       alone. The payments screen and the finance dashboard both leave
       handed-back cash out of the cash total.
     - *(My call:)* if the member asks for points instead, reception may credit
       the order as points (`PaymentService::refund`) from the same list. An
       unclaimed amount stays on the list. Nothing expires it.

7. **An order no one acts on is cancelled** *(product owner, 2026-11-14,
   after the builder check)*. A scheduled job cancels, refunds (decision 4) and
   notifies the member about:
   - an order `awaiting_reception` older than `KITCHEN_RECEPTION_WINDOW_MINUTES`
     (decision 5);
   - an order `pending` that the kitchen has not accepted within
     `KITCHEN_ACCEPT_WINDOW_MINUTES` (15 by default) of reaching the kitchen.
     *(My call:* this applies to every pending order, bill orders included.
     Before this, only a bill closing ever ended an unaccepted order, which
     left an order without a bill waiting forever.*)*

   Cancelled by the system, as `CancelledBy::System`. Card holds keep their own
   deadline (decision 3).

5. **Reception's check.** The reception route adds a status
   `awaiting_reception` before `pending`:
   - **accept:** the order becomes `pending` and the kitchen board sees it. For
     a cash order, accepting records the cash in the same action
     (`settleAtReception`, exactly what is due). An order cannot be accepted as
     cash without the cash being recorded.
   - **reject, with a reason the member sees:** the order becomes `rejected` and
     a paid order is refunded (decision 4).
   - **Not answered in time** *(product owner, 2026-11-14)*: the system
     cancels it after `KITCHEN_RECEPTION_WINDOW_MINUTES`, 15 by default, refunds
     it, and tells the member. Reception might otherwise never see an order
     arrive, and the member would wait with no answer.
   - Reception decides on its own judgment (it knows who is at the desk). No
     automatic presence signal is consulted (see Non-goals).

6. **The cart shows the choice; nothing pops up after Send.** *(My
   recommendation for the app team. The product owner asked for "the most
   suitable way to show it".)* See § The cart.

## The order, amended

```
awaiting_payment ──card paid──▶ awaiting_reception ──reception accepts──▶ pending ──▶ preparing ──▶ ready ──▶ delivered
       │  (card, reception route)       │                                   ▲
       │                                └──reception rejects / times out──▶ rejected / cancelled (refunded)
       └──card paid, kitchen route────────────────────────────────────────────┘
       └──deadline passes──▶ cancelled (system)
```

Wallet and on-the-bill orders skip `awaiting_payment`. Cash orders are always on
the reception route.

- **No bill.** `billable_type`/`billable_id` become nullable. An order with no
  bill is always paid (wallet or card) or cash taken by reception, and is never
  listed on any bill, session report or checkout quote.
- **An order on a bill but paid at send** (the member chose wallet or card)
  appears on that bill's report as a paid `order` line and is never owed again:
  `KitchenOrderSettlement::unpaid` already excludes rows with `paid_at`.
- **Which branch, with no bill:** the QR's branch, else the branch the app names
  (`X-Branch-Id`, as `MenuAccess` already falls back to), else for office staff
  the lease's branch.
- **Where it goes, with no bill:** the scanned QR's desk or space. Otherwise,
  for office staff, their office space, which the member may change
  *(my call, as a room booking's space is today)*. Otherwise the counter or a
  note, as for coworking (decision 2 of 2026-11-10).
- **Kitchen accept** keeps its bill-closed check only for orders on a bill.
- **A bill closing** still cancels its pending orders. Any of those that were
  paid at send are refunded (decision 4).
- `KitchenOrderFulfiller::assertPayable` today refuses anything not yet
  accepted. It must also accept paying an order at send (`awaiting_payment`,
  and the wallet case inside the send transaction), while still refusing
  cancelled, rejected and already-paid ones. Its wallet category stays
  `general`; the `cafe` question (open question 3 of 2026-11-10) is untouched.
- **Card paid** (`fulfil`): marks `paid_at` and moves the order out of
  `awaiting_payment` to `awaiting_reception` or `pending` by its route, with the
  notifications that the move implies.

## API (shape is the builder's)

**Member**
- **Menu** (`MenuAccess`): `can_order` no longer needs a bill. It returns the
  route the order would take (`kitchen` | `reception`) and the payment choices
  the member has right now: `bill` (with the bills, as today), the wallets from
  `spendOptions` with their usable balances, `card`, and `cash` on the
  reception route. The reason `no_open_session` goes; `no_kitchen` stays.
- **Send** takes `payment: {method: bill|wallet|card|cash, wallet_owner_type?,
  wallet_owner_id?, bill?}`. A method the member is not offered is refused
  (422). A card answer carries the gateway start, as a prepaid booking's does.
  Resending with the held order's id switches its method (decision 3).
- **Their orders** show the new statuses, how each was paid, and a refund when
  there was one.

**Admin / reception**
- The queue of orders `awaiting_reception` for a branch, with accept and reject
  (with a reason). Accepting a cash order takes the cash.
- "Cash to return": the branch's cash orders owed back, with **returned in
  cash** and **credit as points** (decision 4).
- **Permission:** new `kitchen-orders.review`, registered in
  `known-permissions.ts` and the role editor. *(My call:* kept apart from
  `kitchen-orders.manage` so that kitchen staff do not become gatekeepers by
  default.*)*

**Realtime:** a reception-channel event when an order arrives at or leaves the
queue. Member events for: accepted by reception, rejected by reception (with the
reason), cancelled because unanswered, payment refunded.

Every new or changed endpoint goes into the canonical Postman collection.

## Dashboard (ADD OS)

- **Reception: "Kitchen orders to confirm."** A queue under the reception
  section. Each row shows the member (name, photo, phone), the items and total,
  the place, how long it has waited, and how it is paid: *paid from wallet*,
  *paid by card*, or **cash to collect: N**. Actions: accept, and reject with a
  reason (the existing `RejectOrderModal` pattern). The count shows on the
  reception navigation.
- **Reception: "Cash to return."** Who is owed, how much, for which order and
  why. Two actions: returned in cash, or credit as points.
- **Kitchen board and history:** the new statuses, and the payment method on
  each card. The board itself still shows only `pending` onward.
- All strings ar/en, tokens only, numbers through `utils/format`, guards green.

## The cart (the app team's; our recommendation)

The choice sits in the cart, above Send, so the member sees the total and their
balance before deciding. It is never a question that appears after pressing Send.

```
┌ Your order ─────────────────────┐
│ Coffee ×2                 40 pts │
│ Water ×1                  10 pts │
│ Total                     50 pts │
├ How to pay ─────────────────────┤
│ ◉ On my session bill (at checkout)│ ← only with a bill; preselected
│ ○ My points now — 120 available  │
│ ○ Company wallet — 900 available │ ← only where spendOptions lists it
│ ○ Card                           │
│ ○ Cash at reception              │ ← reception route only
│ ⓘ Reception will confirm your    │ ← reception route only
│   order before it is prepared    │
│            [ Send order ]        │
└──────────────────────────────────┘
```

A wallet that is short shows disabled, with its balance and a top-up link. With
no bill, the first wallet that covers the total is preselected.

## Non-goals

- Money back to a card. Card and wallet refunds are points. Only cash comes
  back as cash (decision 4).
- An automatic presence check (door events, Wi-Fi). Reception's judgment is the
  check.
- Paying at acceptance, or splitting one order across methods.
- Anything for orders the kitchen has accepted: no refund, as before.
- Reception or kitchen staff placing orders for a member (unchanged).
- The `cafe` wallet category (open question 3, unchanged). Who on a multi-person
  room booking may order onto its bill (open question 2, unchanged).
- The Business Café.

## Anti-goals

- An order reaching the kitchen neither paid nor on an open bill.
- Points kept for an order that was never made.
- Office staff, or a member with a bill, held at reception.
- Cash offered where no one can collect it.
- Reception seeing a card order before the card has gone through.

## Left to the builder

Column and status storage, endpoint paths, resource shapes, the two config keys'
names, notification wording, the queue's layout within the design system, and
test layout, within the decisions above.
