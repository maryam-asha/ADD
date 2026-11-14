# Kitchen Orders Without a Bill — Backend (ADD Core) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Anyone signed in can order from the kitchen. Office-lease staff and members with a bill go straight to the kitchen; everyone else is confirmed by reception first. Orders are paid at send (wallet or card), taken in cash by reception, or put on the bill. Every order that is not made gives back what was paid, and an order nobody acts on is cancelled.

**Architecture:** `KitchenOrder` gains a route (`kitchen` | `reception`), a payment choice, a nullable bill and two new statuses (`awaiting_payment`, `awaiting_reception`). A new `OrderRouting` service decides the route and the payment options once, for the menu and the send alike. Paying at send reuses `PaymentService::start` with `PaymentPurpose::KitchenOrder`. Every path that ends an unmade order goes through one `KitchenOrderRefunder`. Reception's queue and its cash-to-return list are new admin endpoints under `reception/`, and one scheduled command expires stale orders.

**Tech Stack:** Laravel 12, PHP 8.3, MySQL, Spatie Permission, Sanctum, Pusher (via `RealtimeBroadcaster`), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-14-kitchen-orders-without-a-bill-design.md` (approved 2026-11-14). It amends `2026-11-10-kitchen-orders-design.md`. Read both. Decisions are cited below as **N1–N7** (new) and **D1–D12** (2026-11-10).

## Before you start

- `master` has another session's uncommitted access/booking work (`SessionClosureService`, `BookingCheckInService`, `UnlockService`, …). **Do not start until that is committed**, then cut a worktree from `master` (`superpowers:using-git-worktrees`). Before removing the worktree later, `rmdir` any `vendor` junction first (it deletes main's `vendor` otherwise).
- Worktree tests need Laragon PHP 8.3 and a throwaway `APP_KEY`. 13 failures are known on `master`; compare against that baseline, not zero.
- Never `git add` a whole directory. Add the files each task names.

## Global Constraints

- Every Global Constraint of `docs/superpowers/plans/2026-11-10-kitchen-orders-backend.md` still holds: points `unsignedInteger`, no float; enum columns are `string` + backed-enum cast registered in `EnumColumnsHaveBackedEnumCastsTest`; stock only through `StockService`; permission names only from route middleware; realtime only through `RealtimeBroadcaster::send()`; staff notices via `NotificationService::sendToHolders()`; every notification template and API key in both `ar` and `en`.
- Status changes lock first and re-read: lock order is **bill, then order, then payment**, everywhere (as `KitchenOrderService` does today).
- The wallet category stays `WalletTransactionCategory::General` (N2; open question 3 untouched).
- Card money never goes back to a card. Card and wallet refunds are points via `PaymentService::refund`. Cash refunds are cash (N4).
- Config: `config/kitchen.php` → `reception_window_minutes` (`KITCHEN_RECEPTION_WINDOW_MINUTES`, 15), `accept_window_minutes` (`KITCHEN_ACCEPT_WINDOW_MINUTES`, 15). Card holds use the existing `payments.prepayment_window_minutes`. Add both keys to `.env.example`, never to `.env`.
- Run `php artisan test tests/Guards` in every task, and the full suite in every task that touches a shared service (Tasks 3, 4, 7).
- Every new or changed endpoint goes into `postman/ADD-OS.postman_collection.json` (Task 8).

## Review Focus

1. **The card goes through in the same instant its hold expires** → the card wins: the expiry asks the gateway one last time and locks before cancelling, as `CancelExpiredPrepayments` does. (Task 7 `test_card_paid_at_the_deadline_is_not_cancelled`.)
2. **Reception accepts while the expiry job cancels the same order** → exactly one wins, and the other answers 409 `api.kitchen.order_not_awaiting_reception` or skips. Never refunded *and* sent to the kitchen. (Task 7 `test_reception_accept_and_expiry_race_one_wins`.)
3. **A member with a session pays by wallet, then the session closes before the kitchen accepts** → the order is cancelled by the system, its points come back, and the bill does not owe it. (Task 4 `test_bill_closing_refunds_a_prepaid_pending_order`.)
4. **An office employee whose lease is in another branch orders here** → reception route. One with `door_access_enabled = false` also gets the reception route. (Task 2 `test_lease_in_another_branch_is_not_office_staff`, `test_member_without_door_access_is_not_office_staff`.)
5. **Reception took cash, then the kitchen rejected the order** → no points are credited, the order shows on "cash to return", and once it is returned the cash total in `FinanceOverview` no longer counts it. (Task 6 `test_returned_cash_leaves_the_finance_cash_total`.)

---

### Task 1: Schema, statuses and enums

**Files:**
- Create: `database/migrations/2026_11_14_100000_kitchen_orders_without_a_bill.php`, `app/Domain/Kitchen/Enums/OrderRoute.php`, `app/Domain/Kitchen/Enums/OrderPaymentChoice.php`, `app/Domain/Kitchen/Enums/CashReturn.php`, `config/kitchen.php`
- Modify: `app/Domain/Kitchen/Enums/KitchenOrderStatus.php`, `app/Domain/Kitchen/Models/KitchenOrder.php`, `database/factories/KitchenOrderFactory.php`, `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` (register the new columns), `.env.example`
- Test: `tests/Feature/Kitchen/KitchenOrderSchemaTest.php`

**Interfaces:**
- Migration: `billable_type` and `billable_id` nullable. New columns: `route` string (default `kitchen`), `payment_choice` string (default `bill`), `payment_due_at` datetime null, `reached_kitchen_at` datetime null, `reception_decided_by` FK users null, `reception_decided_at` datetime null, `cash_return` string null, `cash_return_resolved_by` FK users null, `cash_return_resolved_at` datetime null. Backfill: existing rows `reached_kitchen_at = created_at`. Index `(status, reached_kitchen_at)` and `(branch_id, status)`.
- `enum OrderRoute: string { Kitchen = 'kitchen'; Reception = 'reception'; }`
- `enum OrderPaymentChoice: string { Bill = 'bill'; Wallet = 'wallet'; Card = 'card'; Cash = 'cash'; }`. `Card` is stored as the choice; the payment row's method is `PaymeraGateway::KEY`.
- `enum CashReturn: string { Owed = 'owed'; ReturnedInCash = 'returned_in_cash'; CreditedAsPoints = 'credited_as_points'; }`
- `KitchenOrderStatus`: add `AwaitingPayment = 'awaiting_payment'` and `AwaitingReception = 'awaiting_reception'`. `isOpen()` includes both (the member's open list). `isOwed()` is unchanged. Add `reachesKitchen(): bool` (true for Pending onward) and `static kitchenBoardValues(): list<string>` = pending, preparing, ready (what the admin `kitchen/orders` default filter already uses; switch it to this).
- `KitchenOrder`: casts for the new columns, `scopeOnABill()` (`whereNotNull('billable_type')`), `hasBill(): bool`, and `billType(): ?string` (null with no bill).

- [ ] **Step 1: Failing tests:** `test_an_order_may_have_no_bill` (factory `->withoutBill()` saves with `billable_*` null, `hasBill()` false, `billType()` null); `test_new_statuses_are_open_but_not_owed` (`AwaitingPayment->isOpen()` true, `->isOwed()` false; same for `AwaitingReception`); `test_existing_orders_reached_the_kitchen_when_sent` (a row created before the migration gets `reached_kitchen_at = created_at`); `test_admin_queue_default_excludes_the_new_statuses` (`GET admin/kitchen/orders` with no `status` omits `awaiting_*`).
- [ ] **Step 2:** `php artisan test tests/Feature/Kitchen/KitchenOrderSchemaTest.php` → FAIL.
- [ ] **Step 3:** Implement as in Interfaces.
- [ ] **Step 4:** `php artisan test tests/Feature/Kitchen tests/Guards` → PASS.
- [ ] **Step 5: Commit** `feat(kitchen): an order may stand without a bill, and wait for payment or reception`.

### Task 2: Who is office staff, which route, which payment options — and the menu

**Files:**
- Create: `app/Domain/Kitchen/Services/OfficeStaff.php`, `app/Domain/Kitchen/Services/OrderRouting.php`, `app/Domain/Kitchen/Services/RouteDecision.php`
- Modify: `app/Domain/Kitchen/Services/MenuAccess.php`, `app/Http/Resources/Kitchen/KitchenMenuResource.php`, `lang/{ar,en}/api.php`
- Test: `tests/Feature/Kitchen/OrderRoutingTest.php`, `tests/Feature/Kitchen/KitchenMenuTest.php` (update)

**Interfaces:**
- `OfficeStaff::leaseFor(User $member, int $branchId): ?OfficeLease`: the first lease `holdingOn(today)` whose `company_id` is among `$member->companies()->wherePivot('door_access_enabled', true)` and whose `space->building->branch_id === $branchId` (N1, the door's test as `UnlockService::163` reads it).
- `final readonly class RouteDecision { int $branchId; OrderRoute $route; list<Booking|WalkinSession> $bills; ?OfficeLease $lease; list<string> $methods; list<array> $wallets; }`. `$methods` is a subset of `['bill','wallet','card','cash']`, ordered that way. `$wallets` is `WalletService::spendOptions($member, General)` (balances included).
- `OrderRouting::for(User $member, ?QrPoint $qr, ?int $branchId): RouteDecision`. Branch: QR's → first bill's → `$branchId` → office lease's branch (any branch's lease, when nothing else names one) → `Branch::defaultId()`. Route: `kitchen` when `$bills !== []` or `leaseFor()` is not null, else `reception`. Methods: `bill` iff `$bills !== []`; `wallet` and `card` always; `cash` iff route is `reception` (N2).
- `MenuAccess::for()` returns `{branch_id, can_order, reason, bills, route, payment_methods, wallets}`. `can_order` is false only for `no_kitchen`. Delete `NO_OPEN_SESSION` and its uses (grep the app and tests).

- [ ] **Step 1: Failing tests:** `test_member_with_a_session_takes_the_kitchen_route_and_may_put_it_on_the_bill` (methods `['bill','wallet','card']`); `test_office_staff_without_a_session_go_to_the_kitchen` (methods `['wallet','card']`, lease set); `test_lease_in_another_branch_is_not_office_staff` and `test_member_without_door_access_is_not_office_staff` (both → reception, Review Focus 4); `test_ended_or_terminated_lease_is_not_office_staff`; `test_anyone_else_goes_to_reception_and_may_pay_cash` (methods `['wallet','card','cash']`); `test_company_wallet_is_listed_for_its_staff`. In `KitchenMenuTest`: `test_member_without_a_bill_may_order` (`can_order` true, `reason` null, `route` `reception`) replaces the old no-session refusal; `test_no_kitchen_still_refuses`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** `php artisan test tests/Feature/Kitchen tests/Guards` → PASS.
- [ ] **Step 5: Commit** `feat(kitchen): office staff and members with a bill order straight to the kitchen; others through reception`.

### Task 3: Sending an order — with or without a bill, paid by the method chosen

**Files:**
- Create: `app/Domain/Kitchen/Events/KitchenOrderAwaitingReception.php` (on the `reception` channel, name `kitchen_order.awaiting_reception`, ids only, like `KitchenOrderChanged`; Task 5 reuses it when an order leaves the queue)
- Modify: `app/Domain/Kitchen/Services/KitchenOrderService.php` (`place`, `deliveryFor`, `accept`), `app/Domain/Kitchen/Services/BillLocator.php` (`resolve` no longer throws `no_open_session`; returns `?Booking|WalkinSession`), `app/Http/Requests/Member/StoreKitchenOrderRequest.php`, `app/Http/Controllers/Api/V1/Member/KitchenController.php` (`store`, use `AnswersPayments`), `app/Domain/Finance/Payments/Fulfillers/KitchenOrderFulfiller.php`, `app/Domain/Kitchen/Services/KitchenOrderNotifier.php`, `lang/{ar,en}/api.php`
- Test: `tests/Feature/Kitchen/MemberKitchenOrderTest.php` (update), `tests/Feature/Kitchen/PayingWhenSendingTest.php`

**Interfaces:**
- Consumes: `OrderRouting::for()` (Task 2).
- Request adds `payment.method` (required, `in:bill,wallet,card,cash`), `payment.wallet_owner_type` and `payment.wallet_owner_id` (nullable, together), and top-level `order_id` (nullable int: resend a held order to switch its method; then `lines`, `qr`, `delivery` and `bill` are ignored).
- `KitchenOrderService::place(User $member, array $lines, ?QrPoint $qr, ?array $delivery, ?array $bill, array $payment): PlacedOrder`, where `final readonly class PlacedOrder { KitchenOrder $order; ?CheckoutOutcome $checkout; }`.
  - A method not in `RouteDecision::$methods` → 422 `api.kitchen.payment_method_not_offered`.
  - The bill: when the member has bills, the order joins one by today's `BillLocator` rules **whatever the method** (an order paid now still appears on its bill's report, already paid). With none, `billable_*` stays null.
  - Delivery with no bill: the QR's place. Else, for office staff, `delivery.type = space` defaults to the lease's `space_id` (the member may pick another space of the branch, or pickup/note). Else pickup or note (D2).
  - Status at creation: `card` → `AwaitingPayment` with `payment_due_at = now + payments.prepayment_window_minutes`. Otherwise `Pending` (route kitchen, `reached_kitchen_at = now`) or `AwaitingReception` (route reception).
  - `wallet`: `PaymentService::start(KitchenOrder, $member, WalletMethod::KEY, ['payable' => $order, ...wallet owner])` **inside** the send transaction. `InsufficientPointsException` and `WalletChoiceRequiredException` roll back the whole send and reach their usual renderers (`AnswersPayments::paymentFailureResponse`).
  - `card`: commit the order first, then `PaymentService::start(..., PaymeraGateway::KEY, ['payable' => $order])` (a gateway runs outside a transaction). A gateway failure leaves the hold, and the answer carries `payment_error` beside the order, as `payBesideNewRecord` does.
  - `cash` and `bill`: no payment row now.
  - `order_id` resend: only the member's own order in `AwaitingPayment` and not expired, else 409 `api.kitchen.order_not_awaiting_payment`. The new method follows the same rules. Wallet or cash moves the order out of the hold at once, to its route's status.
  - The notifier's `placed()` fires only once an order reaches the kitchen (`Pending`). New `awaitingReception(KitchenOrder)` fires for the reception route (reception channel event + staff notice to holders of `kitchen-orders.review`).
- `KitchenOrderService::accept()`: the bill-closed check runs only when `$order->hasBill()`.
- `KitchenOrderFulfiller::assertPayable()`: payable when `isOwed()` **or** status ∈ {`AwaitingPayment`, `AwaitingReception`, `Pending`}, and `paid_at` null. Rejected and cancelled are still refused. `fulfil()`: sets `paid_at`. If the order is `AwaitingPayment`, it also moves to `AwaitingReception` or `Pending` by its route (setting `reached_kitchen_at`), clears `payment_due_at`, and fires the matching notifier call.
- Response: 201 `{data: KitchenOrderResource, payment, redirect_url, next, payment_error?}` (`checkoutFields`).

- [ ] **Step 1: Failing tests** (`PayingWhenSendingTest`): `test_wallet_payment_is_taken_with_the_order` (general balance down by the total, `paid_at` set, status `pending`); `test_short_wallet_writes_nothing` (422, zero orders, zero payments); `test_company_wallet_pays_for_office_staff`; `test_company_wallet_after_access_revoked_is_refused` (422 `api.booking.wallet_not_owned`, nothing written); `test_card_order_is_held_and_unseen` (status `awaiting_payment`, `payment_due_at` = now + 15 min, absent from `GET admin/kitchen/orders` and the reception queue, `redirect_url` returned); `test_card_paid_moves_a_kitchen_route_order_to_pending` and `test_card_paid_moves_a_reception_route_order_to_reception`; `test_held_order_switched_to_wallet` (resend with `order_id`); `test_cash_on_the_kitchen_route_is_refused` (422 `api.kitchen.payment_method_not_offered`); `test_bill_is_refused_without_a_bill`; `test_order_paid_now_still_appears_on_its_bill_as_paid` (report line `paid: true`, `owesFor` false); `test_office_staff_order_defaults_to_their_office`. Update `MemberKitchenOrderTest`: the old `no_open_session` 403 case now expects 201 on the reception route.
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** `php artisan test` (full: this touches the fulfiller and the bill) → PASS against the baseline.
- [ ] **Step 5: Commit** `feat(kitchen): pay for an order when sending it, by wallet, card or cash, or put it on the bill`.

### Task 4: An order that is not made gives back what it took

**Files:**
- Create: `app/Domain/Kitchen/Services/KitchenOrderRefunder.php`
- Modify: `KitchenOrderService` (`cancelByMember`, `reject`, `cancelPendingFor`: each calls the refunder inside its transaction, after the status change), `KitchenOrderNotifier` (`refunded`, `cashToCollect`), `lang/{ar,en}/notifications.php`, `lang/{ar,en}/api.php`
- Test: `tests/Feature/Kitchen/RefundingUnmadeOrdersTest.php`

**Interfaces:**
- `KitchenOrderRefunder::refund(KitchenOrder $order, string $description): void`. Runs inside the caller's transaction, on the order it has locked. For each `Accepted` payment `forPayable($order)` not yet refunded: when `collected_via === PaymentMethod::Cash`, set `$order->cash_return = CashReturn::Owed` and notify `cashToCollect` (no wallet movement). Otherwise `PaymentService::refund($payment, $description)` and notify `refunded` (points, wallet name). No payment → nothing. It is idempotent: refunded rows are skipped, and `cash_return` is set only when null.
- `cancelPendingFor()` also takes `AwaitingReception` orders on that bill, and refunds each order it cancels.
- `cancelByMember()` accepts `AwaitingPayment`, `AwaitingReception` and `Pending` (else 409 `api.kitchen.order_not_pending`, as today). A held card order cancelled by its member also cancels its open gateway payment (`PaymentGateway::cancel`).

- [ ] **Step 1: Failing tests:** `test_member_cancelling_a_wallet_paid_order_gets_the_points_back`; `test_member_may_cancel_while_awaiting_reception_or_payment`; `test_kitchen_rejecting_a_card_paid_order_returns_points_not_money` (general balance back up; no gateway call); `test_company_wallet_payment_returns_to_the_company`; `test_bill_closing_refunds_a_prepaid_pending_order` (Review Focus 3); `test_cash_order_rejected_is_owed_in_cash_not_points` (`cash_return = owed`, wallet unchanged); `test_refunding_twice_changes_nothing`; `test_an_accepted_order_is_never_refunded` (reject after accept → 409, no refund).
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** `php artisan test` (full) → PASS.
- [ ] **Step 5: Commit** `feat(kitchen): an order that is not made gives back what was paid, cash as cash`.

### Task 5: Reception confirms or turns down an order

**Files:**
- Create: `app/Domain/Kitchen/Services/ReceptionOrderReview.php`, `app/Http/Controllers/Api/V1/Admin/Reception/KitchenOrderReviewController.php`. Every accept, reject or expiry sends `KitchenOrderAwaitingReception` (Task 3) again so that the desk drops the row.
- Modify: `routes/api/v1/admin.php`, `KitchenOrderNotifier` (`acceptedByReception`, `rejectedByReception`), `app/Http/Resources/Kitchen/AdminKitchenOrderResource.php`, `lang/{ar,en}/*.php`
- Test: `tests/Feature/Kitchen/ReceptionReviewTest.php`

**Interfaces:**
- Routes (permission `kitchen-orders.review`; N5): `GET admin/reception/kitchen-orders` (`branch_id` filter; `awaiting_reception` only; oldest first; paginated); `POST admin/reception/kitchen-orders/{order}/accept` (optional `amount`, `currency` for a cash order, as `SettleAtReceptionRequest`); `POST admin/reception/kitchen-orders/{order}/reject` (`reason` required, max 200, as `RejectKitchenOrderRequest`).
- `ReceptionOrderReview::accept(KitchenOrder $order, User $staff, ?string $amount, ?string $currency): KitchenOrder`. Lock and re-read; not `AwaitingReception` → 409 `api.kitchen.order_not_awaiting_reception`. Cash choice → `PaymentService::settleAtReception(PaymentPurpose::KitchenOrder, $order, PaymentMethod::Cash, $amount, $currency, $staff)` in the same transaction (short → 422 `api.payment.amount_short`, and the order stays put). Then status `Pending`, `reached_kitchen_at = now`, `reception_decided_by/at`, `notifier->placed()` (kitchen board) and `acceptedByReception()` (member).
- `ReceptionOrderReview::reject(KitchenOrder $order, User $staff, string $reason): KitchenOrder`: status `Rejected`, `rejection_reason`, `reception_decided_by/at`, the refunder (Task 4), and `rejectedByReception()`.
- `AdminKitchenOrderResource` adds `route`, `payment: {choice, wallet_owner_type, paid}`, `cash_due_points` (cash choice, unpaid), `bill` null-safe.

- [ ] **Step 1: Failing tests:** `test_queue_lists_only_orders_awaiting_reception_in_the_branch`; `test_accepting_sends_it_to_the_kitchen_board` (status `pending`, `KitchenOrderChanged` broadcast on `kitchen`); `test_accepting_a_cash_order_records_the_cash` (payment `collected_via = cash`, `received_by = staff`, order `paid_at` set); `test_cash_short_leaves_the_order_waiting`; `test_rejecting_refunds_and_tells_the_member`; `test_accepting_twice_is_409`; `test_kitchen_manage_permission_alone_cannot_review` (403).
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** `php artisan test tests/Feature/Kitchen tests/Guards` → PASS (`AdminRoutesArePermissionGatedTest` included).
- [ ] **Step 5: Commit** `feat(kitchen): reception confirms that a member without a bill is here before the kitchen sees the order`.

### Task 6: Cash to return

**Files:**
- Create: `app/Http/Controllers/Api/V1/Admin/Reception/KitchenCashReturnController.php`, `app/Domain/Kitchen/Services/CashReturns.php`
- Modify: `routes/api/v1/admin.php`, `app/Domain/Finance/Payments/PaymentService.php` (`markReturnedInCash`), `app/Domain/Reports/Services/Dashboard/FinanceOverview.php:74`, `lang/{ar,en}/*.php`
- Test: `tests/Feature/Kitchen/CashReturnsTest.php`

**Interfaces:**
- Routes (permission `kitchen-orders.review`): `GET admin/reception/kitchen-cash-returns` (`branch_id`; `cash_return = owed`; oldest first); `POST .../{order}/returned`; `POST .../{order}/credit-points`.
- `PaymentService::markReturnedInCash(Payment $payment, User $operator): void`: lock the payment; it must be `Accepted`, `collected_via = cash` and not refunded, else 409 `api.payment.not_refundable`. Set `refunded_at = now` and `refund_transaction_id = null`, with no wallet movement (the cash credit and its debit already net to zero). The operator is recorded on the order (`cash_return_resolved_by`).
- `CashReturns::returnedInCash(KitchenOrder, User)` → `markReturnedInCash` + `cash_return = ReturnedInCash` + resolved by/at. `CashReturns::creditAsPoints(KitchenOrder, User)` → `PaymentService::refund` + `cash_return = CreditedAsPoints`. Neither applies unless `cash_return = owed` → 409 `api.kitchen.no_cash_owed`.
- `FinanceOverview`: the `cash` figure excludes payments whose `refunded_at` is set and `refund_transaction_id` is null (cash handed back).

- [ ] **Step 1: Failing tests:** `test_list_shows_cash_owed_in_the_branch`; `test_returned_in_cash_moves_no_points`; `test_credit_as_points_puts_them_in_the_members_wallet`; `test_resolving_twice_is_409`; `test_returned_cash_leaves_the_finance_cash_total` (Review Focus 5).
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** `php artisan test tests/Feature/Kitchen tests/Feature/Reports tests/Guards` → PASS.
- [ ] **Step 5: Commit** `feat(kitchen): reception hands back the cash of an order that was not made`.

### Task 7: Orders nobody acts on are cancelled

**Files:**
- Create: `app/Console/Commands/ExpireStaleKitchenOrders.php`
- Modify: `routes/console.php` (`Schedule::command('kitchen:expire-stale-orders')->everyMinute()->withoutOverlapping();` with a comment citing N3/N7), `KitchenOrderService` (`expire(KitchenOrder $order, string $why): bool`), `KitchenOrderNotifier` (`expired`), `lang/{ar,en}/notifications.php`
- Test: `tests/Feature/Kitchen/ExpireStaleOrdersTest.php`

**Interfaces:**
- Three sweeps, each chunked, each order locked and re-read where it is let go (a decision landing in the same instant wins):
  1. `AwaitingPayment` with `payment_due_at <= now`. First ask the gateway once (reuse `CancelExpiredPrepayments::lastWordFromTheGateway`: extract it to a shared `App\Domain\Finance\Payments\LastWordFromTheGateway` and use it in both). A card the bank took meanwhile settles the order and moves it on instead.
  2. `AwaitingReception` with `created_at` (or `paid_at` for a card order) older than `kitchen.reception_window_minutes`.
  3. `Pending` with `reached_kitchen_at` older than `kitchen.accept_window_minutes`, **bill orders included** (N7).
- `expire()` → `Cancelled`, `cancelled_by = System`, `cancelled_at`, the refunder, `notifier->expired($order, $why)` (`why` ∈ `payment`, `reception`, `kitchen`, which selects the notification template). Returns false when the order had already moved on.

- [ ] **Step 1: Failing tests:** `test_unpaid_card_hold_is_cancelled_at_its_deadline`; `test_card_paid_at_the_deadline_is_not_cancelled` (Review Focus 1, gateway faked to say accepted); `test_unanswered_reception_order_is_cancelled_and_refunded_after_15_minutes` (`travel(16)->minutes()`; 14 minutes leaves it); `test_kitchen_unaccepted_order_is_cancelled_after_15_minutes` (both a bill order and a no-bill wallet-paid one); `test_reception_accept_and_expiry_race_one_wins` (Review Focus 2: accept first, then run the command → order stays `pending`, no refund); `test_windows_come_from_config` (`config(['kitchen.reception_window_minutes' => 5])`).
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** `php artisan test` (full; `CancelExpiredPrepayments` changed) → PASS.
- [ ] **Step 5: Commit** `feat(kitchen): an order nobody acts on is cancelled and gives back what it took`.

### Task 8: Member resources, API collection and records

**Files:**
- Modify: `app/Http/Resources/Kitchen/KitchenOrderResource.php` (adds `route`, `payment: {choice, paid, refunded, cash_return}`, `payment_due_at`; `bill` null with no bill), `postman/ADD-OS.postman_collection.json` (Member › Kitchen: menu fields, send with each method + saved examples for `payment_method_not_offered`, wallet short, card hold, resend with `order_id`; Admin › Reception: the queue, accept (with cash), reject, cash returns list/returned/credit-points), `docs/superpowers/specs/2026-11-14-kitchen-orders-without-a-bill-design.md` (status line: built, with the commit range), `database/seeders/` kitchen demo (one office-staff member, one with no bill)
- Test: `tests/Feature/Kitchen/MemberKitchenOrderTest.php` (resource shape), `php artisan test tests/Guards`

- [ ] **Step 1: Failing test:** `test_member_order_resource_shows_route_and_payment` (a no-bill wallet order: `bill` null, `payment.choice` `wallet`, `payment.paid` true).
- [ ] **Step 2:** FAIL. **Step 3:** Implement and update the collection. Validate it parses: `node -e "JSON.parse(require('fs').readFileSync('postman/ADD-OS.postman_collection.json','utf8'))"`, plus a scan for credential fragments (no tokens or keys in examples).
- [ ] **Step 4:** `php artisan test` (full) → PASS against the baseline.
- [ ] **Step 5: Commit** `docs(kitchen): orders without a bill in the API collection; demo data`.

After merging, the ADD OS snapshot `docs/api/ADD-OS.postman_collection.json` is stale until re-pinned. The dashboard plan's Task 1 does that.
