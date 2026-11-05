# Inventory Warehouses Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stock lives in named warehouses (manager, scope of items, sales flag, one primary per branch); items gain barcode, image and pack units; stock operations take several lines in any unit; reception sells from the branch's primary warehouse by default; ADD OS gets the matching screens.

**Architecture:** The `add/inventory` package gains a `Warehouse` model and keeps the stock ledger's two location columns, now always `('warehouse', id)`; `StockService` refuses anything else. Assets keep ADD places through `LocationResolver`. ADD's host code limits a warehouse's place to a branch, migrates existing stock into warehouses, and resolves reception's warehouse. ADD OS reshapes the inventory module's Stock and Items screens and adds a Warehouses screen.

**Tech Stack:** Laravel 12 package (`packages/add/inventory`, Orchestra-style tests via `composer test:inventory`), ADD Core host (PHPUnit, SQLite in-memory), ADD OS (Vue 3, Naive UI, vue-i18n, Vitest).

**Spec:** `docs/superpowers/specs/2026-11-05-inventory-warehouses-design.md`

## Global Constraints

- PHP is Laragon 8.3: `PHP="/c/laragon/bin/php/php-8.3.33-nts-Win32-vs16-x64/php.exe"`. Package tests: `"$PHP" vendor/bin/phpunit -c packages/add/inventory/phpunit.xml`. Host tests: `"$PHP" artisan test <path>`. Style: `"$PHP" vendor/bin/pint --dirty`.
- ADD Core work on branch `feat/inventory-warehouses` off `master`. ADD OS work on branch `feat/inventory-warehouses` off `feat/hr-module` (which holds merge `5cbe1da`).
- Package stays host-agnostic: nothing under `packages/add/inventory` references `App\…`.
- `StockService` is the only writer of `inv_stock_movements` / `inv_stock_balances`, with row locks; the one exception is the Task 6 data migration, exempted by file name in `tests/Guards/StockMovesOnlyThroughStockServiceTest`.
- Update endpoints answer `{"message": …}` (package keys in `packages/add/inventory/lang/{en,ar}/*.php`, ADD keys in `lang/{en,ar}/api.php`); store answers the resource; anything new in a `data` response goes inside `data`.
- Quantities/money leave as plain strings without trailing zeros (`Decimal::trim` / `Decimal::round`), as today.
- Ledger, balances and costs are always in the item's **main unit**. Reception sells by the piece (main unit, whole numbers).
- Item code stays `sku` with the existing config (`ITM-` + 5-digit pad). No new "code", "type", "category text" or "status" fields on items.
- Item image is stored as `image_url` (the URL `media/upload` returns), like ADD's other images.
- Barcode: nullable, unique; generated ones are EAN-13 starting with `2`, valid check digit.
- ADD OS: Carbon icons via `Icon.vue`, never emoji; all copy in `src/add-os/lang/{ar,en}/inventory.json` (or `retail` keys where the page already uses them); new tables size columns with `COLUMN` and scroll at `tableWidth(...)` from `modules/inventory/utils/display.ts`.
- No markdown docs added or edited for this work beyond this plan and the spec.

## Review Focus

1. **Two operators selling the last piece from the same warehouse at once** — one succeeds, the other gets 422 naming the warehouse; the balance never goes negative. (Task 2 test `concurrent_style_issue_cannot_overdraw`: two sequential issues totalling more than on hand, second refused, balance unchanged.)
2. **A pack quantity that converts to a fraction of a non-fractional unit** (1.5 box × 6 = 9 is fine; 0.1 box × 6 = 0.6 piece is not) — refused with the existing `whole_quantity` message. (Task 4 test.)
3. **Narrowing a warehouse's scope, or deactivating it, while it still holds stock** — refused 422, so an item never sits in a warehouse whose scope excludes it. (Task 2 tests.)
4. **Migration on a database where a place with stock history was deleted, or a branch has no stock at all** — every branch still gets its primary warehouse; orphan rows get an "Unknown place #id" warehouse; per-(item, warehouse) balance equals the sum of its movements. (Task 6 test.)
5. **Operator with no open cash register records printing with no `warehouse_id`** — 422 `api.reception.no_primary_warehouse`; with a `warehouse_id` of any active warehouse it succeeds. (Task 7 tests.)

---

## ADD Core — package (`packages/add/inventory`)

### Task 1: Warehouses

**Files:**
- Create: `packages/add/inventory/database/migrations/2026_11_05_100000_create_inv_warehouses_tables.php`
- Create: `packages/add/inventory/src/Warehouses/Models/Warehouse.php`
- Create: `packages/add/inventory/src/Warehouses/Enums/ItemScope.php`
- Create: `packages/add/inventory/src/Warehouses/Services/WarehouseService.php`
- Create: `packages/add/inventory/src/Warehouses/Http/Controllers/WarehouseController.php`
- Create: `packages/add/inventory/src/Warehouses/Http/Requests/WarehouseRequest.php`
- Create: `packages/add/inventory/src/Warehouses/Http/Resources/WarehouseResource.php`
- Create: `packages/add/inventory/database/factories/WarehouseFactory.php`
- Create: `packages/add/inventory/lang/{en,ar}/warehouses.php`
- Modify: `packages/add/inventory/src/Authorization/Abilities.php` (two constants)
- Modify: `packages/add/inventory/routes/admin.php`
- Modify: `packages/add/inventory/config/inventory.php` (`warehouses.place_types`)
- Modify: `packages/add/inventory/src/Support/Presenter.php` (add `warehouse()`)
- Test: `packages/add/inventory/tests/Feature/Warehouses/WarehouseTest.php`

