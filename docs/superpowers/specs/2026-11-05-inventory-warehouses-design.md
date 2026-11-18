# Inventory warehouses and richer items — 2026-11-05

**For:** the `add/inventory` package (`packages/add/inventory`), its ADD host adapters
(`app/Domain/Inventory`, `app/Domain/Retail`, reception controllers) and the ADD OS
dashboard's inventory section (`ADDOS/src/add-os/modules/inventory`). Builds on the
inventory package merged 2026-10-24 (catalog, assets, stock ledger, reception sales).

The owner showed screenshots of another system's inventory screens (warehouses list,
add-warehouse dialog with allowed-items scope, stock-movement type picker, item form with
barcode and extra units) as the reference to follow. They are a reference for *what*
the screens do, adapted to what ADD already has — not a copy.

## Why

Stock today is counted on ADD's own places (a branch, building, floor, zone or space).
ADD actually keeps goods in **warehouses**: several of them (an external store, one in
the kitchen, one in the café, others), each with someone responsible, and goods move
between them freely. A place in the building hierarchy cannot say who runs it, which
items it may hold, or whether reception may sell from it. Items also lack what a
warehouse needs day to day: a barcode to scan, a picture, and packs (a box of 6).

**Success:** staff create warehouses, decide what each may hold and whether it sells;
every stock quantity lives in exactly one warehouse; receipts, issues, write-offs,
counts and transfers happen per warehouse (several items in one go); reception sells by
the piece from the right warehouse without choosing it every time; items carry barcode,
image and extra units that are actually used — scanning and pack entry — not just stored.

## Goals

