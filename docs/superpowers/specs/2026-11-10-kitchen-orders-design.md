# Kitchen orders — design

**Date:** 2026-11-10 · **Status:** draft, awaiting product-owner approval (builder
check done; its two questions answered as decisions 11 and 12)
**Scope:** ADD Core (API) + ADD OS dashboard. The member app (Flutter) consumes the
member endpoints; its screens are the app team's.

## Intent

ADD has an internal kitchen that serves drinks and snacks to the people working in
the building. Today the only way to buy from it is at the reception counter
(`App\Domain\Retail`: `admin/reception/item-sales`), an instant sale paid on the spot.
There is no way for a member sitting at a desk or in a meeting room to order, and no
place for the kitchen to see and work through orders.

This is **not** the Business Café (`business-cafe` service type, the external café in
the Experience layer). That stays "coming soon" and is untouched here.

**Success:** a member with an open coworking session or a checked-in booking scans
the QR on their desk or in their room (or opens the menu in the app), picks items and
quantities, and sends. The kitchen sees the order live, accepts or rejects it,
prepares it and delivers it (or the member collects it). The amount lands on the
bill of that session or booking and is settled at checkout with everything else.

## Decisions made with the product owner (2026-11-10)

1. **Two ways in, one menu.**
   - **QR** on every desk and in every room. Scanning it opens the menu with the
     place already known, so the kitchen delivers there.
   - **The menu in the app** without a QR. Same menu, same flow; the place is
     worked out as in decision 2.
2. **Where an order goes when there is no QR:**
   - the member has a checked-in room/office/hall booking → that space is filled
     in; the member may change it;
   - the member is in coworking (no fixed desk) → they choose **pick up from the
     kitchen counter** or **a short free-text note** ("by the window").
   An order never reaches the kitchen without the kitchen knowing what to do with it.
3. **Orders are for now.** No scheduling for a later time.
4. **The kitchen accepts or rejects.** Rejection carries a reason the member sees
   (typically: out of stock).
5. **Several items per order, each with a quantity.** The menu shows only what is
   in stock.
6. **The menu is the items ADD already sells** — sale items (`admin/sale-items`),
   each one stock item at a price in points — limited to what the branch's kitchen
   store holds. Every menu item is a stock item sold as it is (a can, a bottle, a
   bar). Drinks made to a recipe with their ingredients drawn from stock are **not**
   in this version.
7. **Stock is drawn when the kitchen accepts**, not when the order is sent. Each line
   is a stock issue with reason *sale*, linked to the order, exactly as a counter sale
   is. If stock ran short between sending and accepting, the kitchen cannot accept and
   rejects. Rejected or cancelled before acceptance: nothing is drawn. Cancelled after
   acceptance: nothing returns to stock automatically; an intact return is a stock
   adjustment by the store manager (already exists).
8. **Only someone with an open coworking session or a checked-in booking can order.**
   Anyone else may browse the menu but cannot send; they are told to check in first.
