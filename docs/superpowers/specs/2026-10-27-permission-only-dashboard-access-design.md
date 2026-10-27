# Dashboard access by permission alone — 2026-10-27

**For:** ADD Core (`routes/api/v1/admin.php`, permission sync) and the ADD OS dashboard
(sidebar + route guard). **Supersedes** the "register, do not enforce" scope of
`2026-10-25-dashboard-screen-permissions-design.md` and finishes the Branches pilot
(`docs/decisions/rbac-permission-pilot.md`).

## Why

A custom role (`stack_holder`) granted `amenities.*` on the Roles page sees no Amenities
page and the API refuses it: every admin route except a handful sits behind
`role:admin|operations`, and the dashboard mirrors that by opening a page to a custom role
only through `openedBy`. Of ~190 permissions the Roles page offers, about twenty do
anything for a custom role. The owner's rule: **any role, whatever its name, reaches —
in the menu and in the API — exactly what its permissions grant. No role name is checked
anywhere for staff access.**

HR (`can:hr.*`) and Inventory already work this way. This extends it to everything.

## The model

- A **role is a bag of permissions**. Authorization checks permissions, never role names.
- **`admin`** holds every permission because `PermissionSyncService::sync()` re-attaches all
  of them on every run (unchanged). No `Gate::before`.
- **`operations`** becomes an ordinary role. A one-time data step grants it every
  permission guarding a route it reached before this change (every route in `admin.php`
  outside the `role:admin` group and outside the existing permission-gated blocks, plus
  what it already holds), so nobody loses access on deploy.
- **Provider and member stay audience boundaries, not permissions**: `role:provider`
  (portal) and `role:member` (member API) are untouched. They hold no admin permissions,
  so the admin API refuses them anyway.
- Every action other than `view` comes with the `view` of the same module (the rule of the
  2026-10-25 spec). Names below follow it.

## ADD Core

1. `routes/api.php`: the admin group keeps `auth:sanctum` + `abilities:dashboard` and
   **drops `role:admin|operations`**. Every `withoutMiddleware('role:admin|operations')`
   wrapper and the `role:admin` group in `admin.php` go away; each route names its
   `permission:` middleware (Spatie, `|` = any of).
2. **Routes are the single source of permission names.** `PermissionSyncService` derives
   names from the `permission:` middleware of every route under `api/v1/admin/`
   (split on `|`), plus the tagged `permissions.sources` (HR, Inventory). The
   `AdminResourceController` reflection and `MANUAL_REGISTRATIONS` are removed —
   there is no longer a way to register a permission that guards nothing.
   `permissions:sync` reports stale names; `permissions:sync --prune` deletes them.
