# RBAC permission pilot: dynamic roles + granular permissions for Branches

**Status:** resolved 2026-08-27. **Owner:** Maryam Asha.
**Type:** design doc, layered on top of D.8's flat-role decision
(`docs/decisions/rbac-scoping.md`) — see "Relationship to D.8" below.

## What this adds

Two backend tasks, scoped to the admin/operations dashboard only —
`member` is untouched, out of scope:

1. **Dynamic roles.** `Admin\RoleController` gets full CRUD (previously just
   `index()`): an operator can now create/rename/delete custom roles and
   sync a role's permission set, on top of the three built-in roles
   (`member`, `operations`, `admin`), which `App\Support\ProtectedRoles`
   makes permanently un-renamable and un-deletable through that controller.
2. **Granular permissions.** `module.action`-named permissions (e.g.
   `branches.delete`), stored in spatie/laravel-permission's stock
   `permissions` table — no new schema. `App\Services\Permissions\
   PermissionSyncService` derives them by reflecting over every
   non-abstract `AdminResourceController` subclass's actually-registered
   routes (`index`/`show` → `view`, `store` → `create`, `update` →
   `update`, `destroy` → `delete`), plus a short hardcoded map covering
   exactly four of the controllers that don't extend that base class —
   `UserController`, `ErrorLogController`, `RoleController`,
   `SettingController`, each for its own documented reason (see their
   class docblocks). This is a deliberately partial list, not a claim that
   these are the only such controllers — see "Explicitly not done here"
   below for the dozen more that currently get zero permission coverage,
   and how that gap is now surfaced rather than silent. Both the
   `permissions:sync` Artisan command
   (`App\Console\Commands\SyncPermissionsCommand`) and `PermissionSeeder`
   call `PermissionSyncService::sync()` as the one source of truth.
3. **Branches switched to enforcing it.** `routes/api/v1/admin.php`'s
   Branches routes (`index`/`show`/`store`/`update`/`destroy`) opt out of
   the file's group-level `role:admin|operations` check
   (`Route::withoutMiddleware('role:admin|operations')`) and gate each
   action with its own `permission:branches.*` middleware instead — proof
   that a custom role can reach an admin action on the strength of its
   granted permissions alone, not a hardcoded role name.

## Why

Once operators can create roles beyond the fixed three, a route middleware
check that only recognizes literal role names (`role:admin|operations`) can
never grant a custom role access to anything — a custom role has no way to
pass that check no matter what it's been granted. Authorization for a
resource custom roles need to reach has to move to checking actual granted
permissions instead of role identity.

Permissions are derived from routes rather than hand-typed to avoid the
same kind of drift this codebase avoids elsewhere by generating structure
from a single source of truth: a hand-maintained permission list can claim
an action a route doesn't actually expose, or silently miss one a route
just added. Reflecting over live `Route::getRoutes()` entries keeps the
permission list a description of what the routes actually allow, not a
separate claim that has to be kept in sync by hand.

## Decision

- **No new database schema.** Reuses spatie/laravel-permission's stock
  `roles`/`permissions`/`model_has_roles`/`role_has_permissions` tables
  exactly as D.8 left them — see "Relationship to D.8" below.