9. **The order is not paid when sent.** On acceptance its total is added to the bill
   of that session or booking, and settled at checkout by whatever method closes the
   session (cash included). This is PRD §5.9 ("joins an open bill when a session is
   active"), and `SessionReportBuilder` was built to take `order` lines for exactly
   this. Consequences:
   - a rejection or a cancellation before acceptance needs no refund — it never
     reached the bill;
   - the midnight auto-close of an open bill includes accepted orders;
   - an order may take the balance owed negative; PRD §5.9 sets no cap.
11. **Orders are a separate charge on the bill, not part of its price** (product owner,
    2026-11-10, after the builder check). Shown together with the session or booking
    at checkout, but paid as their own payable, from the member's **general** points —
    never from space-specific points — and recorded as sales, not as booking or
    session revenue. *Why:* the bill's existing payment path (`SessionPaymentFulfiller`)
    spends `space_specific` first; folding orders into `price_points` / `amount_owed`
    would let points granted for a space pay for drinks, and would report drinks as
    space revenue. *Given up:* the simpler build where orders just raise the bill's
    figure and ride the existing payment path unchanged.
12. **The kitchen has its own store** (product owner, 2026-11-10, after the builder
    check). Each branch names one warehouse as its kitchen store; the menu is the
    active sale items with stock in that store, and nothing else filters it — no
    "on the kitchen menu" flag. The counter keeps selling from reception's store;
    the two draw from separate stock, and kitchen items reach the kitchen store by
    a transfer from the main store. *Given up:* one shared stock for counter and
    kitchen, which would have needed a per-item menu flag to keep things like a
    notebook off the menu.
    - The setting accepts only a sellable warehouse of that branch that is **not**
      its primary (reception's default) store. *(My call.)*
    - The demo seed stocks the kitchen store with the drinks and snacks by a
      transfer, so the menu is not empty out of the box. *(My call.)*
10. **Low stock warning.** Each item in a store may carry a minimum quantity; when
    stock falls below it, the store's manager is notified and restocks with a
    transfer from the main store (transfers already exist). Buying from a supplier
    is outside this module.

## Non-goals (this version)

- **Private-office employees ordering without a session or booking.** The product
  owner wants these as separate orders, not on a session bill; who pays (the
  employee, the company wallet, or the office's monthly invoice) is **open** and
  deferred. Until then such a member cannot order unless they have a session or
  booking.
- Recipes / prepared drinks drawing ingredients from stock.
- Paying when sending (wallet, Paymera). Not needed while every order goes on a bill.
- Spending the `cafe` wallet category. It is still granted (plans, HR benefits,
  company allocation) and still never spent; this design does not change that, but
  it is the first place it could be spent — see open questions.
- Reception or kitchen staff placing an order on a member's behalf.
- Scheduled orders, tips, ratings.
- The Business Café.

## Anti-goals

- An order the kitchen cannot place: no location and no pickup/note choice.
- Stock or a bill line written for an order the kitchen never accepted.
- A second price list. The menu's prices are the sale items' prices; changing one
  changes both the counter and the kitchen.
- A refund flow. Nothing is charged before acceptance.

## How it fits what exists

| Need | Builds on |
|---|---|
| What can be sold, at what price | `SaleItem` (`admin/sale-items`), price in points |
| Drawing stock | `Add\Inventory` `StockService::issue(..., IssueReason::Sale, Place)`, as `ItemSaleFulfiller` does |
| Which store | a per-branch **kitchen store** (new setting, decision 12): a sellable, non-primary warehouse of that branch |
| Who may order | `OpenCoworkingSessionLocator::for($member)`; `CurrentBookingLocator::for($member)` (state `active`) |
| The bill | `SessionClosureService` (`closeOut`, `autoClose`, `checkOutByMember`, `quote`, `settlePayment`), `ClosedBookingSettlement` ("the one figure anything added to a booking later must add to"), `SessionReportBuilder` (`lines`, kind `order`) |
| Desk QR | `SeatDesk.code` (unique per branch, "the code its QR carries") |
| The app's scan button | `ScanController` — dispatches by prefix (`add-cowork:v1:` today; anything else is a lock sticker) |
| Live updates | `RealtimeBroadcaster` / dashboard `services/realtime.ts` |
| Member notifications | Push, as `PaymentNotifier` / `BookingNotifier` |
| Staff notifications | the dashboard notification inbox |
| Sticker printing | the dashboard already prints a lock's door sticker (`feat(access)`) |

## The order

**Fields (the builder names them):** member · branch · the session or booking it is
billed to (exactly one) · delivery: `desk` (a seat desk) | `space` | `pickup` | `note`
(free text, required for `note`) · source: `qr` | `app` · status · lines (sale item,
inventory item, quantity, unit price in points, line points — prices frozen when the
order is **sent**) · total points · who accepted/rejected/delivered and when ·
rejection reason · cancellation source.

**Statuses:**

```
pending ──accept──▶ preparing ──▶ ready ──▶ delivered
   │                   (pickup orders wait here;
   ├─reject──▶ rejected  delivery orders may go
   └─cancel──▶ cancelled straight to delivered)
```

- `pending → cancelled` by the member, or by the system when the session/booking it
  is billed to closes before the kitchen accepts.
- `pending → rejected` by the kitchen, with a reason.
- `pending → preparing` (**accept**): in one transaction, lock the session/booking,
  check it is still open, draw every line from the kitchen store, add the order to
  the bill. Any failure leaves nothing behind (same rule as `ItemSaleService`).
- No transition back from `preparing`. A problem after acceptance is handled by staff
  (stock adjustment; a bill correction through the existing desk tools).

**Which bill an order joins** (decided at send time, re-checked at accept):
1. QR of a space the member holds a checked-in booking on → that booking.
2. Otherwise the member's open coworking session, if any.
3. Otherwise their single checked-in booking.
4. Several checked-in bookings and no QR match → the member picks one.
*(My call: the rules are the product owner's "active session or booking"; the order
of precedence is mine.)*

**Who on a booking may order:** in this version, the booking's own member only.
Several people in one meeting room ordering onto one bill is **open** (deferred).

## The QR

A new scan prefix the app's scan button recognises: `add-place:v1:<value>`, where
`<value>` is a stored random code (`Str::random(40)`, as lock stickers are —
`QrValueIsRandomNotSequentialTest`) naming one desk or one space. `ScanController`
answers `outcome: "menu"` with the place and whether the member may order (and why
not). Ordering still needs an open session or booking.
*(My call. A room's door already has a lock sticker that unlocks; the order sticker is
a different code, placed inside the room.)*
*(Changed 2026-11-10, while planning: the first draft encoded the desk or space id in
the value, `add-order:v1:desk:<id>`. The repo's guard requires QR values to be random
and underivable, and a guessable value would let anyone order "to" any place; given
up: printing a sticker without storing anything.)*

The dashboard prints these stickers per desk and per room, like the door sticker.

## The bill

Accepted orders are owed **alongside** the session or booking, as their own payable
(decision 11) — the session's or booking's own price, `price_points`, `amount_owed`
and `pointsPaid` are not changed by an order:
- a new payment purpose with its own fulfiller, whose wallet category is `general`;
- `quote` / `checkout` show the session charge and the orders as two figures and
  their total, so the member sees the true total before asking to end;
- settling at the desk (`settlePayment`) and at a wallet-paid booking's close
  (`ClosedBookingSettlement`) settle both — the orders by the same method the
  session is settled with;
- a session or booking that is fully covered by its plan or already paid still owes
  its orders: it is not "paid" while accepted orders are unpaid — the
  `awaiting_payment` home card, `CurrentBookingLocator::stateOf` and the
  plan-covered auto-Paid closures must all account for that;
- the midnight auto-close includes them;
- the session report lists each order as a line of kind `order`.

## API (shape is the builder's)

**Member:** the menu (for the member's branch, with availability and whether they may
order now) · send an order · their orders (current and recent) · cancel a pending one ·
scan handles the order prefix.

**Admin / kitchen:** the live queue (pending, preparing, ready) and history with
filters · accept · reject with reason · mark ready · mark delivered · the branch's
kitchen store setting · item minimum quantities per store.

**Permissions:** new `kitchen-orders.*` (view, manage); registered in
`known-permissions.ts` and the role editor.

**Realtime:** kitchen channel — order created / cancelled; member — status changes
(push: accepted, ready for pickup, rejected, delivered).

Every new endpoint goes into the canonical Postman collection.

## Dashboard (ADD OS)

- **Kitchen screen:** live board of pending → preparing → ready, each card showing
  the place (desk label / room name / "pickup" / the note), the items and quantities,
  how long it has waited, and the actions. An audible cue on a new order is the
  builder's call.
- **Orders history** with filters (branch, status, date, member).
- **Settings:** the kitchen store per branch; minimum quantities on the warehouse's
  item list.
- **Stickers:** print order QRs for desks and rooms.
- The existing "Café — coming soon" page under Community stays as it is (it is the
  Business Café).
- All strings ar/en, tokens only, numbers through `utils/format`, guards green.

## Low stock

A minimum quantity per item per warehouse (inventory package — a host-agnostic
feature, not kitchen-specific). When an issue takes the balance below it, the
warehouse's manager is notified once (not on every further issue until it is
restocked above the minimum). Restocking is the existing transfer.

## Open questions (deferred by the product owner, 2026-11-10)

1. Private-office employees: who pays their orders, and when.
2. A meeting-room booking with several people: who may order onto its bill.
3. Should the orders' payable spend the member's `cafe` wallet balance before
   `general`? (With decision 11 this is a one-line change to its fulfiller's
   wallet category.)

## Left to the builder

Table and column names, endpoint paths, resource shapes, the kitchen-store setting's
storage, notification wording, the kitchen screen's layout within the design system,
and test layout — within the decisions above.

## Changed 2026-11-14 — orders without a bill, and paying when sending

Amended by `2026-11-14-kitchen-orders-without-a-bill-design.md` (draft). Kept
above as it was decided; what changes:

- **Decision 8** (only someone with a bill may order): anyone signed in may now
  order. Office-lease staff go straight to the kitchen, and anyone else without
  a bill goes through a reception presence check first. *Given up:* the
  guarantee that every order sits on a bill reception already knows about.
- **Decision 9** (not paid when sent): the member now chooses. "On my bill" is
  today's rule and is offered only where a bill exists. Wallet or card is paid
  at send, and cash is taken by reception on the reception route.
- **Anti-goal "A refund flow"**: reversed. A paid order that is not made is
  refunded as points to the wallet that paid. *Given up:* charging only for
  orders certain to be made.
- **Non-goal "Private-office employees" / open question 1**: settled. They pay
  at send, from their own wallet or a company wallet they may spend from.
