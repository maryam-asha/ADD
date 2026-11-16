# Inventory: a serial per unit, and filters on every page

**Date:** 2026-11-16 · **Packages:** `add/inventory` (ADDCore) · `modules/inventory` (ADDOS)

## Intent

1. **Serials when quantity > 1.** Registering five chairs makes five assets,
   each with its own code (AST-…) and QR — but the manufacturer's serial could
   only be given to a line of one, so every serial had to be typed in later by
   editing each asset. Staff have the serials in hand at registration (they
   are on the boxes); the form should take them then.
2. **Filters on every inventory page, branch included.** Each list should
   narrow by where things are (branch → building → floor → zone → space) and
   by the details that matter on that page, and keep its filters in the URL.

## Goals

- A line of N assets takes up to N serials, one per unit, each optional.
- Every inventory list that holds located things filters by place.
- Filters survive refresh / back and can be shared as a link; one "clear
  filters" action per page.

## Non-goals

- Generating serials. A serial is the manufacturer's; the system's own
  identifier is the asset code, which already exists per unit.
- A branch filter on Items / Catalog — items have no location; only assets
  and stock do.
- Changing the pagination contract or any list's sort order.

## Anti-goals

- A filter control the API ignores (invariant 4: no shipped control may be a
  no-op). Every control added has a backend filter and a test behind it.
- A second place picker. The existing `PlacePicker` (inline mode) is reused.

## Approaches

| | Approach | Trade-off |
|---|---|---|
| **Picked** | `serial_numbers: string[]` on a line, up to `count` entries, the i-th going to the i-th unit created | One more field in the API and the form; matches how goods arrive |
| Rejected | Keep it: serials filled in later per asset | Free, but slow for 20 units and easily forgotten |
| Rejected | Auto-generate serials | Invents a manufacturer's number; the asset code already does this job |

The filters follow one way only: extend each controller's existing `when()`
chain with the same `place[type]` + `place[id]` shape already used by assets,
warehouses and stock, and reuse `PlacePicker` + `useRouteFilters` on the
pages. Anything else would be a second filter vocabulary.

## Design — serials

**API**

- `POST assets/batch` — `lines.*.serial_numbers`: nullable array, `max:count`,
  each `nullable|string|max:100`. Blank entries mean "not yet known".
- `POST assets` (single endpoint with `count`) — same `serial_numbers` field.
- `serial_number` (singular) stays accepted for a line/request of one, for
  existing callers. Sending both is refused. Sending `serial_number` with
  `count > 1` stays refused on batch and **becomes refused on the single
  endpoint too** — today it silently copies one serial onto every unit.
- `AssetService::register()` takes the list and gives the i-th serial to the
  i-th asset it creates; codes are still assigned in creation order.

**Uniqueness (my call — correct me if wrong)**

- Two serials in one request, for the same item, may not be equal (trimmed,
  case-insensitive). Error lands on the offending entry:
  `lines.{i}.serial_numbers.{j}`.
- A serial already carried by another asset **of the same item** is refused,
  on register and on edit. Different items may share a serial (different
  manufacturers). No DB unique index — the column stays nullable/indexed —
  because disposed assets keep their serial and history.

**Dashboard (`RegisterAssetModal`)**

- Count 1: one serial input, as today.
- Count > 1: the line shows N small serial inputs (N = count), labelled
  "Unit 1 … N", in a wrapping grid; shrinking the count drops the trailing
  inputs. Count is capped at the existing max (100).
- Field errors from the API map onto the matching input.
- New strings in `ar` and `en`.

## Design — filters

| Page | Added controls | Backend change |
|---|---|---|
| Assets | custodian, item | none (API accepts both) |
| Warehouses | place | none |
| Stock · balances | place, category, search | none |
| Stock · movements | place, category, search | `category_id`, `search` on movements |
| Stock · operations | place | `place` on operations (warehouse or to-warehouse within it) |
| Custody | place (where the asset sits), search (code/serial), category | `place`, `search`, `category_id` on custody |
| Maintenance · records | place, type, date range, search | `place`, `type`, `from`/`to` (opened_at), `search` |
| Maintenance · due | place | `place` on `maintenance/due` |
| Reports · asset register | place, category | `place`, `category_id` on asset register |

- Place filters mean "anywhere inside": a branch takes everything in it, via
  the existing `LocationResolver::within()` / `Warehouse::idsFromFilter()`.
- A place that matches nothing lists nothing (as `unlessFilteredToNone`
  already does for stock), never everything.
- Every page moves to `useRouteFilters` so filters live in the query string;
  a filter change returns to page 1.
- Each page has one "clear filters" button, shown only when a filter is set.

## Constraints

- ADDOS file ownership: all work is Category A (`src/add-os/**`).
- Every string through vue-i18n, `ar` and `en`; no hardcoded design values.
- Backend: each new filter gets a feature test in the package's
  `tests/Feature/...`; the serial rules get tests next to
  `AssetBatchRegistrationTest`.
- The API change means re-pinning the Postman snapshot in ADDOS
  (`docs/api/`) after the canonical collection is updated.

## Left to the builder

- Exact layout of the per-unit serial grid at phone width.
- Whether the movements `search` matches item name/SKU/barcode (as balances
  does) — it should, for consistency.
