# Inventory Serials per Unit and Page Filters — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Take one serial per unit when registering several assets at once, and give every inventory list page place (branch…) and detail filters kept in the URL.

**Architecture:** Backend: a `serial_numbers` list per registration line, validated by one shared trait and handed to `AssetService::register()`; new filters added to each controller's existing `when()` chain using the `place[type]`+`place[id]` shape already used elsewhere, through one `PlaceFilter` helper. Dashboard: a `placeFilter` util that stores the place picker's levels as five numeric URL keys so `useRouteFilters` can hold them; each page binds `PlacePicker` to it.

**Tech Stack:** Laravel package `add/inventory` (PHPUnit, Orchestra-style package tests) · Vue 3 + TS + Naive UI + vue-i18n + Vitest (ADDOS).

**Spec:** `ADDCore/docs/superpowers/specs/2026-11-16-inventory-serials-and-filters-design.md`

## Global Constraints

- Work in two fresh worktrees: ADDCore from `master` HEAD, ADDOS from `feat/hr-module` HEAD. Both main checkouts carry other sessions' uncommitted work — never `git add` a directory, add files by name.
- ADDCore package tests: Laragon PHP 8.3 and a throwaway `APP_KEY`; run `php vendor/bin/phpunit -c packages/add/inventory/phpunit.xml` from the ADDCore root.
- ADDOS: every new string in both `src/add-os/lang/ar/ar.json` and `en/en.json`; no hex/rgb/raw px; TS strict, no `any`; numbers through `src/add-os/utils/format/`.
- ADDOS verification is `pnpm test:unit`, `pnpm type-check`, `pnpm lint:check` — run one at a time (forks-pool timeouts under contention), never `pnpm lint`.
- Serial: max 100 chars; compared trimmed and case-insensitively; blank means "not yet known".
- A place filter that matches nothing returns an empty list, never the unfiltered one.
- No filter control ships without a backend filter and a test behind it.

## Review Focus

1. **Count lowered after typing serials** — the grid drops trailing inputs; the payload never sends more serials than `count` (Task 7 test `resizeSerials` shrink case).
2. **Same serial on two lines of the same item in one batch** — refused on the second occurrence, not only within a line (Task 1 test `test_a_serial_repeated_across_lines_of_the_same_item_is_refused`).
3. **Back button after changing the branch filter** — the picker's selects follow the URL, not just the list (Task 5 test on `PlacePicker` chain watch).
4. **Place filter pointing at a deleted/unknown place** — empty list, HTTP 200 (Task 2 test `test_an_unknown_place_lists_nothing`).
5. **Editing an asset to its own current serial** — not reported as taken (Task 1 test `test_editing_keeps_its_own_serial`).

---

## Backend (ADDCore, `packages/add/inventory`)

### Task 1: Serials per unit on registration, and the duplicate rule

**Files:**
- Create: `src/Assets/Http/Requests/ValidatesSerials.php`
- Modify: `src/Assets/Http/Requests/RegisterAssetBatchRequest.php`, `RegisterAssetRequest.php`, `UpdateAssetRequest.php`, `AssetDetailRules.php` (withValidator hook)
- Modify: `src/Assets/Http/Controllers/AssetController.php` (`store`, `storeBatch`)
- Modify: `src/Assets/Services/AssetService.php` (`register`, `registerBatch`)
- Modify: `lang/en/assets.php`, `lang/ar/assets.php`
- Test: `tests/Feature/Assets/AssetSerialsTest.php`; update `AssetBatchRegistrationTest::test_a_bad_line_is_named_and_nothing_is_registered` only if its `lines.3.serial_number` expectation changes (it should not).

**Interfaces:**
- Produces: `AssetService::register(Item $item, ?Place $location, array $details = [], int $count = 1, bool $draft = false, array $serials = []): Collection` — `$serials` is `list<?string>`, length ≤ `$count`; asset *i* gets `$serials[$i]` (trimmed, blank → null) and it overrides `$details['serial_number']`. `registerBatch` line shape gains optional `'serials' => list<?string>`.
- Produces: request field `serial_numbers` (top-level on `POST assets`, `lines.*.serial_numbers` on `POST assets/batch`).
- Produces lang keys: `inventory::assets.serials_over_count` (":max at most"), `serial_repeated`, `serial_taken`, `serial_or_serials`.

