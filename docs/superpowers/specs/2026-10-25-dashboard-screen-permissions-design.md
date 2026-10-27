# Permissions for the dashboard screens that have none — 2026-10-25

**From:** the ADD OS dashboard. **For:** ADD Core.

## Why

Since 2026-10-25 the dashboard builds its menu from the `permissions` array of
`GET /api/v1/auth/me`:

- **Admin** sees every screen.
- **Operations** sees a screen only when it holds that screen's `<module>.view`
  permission. A screen that names no permission is admin's alone. This is how a
  role's menu follows what it is granted on the Roles page.

ADD Core registers no permission for the screens listed below, so the Roles
page cannot grant them to operations. A reception account
(`reception.lead@staff.demo`) currently sees only Dashboard and Branches. It
cannot be given the reception desk at all.

This task asks ADD Core to **register** these permissions so they appear in
`GET /api/v1/admin/permissions` and in `/me`. It does **not** ask ADD Core to
enforce them on the routes, which still sit behind `role:admin|operations`.
Enforcing them is a separate decision. If it is ever made, follow the Branches
pilot: seed `operations` with what it already reaches, as `PermissionSeeder`
did for `branches.*`.

## The rule: no action without its view

Every permission other than `view` is granted together with the `view` of the
same resource: the same name with its last segment replaced by `view`.

| Action | Its view |
|---|---|
| `spaces.update` | `spaces.view` |
| `hr.payroll.approve` | `hr.payroll.view` |
| `inventory.stock.issue` | `inventory.stock.view` |

An action with no such view stays as it is: `renewals.manage`,
`dashboard.finance`, `hr.roster.manage`.

The Roles page already applies this rule:

- Selecting an action selects its view.
- Clearing a view clears the actions that need it.
- Saving a role created before the rule adds the views it is missing.

**Asked of ADD Core:** apply the same rule in `StoreRoleRequest` and
`UpdateRoleRequest` (or in `RoleController`), so a role saved through the API
directly cannot hold an action without its view. Adding the missing views is
enough; refusing with a 422 would also work. Name new permissions so that each
action has a `.view` beside it, and the rule then holds by name alone.

## Screens with no permission, and the names the dashboard proposes

The names are proposals. ADD Core owns them. Any name works if it follows the
`module.action` convention and gives each action a `.view`. Once a name is
registered, the dashboard adds it to one line in `src/add-os/navigation/sections.ts`.

| Screen (dashboard) | Routes it reads | Proposed permission(s) |
|---|---|---|
| Reception desk, arrivals, approval queue, active sessions, close session | `reception/summary`, `reception/arrival-requests`, `reception/bookings/pending-approval`, `reception/sessions/*`, `reception/outstanding` | `reception.view`; actions: `reception.check_in`, `reception.approve`, `reception.settle_payment`, `reception.walk_in` |
| All bookings, booking calendar | `bookings`, `calendar`, `calendar/occupancy` | `bookings.view` |
| Members (individuals, member profile) | `members/{user}/*`, `users` (read) | `members.view` |
| Companies (list, profile, members) | `companies`, `companies/{company}/members` | `companies.view`; actions: `companies.update_status`, `companies.manage_members` |
| Memberships | `memberships` (today `role:admin`) | `memberships.view`, `memberships.create`. Today operations is refused here (403) even though the dashboard used to offer it the page. |
| Currencies | `currencies` | `currencies.view`, `currencies.create`, `currencies.update` |
| Exchange rates | `exchange-rates`, `exchange-rates/suggestion` | `exchange-rates.view`, `exchange-rates.create` |
| Payment methods | `payment-methods` (index only) | `payment-methods.view` |
| Wallet top-ups | `reception/wallet-balance`, `reception/wallet-top-ups` | `wallet-top-ups.view`, `wallet-top-ups.create` |
| Cash register | `reception/cash-register-sessions*` | `cash-register.view`, `cash-register.manage` |
| Plan purchases | `reception/plan-purchases*` | `plan-purchases.view`, `plan-purchases.create` |
| Item sales | `item-sales`, `reception/item-sales` | `item-sales.view`, `item-sales.create` |
| Discount report, offer report | `reports/discounts`, `reports/offers` | `coupons.view` (already registered), or `reports.view` if that is meant. **ADD Core decides which.** |
| Operations requests | `service-requests`, `service-types` | `service-requests.view`, `service-requests.update` |
| Notifications | `notifications/broadcast` (today `role:admin`) | `notifications.view`, `notifications.broadcast` |
| App content: privacy policy, terms of use, help & support | `privacy-policy`, `terms-of-use`, `help-support` | `app-content.view`, `app-content.update` |

Most of these controllers do not extend `AdminResourceController`, so they go in
`PermissionSyncService::MANUAL_REGISTRATIONS`. `uncoveredControllers()` already
lists most of them as an acknowledged gap.

## Done when

- `php artisan permissions:sync` creates the names above (or ADD Core's own
  names), and admin holds them, as it does every permission.
- `GET /api/v1/admin/permissions` lists them, so the Roles page offers them.
- A role saved with an action and without its view ends up holding the view
  too (or the save is refused).
- The final names are sent back to the dashboard, which adds one line per
  screen in `sections.ts`.
