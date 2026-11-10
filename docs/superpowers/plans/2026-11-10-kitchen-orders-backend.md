# Kitchen Orders — Backend (ADD Core) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Members with an open coworking session or a checked-in booking order from the internal kitchen by QR or from the app; the kitchen accepts/rejects, prepares and delivers; accepted orders are owed beside the session or booking and paid from general points.

**Architecture:** A new `App\Domain\Kitchen` domain owns orders, the per-branch kitchen store and QR points. It reuses `SaleItem` prices, draws stock through `Add\Inventory` `StockService::issue` at acceptance, and pays each accepted order as its own payable (`PaymentPurpose::KitchenOrder`, wallet category `general`) through `PaymentService`. Session/booking settlement paths call one `KitchenOrderSettlement` so orders settle with their bill. Low-stock minimums are a host-agnostic inventory-package feature with an event the host turns into a staff notice.

**Tech Stack:** Laravel 12, PHP 8.3, MySQL, Spatie Permission, Sanctum, Pusher broadcasting (via `RealtimeBroadcaster`), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-10-kitchen-orders-design.md` (approved 2026-11-10). Read it first; decisions are cited below as **D1–D12**.

## Before you start

- The working tree on `master` has another session's uncommitted booking/coworking work (`SessionClosureService`, `SessionEndService`, the new untracked `app/Domain/Booking/Events/CoworkingSessionClosed.php`, `BookingCheckedOut`). **Do not start until that is committed**, then cut a worktree from `master` (`superpowers:using-git-worktrees`). Tasks 6–8 edit `SessionClosureService` and `ClosedBookingSettlement`; never edit them under someone else's uncommitted changes.
- Never `git add` a whole directory; add the files each task names.

## Global Constraints

- Points are `unsignedInteger`; money stays DECIMAL; no float anywhere (`MoneyIsDecimalOnlyTest`, `PointRateIsNeverFloatTest`).
- Every enum-shaped column is `string` + PHP backed enum cast, registered in `EnumColumnsHaveBackedEnumCastsTest`; no MySQL `enum` columns (`NoNewMysqlEnumColumnsTest`).
- Stock moves only through `StockService` (`StockMovesOnlyThroughStockServiceTest`); the inventory package stays host-agnostic (`InventoryPackageStaysHostAgnosticTest`).
- Permission names come only from route middleware (`PermissionSyncService`); every admin route is gated (`AdminRoutesArePermissionGatedTest`).
- Realtime goes through `RealtimeBroadcaster::send()`, never `event()`. Staff notices go through `NotificationService::sendToHolders()` / `sendToUser(..., NotificationAudience::Staff)` — never pick staff by role name (`NotificationRecipientsComeFromPermissionsTest`).
- Every notification template exists in `lang/ar/notifications.php` and `lang/en/notifications.php` (`NotificationTemplatesHaveBothLanguagesTest`); every API message key in both `lang/*/api.php`.
- QR values: `Str::random(40)`, unique (`QrValueIsRandomNotSequentialTest`).
- New models that are morph targets are added to `Relation::morphMap()` in `app/Providers/MorphMapServiceProvider.php`.
- Run the full suite (`php artisan test`) at the end of every task that touches a shared service; `php artisan test tests/Guards` always.
- Every new endpoint goes into `postman/ADD-OS.postman_collection.json` (Task 11).

## Review Focus

1. **Two kitchen screens accept the same order at once** → exactly one succeeds; the other gets 409 `api.kitchen.order_not_pending`, and stock is drawn once. (Task 4 test `test_accepting_twice_draws_stock_once`.)
2. **Two pending orders for the last can** → the first accept draws it; the second accept answers 422 `api.kitchen.out_of_stock` naming the item and leaves the order `pending` so the kitchen can reject it with a reason. (Task 4 `test_accept_with_stock_short_changes_nothing`.)
3. **The session closes between send and accept** → closing cancels the pending order (`cancelled_by = system`, member notified); accepting afterwards answers 409. (Task 8 `test_closing_a_session_cancels_its_pending_orders`.)
4. **Member holds a coworking session and a checked-in room booking** → room QR bills the room booking; desk QR or no QR bills the coworking session. (Task 3 `test_bill_precedence`.)
5. **Wallet short when a wallet-paid booking closes** → the booking still closes; its unpaid orders stay owed and appear in the desk's outstanding items. (Task 7 `test_wallet_short_leaves_orders_outstanding`.)

---

### Task 1: QR points

A stored random code per desk or space; admin creates, lists and deletes them; the app's scan resolves them (Task 5).

**Files:**
- Create: `database/migrations/2026_11_10_100000_create_qr_points_table.php`, `app/Domain/Kitchen/Models/QrPoint.php`, `app/Domain/Kitchen/Enums/QrPointPlace.php`, `database/factories/QrPointFactory.php`, `app/Http/Controllers/Api/V1/Admin/QrPointController.php`, `app/Http/Requests/Admin/StoreQrPointRequest.php`, `app/Http/Resources/Kitchen/QrPointResource.php`
- Modify: `routes/api/v1/admin.php` (beside `sale-items`), `tests/Guards/QrValueIsRandomNotSequentialTest.php` (add a case), `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
- Test: `tests/Feature/Kitchen/QrPointAdminTest.php`

**Interfaces:**
- Produces: table `qr_points` (`id`, `branch_id` FK, `place_type` string(20), `place_id` unsignedBigInteger, `qr_value` string(60) unique, timestamps; unique (`place_type`,`place_id`)). `enum QrPointPlace: string { case SeatDesk = 'seat_desk'; case Space = 'space'; }`. `QrPoint::PREFIX = 'add-place:v1'`; `QrPoint::scanValue(): string` → `"add-place:v1:{$qr_value}"`; `QrPoint::fromScan(string $value): ?QrPoint`; `QrPoint::placeLabel(): string` (desk `label` or space translated `name`); relation `branch()`.
- Routes: `$permitted(Route::apiResource('qr-points', QrPointController::class)->only(['index','store','destroy'])->parameters(['qr-points'=>'qrPoint']), 'qr-points')`. Store body: `{place_type, place_id}`; branch derived from the place (desk → `space->building->branch_id`); creating one for a place that has one returns the existing row (200), else 201. Index filters `branch_id`, `place_type`.
- `seat_desks.qr_point_id` is **not** wired here (deliberate; it belongs to the PRD's wider QR layer).

- [ ] **Step 1: Failing tests** in `QrPointAdminTest`: `test_store_creates_a_random_value_for_a_desk` (201; `data.scan_value` starts with `add-place:v1:` and its random part is 40 chars), `test_store_is_idempotent_per_place` (second POST → 200, same id), `test_branch_comes_from_the_place`, `test_unknown_place_is_422`, `test_requires_qr_points_create` (member token → 403). Add `test_qr_points_are_not_sequential` to the guard: 20 POSTs to `/api/v1/admin/qr-points` for 20 desks → 20 unique values, no shared 39-char prefix.
- [ ] **Step 2:** `php artisan test tests/Feature/Kitchen/QrPointAdminTest.php` → FAIL (route not defined).
- [ ] **Step 3:** Implement migration, model (`casts: place_type => QrPointPlace`), factory, request, resource (`id, branch_id, place_type, place_id, place_label, scan_value`), controller, route.
- [ ] **Step 4:** Run the test file and `php artisan test tests/Guards` → PASS.
- [ ] **Step 5: Commit** `feat(kitchen): qr points — a random code per desk or room` (the files above).

### Task 2: Kitchen store per branch and the menu

**Files:**
- Create: `database/migrations/2026_11_10_110000_create_kitchen_stores_table.php`, `app/Domain/Kitchen/Models/KitchenStore.php`, `app/Domain/Kitchen/Services/KitchenMenu.php`, `app/Http/Controllers/Api/V1/Admin/KitchenStoreController.php`, `app/Http/Requests/Admin/UpdateKitchenStoreRequest.php`
- Modify: `routes/api/v1/admin.php`, `lang/{ar,en}/api.php`
- Test: `tests/Feature/Kitchen/KitchenStoreTest.php`, `tests/Feature/Kitchen/KitchenMenuTest.php`

**Interfaces:**
- Produces: table `kitchen_stores` (`id`, `branch_id` unique FK, `warehouse_id` FK `inv_warehouses` restrictOnDelete, timestamps). `KitchenStore::forBranch(int $branchId): ?KitchenStore`; `KitchenStore::place(): Place` (the warehouse's `asPlace()`).
- `KitchenMenu::for(int $branchId): list<array{sale_item: SaleItem, available: string}>` — active `SaleItem`s whose `StockService::onHand($item, $store->place())` is > 0 (compare with `bccomp`), ordered by item name; empty list when the branch has no kitchen store.
- Routes (D12): `GET admin/kitchen/stores` (`permission:kitchen-stores.view`) lists every branch as `{branch: {id, name}, warehouse: {id, name} | null}`; `PUT admin/kitchen/stores/{branch}` (`permission:kitchen-stores.update`) body `{warehouse_id}`. Validation, each its own key: warehouse exists, belongs to that branch (`place_type = branch`, `place_id = branch`) → `api.kitchen.store_not_in_branch`; `allows_sales` true → `api.kitchen.store_not_sellable`; `is_primary` false → `api.kitchen.store_is_primary`.

- [ ] **Step 1: Failing tests.** `KitchenStoreTest`: `test_sets_the_branch_kitchen_store`, `test_refuses_the_primary_store` (422 key above), `test_refuses_a_non_selling_store`, `test_refuses_another_branchs_store`, `test_replacing_keeps_one_row_per_branch`. `KitchenMenuTest` (unit-level against the service): `test_lists_only_items_in_stock_in_the_kitchen_store` (cola 3 in kitchen, water 5 in main → only cola), `test_inactive_sale_items_are_hidden`, `test_no_kitchen_store_means_empty_menu`.
- [ ] **Step 2:** Run both → FAIL.
- [ ] **Step 3:** Implement migration, model, service, request, controller, routes, translations (ar/en).
- [ ] **Step 4:** Run both + guards → PASS.
- [ ] **Step 5: Commit** `feat(kitchen): each branch names its kitchen store; the menu is what it holds`.

### Task 3: Orders — schema, which bill, sending from the app

**Files:**
- Create: migration `2026_11_10_120000_create_kitchen_orders_tables.php`; `app/Domain/Kitchen/Models/{KitchenOrder,KitchenOrderLine}.php`; `app/Domain/Kitchen/Enums/{KitchenOrderStatus,DeliveryType,OrderSource,CancelledBy}.php`; `app/Domain/Kitchen/Services/{BillLocator,KitchenOrderService}.php`; `app/Domain/Kitchen/Exceptions/KitchenOrderException.php`; `app/Http/Controllers/Api/V1/Member/KitchenController.php`; `app/Http/Requests/Member/StoreKitchenOrderRequest.php`; `app/Http/Resources/Kitchen/{KitchenOrderResource,KitchenMenuResource}.php`; `database/factories/KitchenOrderFactory.php`
- Modify: `routes/api/v1/member.php`, `app/Providers/MorphMapServiceProvider.php` (`kitchen_order`), `EnumColumnsHaveBackedEnumCastsTest`, `lang/{ar,en}/api.php`
- Test: `tests/Feature/Kitchen/MemberKitchenOrderTest.php`, `tests/Feature/Kitchen/BillLocatorTest.php`

**Interfaces:**
- Tables. `kitchen_orders`: `id`, `user_id`, `branch_id`, `warehouse_id` (the kitchen store at send), `billable_type` string(40), `billable_id`, `delivery_type` string(20), `seat_desk_id` nullable, `space_id` nullable, `delivery_note` string(200) nullable, `source` string(10), `status` string(20) index, `total_points` unsignedInteger, `rejection_reason` string(300) nullable, `cancelled_by` string(10) nullable, `accepted_by/rejected_by/delivered_by` nullable FKs users, `accepted_at/ready_at/delivered_at/rejected_at/cancelled_at/paid_at` nullable timestamps, timestamps; index (`billable_type`,`billable_id`). `kitchen_order_lines`: `kitchen_order_id` cascade, `sale_item_id`, `inventory_item_id`, `quantity` unsignedInteger, `unit_price_points`, `line_points`.
- Enums: `KitchenOrderStatus { Pending='pending', Preparing='preparing', Ready='ready', Delivered='delivered', Rejected='rejected', Cancelled='cancelled' }` with `isOpen(): bool` (pending|preparing|ready) and `isOwed(): bool` (preparing|ready|delivered). `DeliveryType { Desk='desk', Space='space', Pickup='pickup', Note='note' }`. `OrderSource { Qr='qr', App='app' }`. `CancelledBy { Member='member', System='system' }`.
- `BillLocator::eligibleBills(User $member): list<Booking|WalkinSession>` — `OpenCoworkingSessionLocator::for()` plus bookings from `CurrentBookingLocator::for()` whose `stateOf()` is `active`.
- `BillLocator::resolve(User $member, ?QrPoint $qr, ?array $chosen /* {type: 'booking'|'walkin', id} */): Booking|WalkinSession` — precedence (spec "Which bill"): (1) `$qr` is a Space and the member has an active booking on that space → it; (2) open coworking session; (3) exactly one active booking; (4) `$chosen` naming one of `eligibleBills()`; else throws `KitchenOrderException('api.kitchen.choose_bill', 422)`; no eligible bill → `KitchenOrderException('api.kitchen.no_open_session', 403)`.
- `KitchenOrderService::place(User $member, array $lines /* list<{sale_item_id:int, quantity:int}> */, ?QrPoint $qr, ?array $delivery /* {type, space_id?, note?} */, ?array $bill): KitchenOrder` — resolves the bill; branch = bill's `space->building->branch_id`; requires a kitchen store (`api.kitchen.no_kitchen`, 422); each line's sale item active and `quantity` ≤ on hand (`api.kitchen.out_of_stock`, 422, `:item`); prices frozen from `price_points` now (D1, spec "prices frozen when sent"); delivery: QR desk → `desk`+`seat_desk_id`; QR space → `space`+`space_id`; no QR + bill is a room booking → `space` = that booking's space unless `$delivery` overrides with `pickup`/`note`/another `space_id`; no QR + coworking → `$delivery.type` must be `pickup` or `note` (`api.kitchen.delivery_required`, 422; note requires non-empty `note`). Status `pending`. Broadcast is Task 9.
- `KitchenOrderService::cancelByMember(KitchenOrder $order): void` — only `pending` (`api.kitchen.order_not_pending`, 409).
- Member routes: `GET member/kitchen/menu?qr=&branch_id=` → `{branch, can_order, reason (null|'no_open_session'|'no_kitchen'), place (from qr) , bills: [{type,id,label}], items: [{sale_item_id, name, image_url, price_points, available_quantity}]}`; branch = qr's, else the first eligible bill's, else `branch_id` (required then). `POST member/kitchen-orders` (throttle `10,1,member-kitchen`) body `{lines, qr?, delivery?, bill?}` → 201 `KitchenOrderResource`; `GET member/kitchen-orders` (open first, then the last 20 closed); `GET member/kitchen-orders/{order}` (own only, else 404); `POST member/kitchen-orders/{order}/cancel`.
- Resource fields: `id, status, source, delivery {type, label, note}, bill {type, id}, lines [{sale_item_id, name, quantity, unit_price_points, line_points}], total_points, rejection_reason, cancelled_by, timestamps (submitted_at, accepted_at, ready_at, delivered_at), paid`.

- [ ] **Step 1: Failing tests.** `BillLocatorTest::test_bill_precedence` (Review Focus 4: member with open walk-in + checked-in room booking; room QR → booking; desk QR → walk-in; no QR → walk-in), `test_single_room_booking_is_used`, `test_two_room_bookings_need_a_choice` (422 `choose_bill`), `test_no_session_or_booking_is_refused`. `MemberKitchenOrderTest`: `test_menu_says_why_ordering_is_closed` (no session → `can_order=false`, `reason='no_open_session'`, items still listed), `test_places_an_order_from_a_desk_qr` (201, `delivery.type=desk`, `total_points = 2×20`, status pending, **no** stock movement), `test_coworking_without_qr_must_choose_pickup_or_note`, `test_note_requires_text`, `test_room_booking_without_qr_delivers_to_its_space`, `test_quantity_above_stock_is_refused`, `test_inactive_sale_item_is_refused`, `test_member_cancels_a_pending_order`, `test_cannot_see_another_members_order` (404).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement schema, enums (with casts), models (`lines()`, `billable()` morphTo, `user()`), `BillLocator`, `KitchenOrderService::place/cancelByMember`, request, resources, controller, routes, translations.
- [ ] **Step 4:** Run both + guards → PASS.
- [ ] **Step 5: Commit** `feat(kitchen): members send orders onto their open session or booking`.

### Task 4: The kitchen's actions and its queue

**Files:**
- Create: `app/Http/Controllers/Api/V1/Admin/KitchenOrderController.php`, `app/Http/Requests/Admin/RejectKitchenOrderRequest.php`
- Modify: `app/Domain/Kitchen/Services/KitchenOrderService.php`, `routes/api/v1/admin.php`, `lang/{ar,en}/api.php`
- Test: `tests/Feature/Kitchen/KitchenOrderActionsTest.php`

**Interfaces:**
- `KitchenOrderService::accept(KitchenOrder $order, User $staff): KitchenOrder` — one `DB::transaction`: lock the order row then the billable row (`lockForUpdate`); order must be `pending` (409 `order_not_pending`); billable must be open — `checked_in_at` set and `checked_out_at` null (409 `api.kitchen.bill_closed`); for each line `StockService::issue($line->item, $order->store place, $line->quantity, IssueReason::Sale, new Place(KitchenOrder::SOURCE /* 'kitchen_order' */, $order->id))`; a short shelf throws → 422 `out_of_stock` and the transaction rolls back (order stays pending). Sets `preparing`, `accepted_at/by`.
- `reject(KitchenOrder, User, string $reason): KitchenOrder` — pending only; reason required, ≤300.
- `markReady(KitchenOrder, User)` — preparing only. `markDelivered(KitchenOrder, User)` — preparing or ready.
- Admin routes: `GET admin/kitchen/orders` (`permission:kitchen-orders.view`; filters `branch_id`, `status` (csv), `from`, `to`, `user_id`; default `status=pending,preparing,ready` oldest first; paginated, Laravel default shape); `GET admin/kitchen/orders/{order}`; `POST admin/kitchen/orders/{order}/{accept|reject|ready|deliver}` (`permission:kitchen-orders.manage`). Admin resource adds `member {id, name, phone}` and `waited_seconds`.

- [ ] **Step 1: Failing tests:** `test_accept_draws_stock_and_starts_preparing` (on hand 10 → 8; movement reason sale, source `kitchen_order:{id}`), `test_accepting_twice_draws_stock_once` (Review Focus 1: second call 409; on hand drawn once), `test_accept_with_stock_short_changes_nothing` (Review Focus 2), `test_accept_refuses_a_closed_bill`, `test_reject_needs_a_reason_and_draws_nothing`, `test_ready_then_delivered`, `test_pickup_order_can_go_straight_to_delivered`, `test_queue_lists_open_orders_oldest_first`, `test_actions_need_kitchen_orders_manage` (view-only staff → 403).
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run + guards → PASS.
- [ ] **Step 5: Commit** `feat(kitchen): the kitchen accepts, rejects, prepares and delivers`.

### Task 5: The scan button opens the menu

**Files:** Modify `app/Http/Controllers/Api/V1/Member/ScanController.php`, `lang/{ar,en}/api.php`. Test: `tests/Feature/Kitchen/ScanOpensMenuTest.php`.

**Interfaces:** in `scan()`, before the lock fallback: `str_starts_with(trim($qrValue), QrPoint::PREFIX.':')` → `QrPoint::fromScan()`; unknown → 422 `api.scan.unknown_code`; found → 200 `{data: {outcome: 'menu', qr: <the scanned value>, place: {type, id, label}, branch_id, can_order, reason}}` (same `can_order/reason` rules as the menu endpoint — reuse one method on `KitchenController` or a small `MenuAccess` value object in `App\Domain\Kitchen`).

- [ ] **Step 1: Failing tests:** `test_scanning_a_desk_point_answers_menu`, `test_unknown_place_code_is_422`, `test_lock_stickers_still_unlock` (existing behaviour unchanged), `test_kiosk_codes_still_start_coworking`.
- [ ] **Steps 2–4:** FAIL → implement → PASS (+ `tests/Feature/Access`, `tests/Feature/Booking` scan tests).
- [ ] **Step 5: Commit** `feat(kitchen): scanning a desk or room code opens the kitchen menu`.

### Task 6: Paying an order — its own payable (D11)

**Files:**
- Create: `app/Domain/Finance/Payments/Fulfillers/KitchenOrderFulfiller.php`, `app/Domain/Kitchen/Services/KitchenOrderSettlement.php`
- Modify: `app/Domain/Finance/Enums/PaymentPurpose.php` (`case KitchenOrder = 'kitchen_order';` + doc line), the `FulfillerRegistry` wiring in `app/Providers/PaymentServiceProvider.php:52`, `app/Domain/Finance/Payments/MemberPayables.php`, `ReceptionPaymentController.php:150` match, `app/Domain/Finance/Payments/OutstandingItems.php` (`owed()` gains `...$this->kitchenOrders($scope)`), `PaymentNotifier.php:130` (`target_type => 'kitchen_order'`), `lang/{ar,en}/api.php` (`api.kitchen_order.description`)
- Test: `tests/Feature/Kitchen/KitchenOrderPaymentTest.php`

**Interfaces:**
- `KitchenOrderFulfiller`: `purpose()` KitchenOrder; `pointsDue()` = `$payment->amount_points ?? $order->total_points`; `walletCategory()` **`General`** (D11; `cafe` stays open question 3); `assertPayable()` — order `status->isOwed()` and `paid_at` null (409 `api.payment.already_paid`); `fulfil()` sets `paid_at` only (stock was drawn at acceptance).
- `KitchenOrderSettlement::unpaidFor(Booking|WalkinSession $bill): Collection<KitchenOrder>` — owed, unpaid, oldest first.
- `KitchenOrderSettlement::settleAtReception(Booking|WalkinSession $bill, PaymentMethod $method, User $operator): void` — each via `PaymentService::settleAtReception(PaymentPurpose::KitchenOrder, $order, $method, null, null, $operator)`.
- `KitchenOrderSettlement::payFromWallet(Booking|WalkinSession $bill): void` — each in its own savepoint via `PaymentService::start(PaymentPurpose::KitchenOrder, $bill->user, WalletMethod::KEY, ['payable'=>$order,'wallet_owner_type'=>OwnerType::User,'wallet_owner_id'=>$bill->user_id])`; a short wallet leaves that order unpaid and logs, like `ClosedBookingSettlement::payFromWallet`.
- `OutstandingItems::kitchenOrders()` lists owed unpaid orders **whose bill is closed** (`checked_out_at` set), item shape like the others with `purpose = kitchen_order`, `payable_id`.

- [ ] **Step 1: Failing tests:** `test_wallet_payment_spends_general_not_space_specific` (member holds 100 space-specific + 50 general; paying a 40-point order leaves space-specific 100, general 10), `test_paying_twice_is_409`, `test_desk_settles_an_order_by_purpose_and_id` (`POST admin/reception/payments/settle {purpose: kitchen_order, payable_id}`), `test_outstanding_items_list_orders_of_closed_bills_only`, `test_pending_or_rejected_orders_are_not_payable`.
- [ ] **Steps 2–4:** FAIL → implement → PASS + `tests/Feature/Finance` + guards.
- [ ] **Step 5: Commit** `feat(kitchen): an accepted order is paid on its own, from general points`.

### Task 7: Orders settle with their bill and show on it

**Files:**
- Modify: `app/Domain/Booking/Services/SessionClosureService.php` (`settlePayment` → after the session settles, `KitchenOrderSettlement::settleAtReception($session, $method, $operator)`; `quote()` adds `orders_points`), `app/Domain/Booking/Services/ClosedBookingSettlement.php` (`settle()`: the early return no longer skips orders — when the booking's `chosen_payment_method` is `wallet`, call `payFromWallet` for orders even if the booking itself is paid/plan-covered), the member pay endpoints `app/Http/Controllers/Api/V1/Member/BookingController.php::pay` and `CoworkingSessionController.php::pay` (after the session payment succeeds, settle its unpaid orders by the same method: wallet → `payFromWallet`; card/Paymera → **out of scope**, the orders stay outstanding and are paid at the desk — note it in the controller docblock), `CoworkingSessionController::checkout` and the session card resource (add `orders: {count, total_points, unpaid_points}`), `SessionReportBuilder` (one line per owed order: `kind: 'order'`, `label`, `points`, `paid`), `CurrentBookingLocator::stateOf()` (`awaiting_payment` also when `KitchenOrderSettlement::unpaidFor($booking)` is non-empty), and the coworking equivalent wherever a closed session's "still owing" is decided (`OutstandingItems::sessions` / home card — grep `PaymentState::Unpaid` in `MemberHomeService`).
- Test: `tests/Feature/Kitchen/OrdersOnTheBillTest.php`

**Interfaces:** Consumes Task 6. Produces nothing new for later tasks.

- [ ] **Step 1: Failing tests:** `test_desk_settlement_pays_session_and_its_orders`, `test_checkout_quote_shows_orders_separately` (`points` unchanged by orders; `orders_points` = sum), `test_report_lists_orders_as_order_lines`, `test_plan_covered_booking_with_unpaid_order_awaits_payment` (`stateOf` → `awaiting_payment`), `test_wallet_paid_booking_close_pays_its_orders`, `test_wallet_short_leaves_orders_outstanding` (Review Focus 5), `test_orders_never_change_price_points_or_amount_owed`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** `php artisan test` (full — this touches the bill) → PASS.
- [ ] **Step 5: Commit** `feat(kitchen): orders settle with the session or booking they are on`.

### Task 8: A closing bill cancels its pending orders

**Files:** Modify `SessionClosureService::finalizeClosure()` (first line: `app(KitchenOrderService::class)->cancelPendingFor($session)`; covers room closures, desk/member/auto/hand-over closes, since every path reaches it), `KitchenOrderService`. Test: `tests/Feature/Kitchen/ClosingCancelsPendingOrdersTest.php`.

**Interfaces:** `KitchenOrderService::cancelPendingFor(Booking|WalkinSession $bill): int` — sets `cancelled`, `cancelled_by = system`, `cancelled_at`; returns the count; member notification is Task 9.

- [ ] **Step 1: Failing tests:** `test_closing_a_session_cancels_its_pending_orders` (Review Focus 3, incl. a later accept → 409), `test_preparing_orders_are_untouched`, `test_midnight_auto_close_cancels_pending_too` (via `autoClose`), `test_member_checkout_of_a_room_cancels_pending`.
- [ ] **Steps 2–4:** FAIL → implement → full suite PASS.
- [ ] **Step 5: Commit** `feat(kitchen): a session that closes takes its unaccepted orders with it`.

### Task 9: Live board and notifications

**Files:**
- Create: `app/Domain/Kitchen/Events/{KitchenUpdate,KitchenOrderChanged}.php` (staff), `app/Domain/Kitchen/Events/MemberKitchenOrderUpdated.php` (extends `MemberUpdate`), `app/Domain/Kitchen/Services/KitchenOrderNotifier.php`
- Modify: `routes/channels.php` (`Broadcast::channel('kitchen', fn (User $u) => $u->can('kitchen-orders.view') && $u->tokenCan('dashboard'))`), `KitchenOrderService` (notify after commit on place, cancel, accept, reject, ready, deliver, system cancel), `lang/{ar,en}/notifications.php`
- Test: `tests/Feature/Kitchen/KitchenRealtimeTest.php` (follow `tests/Feature/Booking/CoworkingRealtimeTest.php`)

**Interfaces:** `KitchenOrderChanged` on `private-kitchen`, `broadcastAs()` = `kitchen_order.created` | `kitchen_order.updated`, payload `{order_id, branch_id, status}` only. `MemberKitchenOrderUpdated` on `private-member.{id}`, `broadcastAs()` `kitchen_order.updated`, payload `{order_id, status}`. Push templates (member, both languages): `kitchen_order.accepted`, `kitchen_order.ready` (sent only for `pickup` orders), `kitchen_order.rejected` (`:reason`), `kitchen_order.delivered`, `kitchen_order.cancelled_by_system`. No staff inbox notice per order (the board is live) — *my call*.

- [ ] **Step 1: Failing tests:** `test_placing_broadcasts_to_the_kitchen`, `test_status_change_reaches_the_member`, `test_ready_pushes_only_for_pickup`, `test_rejection_push_carries_the_reason`, `test_kitchen_channel_needs_kitchen_orders_view`.
- [ ] **Steps 2–4:** FAIL → implement → PASS + guards (`NotificationTemplatesHaveBothLanguagesTest`).
- [ ] **Step 5: Commit** `feat(kitchen): the kitchen board updates live and members hear back`.

### Task 10: Low-stock minimums (D10)

**Files:**
- Package: create `packages/add/inventory/database/migrations/2026_11_10_130000_create_inv_stock_minimums_table.php`, `src/Stock/Models/StockMinimum.php`, `src/Events/StockFellBelowMinimum.php`, controller + request under the package's warehouse HTTP layer; modify `src/Stock/Services/StockService.php` (after each recorded movement), `routes/admin.php`, minimums are gated by the existing `Abilities::WAREHOUSES_VIEW` (read) and `Abilities::WAREHOUSES_MANAGE` (write) — no new ability.
- Host: create `app/Listeners/NotifyWarehouseManagerOfLowStock.php`; modify the event-listener registration, `lang/{ar,en}/notifications.php` (`inventory.low_stock`: `:item`, `:warehouse`, `:on_hand`, `:minimum`).
- Test: `packages/add/inventory/tests/Feature/Stock/StockMinimumTest.php`, `tests/Feature/Kitchen/LowStockNoticeTest.php`

**Interfaces:** table `inv_stock_minimums` (`warehouse_id`, `item_id`, unique pair, `minimum` decimal(14,3), `alerted_at` nullable). `StockFellBelowMinimum(Item $item, Warehouse $warehouse, string $onHand, string $minimum)`. Rule: after a movement at a warehouse place, if on hand < minimum and `alerted_at` is null → set `alerted_at`, dispatch; if on hand ≥ minimum and `alerted_at` set → clear it. Endpoints: `GET/PUT {package admin prefix}/warehouses/{warehouse}/minimums` (`{items: [{item_id, minimum}]}`; minimum 0 removes the row). Host listener → `NotificationService::sendToUser($warehouse->manager, 'inventory.low_stock', …, NotificationAudience::Staff)`; no manager → `sendToHolders(Abilities::WAREHOUSES_MANAGE /* 'inventory.warehouses.manage' */, …)`.

- [ ] **Step 1: Failing tests:** `test_falling_below_minimum_alerts_once`, `test_restocking_above_minimum_rearms`, `test_no_minimum_no_event`, `test_minimum_zero_removes_it`; host `test_manager_gets_the_low_stock_notice`.
- [ ] **Steps 2–4:** FAIL → implement → package tests + `InventoryPackageStaysHostAgnosticTest` + `StockMovesOnlyThroughStockServiceTest` PASS.
- [ ] **Step 5: Commit** `feat(inventory): a store warns its manager when an item falls below its minimum`.

### Task 11: Demo data, API collection, decision record

**Files:** Modify `database/seeders/InventoryDemoSeeder.php` (make the seeded "Café store" the branch's kitchen store; transfer water, cola, chocolate from Main warehouse; minimum 5 each), a seeder for QR points on the demo desks/spaces (or extend the seeder that creates them), `postman/ADD-OS.postman_collection.json` (folders *Member › Kitchen* and *Admin › Kitchen* with every route from Tasks 1–5 and 10, example bodies, no credentials), create `docs/decisions/kitchen-orders.md` (short: D1–D12, link to the spec, what each guard covers). Test: `tests/Feature/Seeders` existing seeder test still passes; add `test_demo_kitchen_has_a_menu`.

- [ ] **Step 1:** Failing `test_demo_kitchen_has_a_menu` (after `DatabaseSeeder`, `KitchenMenu::for(branch)` is non-empty and holds no notebook).
- [ ] **Steps 2–4:** FAIL → implement → `php artisan test` full suite PASS.
- [ ] **Step 5: Commit** `chore(kitchen): demo kitchen, API collection and the decision record`. Tell the dashboard side the collection changed so ADD OS re-pins its snapshot.