- **`module.action` naming**, one permission per derivable route action,
  grouped by `module` (the resource's URL segment) in
  `RoleController::permissions()`'s response.
- **Derived, not hand-typed** — see "Why" above.
  `PermissionSyncService::MANUAL_REGISTRATIONS` is the explicit, narrow
  exception for four specific controllers this pilot chose to cover by
  hand, not a claim that those are the only controllers reflection can't
  reach, and not a general opt-out from derivation. A dozen more admin
  controllers are in the same "doesn't extend AdminResourceController"
  boat and are simply not covered yet — see "Explicitly not done here".
- **`admin` is a normal seeded role holding every permission**, not a
  hardcoded `Gate::before` bypass. `PermissionSyncService::sync()`
  re-attaches `Permission::all()` to `admin` on every run
  (`permissions:sync`, `PermissionSeeder`, or any future call site) — the
  self-healing property: if `admin`'s permission set is ever edited down
  through the same role-management UI available for custom roles, the next
  sync restores it.
- **The three built-in roles stay permanently protected.**
  `App\Support\ProtectedRoles::NAMES` (`member`, `operations`, `admin`)
  can't be renamed or deleted through `RoleController` — other code depends
  on those exact literal strings (member-vs-dashboard login separation, the
  first-admin bootstrap). A role with users still attached can't be
  deleted either, protected or not.

## Explicitly not done here

- **Not a Filament/filament-shield adoption.** This app has no Filament
  installed and serves JSON only under `/api/v1` (see this file's own
  "Project" section in `CLAUDE.md`). The auto-derivation *idea*
  filament-shield popularized — generate permissions from what a resource
  actually exposes, rather than hand-typing them — was worth borrowing, but
  the package itself is built around Filament Resource classes, which don't
  exist in this API-only app; there is nothing for it to introspect here.
- **Not a reopening of D.8.** `CompanyPolicy` is unchanged — still the only
  Policy class in the app, still checking a genuinely different concern (a
  per-company `is_admin`/`door_access_enabled` pivot flag, not a role or
  permission). See "Relationship to D.8" below for the one real interaction
  between the two systems.
- **Not a full rollout.** Only Branches enforces `permission:` middleware
  today; every other admin resource in `routes/api/v1/admin.php` still runs
  on the original `role:admin|operations`/narrower `role:admin` middleware.
  Converting the rest is deliberate follow-up work, evaluated resource by
  resource, not assumed.
- **Not full permission *coverage* either — a real, acknowledged gap, not
  an oversight.** `PermissionSyncService::MANUAL_REGISTRATIONS` covers
  exactly four controllers that don't extend `AdminResourceController`
  (`UserController`, `ErrorLogController`, `RoleController`,
  `SettingController`). At least a dozen more admin controllers also don't
  extend it and get **no permission coverage at all** today — no
  `permission:` middleware could even be wired to them yet, since no
  permission for them exists:
  - `CompanyController`
  - `CompanyMemberController`
  - `CurrencyController`
  - `ExchangeRateController`
  - `ExchangeRateSuggestionController`
  - `PrivacyPolicyController`
  - `Reception\AccessActivationController`
  - `Reception\ArrivalRequestController`
  - `Reception\BookingReceptionController`
  - `Reception\ReceptionSessionsController`
  - `Reception\WalkInSessionController`
  - `Reception\WalletTopUpController`

  This isn't silent: `PermissionSyncService::sync()` returns an `uncovered`
  list (the six non-Reception controllers above, resolved by diffing every
  `Api/V1/Admin/*Controller.php` file against the union of reflected +
  manually-registered classes), and `permissions:sync` prints it as a
  warning section on every run, the same way it already warns about stale
  permissions. The six `Reception/*` controllers are deliberately excluded
  from even that visibility check — same flat, non-recursive glob
  `discoverControllers()` already uses to skip them — because their
  actions (`checkIn`/`checkOut`/`approve`/`reject`/`settlePayment`/...)
  don't fit the `index`/`show`/`store`/`update`/`destroy` vocabulary this
  service derives `module.action` names from at all; surfacing them in the
  same warning wouldn't point at an actionable fix the way the other six
  do, since they'd need their own custom permission-naming scheme first,
  not just an entry added to `MANUAL_REGISTRATIONS`. Converting any of
  these 12 to real permission coverage is future work, evaluated
  controller by controller like the route-enforcement rollout above.

## Relationship to D.8

D.8 (`docs/decisions/rbac-scoping.md`) keeps `roles`/`permissions` flat —
no `scope_type`/`scope_id` — and settles that the one genuinely scoped
capability in the app (a member's use of their employer's shared door
code, and a company admin's ability to manage fellow members) is a pivot
flag plus one Policy method, not a role/permission concern. This pilot
operates entirely inside those constraints: it adds rows to the same flat
`permissions` table D.8 describes, and touches nothing about
`CompanyPolicy` or `company_user`.

The one real interaction: removing `AppServiceProvider`'s unconditional
`Gate::before` admin-bypass-everything means an admin/operations-role
account no longer gets an automatic pass on `CompanyPolicy::manageMembers()`
/`useDoorAccess()` — it now needs an actual `is_admin=true`/
`door_access_enabled=true` row on that specific company's pivot, same as
any other account. This was evaluated and accepted as a deliberate side
effect — it closes an unintended blanket-access gap an admin/operations
account previously had into every company's members regardless of any real
relationship to that company — not an oversight, and not a re-litigation
of D.8's own decision about what stays scoped.

## What changed in code

Task B1 (`79436de`) — role/permission infrastructure, no route switched
yet:
- `App\Services\Permissions\PermissionSyncService` (new)
- `App\Support\ProtectedRoles` (new)
- `App\Console\Commands\SyncPermissionsCommand` (new, `permissions:sync`)
- `App\Http\Controllers\Api\V1\Admin\RoleController` — rewritten for full
  CRUD (create/rename/delete custom roles, sync a role's permissions,
  `GET admin/permissions` grouped by module)
- `App\Http\Requests\Admin\StoreRoleRequest` / `UpdateRoleRequest` (new)
- `App\Http\Resources\RoleResource` (new)
- `database/seeders/PermissionSeeder.php` (new, wired into
  `DatabaseSeeder` right after `RoleSeeder`)
- `App\Http\Requests\Admin\AssignRoleRequest` / `StoreUserRequest` — now
  validate `role` against the `roles` table instead of a hardcoded
  three-name list
- `App\Providers\AppServiceProvider` — the unconditional `Gate::before`
  admin bypass removed
- `lang/{en,ar}/api.php` — new `role.*` keys
- Tests: `tests/Feature/Console/SyncPermissionsCommandTest.php`,
  `tests/Feature/Admin/RoleControllerTest.php`,
  `tests/Feature/Identity/CustomRoleAssignmentTest.php`;
  `tests/Feature/Identity/CompanyMemberDoorAccessTest.php` updated to
  assert the new, more secure post-`Gate::before` behavior

Task B2 (`d8e4736`) — Branches switched to enforce it:
- `routes/api/v1/admin.php` — Branches routes moved out of the
  `role:admin|operations` group, gated by `permission:branches.*` instead
- `database/seeders/PermissionSeeder.php` — grants `operations` the
  `branches.view`/`branches.create`/`branches.update` permissions it
  already had pre-switch (not `branches.delete`, which was already
  `role:admin`-only, so `operations` never had it)
- `bootstrap/app.php` — a translated `render()` branch for spatie's
  `UnauthorizedException`, matching the existing `AccessDeniedHttpException`
  handling. This exception type isn't specific to `PermissionMiddleware`:
  spatie's `RoleMiddleware` and `RoleOrPermissionMiddleware` throw the same
  class on denial, so this one branch also fixes the untranslated,
  English-only 403 body for every existing `role:`-based denial app-wide
  (every `role:admin`, `role:admin|operations`, `role:member` route in the
  app, not just Branches' new `permission:` checks) — a bigger fix than
  "Branches' 403s are now translated" alone would suggest.
- Tests: `tests/Feature/Foundation/BranchPermissionEnforcementTest.php`
  (new — a custom role reaching Branches via permissions alone, the admin
  self-heal safeguard, the operations-parity regression, translated en/ar
  403 bodies, seeder idempotency); `tests/Feature/Foundation/
  BranchControllerTest.php` updated to seed `PermissionSeeder`

Final-review fix pass (post-`e0deeac`) — see
`.superpowers/sdd/recursive-enchanting-hippo/task-B-finalfix-report.md` for
the full list; the two behavioral/decision-relevant ones:
- `RoleController::update()` now rejects `PATCH admin/roles/{role}` with a
  `permissions` key when the target role is `member` (422,
  `api.role.member_out_of_scope`) — closes the gap where `member` could be
  handed admin-panel permissions even though this decision always intended
  it to be entirely out of scope. Admin/operations permission edits are
  unaffected — only `member` is restricted this way.
- `RoleController::destroy()` now returns `204 No Content` on success
  instead of a `{message}` body, matching every other admin destroy
  endpoint in the app (`AdminResourceController::destroy()`,
  `ErrorLogController::destroy()`, `CompanyMemberController::destroy()`) —
  the message-on-destroy was a one-off inconsistency in the original
  RoleController implementation, not a deliberate second convention.

## Deploy ordering

On a fresh deploy, the `permissions` table starts empty. Until
`permissions:sync` (or `PermissionSeeder`, which calls the same service)
runs, `permission:branches.*` middleware finds nothing granted to any
role — including `admin` — so Branches becomes unreachable for everyone
until that first sync completes. This means `permissions:sync` (or
seeding) must run as a required step of every deploy, not as an
afterthought left to whoever remembers — the same way `migrate` is a
required step, not optional polish.

## Guard

`tests/Guards/RbacStaysFlatTest.php` still enforces D.8's underlying
constraints — no `scope_type`/`scope_id` column on `roles`, `permissions`,
`model_has_roles`, or `company_user`, and `CompanyPolicy` remains the only
Policy class in the app — and continues to pass. This pilot adds rows and a
route-level enforcement mechanism inside those constraints; it doesn't
relax them. No new guard test was added for the pilot itself — like
`kiosk-display.md`/`reception-operations-scope.md`, this is additive
capability rather than a schema-shape invariant, covered instead by
`SyncPermissionsCommandTest`, `RoleControllerTest`, and
`BranchPermissionEnforcementTest`.