**Interfaces:**
- Produces:
  - Table `inv_warehouses`: `id, name json, code string(40) null unique, manager_id ubigint null, address text null, location_url string(2048) null, notes text null, place_type string(40) null, place_id ubigint null, item_scope string(10) default 'all', allows_sales bool default true, is_primary bool default false, is_active bool default true, timestamps`; index `(place_type, place_id)`.
  - Table `inv_warehouse_items (warehouse_id FK cascade, item_id FK restrict)`, primary key both.
  - `enum ItemScope: string { All = 'all'; Selected = 'selected'; }`
  - `Warehouse::TYPE = 'warehouse'` (the stock location type); `Warehouse::place(): ?Place`; `Warehouse::items(): BelongsToMany`; `Warehouse::allows(Item $item): bool` (scope check); `Warehouse::scopeActive(Builder)`; `Warehouse::asPlace(): Place` (returns `new Place(self::TYPE, $this->id)`).
  - `WarehouseService::create(array $data): Warehouse`, `update(Warehouse $w, array $data): Warehouse`, `delete(Warehouse $w): void` — all under `DB::transaction`; `$data['item_ids']` (list<int>) syncs the pivot when scope is `selected`.
  - `Abilities::WAREHOUSES_VIEW = 'inventory.warehouses.view'`, `Abilities::WAREHOUSES_MANAGE = 'inventory.warehouses.manage'`.
  - Routes (inside the package's admin group, mounted at `/api/v1/inventory`): `apiResource('warehouses')` — index/show `can:inventory.warehouses.view`, store/update/destroy `can:inventory.warehouses.manage`; names `inventory.warehouses.*`.
  - `Presenter::warehouse(?int $id): ?array{id:int, name:array, code:?string, place:?array}` (cached per request like `location()`).
  - Config `inventory.warehouses.place_types` default `null` (any type the resolver knows); a list limits it.

- [ ] **Step 1: Write the failing tests** in `WarehouseTest` (use `BuildsInventory`, an admin user with every `Abilities::all()` like the existing feature tests):

```php
public function test_store_creates_a_warehouse_and_returns_it(): void
// POST inventory/warehouses {name:{en:'Kitchen',ar:'المطبخ'}, manager_id, address, location_url, notes, place:{type,id}, item_scope:'all', allows_sales:true}
// assertCreated(); assertJsonPath('data.name.en','Kitchen'); assertJsonPath('data.item_scope','all'); assertJsonPath('data.manager.id', $manager->id)

public function test_selected_scope_requires_items_and_only_stock_items(): void
// item_scope 'selected' with item_ids [] → 422 on item_ids; with an asset item id → 422; with a stock item → 201 and data.items[0].id

public function test_making_one_primary_clears_the_other_primary_of_the_same_place(): void
// two warehouses at the same place, second created with is_primary:true → first refresh()->is_primary false

public function test_primary_needs_a_place_and_sales(): void
// is_primary:true with place null → 422; with allows_sales:false → 422

public function test_place_type_outside_config_is_refused(): void
// config(['inventory.warehouses.place_types' => ['branch_like_type']]) then a place of OwnLocationResolver::TYPE → 422 on place

public function test_empty_warehouse_can_be_deactivated_and_deleted(): void
// PATCH is_active:false → 200 message; DELETE → 204

public function test_index_filters_by_search_status_and_manager(): void
// ?search=Kit, ?active=1, ?manager_id=
```

The rules that need stock in a warehouse (deactivate/delete/narrow refused, `items_with_balance`) are tested in Task 2, once `StockService` accepts warehouses; the service code for them is written here.

- [ ] **Step 2: Run to verify they fail**

Run: `"$PHP" vendor/bin/phpunit -c packages/add/inventory/phpunit.xml --filter WarehouseTest`
Expected: FAIL (route / table missing).

- [ ] **Step 3: Implement** the migration, model, enum, factory, request, resource, controller, service, routes, abilities, config, Presenter method and lang files listed above.
  - `WarehouseRequest` rules: `name` via `TranslatableField::rules('name')`; `code` nullable max:40 unique (ignore self on update); `manager_id` nullable exists on the user model's table (`(new (Inventory::userModel()))->getTable()`); `address`/`notes` nullable max:2000; `location_url` nullable max:2048; `place` nullable array with `type`/`id`, type in `config('inventory.warehouses.place_types') ?? Inventory::locations()->types()`, existence through `Inventory::locations()->exists()`; `item_scope` in enum; `item_ids` array required_if selected, each exists in `inv_items` and is a stock item; booleans `allows_sales`, `is_primary`, `is_active`. On update every key is `sometimes`.
  - `WarehouseService` enforces: primary ⇒ place set, sales allowed, active; setting primary clears `is_primary` on other warehouses with the same `place_type/place_id` (lock them `lockForUpdate`); deactivate/delete refused when any `StockBalance` with `location_type='warehouse'`, `location_id=id`, `quantity > 0`; delete refused when any `StockMovement` there; narrowing refused when an item outside the new list has a positive balance there. Failures use `InventoryException::fail('inventory::warehouses.<key>')`.
  - `WarehouseResource`: `id, name, code, manager (Presenter::actor), address, location_url, notes, place (Presenter::location or null), item_scope, items (whenLoaded: id, sku, name), allows_sales, is_primary, is_active, items_with_balance (when counted), created_at`.
  - Index: search on `code`, `address`, `name` (like); filters `active`, `manager_id`, `place[type]`+`place[id]`, `allows_sales`; `withCount` of positive balances as `items_with_balance`; paginated `per_page` ≤ 100, default 25; order `is_primary desc, id`.
  - Update returns `['message' => __('inventory::warehouses.updated')]`; destroy `204`.

- [ ] **Step 4: Run tests**

Run: `"$PHP" vendor/bin/phpunit -c packages/add/inventory/phpunit.xml --filter WarehouseTest` and `tests/Unit` of the package (`AbilitiesTest`, `MigrationIdentifiersFitMysqlTest`, `ModelsDeclareFillableTest`).
Expected: PASS.

- [ ] **Step 5: Commit** — `feat(inventory): warehouses with a manager, an item scope, a sales flag and one primary per place`

### Task 2: Stock lives only in warehouses

**Files:**
- Modify: `packages/add/inventory/src/Stock/Services/StockService.php`
- Modify: `packages/add/inventory/src/Stock/Http/Resources/StockBalanceResource.php`, `StockMovementResource.php`
- Modify: `packages/add/inventory/src/Stock/Http/Controllers/StockController.php` (filters only; write actions are replaced in Task 4)
- Modify: `packages/add/inventory/src/Reports/Http/Controllers/ReportController.php` (`stockValuation`)
- Modify: `packages/add/inventory/src/Stock/Models/StockBalance.php`, `StockMovement.php` (`warehouse(): BelongsTo` via `location_id` constrained by type)
- Modify: `packages/add/inventory/tests/Concerns/BuildsInventory.php` (add `warehouse()`), existing `tests/Feature/Stock/StockTest.php`, `tests/Feature/Reports/ReportsTest.php`
- Modify: `packages/add/inventory/lang/{en,ar}/stock.php`

**Interfaces:**
- Consumes: `Warehouse`, `Warehouse::TYPE`, `Warehouse::allows()`, `Presenter::warehouse()` (Task 1).
- Produces:
  - `StockService` public signatures gain a final parameter `array $meta = []` (keys allowed: `operation_id`, `entered_unit_id`, `entered_quantity`; anything else ignored) on `receive`, `issue`, `transfer`, `adjust`; `$at`/`$from`/`$to` remain `Place` but must be `Warehouse::TYPE`. `onHand(Item, Place): string` unchanged.
  - `ensureLocation(Place $at, ?Item $item, bool $incoming)`: refuses a non-warehouse type (`inventory::stock.not_a_warehouse`), a missing or inactive warehouse (`inventory::stock.warehouse_inactive`), and on incoming quantities (receipt, transfer in, positive adjustment) an item outside scope (`inventory::stock.outside_scope`, `:warehouse` param = its name).
  - `InsufficientStockException::for(...)` message names the warehouse: new param `?string $warehouse`.
  - Balance/movement resources: key `location` replaced by `warehouse` (`Presenter::warehouse`).
  - `StockController::balances|movements` filters: `warehouse_id`; `place[type]`+`place[id]` = warehouses whose place is within that place (`Inventory::locations()->within()`); movements also `actor_id`, `operation_id`. `location`/`within` filters removed.
  - `reports/stock-valuation`: rows keyed `warehouses` (each `warehouse`, `items`, `value`), filters `warehouse_id` or `place`.
  - `BuildsInventory::warehouse(array $attributes = []): Warehouse` and `BuildsInventory::in(Warehouse $w): Place`.

- [ ] **Step 1: Write / change the failing tests** in `StockTest`:

```php
public function test_stock_cannot_be_received_on_a_plain_place(): void
// $stock->receive($item, $this->place(), 1) → InventoryException message __('inventory::stock.not_a_warehouse')

public function test_inactive_warehouse_takes_no_movement(): void

public function test_incoming_stock_outside_scope_is_refused_but_outgoing_is_allowed(): void
// selected-scope warehouse with [A]; receive B → outside_scope; receive A 3, issue A 1 → ok

public function test_concurrent_style_issue_cannot_overdraw(): void
// receive 2; issue 1 ok; issue 2 → InsufficientStockException whose message contains the warehouse name; onHand === '1'

public function test_balances_filter_by_warehouse_and_by_place(): void
public function test_movements_filter_by_actor_and_operation(): void
public function test_resources_show_the_warehouse(): void
// GET stock/balances → data[0].warehouse.id, no 'location' key

// in WarehouseTest:
public function test_cannot_deactivate_or_delete_while_holding_stock(): void
// receive 5 → PATCH is_active:false 422; DELETE 422
public function test_cannot_delete_with_history(): void
// receive 5, issue 5 → DELETE 422; PATCH is_active:false 200
public function test_narrowing_scope_refused_while_a_removed_item_holds_stock(): void
// all-scope warehouse holding A; PATCH item_scope:'selected', item_ids:[B] → 422
public function test_index_counts_items_with_balance(): void
// data[0].items_with_balance === 1
```

Rewrite every existing `StockTest`/`ReportsTest` call that passes `$this->place()` to pass `$this->in($this->warehouse())`.

- [ ] **Step 2: Run to verify they fail** — `--filter "StockTest|ReportsTest|WarehouseTest"` → FAIL.
- [ ] **Step 3: Implement** the changes listed in Interfaces. `record()` merges whitelisted `$meta` into the row (columns added in Task 4; until then only `operation_id` — add the `operation_id uuid null index` column to `inv_stock_movements` in this task's migration `2026_11_05_100100_add_operation_columns_to_inv_stock_movements.php`, together with `entered_unit_id` FK null to `inv_units` restrict and `entered_quantity decimal(14,3) null`).
- [ ] **Step 4: Run** the package suite: `"$PHP" vendor/bin/phpunit -c packages/add/inventory/phpunit.xml` → PASS. (ADD host tests that put stock on places — `tests/Feature/Inventory/InventoryIntegrationTest`, the demo seeder test, reception sale/consumption tests — go red here by design and are brought back by Tasks 5–7; do not patch them in this task.)
- [ ] **Step 5: Commit** — `feat(inventory): stock is counted only in warehouses, inside each one's item scope`

### Task 3: Items — barcode, image, other units

**Files:**
- Create: `packages/add/inventory/database/migrations/2026_11_05_100200_add_barcode_image_units_to_inv_items.php`
- Create: `packages/add/inventory/src/Catalog/Models/ItemUnit.php`
- Create: `packages/add/inventory/src/Catalog/Services/BarcodeGenerator.php`
- Modify: `Item.php` (fillable `barcode`, `image_url`; `otherUnits(): HasMany` of `ItemUnit`; `factorFor(int $unitId): string`)
- Modify: `CatalogService.php` (`createItem`/`updateItem` sync `units`), `StoreItemRequest.php`, `UpdateItemRequest.php`, `ItemResource.php`, `ItemController.php`
- Modify: `routes/admin.php`, `lang/{en,ar}/catalog.php`
- Test: `packages/add/inventory/tests/Feature/Catalog/ItemUnitsAndBarcodeTest.php`, `packages/add/inventory/tests/Unit/BarcodeGeneratorTest.php`

**Interfaces:**
- Produces:
  - Columns on `inv_items`: `barcode string(32) null unique`, `image_url string(2048) null`. Table `inv_item_units (id, item_id FK cascade, unit_id FK restrict, factor decimal(14,4), timestamps, unique(item_id, unit_id))`.
  - `Item::factorFor(int $unitId): string` — `'1'` for the main unit, the stored factor for another unit; throws `InventoryException` (`inventory::catalog.unit_not_of_item`) otherwise.
  - `BarcodeGenerator::next(): string` — `'2'` + 11 random digits + EAN-13 check digit, retried until unused in `inv_items.barcode`. `BarcodeGenerator::checkDigit(string $twelve): int`.
  - Item payload `units`: `list<{unit_id:int, factor:number}>`, replaces the item's other units whole when present. Refused: the main unit (`unit_is_main`), factor ≤ 0, duplicate unit, and removing or changing the factor of a unit any movement has as `entered_unit_id` (`unit_in_use`).
  - Routes: `GET items/barcode/{barcode}` (`can:inventory.catalog.view`, name `inventory.items.barcode`) → `ItemResource` or 404; `POST items/barcodes` (`can:inventory.catalog.manage`, name `inventory.items.barcodes`) → `{"data":{"barcode":"2…"}}` (201).
  - `ItemResource` adds `barcode`, `image_url`, `units` (`[{unit: UnitResource, factor}]`, loaded with the item).
  - `ItemController::index` `search` also matches `barcode`.

- [ ] **Step 1: Write the failing tests**

```php
// BarcodeGeneratorTest
public function test_check_digit_matches_a_known_ean13(): void
// checkDigit('400638133393') === 1
public function test_next_is_13_digits_starting_with_2_and_valid(): void

// ItemUnitsAndBarcodeTest
public function test_store_with_units_barcode_and_image(): void
// units [{unit_id: box, factor: 6}], barcode '2000000000015' (valid), image_url 'https://x/y.png' → data.units[0].factor === '6', data.barcode, data.image_url
public function test_main_unit_cannot_be_another_unit(): void
public function test_duplicate_barcode_is_refused(): void
public function test_lookup_by_barcode(): void            // GET items/barcode/{code} → data.id; unknown → 404
public function test_generate_returns_an_unused_barcode(): void
public function test_unit_used_by_a_movement_is_locked(): void
// receive with meta entered_unit_id = box, then PATCH units [] → 422 unit_in_use; PATCH factor 12 → 422
public function test_search_matches_barcode(): void
```

- [ ] **Step 2: Run** `--filter "ItemUnitsAndBarcodeTest|BarcodeGeneratorTest"` → FAIL.
- [ ] **Step 3: Implement** as listed. `factor` is stored and returned via `PlainDecimal:4`.
- [ ] **Step 4: Run** the package suite → PASS.
- [ ] **Step 5: Commit** — `feat(inventory): items carry a barcode, an image and packs counted in their main unit`

### Task 4: Stock operations with several lines and any unit

**Files:**
- Create: `packages/add/inventory/src/Stock/Services/StockOperations.php`
- Create: `packages/add/inventory/src/Stock/Http/Requests/StockOperationRequest.php` (replaces `StockRequest.php`, delete it)
- Modify: `StockController.php` (`receive`, `issue`, `transfer`, `adjust` bodies), `StockMovement.php` (fillable/casts for new columns), `StockMovementResource.php`
- Modify: `lang/{en,ar}/stock.php`
- Test: `packages/add/inventory/tests/Feature/Stock/StockOperationsTest.php`

**Interfaces:**
- Consumes: `StockService` with `$meta` (Task 2), `Item::factorFor()` (Task 3), `Warehouse` (Task 1).
- Produces:
  - `StockOperations::receive(Warehouse $at, array $lines, ?string $note): Collection<StockMovement>`; `issue(Warehouse $at, array $lines, IssueReason $reason, ?string $note)`; `transfer(Warehouse $from, Warehouse $to, array $lines, ?string $note)`; `adjust(Warehouse $at, array $lines, ?string $note)` — each one `DB::transaction`, one `operation_id` (uuid) for all rows, lines processed in item-id order (stable lock order).
  - Line shape: `{item_id:int, unit_id?:int (default main), quantity:number}` (adjust: `counted` instead of `quantity`; receive/adjust optional `unit_cost` per entered unit). Conversion: `main = quantity × factor`; `unit_cost_main = unit_cost ÷ factor` (4 dp). `meta = [operation_id, entered_unit_id, entered_quantity]` (entered_* only when the unit is not the main unit).
  - Endpoints (same names/abilities as today): `POST stock/receive|issue|transfer|adjust` with body `{warehouse_id}` (`from_warehouse_id`, `to_warehouse_id` for transfer), `lines` (1–100), `reason` (issue: `IssueReason::manual()` values), `note`. Response 201 `{"data": [movements…]}` — adjust with every line matching the book answers 200 `{"message": __('inventory::stock.count_matched')}`.
  - `StockMovementResource` adds `operation_id`, `entered: {unit:{id,code,name}, quantity} | null`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_receive_several_lines_shares_one_operation(): void
// 2 lines → 2 rows, same operation_id, balances updated
public function test_receive_in_packs_converts_to_main_unit_and_cost(): void
// box factor 6, quantity 2, unit_cost 12 → quantity '12', unit_cost '2', entered.quantity '2', entered.unit.id box
public function test_fractional_pack_into_whole_unit_is_refused(): void
// 0.1 box (factor 6) of a non-fractional piece → 422 whole_quantity; 1.5 box → 9 ok
public function test_one_failing_line_rolls_back_the_operation(): void
// line 2 overdraws → 422; line 1's balance unchanged, no rows
public function test_transfer_lines_between_warehouses(): void
// transfer_out/transfer_in share transfer_id per line and one operation_id across lines
public function test_adjust_lines_record_only_differences(): void
public function test_unit_not_of_item_is_refused(): void
```

- [ ] **Step 2: Run** `--filter StockOperationsTest` → FAIL.
- [ ] **Step 3: Implement.** Rewrite the remaining single-line `StockTest` HTTP calls to the `lines` shape.
- [ ] **Step 4: Run** the package suite → PASS.
- [ ] **Step 5: Commit** — `feat(inventory): a stock operation takes several lines, each in any of its item's units`

## ADD Core — host (`app/`, `database/`, `routes/`, `lang/`)

### Task 5: Host wiring — branch-only places, permissions, guard

**Files:**
- Modify: `config/inventory.php` (`'warehouses' => ['place_types' => [AddLocationResolver::BRANCH]]`)
- Modify: `app/Domain/Inventory/PlaceInventoryGuard.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/Concerns/RefusesDeletingPlacesWithInventory.php` (branch: refuse when any warehouse sits there, `api.admin.branch_has_warehouses`)
- Modify: `lang/{en,ar}/api.php`
- Test: `tests/Feature/Inventory/PlaceDeletionKeepsInventoryTest.php` (update), `tests/Feature/Inventory/WarehouseHostTest.php` (new)

**Interfaces:**
- Produces: `PlaceInventoryGuard::holdsInventory(Place)` counts live assets only; `PlaceInventoryGuard::hasWarehouses(Place $branch): bool`.
- Permissions `inventory.warehouses.view|manage` reach ADD through `InventoryPermissionSource` (no change needed); `permissions:sync` creates them.

- [ ] **Step 1: Failing tests:** `test_warehouse_place_must_be_a_branch` (space place → 422), `test_branch_with_a_warehouse_cannot_be_deleted` (422 with the new key), `test_space_delete_no_longer_checks_stock` (a space id never holds stock now — assets still refuse), `test_admin_holds_the_warehouse_permissions` (after `permissions:sync` in setUp, admin can `GET api/v1/inventory/warehouses`).
- [ ] **Step 2: Run** `"$PHP" artisan test tests/Feature/Inventory` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `tests/Feature/Inventory` and `--testsuite=Guards` → PASS except the stock-writes guard, which only Task 6 touches.
- [ ] **Step 5: Commit** — `feat(inventory): ADD's warehouses sit in a branch, and a branch keeps its warehouses`

### Task 6: Migrate existing stock into warehouses

**Files:**
- Create: `database/migrations/2026_11_05_200000_move_stock_into_warehouses.php`
- Modify: `tests/Guards/StockMovesOnlyThroughStockServiceTest.php` (exempt exactly that file, with a comment: re-points rows, writes no quantity)
- Modify: `app/Http/Resources/Retail/ItemSaleResource.php` (`stock_location` → `warehouse`), `app/Domain/Retail/Models/ItemSale.php` (`warehouse()` helper; `location()` stays for the fulfiller)
- Modify: `database/seeders/InventoryDemoSeeder.php` — creates through `WarehouseService` a primary "Main warehouse" in the demo branch, a "Store room" and a "Café" warehouse (sales on) in it, and receives into those (`$w['reception']`, `$w['store']`, `$w['cafe']` replace `$p[...]` for stock calls; asset places unchanged)
- Test: `tests/Feature/Inventory/MoveStockIntoWarehousesMigrationTest.php`, `tests/Feature/Inventory/InventoryDemoSeederTest.php` (update its stock assertions to the warehouses)

**Interfaces:**
- Produces: after `up()`, every `inv_stock_movements`, `inv_stock_balances` and `item_sales` row has `location_type = 'warehouse'`. Every branch has exactly one primary warehouse named `{"en": "Main warehouse – {branch en}", "ar": "المستودع الرئيسي – {branch ar}"}`, place = the branch, scope all, sales on, active.
- Rules (from the spec): branch-level rows → that branch's primary; rows on a building/floor/zone/space → one warehouse per place, named after `AddLocationResolver::describe()` label, place = its branch, scope all, **sales off**, not primary, **active iff** any positive balance; rows on a place that no longer exists → `"Unknown place #{type}:{id}"`, no place, inactive unless a positive balance. Only `location_type`/`location_id` change; nothing else on any row. `down()` throws (one-way; DB backup is the rollback).
- Done with `DB::table(...)` updates in chunks inside one transaction.

- [ ] **Step 1: Failing test:** build a branch with a building and a space; write ledger + balance rows for the branch, the space and a deleted space id **directly via the pre-migration shape** (run the migration's `down` is not possible — instead create rows with `DB::table` inside the test, which the guard does not scan since it scans `app/`, `database/` and the package only), plus an `item_sales` row; run `(require migration)->up()`; assert:
  - branch rows → primary warehouse; space rows → warehouse "label", sales off, active; deleted-place rows → "Unknown place #space:{id}";
  - a second branch with no stock still has its primary warehouse;
  - for each (item, warehouse) `sum(movements.quantity) === balance.quantity`, and every movement's `quantity`, `unit_cost`, `created_at`, `actor_id`, `reason`, `source_*` equal their pre-migration values;
  - `item_sales` row re-pointed.
- [ ] **Step 2: Run** `"$PHP" artisan test tests/Feature/Inventory/MoveStockIntoWarehousesMigrationTest.php` → FAIL.
- [ ] **Step 3: Implement** the migration, the guard exemption and `ItemSaleResource` change (`'warehouse' => app(Presenter::class)->warehouse($this->location_id)`).
- [ ] **Step 4: Run** `tests/Feature/Inventory`, `tests/Feature/Retail` (if present) and `--testsuite=Guards` → PASS.
- [ ] **Step 5: Commit** — `feat(inventory): existing stock moves into warehouses with its whole history`

### Task 7: Reception takes stock from a warehouse

**Files:**
- Create: `app/Domain/Inventory/ReceptionWarehouse.php` (replaces `ReceptionStockLocation.php`, delete it)
- Create: `app/Http/Controllers/Api/V1/Admin/Reception/ReceptionWarehouseController.php`
- Modify: `app/Http/Requests/Admin/Reception/StoreItemSaleRequest.php`, `StoreResourceConsumptionRequest.php` (`stock_location` → `warehouse_id` nullable exists `inv_warehouses`)
- Modify: `app/Http/Controllers/Api/V1/Admin/Reception/ItemSaleController.php`, `ResourceConsumptionController.php`
- Modify: `app/Http/Resources/Retail/SaleItemResource.php` (item gains `barcode`, `image_url`)
- Modify: `routes/api/v1/admin.php` (`GET reception/warehouses`, `permission:item-sales.create|reception.record_consumption`)
- Modify: `lang/{en,ar}/api.php`
- Test: `tests/Feature/Inventory/ReceptionWarehouseTest.php`; update existing reception item-sale / consumption tests that sent `stock_location`

**Interfaces:**
- Consumes: `Warehouse`, `CashRegisterService::currentSession(User)?->branch_id`.
- Produces:
  - `ReceptionWarehouse::resolve(?int $warehouseId, User $operator, bool $forSale): Warehouse` — throws `ReceptionActionException` with keys `api.reception.no_primary_warehouse` (no id and no primary for the register's branch, or no register), `api.reception.warehouse_unavailable` (inactive / not found), `api.reception.warehouse_not_for_sale` (`$forSale` and sales off), `api.reception.warehouse_other_branch` (register open and the warehouse's place is not that branch).
  - `ReceptionWarehouse::options(User $operator): Collection<Warehouse>` — active warehouses of the register's branch, or every active warehouse when no register is open; primary first.
  - `GET reception/warehouses` → `{"data": [{id, name, code, allows_sales, is_primary}]}`.
  - The callers pass `$warehouse->asPlace()` where they passed a `Place` before; `ItemSaleService::sell()` signature unchanged.
  - Shortage keeps today's 422 path; its message now names the warehouse (Task 2).

- [ ] **Step 1: Failing tests:**

```php
test_sale_without_warehouse_uses_the_registers_branch_primary
test_sale_from_another_sellable_warehouse_of_the_branch
test_sale_from_a_warehouse_with_sales_off_is_refused          // warehouse_not_for_sale
test_sale_from_another_branchs_warehouse_is_refused           // warehouse_other_branch
test_no_register_and_no_warehouse_is_refused                  // no_primary_warehouse
test_consumption_without_register_from_a_named_warehouse_works // any active, sales off allowed
test_shortage_names_the_warehouse_and_takes_nothing_elsewhere  // second warehouse has stock, untouched
test_options_list_the_branchs_active_warehouses_primary_first
test_sale_items_carry_the_item_barcode
```

- [ ] **Step 2: Run** `"$PHP" artisan test tests/Feature/Inventory/ReceptionWarehouseTest.php` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `tests/Feature/Inventory`, the reception tests touched (`grep -rl "stock_location\|item-sales\|resource-consumptions" tests`), `--testsuite=Guards`, `"$PHP" vendor/bin/pint --dirty` → PASS / clean.
- [ ] **Step 5: Commit** — `feat(reception): sales and consumption take stock from a warehouse — the branch's primary unless another is named`

## ADD OS (`ADDOS/src/add-os`)

### Task 8: Types and services

**Files:**
- Modify: `modules/inventory/types/inventory.ts`, `services/inventory.ts`, `services/__tests__/inventory.spec.ts`
- Modify: `modules/plans/types/retail.ts`, `services/retail.ts`
- Modify: `modules/system/config/known-permissions.ts` (`inventory.warehouses.view`, `inventory.warehouses.manage`)

**Interfaces:**
- Produces (TypeScript):
  - `interface Warehouse { id; name: Bilingual; code: string | null; manager: { id: number; name: string | null } | null; address; location_url; notes; place: PlaceDescription | null; item_scope: "all" | "selected"; items?: { id; sku; name: Bilingual }[]; allows_sales; is_primary; is_active; items_with_balance?: number }`
  - `WarehousePayload` (write shape incl. `item_ids?: number[]`, `place?: PlaceRef | null`).
  - `listWarehouses(filters: { page?; search?; active?: boolean | null; manager_id?: number | null }): Promise<Paginated<Warehouse>>`, `getWarehouse(id)`, `createWarehouse(payload)`, `updateWarehouse(id, payload): Promise<MessageResult>`, `removeWarehouse(id)`.
  - `type StockOperationKind = "receive" | "issue" | "writeOff" | "adjust" | "transfer"` (writeOff posts to `stock/issue` with reason `damaged`/`expired`).
  - `interface StockLine { item_id: number; unit_id?: number | null; quantity?: number; counted?: number; unit_cost?: number | null }`
  - `submitStockOperation(kind: StockOperationKind, payload: { warehouse_id?; from_warehouse_id?; to_warehouse_id?; lines: StockLine[]; reason?: IssueReason; note?: string | null }): Promise<StockMovement[] | MessageResult>`
  - `findItemByBarcode(code: string): Promise<Item | null>` (404 → null), `generateBarcode(): Promise<string>`
  - `Item` gains `barcode`, `image_url`, `units: { unit: Unit; factor: string }[]`; `ItemPayload` gains `barcode?`, `image_url?`, `units?: { unit_id: number; factor: number }[]`.
  - `StockBalance`/`StockMovement`: `location` → `warehouse: WarehouseRef` (`{ id; name; code; place }`); movement gains `operation_id`, `entered`.
  - `listBalances` / `listStockMovements` filters: `warehouse_id`, `place`, `actor_id`, `operation_id` (drop `location`/`within`).
  - `listReceptionWarehouses(): Promise<{ id; name; code; allows_sales; is_primary }[]>` in `services/retail.ts`; `ItemSalePayload.stock_location` → `warehouse_id?: number | null`; `ItemSale.stock_location` → `warehouse`; `SaleItem.item` gains `barcode`, `image_url`.

- [ ] **Step 1: Failing spec tests** in `inventory.spec.ts` (same mocking style as the file): `listWarehouses` sends `search`/`active`/`manager_id`; `submitStockOperation("writeOff", …)` posts `inventory/stock/issue` with the given reason; `submitStockOperation("transfer", …)` posts `from_warehouse_id`/`to_warehouse_id`; `findItemByBarcode` returns null on 404; `generateBarcode` returns `data.barcode`.
- [ ] **Step 2: Run** `npx vitest run --dir src src/add-os/services/__tests__/inventory.spec.ts` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same → PASS.
- [ ] **Step 5: Commit** — `feat(inventory): warehouse, stock-operation and barcode services`

### Task 9: Warehouses screen

**Files:**
- Create: `modules/inventory/views/WarehousesPage.vue`, `modules/inventory/components/WarehouseModal.vue`, `modules/inventory/components/WarehouseDrawer.vue`, `modules/inventory/components/StaffSelect.vue`
- Modify: `navigation/sections.ts` (child `{ key: "inventoryWarehouses", path: "warehouses", permission: "inventory.warehouses.view" }` placed before `inventoryStock`), `navigation/routes.ts`, `lang/{ar,en}/inventory.json` (incl. nav label), `lang/{ar,en}` nav keys where the section's labels live
- Test: `modules/inventory/components/__tests__/WarehouseModal.spec.ts`

**Interfaces:**
- Consumes: Task 8 services; `ItemSelect` (multi, `kind="stock"`) for selected items; `listUsers()` for `StaffSelect` (users holding at least one permission — filter on what `User` exposes; "no manager" option).
- Page: header (title, subtitle, "Add warehouse" when `inventory.warehouses.manage`), filters (search, status, manager), table columns — name (+ primary tag, "all items"/"N items" sub-line) `COLUMN.name`, manager `COLUMN.person`, location (address or place) `COLUMN.place`, items with balance `COLUMN.count`, status `COLUMN.status`, actions `COLUMN.actions` (view, edit, activate/deactivate, delete — shown by permission; server 422 messages surfaced with `apiErrorMessage`).
- Modal fields in the reference's order: name*, manager, address, location link, notes, branch (`PlacePicker` limited to branch, or a branch select), primary switch (disabled without branch), allowed items radio (all / selected + item search by name/code/barcode), allow-sales checkbox.
- Drawer: warehouse facts + balances (`listBalances({ warehouse_id })`) + recent movements (`listStockMovements({ warehouse_id })`).

- [ ] **Step 1: Failing spec:** modal sends `item_scope: "selected"` with `item_ids` only when selected; primary switch disabled with no branch; submitting calls `createWarehouse` with `allows_sales`.
- [ ] **Step 2: Run** `npx vitest run --dir src src/add-os/modules/inventory` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the module specs and `npx vitest run --dir src src/add-os/navigation src/add-os/modules/system` (menu/permission guards) → PASS (baseline `menu-matches-guard` failure on `dashboard.myNotifications` excepted).
- [ ] **Step 5: Commit** — `feat(inventory): warehouses screen — list, add/edit dialog, details`

### Task 10: Stock movements screen

**Files:**
- Modify: `modules/inventory/views/StockPage.vue` (becomes "Stock movements"; keeps the restyled cards/filters layout)
- Create: `modules/inventory/components/StockOperationPicker.vue`, `modules/inventory/components/StockOperationModal.vue`, `modules/inventory/components/StockLinesEditor.vue`
- Delete: `modules/inventory/components/StockActionModal.vue` (replaced)
- Modify: `lang/{ar,en}/inventory.json`, nav label of `inventoryStock`
- Test: `modules/inventory/components/__tests__/StockLinesEditor.spec.ts`

**Interfaces:**
- Consumes: Task 8 services; `ItemSelect`; `findItemByBarcode`.
- Picker: five cards with title + one-line description, as the reference: transfer, adjust, write-off, manual receipt, manual issue — each shown only with its ability (`inventory.stock.transfer|adjust|issue|receive|issue`).
- Modal: warehouse select(s) (active only; transfer needs two different), lines editor, reason (issue: internal use/other; write-off: damaged/expired), note.
- Lines editor: barcode input (Enter → `findItemByBarcode` → add line or show "not found"), item search, unit select (main + `item.units`, label "box (6)"), quantity / counted (adjust shows book qty from `listBalances({ warehouse_id, item_id })` and the ± difference), unit cost (receive, per chosen unit); remove line; at least one line.
- Lists: balances tab filtered by warehouse; movements tab filters warehouse, type, period (`from`/`to`), user, item; columns use `COLUMN`; quantity cell shows `entered` when present ("2 box · 12 piece"); clicking a row filters by its `operation_id`.

- [ ] **Step 1: Failing spec:** scanning a known barcode adds a line with the main unit; choosing a pack unit keeps `unit_id`; adjust line sends `counted`, not `quantity`; transfer with the same warehouse twice blocks submit.
- [ ] **Step 2: Run** module specs → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** module specs → PASS.
- [ ] **Step 5: Commit** — `feat(inventory): stock movements by warehouse — five operation kinds, several lines, scan and packs`

### Task 11: Item form

**Files:**
- Modify: `modules/inventory/components/ItemModal.vue`, `modules/inventory/views/ItemsPage.vue` (image thumb + barcode in the name cell, search hint "name, code or barcode")
- Create: `modules/inventory/components/ItemUnitsEditor.vue`
- Modify: `lang/{ar,en}/inventory.json`
- Test: `modules/inventory/components/__tests__/ItemUnitsEditor.spec.ts`

**Interfaces:**
- Consumes: `generateBarcode`, `services/media.ts` upload (existing), `listUnits`.
- Form, in the reference's grouping: basic data (image upload → `image_url`, name*, code shown read-only after save with "generated automatically" hint, barcode + "Generate", category, status, main unit*) and "Other units" panel (add unit + factor, hint "the factor is how many main units are inside this unit — e.g. box = 6"; main unit not offered; a unit the server refuses with `unit_in_use` shows the server message).

- [ ] **Step 1: Failing spec:** editor excludes the main unit from choices; emits `[{ unit_id, factor }]`; a factor ≤ 0 marks the row invalid.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `feat(inventory): item form — image, barcode with generate, and other units`

### Task 12: Reception sale — warehouse and scan

**Files:**
- Modify: `modules/plans/views/ItemSalesPage.vue` (sell form: `PlacePicker` for stock location → warehouse select from `listReceptionWarehouses()` with only `allows_sales` options, primary preselected, empty = server default; barcode input matching `SaleItem.item.barcode` → add line or "not for sale"; sales table column `stock_location` → `warehouse` with `COLUMN.place`)
- Modify: `lang/{ar,en}` retail keys used by the page
- Test: `modules/plans/views/__tests__/ItemSalesPage.spec.ts` (create if absent)

- [ ] **Step 1: Failing spec:** the sell payload carries `warehouse_id` of the chosen warehouse and no `stock_location`; a scanned barcode of an active sale item adds a line of quantity 1, scanning it again increments it.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** — `feat(retail): reception sells from a warehouse and adds lines by barcode`

### Task 13: Final verification

- [ ] ADD Core: `"$PHP" vendor/bin/phpunit -c packages/add/inventory/phpunit.xml` → all pass.
- [ ] ADD Core: `"$PHP" artisan test --testsuite=Guards` and `"$PHP" artisan test tests/Feature/Inventory` → pass; then the full suite once, failures compared to the baseline in memory `pre-existing-sqlite-audit-log-failures` (expected ≈11 known names, nothing in Inventory/Retail/Reception).
- [ ] ADD Core: `"$PHP" vendor/bin/pint --test` clean on changed files; `"$PHP" artisan route:list --path=inventory/warehouses` shows the 5 routes with their `can:` middleware.
- [ ] ADD OS: `NODE_OPTIONS=--max-old-space-size=6144 npx vue-tsc --build --force` → only the 3 baseline plans-test errors; `npx vitest run --dir src src/add-os/modules/inventory src/add-os/modules/plans src/add-os/services src/add-os/navigation` → pass except the baseline `menu-matches-guard`; `npx eslint` on changed files clean.
- [ ] Live check against the user's dashboard (http://127.0.0.1:5173, local ADD Core on 127.0.0.1:8000, after `migrate` + `optimize:clear` + `permissions:sync` on a backed-up local DB): add a warehouse, receive 2 boxes, transfer 3 pieces, sell 1 at reception, see balances per warehouse; screenshot the warehouses list and the movement picker.
- [ ] One whole-branch review (code-reviewer agent) on each repo; fix findings; no merge, no push without the user.