1. A **warehouse** entity in the package, with the reference's fields.
2. **Stock lives only in warehouses** (owner's decision). Assets — tracked one by one,
   with QR, custody and maintenance — stay on ADD's places; they are not stored goods.
3. Existing stock balances and their full ledger history move into warehouses with
   nothing lost.
4. Stock movements by warehouse, several lines per operation, entered in any of the
   item's units.
5. Reception sells and records consumption from a warehouse: the branch's primary one
   by default, another sellable one of the same branch on request.
6. Items gain barcode (with internal generation), image and other units — reusing every
   field that already exists instead of adding a parallel one (owner: "modify what we
   have to prevent duplication").
7. ADD OS screens for all of the above, in the reference's shape.

## Non-goals

- Purchasing, suppliers, purchase orders, reorder levels (still out, as in the original
  package scope).
- Approval workflows or draft/posted statuses on movements — a movement is recorded when
  it is submitted (consistent with the package's "no approvals" rule and the owner's
  preference for minimal status sets).
- Selling by pack. Reception sells **by the piece** (the item's main unit) with a count
  — owner's decision. Other units are for warehouse operations only.
- Per-unit barcodes (one barcode per item, for its main unit).
- Prices in the package. Sale prices stay in ADD's `sale_items`.
- Projects/cost centres for manual issue (the reference's "for a project" wording) —
  an issue keeps its reason and note.

## Anti-goals (would make this a failure even if it works)

- A second field that means the same as an existing one: a free-text "item category"
  next to the category tree, an "item type" next to the category's `kind`, a new "code"
  next to `sku`, a new status next to `is_active`.
- Reception silently taking stock from another warehouse when the chosen one is short.
  Per-warehouse balances must stay true; a shortage is fixed by a transfer.
- Losing or rewriting the meaning of any past movement during the migration (amounts,
  costs, dates, who, why must read the same afterwards).
- Stock still landing on a plain place after this ships (any path — API, reception,
  consumption, seeders).

## The model

### Warehouse (`inv_warehouses`, package)

| Field | Notes |
|---|---|
| `name` | translatable JSON like every package name; required |
| `code` | optional, unique, short |
| `manager_id` | nullable; a host user id (a dashboard staff account). Shown by name. |
| `address` | text, nullable |
| `location_url` | nullable; a map link or how to get there |
| `notes` | text, nullable |
| `place_type` / `place_id` | nullable; where it sits in the host's places (see below) |
| `item_scope` | `all` \| `selected` |
| `allows_sales` | boolean, default true |
| `is_primary` | boolean, default false |
| `is_active` | boolean, default true |

Pivot `inv_warehouse_items (warehouse_id, item_id)`, used only when `item_scope =
selected`. Only stock items can be selected (assets are never in a warehouse).

**Where it sits.** The package stores the warehouse's place as a `Place` through the
host's `LocationResolver`, like everything else. A host config key limits which place
types a warehouse may sit in; **in ADD a warehouse sits in a branch or in nothing** (an
external store belongs to no branch). Kitchen vs café is told apart by the warehouse's
own name, address and notes, not by tying it to a space. *(My call: tying it to a space
buys nothing reception needs and makes "the branch's warehouses" a hierarchy walk.)*

**Primary.** At most one active primary warehouse per place (per branch in ADD). A
primary warehouse must allow sales. Making one primary clears the flag on the other
warehouse of that place, in one transaction. A warehouse with no place cannot be primary.

**Scope rule.** When `item_scope = selected`, a receipt, an incoming transfer or a
positive adjustment of an item outside the list is refused (422). Narrowing the scope
is refused while a removed item still has a non-zero balance in that warehouse.
Outgoing movements of an item already there are always allowed (so stock can be emptied).

**Deactivating / deleting.** A warehouse holding any non-zero balance can be neither
deactivated nor deleted. A warehouse with ledger history cannot be deleted (only
deactivated). An inactive warehouse takes no movement at all and is not offered to
reception.

**Listing** (`GET warehouses`): search on name/code/address; filters status and
manager; each row carries the number of items with a non-zero balance (the reference's
"items with balance" column) and the primary/scope badges.

### Stock location becomes the warehouse

- The stock ledger and balance keep their `location_type`/`location_id` columns. Every
  stock row now has `location_type = 'warehouse'` and `location_id` = the warehouse id.
  *(My call: keeping the two columns means no ledger rewrite of shape, only of values,
  and `item_sales.location_*` keeps working unchanged.)*
- `StockService` refuses any location that is not an existing, active warehouse; the
  scope rule is checked there too, so no host path can bypass it.
- The stock endpoints take `warehouse_id` instead of a location place.
  `stock/balances` filters by warehouse (and, for "this branch", by the branch's
  warehouses).
- `LocationResolver` stays as is, for assets only. Stock gets its own warehouse lookup:
  `StockService::ensureLocation` and `Presenter::location()` (which today ask the
  resolver about stock rows and would refuse / leave unnamed a `warehouse` row) go
  through it. The resolver is asked only to describe a warehouse's place.
- Resources: the stock balance and movement resources replace `location` with
  `warehouse` (id, name, place label); `ItemSaleResource` replaces `stock_location` with
  `warehouse`. Their only readers are admin endpoints and ADD OS, updated in the same
  work. *(My call.)*
- `reports/stock-valuation` groups by warehouse and filters by `warehouse_id` or by
  branch (the branch's warehouses). *(My call.)*

### Items (changes to `inv_items`, reusing what exists)

| Reference field | Existing | Change |
|---|---|---|
| Item code, auto sequence | `sku` (generated from id when empty, config `sku_prefix` `ITM-`, `sku_pad` 5 → `ITM-00001`) | Same column and same generation, presented as "item code". No format change. *(My call: the reference's `ITEM-001` is only a look; a 3-digit pad runs out at 999.)* |
| Barcode + auto generate | — | New `barcode` column, nullable, unique. "Generate" makes an internal EAN-13 (prefix `2`, valid check digit) unique in the table. Any typed code (a supplier's barcode) is accepted if unique. |
| Image | — | New `image_url`: the URL ADD's existing upload (`media/upload`) returns, stored as is like ADD's other images; the package needs no media code. |
| Category | category tree with typed fields | Unchanged. No free-text category. |
| Type (goods/…) | from the category's `kind` | Unchanged. No new type field. |
| Status | `is_active` | Unchanged. |
| Main unit | `unit_id` | Unchanged. Ledger, balances and costs stay in the main unit. |
| Other units with factor | — | New `inv_item_units (item_id, unit_id, factor)`: "1 box = 6 main units". Unique per item+unit; the main unit itself cannot be added; factor > 0. A unit used by any recorded movement cannot be removed from the item or have its factor changed. |
| Sale price | ADD `sale_items.price_points` | Unchanged; stays in ADD. |

**Barcode is used, not just stored:**
- `GET items/barcode/{code}` (catalog view) returns the item.
- Reception's sale screen accepts a scanned barcode to add a line.
- The warehouse movement screen accepts a scanned barcode to add a line.

**Other units are used:** every stock operation line may name a `unit_id` among the
item's main unit and its other units; the quantity is converted to the main unit
(`quantity × factor`) before the ledger sees it, and the fraction rule
(`allows_fractions`) applies to the converted main-unit quantity. Each movement row also
stores what was typed (`entered_unit_id`, `entered_quantity`) so lists show "2 box (12
piece)". A receipt's unit cost, when entered per pack, is divided by the factor.

### Stock operations

The reference's five movement kinds map onto the existing ledger types — no new
movement types except where noted:

| Reference | Ledger |
|---|---|
| Manual receipt (goods from outside purchasing) | `receipt` |
| Manual issue (internal use) | `issue`, reason `internal_use` / `other` |
| Write-off (damaged goods out) | `issue`, reason `damaged` / `expired` — shown as its own menu entry |
| Adjustment up or down | `adjustment` |
| Transfer between warehouses | `transfer_out` + `transfer_in` |

- **Several lines per operation.** Each operation endpoint takes `lines[]` (item, unit,
  quantity, and cost for a receipt) and one warehouse (two for a transfer), note and
  reason. All lines are one transaction: one failing line fails the operation. Rows of
  one operation share a new `operation_id` (uuid) so the movements list can show and
  open them as one entry. The existing `transfer_id` keeps pairing a transfer's halves.
- **Adjustment** stays a physical count (the line carries the counted quantity; the
  ledger records the difference) — the screen shows book quantity, counted quantity
  and the resulting increase/decrease. *(My call: a count cannot go wrong on a stale
  screen the way a typed difference can.)*
- **Movements list** filters: warehouse, type, period, item, user (the reference's
  filters, minus "status", which does not exist here).

### Reception (ADD host)

- `stock_location` on item sales and resource consumption is replaced by an optional
  `warehouse_id`.
- No `warehouse_id` → the active primary warehouse of the branch of the operator's open
  cash register. None found → 422 with a message saying the branch has no primary
  warehouse.
- A given `warehouse_id` must be active; for a sale it must also allow sales. When the
  operator has an open register, it must sit in that register's branch; with no open
  register any such warehouse is accepted (today a named location skips the register
  check, and recording printing without a register must keep working). Else 422.
  *(My call.)*
- Consumption by a plan's resource type may come from any active warehouse (not only
  sellable ones), since it is not a sale; the default is still the primary. *(My call.)*
- Shortage → 422 naming the warehouse and the quantity on hand. Never another warehouse.
- Sales stay by the piece: quantity in the item's main unit, whole numbers as today.
- `GET` endpoint for reception: the active warehouses of the operator's branch (all
  active ones when no register is open), each with `allows_sales` and the primary
  marked. The sale screen offers only sellable ones; the consumption form offers all.
- Barcode at reception resolves to the item, then to its `sale_items` row (one per
  inventory item); an item with no active sale item is refused as "not for sale".

### Migration of existing stock (ADD host migration, needs a DB backup first)

1. Every branch gets a primary warehouse, "المستودع الرئيسي" / "Main warehouse" plus
   the branch name, placed in that branch, all items, sales allowed.
2. Stock counted on the branch itself goes to that primary warehouse.
3. Any place inside a branch (building, floor, zone, space) with stock rows — a balance
   or only history — gets a new warehouse named after the place's label, placed in its
   branch, all items, **sales off**, not primary; **active** if it holds a non-zero
   balance, **inactive** if it holds only history. Staff turn sales on where they want.
   *(My call: nothing new appears in reception's switch on day one; reception has
   always taken from the branch.)*
4. `inv_stock_movements`, `inv_stock_balances` and `item_sales` rows are re-pointed
   (`location_type = 'warehouse'`, new id). Quantities, costs, dates, actors, reasons and
   sources are untouched. Running balances per (item, warehouse) must equal before/after.
5. A place that no longer exists (its row deleted) gets a warehouse "Unknown place #id"
   with no place, so no history is orphaned.
6. The re-pointing is raw `DB::table` updates (it goes around the `AppendOnly` model on
   purpose). `tests/Guards/StockMovesOnlyThroughStockServiceTest` forbids writes to
   `inv_stock_movements` under `database_path()`; this one migration is exempted by name
   in that guard, with a comment saying why. Nothing else is exempted.
7. `PlaceInventoryGuard` stops counting stock on places (assets still count). Deleting a
   branch that has **any** warehouse is refused (move or delete the warehouses first),
   so no warehouse ever points at a deleted branch or stays primary for it. *(My call.)*

### Permissions

New package abilities, resolved as permissions in ADD (`permissions:sync` on deploy):
`inventory.warehouses.view`, `inventory.warehouses.manage`. Stock operations keep their
existing abilities (`inventory.stock.receive|issue|transfer|adjust`); write-off uses
`inventory.stock.issue`. Barcode lookup uses `inventory.catalog.view`. Reception
endpoints keep their existing permissions.

### ADD OS dashboard

Inventory section, matching the reference layout and the dashboard's existing patterns
(Carbon icons via `Icon.vue`, no emoji; the module's existing helpers and
`PlacePicker`/`ItemSelect`):

- **Warehouses page** (new, `inventory.warehouses.view`): header + "add warehouse";
  filters search / status / manager; table name (+ primary badge, scope note), manager,
  location, items-with-balance, status; row actions view / edit / activate–deactivate /
  delete, each shown only with permission and when allowed.
- **Add / edit warehouse dialog**: name*, manager (staff picker, "no manager"),
  address, location link, notes, branch (or none), primary toggle, allowed items
  (all / selected with item search by name, code or barcode), allow sales checkbox.
- **Warehouse details**: its balances (item, quantity in main unit, average cost, value)
  and its recent movements.
- **Stock movements page** (the existing Stock page reshaped): list of operations with
  filters; "add movement" opens the five-kind picker; each kind opens its entry form —
  warehouse(s), lines (item by search or barcode scan, unit among the item's units,
  quantity, cost for receipts), reason where relevant, note.
- **Item form**: image, name, code (shown, generated), barcode with "generate", category,
  status, main unit, and an "other units" panel (add unit + factor, with the hint "the
  factor is how many main units are inside this unit").
- **Reception sale screen**: barcode scan field; warehouse shown (primary by default)
  with a switch among the branch's sellable warehouses.
- Navigation: the inventory section gains "Warehouses"; "Stock" is renamed "Stock
  movements". Arabic and English strings in `lang/{ar,en}/inventory.json`.

## Constraints

- Package stays host-agnostic: no ADD model, table or class referenced from
  `packages/add/inventory`. ADD specifics (branch-only places, reception defaults,
  migration of ADD places) live in `app/Domain/Inventory` and a host migration.
- `StockService` remains the only way a quantity changes, with row locks as today.
- Update endpoints return `{"message": ...}`; create returns the resource; anything new
  in a `data` response goes inside `data`. Messages localized (package `lang/` files for
  package messages, `lang/{en,ar}/api.php` for ADD's).
- Money/quantity formatting: plain strings without trailing zeros, as today.
- Deploy: DB backup, `composer install`, `migrate`, `optimize:clear` before
  `permissions:sync`.
- Tests: package tests for warehouses, scope, units conversion, multi-line operations,
  barcode; ADD feature tests for reception defaults and the data migration (balances and
  history equal before/after). ADD OS: service and component specs like the module's.

## Where it is built

- ADD Core: a branch off `master`.
- ADD OS: on top of `feat/hr-module` (the checkout's branch, which holds the inventory
  module). The QA fix `a92a643` was merged into it first (owner's decision, merge
  `5cbe1da`): the restyled pages were kept, and the fix's `COLUMN` widths and
  `tableWidth` helpers are available. New and reshaped inventory tables (warehouses,
  stock movements, items) size their columns with `COLUMN` and scroll at
  `tableWidth(...)`.

## Left to the builder

- Exact endpoint paths and payload names beyond those named here.
- How the package stores and exposes the image path (a config'd URL resolver, or the
  host passes a URL).
- Barcode generation algorithm details within "internal EAN-13, prefix 2, unique".
- Whether the movements list groups by `operation_id` server-side or client-side.
- UI composition details within the reference's layout.