- [ ] **Step 1: Write the failing tests** in `AssetSerialsTest` (uses `BuildsInventory`, abilities `ASSETS_VIEW`, `ASSETS_MANAGE`):
  - `test_a_line_of_several_takes_a_serial_per_unit` — batch line `count: 3, serial_numbers: ['A1', null, ' A3 ']` → 201; the three assets ordered by id have serials `A1`, `null`, `A3`.
  - `test_the_single_endpoint_takes_serials_too` — `POST /api/inventory/assets` `count: 2, serial_numbers: ['S1','S2']` → both set.
  - `test_more_serials_than_units_is_refused` — `count: 2`, three serials → 422 on `lines.0.serial_numbers`.
  - `test_a_serial_repeated_in_a_line_is_refused` — `['X', 'x ']` → 422 on `lines.0.serial_numbers.1`; nothing registered.
  - `test_a_serial_repeated_across_lines_of_the_same_item_is_refused` — two lines same item, `['X']` and `['X']` → 422 on `lines.1.serial_numbers.0`.
  - `test_different_items_may_share_a_serial` — two items, same serial → 201.
  - `test_a_serial_an_asset_of_the_same_item_already_has_is_refused` — existing asset serial `OLD` → registering `['old']` → 422 `lines.0.serial_numbers.0` with message `__('inventory::assets.serial_taken')`.
  - `test_sending_both_serial_fields_is_refused` — `serial_number` + `serial_numbers` → 422 on `serial_numbers`.
  - `test_the_single_endpoint_refuses_one_serial_for_several` — `count: 3, serial_number: 'S'` → 422 `serial_number` (`serial_for_one`).
  - `test_editing_to_a_serial_taken_by_the_same_item_is_refused` — `PATCH assets/{b}` with `a`'s serial → 422 `serial_number`.
  - `test_editing_keeps_its_own_serial` — `PATCH assets/{a}` with its own serial → 200.

- [ ] **Step 2: Run** `php vendor/bin/phpunit -c packages/add/inventory/phpunit.xml --filter AssetSerialsTest` — expected: FAIL (unknown field ignored / no errors).

- [ ] **Step 3: Implement `ValidatesSerials`** — trait with `protected function checkSerials(Validator $validator, array $entries): void` where each entry is `['key' => string /* error key prefix, e.g. "lines.0.serial_numbers" or "serial_numbers" */, 'item_id' => int, 'count' => int, 'serials' => list<mixed>, 'ignore' => ?int]`. It: errors `{key}` with `serials_over_count` when `count(serials) > count`; normalises with `mb_strtolower(trim())`; errors `{key}.{j}` with `serial_repeated` on the second occurrence per item across *all* entries; one query `Asset::where('item_id')->whereIn(LOWER(TRIM(serial_number)))` per item (skip `ignore` id) → `{key}.{j}` `serial_taken`. Add rules `serial_numbers` / `lines.*.serial_numbers`: `['nullable','array']`, `.*`: `['nullable','string','max:100']`. Both-fields check → `serial_or_serials`. Single endpoint: `serial_number` with `count > 1` → `serial_for_one`. Single `serial_number` paths (count 1, and `UpdateAssetRequest` with `ignore` = route asset id) go through the same `checkSerials` with a one-element list.

- [ ] **Step 4: Wire controller + service** — `store`/`storeBatch` pass `$data['serial_numbers'] ?? []` (per line) as `$serials` and strip it from details; `register()` assigns per index.

- [ ] **Step 5: Run** the filter again → PASS; then the whole package suite → only the 13 known master failures (see memory), none new.

- [ ] **Step 6: Commit** — `git add` the files above by name; `feat(inventory): a serial per unit when registering several assets`.

### Task 2: Filters on custody, maintenance, due schedules and the asset register

**Files:**
- Create: `src/Support/Http/PlaceFilter.php`
- Modify: `src/Assets/Http/Controllers/CustodyController.php`, `MaintenanceController.php`, `MaintenanceScheduleController.php` (`due`), `src/Reports/Http/Controllers/ReportController.php` (`assetRegister`), `AssetController.php` (replace private `place()` with `PlaceFilter::from`)
- Test: `tests/Feature/Assets/AssetListFiltersTest.php`