3. Two routes have no resource of their own: `GET me` (the account's own record) and
   `POST media/upload` (used by every content form). They need **no** permission but do
   need a staff account: a small middleware lets through any account holding at least one
   permission. An architecture test fails if any other admin route lacks `permission:`.
4. A data migration runs the sync and grants `operations` its previous reach (see above).
   Tests get permissions the same way (the migration runs under `RefreshDatabase`), so
   `assignRole('admin')` in a test still means "holds everything".
5. `StoreRoleRequest` / `UpdateRoleRequest` add the `view` of every action granted
   (the 2026-10-25 ask).

### Permission per route

Routes already carrying a `permission:` keep it. `<module>` for an `apiResource` is the
URI segment, as today: index/show → `.view`, store → `.create`, update → `.update`,
destroy → `.delete`.

| Routes | Permission |
|---|---|
| `buildings`, `floors`, `zones`, `spaces`, `seats-desks`, `devices`, `device-capabilities`, `business-hours`, `business-hour-exceptions`, `amenities`, `founders`, `partners`, `plans`, `plan-modules`, `resource-types`, `sale-items`, `contact-links`, `announcements`, `event-categories`, `events`, `community-members`, `private-office-requests`, `kiosks` (apiResource verbs, destroys included) | `<module>.view/create/update/delete` |
| `spaces/{space}/status`, `spaces/{space}/amenities` | `spaces.update` |
| `devices/{device}/regenerate-qr-value` | `devices.update` |
| `space-type-amenities` GET / PUT | `amenities.view` / `amenities.update` |
| `announcements/options` | `announcements.view` |
| `events/rooms`, `events/statuses`, `events/{event}/registration-fields` GET, attendees GET (`index`, `pending`) | `events.view` |
| registration-fields writes | `events.update` |
| attendees approve / reject / reject-pending / check-in | `events.manage_attendees` |
| `coupons` GET (index, show, redemptions, revisions) / store / update / destroy | `coupons.view/create/update/delete` |
| `reports/discounts`, `reports/offers`, `reports/revenue`, `reports/providers` | `reports.view` |
| `privacy-policy`, `terms-of-use`, `help-support` GET / PATCH | `app-content.view` / `app-content.update` |
| `settings` GET / `settings/{key}` PATCH | `settings.view` / `settings.update` |
| `exchange-rates` GET, `exchange-rates/suggestion` GET | `exchange-rates.view` |
| `exchange-rates` POST, `exchange-rates/sync`, suggestion dismiss | `exchange-rates.create` |
| `currencies` GET / POST / PATCH (`{currency}`, `status`, `base`) | `currencies.view/create/update` |
| `companies` GET (index, show), `companies/{company}/members` GET | `companies.view` |
| `companies/{company}/status` | `companies.update_status` |
| company member store / update / admin / destroy | `companies.manage_members` |
| `companies` POST | `office-leases.create` (unchanged) |
| `error-logs` GET / DELETE | `error-logs.view` / `error-logs.delete` |
| `members/{user}/*` | `members.view` |
| `users` GET (index, show) | `users.view\|members.view\|reception.view` (member search at the desk) |
| `users` POST / PUT·PATCH / `status` / `role` | `users.create/update/update_status/assign_role` |
| `roles` GET, `permissions` GET / POST / PUT·PATCH / DELETE | `roles.view` / `roles.create/update/delete` |
| `memberships` GET / POST, `memberships/{m}/desks` | `memberships.view` / `memberships.create` |
| `bookings` GET, `calendar`, `calendar/occupancy` | `bookings.view` |
| `spaces/{space}/availability/slots` | `bookings.view\|reception.book` |
| `branches/{branch}/coworking-pool`, `spaces/{space}/coworking-windows` GET | `spaces.view` |
| coworking-windows POST / PATCH / DELETE | `spaces.update` |
| `reception/summary`, `reception/arrival-requests` GET, `reception/bookings/pending-approval`, `reception/sessions/*`, `reception/outstanding`, `reception/bookings/{b}/access-grant`, `reception/bookings/{b}/extension-options` | `reception.view` |
| check-in, check-out, walk-ins (store, check-out), arrival confirm / reject, session-end confirm, `access-grants/{g}/activate` | `reception.check_in` |
| `reception/bookings` POST, `quote`, `cancel`, `extend` | `reception.book` |
| `reception/bookings/{b}/approve`, `reject` | `reception.approve` |
| `settle-payment` (booking, walk-in), `reception/payments/settle` | `reception.settle_payment` |
| `reception/resource-consumptions` | `reception.record_consumption` |
| `reception/wallet-balance` | `wallet-top-ups.view\|members.view` |
| `reception/wallet-top-ups` POST | `wallet-top-ups.create` |
| `reception/plan-purchases/preview`, `reception/plan-purchases` | `plan-purchases.create` |
| `item-sales` GET / `reception/item-sales` POST | `item-sales.view` / `item-sales.create` |
| `reception/cash-register-sessions` GET (index, current) / open / close | `cash-register.view` / `cash-register.manage` |
| `site-inquiries` GET / PATCH | `site-inquiries.view` / `site-inquiries.update` |
| `payments` GET (index, summary, show) | `payments.view` |
| `payment-methods` GET | `payment-methods.view` |
| `notifications/broadcast` | `notifications.broadcast` |
| `service-types` (all, field editor included) | `service-types.view/create/update/delete` (field writes: `.update`) |
| `service-requests` GET / PATCH | `service-requests.view` / `service-requests.update` |
| `kiosks/{k}/pairing-code`, `kiosks/{k}/secret` DELETE | `kiosks.pair` |
| `me`, `media/upload` | none — staff middleware (any permission) |

Branches, providers, provider-requests, invoices, office-leases, renewals, payments reverse,
dashboard: unchanged.

## ADD OS

- **One rule:** a staff page is listed and routable iff the account holds its `permission`.
  `openedBy`, `isAdminGated`, `ADMIN_GATE`, `mayOpenAdminPage` and every admin/operations
  role check for staff access go. Provider portal (`roles: ["provider"]`) and My HR
  (`employee`) keep their own audience rules.
- Every staff page carries the `permission` of the first API route it reads (or, for a
  write-only page, the one it writes). Pages with no API — the dashboard overview, account
  security, ComingSoon placeholders — are marked as open to every staff account.

| Page | Permission |
|---|---|
| spatial.branches | `branches.view` |
| members.individuals, memberProfile | `members.view` |
| members.companies, companyProfile | `companies.view` |
| members.memberships | `memberships.view` |
| members.officeLeases / stoppedRenewals | `office-leases.view` / `renewals.manage` |
| providers.list / requests | `providers.view` / `provider-requests.view` |
| bookings: receptionDesk, arrivals, approvalQueue, activeSessions(+detail), closeSession(+detail) | `reception.view` |
| bookings.allBookings, calendar | `bookings.view` |
| plans.discountReport, offerReport | `reports.view` |
| plans.planPurchases / itemSales | `plan-purchases.create` / `item-sales.view` |
| payments.paymentMethods / currencies / exchangeRates | `payment-methods.view` / `currencies.view` / `exchange-rates.view` |
| payments.walletTopUps / cashRegister / invoices | `wallet-top-ups.view` / `cash-register.view` / `invoices.view` |
| reports.providersReport, revenueReport | `reports.view` |
| address.operationsRequests | `service-requests.view` |
| cms.notifications | `notifications.broadcast` |
| appContent.* | `app-content.view` |

Pages that already name a `permission` keep it.

## Deploy

`php artisan migrate` (runs the sync and the operations grant) — then
`php artisan permissions:sync --prune` once, to drop names that guard nothing.
Admin is locked out of every admin route until the migration has run.
