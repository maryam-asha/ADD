# Kitchen orders

Members order from the branch kitchen onto their open coworking session or
checked-in booking. Full design: [the spec](../superpowers/specs/2026-11-10-kitchen-orders-design.md).
Decisions D1-D12 are the spec's numbered list, with the product owner,
2026-11-10.

## Decisions

- **D1** Two ways in, one menu: scan a desk's or room's QR, or open the menu in the app.
- **D2** With no QR, the place is the booked space, or a pickup-at-the-counter / free-text note; an order never reaches the kitchen without a delivery.
- **D3** Orders are for now; nothing is scheduled.
- **D4** The kitchen accepts or rejects; a rejection carries a reason the member sees.
- **D5** Several items per order, each with a quantity; the menu shows only what is in stock.
- **D6** The menu is the active sale items the kitchen store holds; stock items sold as they are, no recipes.
- **D7** Stock is drawn when the kitchen accepts, as a `sale` issue linked to the order; nothing to undo before then.
- **D8** Only a member with an open session or checked-in booking can order; anyone may browse.
- **D9** The order is not paid when sent; it joins the bill on acceptance and is settled with it.
- **D10** An item in a store may carry a minimum; falling below it notifies the store's manager.
- **D11** Orders are a separate charge beside the bill, never part of its price.
- **D12** Each branch names one sellable, non-primary warehouse as its kitchen store; no per-item menu flag.

## Rulings that changed behaviour

- **Orders are billed as their own payables, from general points** (D11, reversing D9's "added to the bill's figure"). Folding them into `price_points` / `amount_owed` would let space-specific points pay for drinks and report drinks as space revenue. Given up: the simpler build on the existing payment path. Consequences: `closing.orders_points` / `closing.total_points` at checkout, `orders` on cards, report lines of kind `order`, `kitchen_order` as a payment purpose, a paid bill that still owes orders can be settled for those alone.
- **QR values are random**, never derived from the desk or space (`QrPoint`, 40 random characters, prefix `add-place:v1:`), so a printed code cannot be guessed or enumerated.
- **Lock order is bill, then order.** Anything that changes both takes the session/booking row first, then the order row, so an accept racing a checkout cannot deadlock or bill a closed bill.
- **Pickup may go straight from preparing to delivered**; `ready` is optional (it only notifies a pickup member).

## What guards what

- `tests/Guards/QrValueIsRandomNotSequentialTest` - QR values are random.
- `tests/Guards/StockMovesOnlyThroughStockServiceTest` - the kitchen draws stock only through `StockService`.
- `tests/Guards/AdminRoutesArePermissionGatedTest` - every kitchen and QR route has a permission.
- `tests/Guards/EnumColumnsHaveBackedEnumCastsTest` - the order's status / source / delivery columns are cast to enums.
- `tests/Guards/PointConversionIsCentralizedTest`, `PricingIsCentralizedTest` - order points convert through the central services.
- `tests/Feature/Kitchen/*` - behaviour: menu, sending, actions and their races, payment and settlement, closing cancelling pending orders, live updates, low-stock notice, QR admin, scan.
- `tests/Feature/Inventory/InventoryDemoSeederTest::test_demo_kitchen_has_a_menu` - the demo branch has a kitchen with a menu and QR points.

The API surface is in the collection under *Member -> 15. Kitchen* and *Admin -> Kitchen* (ADD OS re-pins its snapshot of it).