**Interfaces:**
- Produces: `PlaceFilter::from(Request $request, string $field = 'place'): ?Place`; `PlaceFilter::assetsWithin(Builder $assetQuery, Place $place): Builder` (applies `->at(Inventory::assetPlaces()->within($place))`).
- Query params produced (all optional):
  - `GET custody`: `place[type]`,`place[id]` (asset's location, within), `search` (asset code/serial, like), `category_id` (subtree).
  - `GET maintenance`: `place`, `type` (`MaintenanceType` value), `from`/`to` (days, on `opened_at`), `search` (asset code/serial, description, vendor).
  - `GET maintenance-schedules/due`: `place`.
  - `GET reports/asset-register`: `place`, `category_id`.

- [ ] **Step 1: Write failing tests** (package `Location` tree: `$building`, `$room = Location::factory()->in($building)`, `$elsewhere`):
  - `test_custody_filters_by_place_search_and_category`
  - `test_maintenance_filters_by_place_type_dates_and_search`
  - `test_due_schedules_filter_by_place`
  - `test_asset_register_filters_by_place_and_category` — totals reflect only filtered rows.
  - `test_an_unknown_place_lists_nothing` — `place[type]=location&place[id]=999999` on custody → 200, `data` empty.
- [ ] **Step 2: Run** `--filter AssetListFiltersTest` → FAIL.
- [ ] **Step 3: Implement** `PlaceFilter` and each controller's `when()` additions; custody/maintenance/due use `whereHas('asset', fn ($a) => PlaceFilter::assetsWithin($a, $place))`. Update each method's docblock filter list.
- [ ] **Step 4: Run** filter → PASS; whole package suite → no new failures.
- [ ] **Step 5: Commit** — `feat(inventory): place and detail filters on custody, maintenance and the asset register`.

### Task 3: Place on stock operations; category and search on movements

**Files:**
- Modify: `src/Stock/Http/Controllers/StockController.php` (`operations`, `movements`)
- Test: `tests/Feature/Stock/StockFiltersTest.php`

**Interfaces:**
- `GET stock/operations`: `place` → operations whose `warehouse_id` **or** `to_warehouse_id` is in `Warehouse::idsFromFilter()`; an empty match lists nothing.
- `GET stock/movements`: `category_id` (subtree), `search` (item sku/barcode/name — same clause as `balances`; extract it to a private `itemsMatching(string $needle)` used by both).

- [ ] **Step 1: Failing tests** — `test_operations_filter_by_place_on_either_side_of_a_transfer`, `test_movements_filter_by_category_and_search`.
- [ ] **Step 2: Run** → FAIL. **Step 3:** implement. **Step 4:** run → PASS, whole suite → no new failures.
- [ ] **Step 5: Commit** — `feat(inventory): place filter on stock operations, category and search on movements`.

### Task 4: Canonical Postman collection

**Files:** Modify `ADDCore/postman/ADD-OS.postman_collection.json`

- [ ] **Step 1:** Add the query params from Tasks 2–3 (disabled, with descriptions) to the matching inventory requests, and `serial_numbers` to the `assets` / `assets/batch` example bodies.
- [ ] **Step 2:** Validate it parses: `node -e "JSON.parse(require('fs').readFileSync('postman/ADD-OS.postman_collection.json','utf8'))"`; grep it for credential-looking strings (none added).
- [ ] **Step 3: Commit** — `docs(postman): inventory serials per unit and new list filters`.

---

## Dashboard (ADDOS, `src/add-os`)

### Task 5: Place filter in the URL

**Files:**
- Create: `modules/inventory/utils/placeFilter.ts`, `modules/inventory/utils/__tests__/placeFilter.spec.ts`
- Modify: `modules/inventory/components/PlacePicker.vue` (follow an externally changed `chain`)
- Test: `modules/inventory/components/__tests__/PlacePicker.spec.ts`

**Interfaces:**
- Produces:
  - `PLACE_FILTER_SCHEMA = { branch: "number", building: "number", floor: "number", zone: "number", space: "number" } as const`
  - `type PlaceFilterValues = { branch: number | null; building: number | null; floor: number | null; zone: number | null; space: number | null }`
  - `chainOf(values: PlaceFilterValues): PlaceChain | null` (null when `branch` is null)
  - `placeOf(chain: PlaceChain | null): PlaceRef | null` (deepest level; same order as `PlacePicker.emit`)
  - `filtersOf(chain: PlaceChain | null): PlaceFilterValues`
  - `usePlaceFilter(filters: Ref<PlaceFilterValues & Record<string, unknown>>): { place: ComputedRef<PlaceRef | null>; chain: WritableComputedRef<PlaceChain | null> }` — writing `chain` writes all five keys at once.

- [ ] **Step 1: Failing tests** — `placeOf` returns the deepest level, `space` without `zone` → space; `chainOf` with no branch → null; round-trip `chainOf(filtersOf(c))` equals `c`; `PlacePicker` mounted with a chain, then the `chain` prop replaced by another branch → its branch select shows the new value; set to `null` → all selects cleared.
- [ ] **Step 2: Run** `pnpm vitest run src/add-os/modules/inventory` → FAIL.
- [ ] **Step 3: Implement** utils; in `PlacePicker` add `watch(chain, next => { if (next && !sameChain(next)) restore(next) })` where `sameChain` compares to the five internal refs.
- [ ] **Step 4: Run** → PASS. **Step 5: Commit** — `feat(inventory): keep the place filter's levels in the URL`.

### Task 6: Service and type additions

**Files:** Modify `services/inventory.ts`, `modules/inventory/types/inventory.ts`

**Interfaces:**
- `AssetBatchLine` and `RegisterAssetPayload` gain `serial_numbers?: (string | null)[]`.
- `listCustody(filters: CustodyFilters)` with `CustodyFilters = { page?; custodian?: CustodianRef | null; open?: boolean; place?: PlaceRef | null; search?: string | null; category_id?: number | null }`.
- `listMaintenance(filters: MaintenanceFilters)` with `{ page?; asset_id?; open?; place?; type?: MaintenanceType | null; from?: string | null; to?: string | null; search?: string | null }`.
- `listDueSchedules(days?: number, place?: PlaceRef | null)`.
- `StockFilters` gains `search?: string | null`; `StockOperationFilters` gains `place?: PlaceRef | null`.
- `assetRegister(includeDisposed = false, filters: { place?: PlaceRef | null; category_id?: number | null } = {})`.

- [ ] **Step 1:** Make the changes. **Step 2:** `pnpm type-check` → passes (call sites still compile; new params optional). **Step 3: Commit** — `feat(inventory): service filters for custody, maintenance, operations and the asset register`.

### Task 7: A serial field per unit in the register form

**Files:**
- Create: `modules/inventory/utils/serials.ts`, `modules/inventory/utils/__tests__/serials.spec.ts`
- Modify: `modules/inventory/components/RegisterAssetModal.vue`, both lang files

**Interfaces:**
- `resizeSerials(serials: string[], count: number): string[]` — pads with `""` / truncates to `count` (min 1).
- `serialPayload(serials: string[]): { serial_number?: string | null; serial_numbers?: (string | null)[] }` — one entry → `serial_number` (trimmed or null); more → `serial_numbers` (trimmed, blank → null); all blank → `{}`.
- `serialErrorKey(lineIndex: number, unit: number): string` → `lines.{i}.serial_numbers.{u}`.
- i18n: `inventory.assets.unitSerial` — en `"Unit {n}"`, ar `"الوحدة {n}"`; `inventory.assets.serialsHint` — en `"Leave blank any serial you don't have yet."`, ar `"اترك فارغاً أي رقم تسلسلي غير متوفر بعد."`.

- [ ] **Step 1: Failing tests** for the three functions, including shrink (`["a","b","c"]`, 2 → `["a","b"]`) and grow.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** utils; in the modal replace `serial_number: string` on a line with `serials: string[]`, `watch` each line's `count` → `resizeSerials`; render one `n-input` (dir `ltr`, maxlength 100) per unit in a `grid grid-cols-2 sm:grid-cols-4 gap-2` with the `unitSerial` label when count > 1, the existing single input when count is 1; show `serialsHint` under the grid; map 422 field errors with `serialErrorKey` onto the inputs' `validation-status`/feedback.
- [ ] **Step 4: Run** tests → PASS. **Step 5: Commit** — `feat(inventory): a serial field per unit when registering several`.

### Task 8: Assets and Warehouses pages

**Files:** Modify `modules/inventory/views/AssetsPage.vue`, `WarehousesPage.vue`, lang files

- [ ] **Step 1: Assets** — move `place`/`placeChain` refs into `useRouteFilters` via `PLACE_FILTER_SCHEMA` + `usePlaceFilter`; add `item_id: "number"` (`ItemSelect`, asset items only) and `custodian_type: "string"`, `custodian_id: "number"` (`CustodianPicker inline`); `hasActiveFilters`/`resetFilters` include them; `RegisterAssetModal` still receives `:place :place-chain`.
- [ ] **Step 2: Warehouses** — add the place keys and an inline `PlacePicker`; pass `place` to `listWarehouses`; reset button covers it.
- [ ] **Step 3:** `pnpm type-check`; manual check deferred to Task 12. **Step 4: Commit** — `feat(inventory): branch and detail filters on assets and warehouses`.

### Task 9: Stock page

**Files:** Modify `modules/inventory/views/StockPage.vue`, lang files

- [ ] **Step 1:** Move its filter refs (`itemId`, `warehouseId`, `actorId`, `period`, `movementType`, `operationKind`, `operationId`) into `useRouteFilters` (`period` as `from`/`to` strings) plus place keys, `category_id`, debounced `search`. Show search + category on the balances and movements tabs, place on all three tabs. Passing both `warehouse_id` and `place` lets `warehouse_id` win (as the API does) — disable the warehouse select's options outside the chosen place, or simply clear `warehouse_id` when the place changes.
- [ ] **Step 2:** `pnpm type-check`. **Step 3: Commit** — `feat(inventory): branch, category and search filters on stock`.

### Task 10: Custody and Maintenance pages

**Files:** Modify `modules/inventory/views/CustodyPage.vue`, `MaintenancePage.vue`, lang files

- [ ] **Step 1: Custody** — `useRouteFilters`: place keys, debounced `search`, `category_id`, `custodian_type`/`custodian_id`, `returned: "string"` (`"1"` = include returned); reset button.
- [ ] **Step 2: Maintenance** — place keys (both lists), `type` select (`MaintenanceType` options, labels from existing `inventory.maintenanceType.*` keys if present, else add), `from`/`to` via `n-date-picker type="daterange"`, debounced `search`, existing `scope` and `days` moved into the URL too; reset button.
- [ ] **Step 3:** `pnpm type-check`. **Step 4: Commit** — `feat(inventory): branch and detail filters on custody and maintenance`.

### Task 11: Reports page

**Files:** Modify `modules/inventory/views/InventoryReportsPage.vue`

- [ ] **Step 1:** Place keys + `category_id` + `disposed: "string"` in `useRouteFilters`; the place applies to both reports, category to the asset register only (label the category select so it's clear).
- [ ] **Step 2:** `pnpm type-check`. **Step 3: Commit** — `feat(inventory): branch and category filters on the inventory reports`.

### Task 12: Re-pin, verify, and see it working

- [ ] **Step 1: Re-pin** `ADDOS/docs/api/ADD-OS.postman_collection.json` byte-identical from the canonical file; update the date/endpoint count in `docs/api/README.md`; `pnpm api:collection:check` → match.
- [ ] **Step 2:** In ADDOS run, one at a time: `pnpm test:unit` (all guards green), `pnpm type-check`, `pnpm lint:check` — each exit 0.
- [ ] **Step 3:** ADDCore: full inventory package suite → no failures beyond the known 13 master ones.
- [ ] **Step 4: Live check** in the built-in browser (worktree dev server via the 5175 proxy wrapper, local ADDCore :8000): register 3 of one item with 2 serials filled → 3 assets, serials as typed; repeat one serial → error under that unit's input; on each page pick a branch → list narrows and the URL carries `branch=…`; refresh → filter and picker levels come back; back button → previous filter restored; Arabic (RTL) once.
- [ ] **Step 5: Commit** the re-pin — `docs(api): re-pin the collection for inventory serials and filters`.
