# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

ADD Core is the backend API for **ADD OS** (internal name; product is "Aleppo Digital District"). It is a Laravel 12 application that serves JSON only under `/api/v1` — there are no server-rendered views. A separate frontend ("AddDashboard", Vite-based) and a member-facing app consume this API; see `SANCTUM_STATEFUL_DOMAINS` / `CORS_ALLOWED_ORIGINS` in `.env` for the trusted origins.

**Never read or search inside `vendor/`** — it is ~10k files of Composer-managed third-party code, not part of this app, and will blow up context for no benefit. If you need to know how a dependency behaves, check its version in `composer.json`/`composer.lock` and reason from its public docs instead of grepping the vendor tree. The same goes for `storage/logs`, `storage/framework`, and `bootstrap/cache` — generated, never hand-edited.

## Commands

```bash
# First-time setup (installs deps, copies .env, generates key, migrates, builds assets)
composer setup

# Local dev — runs php artisan serve + queue:listen + pail (logs) + vite concurrently
composer run dev

# Run the full test suite (clears config cache first)
composer test
# equivalent to:
php artisan test

# Run a single test file / method
php artisan test tests/Feature/SomeTest.php
php artisan test --filter=test_method_name

# Migrations / seeders
php artisan migrate
php artisan db:seed                          # runs RoleSeeder + SettingSeeder (see DatabaseSeeder)
php artisan db:seed --class=AdminUserSeeder  # bootstraps the first admin account (local only)

# Code style (Laravel Pint, no custom pint.json — uses Pint's defaults)
./vendor/bin/pint          # fix
./vendor/bin/pint --test   # check only, no changes

# Inspect routes
php artisan route:list
```

Tests run against an in-memory SQLite DB (`phpunit.xml`), not the MySQL connection configured in `.env`.

## Architecture

**Domain namespaces.** Models, enums, and domain services live under `app/Domain/<Domain>/{Models,Enums,...}` (`App\Domain\Foundation\Models\Space`, not `App\Models\Space`) — one namespace per functional domain (`Foundation`, `Identity`, `Ecosystem`, `Experience`, and more as later phases add them), matching PRD §7.1's structural separation of the three product layers. `database/migrations/` stays one flat, chronologically ordered directory regardless of domain — see `docs/architecture/2026-08-08-backend-build-plan.md` §A for the full rationale. `tests/Guards/` holds executable checks for locked PRD decisions (§9: "written prose is a request, not a guarantee"); `docs/decisions/` traces each one back to the guard that enforces it.

**Route composition.** `routes/api.php` only sets up the `/api/v1` prefix and middleware groups; it `require`s five split files instead of defining routes itself:
- `routes/api/v1/auth.php` — OTP request/verify/logout, unauthenticated + sanctum-authed `me`.
- `routes/api/v1/public.php` — unauthenticated reads for the marketing site.
- `routes/api/v1/mobile.php` — unauthenticated endpoints the member mobile app calls directly, distinct from `public.php`'s marketing-site reads and from the auth flow (e.g. `POST errors` for client-side crash reporting, since errors can happen before login and the app has no session yet). Grows as more no-auth mobile-app endpoints (config, feature flags, ...) land.
- `routes/api/v1/admin.php` — everything here already sits behind `auth:sanctum` + `role:admin|operations`, applied once by the group wrapping the `require` in `api.php`. Don't re-add that middleware inside `admin.php`; do add narrower `role:admin` groups there for admin-only actions (e.g. user/role management, deleting error logs). One documented exception: Branches opts out of that group's `role:` check via `Route::withoutMiddleware('role:admin|operations')` and enforces `permission:branches.*` middleware per route instead (`docs/decisions/rbac-permission-pilot.md`) — every other resource in the file is unaffected, and the general guidance above still applies to them.
- `routes/api/v1/member.php` — everything here already sits behind `auth:sanctum` + `role:member`, applied the same way. A company member is still just a `member` role-wise (D.8) — no separate group for them.

**Two-tier abstract controller pattern** for content resources (`Founder`, `Partner`, `CommunityMember`, `Event`, ...):
- `Api\V1\Admin\AdminResourceController` — generic `index`/`show`/`destroy` (index auto-orders by an `order` column when `hasOrderColumn()` is true). Concrete controllers only implement `modelClass()`/`resourceClass()` plus their own `store`/`update`, each typed against a dedicated Form Request rather than a shared generic rules array.
- `Api\V1\Public\PublicResourceController` — generic `index` only. Concrete controllers override `scopeQuery()` to filter which rows are publicly visible (ordering, published/active flags, etc.) — that's the one axis public listings differ on.
- `Api\V1\Admin\UserController` deliberately does **not** extend `AdminResourceController`: users have no `order` column, and "removing" a user means deactivating `status`, not a hard delete — the shape genuinely differs. `Api\V1\Admin\ErrorLogController` is the same call for a different reason: `AdminResourceController::index()` returns every row unpaginated, which fits small bounded tables but not client-reported error logs, which can grow quickly — it paginates and supports filtering instead.
- New content resource = model + migration + Resource + two Form Requests (Store/Update) + Admin controller (extends `AdminResourceController`) + Public controller (extends `PublicResourceController`) + routes registered in both `admin.php` (`apiResource`) and `public.php` (`index` only).

**Update endpoints return a message, not the updated resource.** Every PATCH/PUT action — `update()`, `updateStatus()`, `assignRole()`, and equivalents, on both `admin.php` and `member.php` — responds with `response()->json(['message' => __('api.<domain>.<key>')])` instead of re-serializing the model: the client already sent the data, it doesn't need it echoed back. Keys are localized in `lang/{en,ar}/api.php`, grouped by domain (`member`, `admin`, `mobile`, alongside the existing `auth`/`wallet`/`system`/`validation` groups) — add a new key there rather than inlining a string. This is the default for any new update-style endpoint; `store()` (POST/create) is the one exception, since the client typically needs the created resource back (e.g. its new ID), and `index()`/`show()` (GET) are unaffected.

**Two separate auth systems share the one `users` table:**
- **Members** authenticate with **phone + password** (`MemberAuthController` in `auth.php`); WhatsApp OTP is no longer a login, only the gate on sign-up and password recovery — see `docs/decisions/member-auth-hybrid.md`. Sign-up is two steps: `POST auth/register` validates the whole profile (name, optional unique email, password, and that the phone is free) and *only then* sends a code, parking the profile in `pending_registrations` (password already hashed, expiring with its code, swept by an hourly `model:prune`); `POST auth/register/verify` takes only `{phone, code}`, spends the code, and creates the `User` + wallet + `member` role in one transaction that also drops the parked row. Nothing lands in `users` until the code comes back. `POST auth/login` is the everyday path; `MemberPasswordController` owns `password/forgot` → `password/reset`. There is no generic `otp/request`/`otp/verify` any more, and no client-supplied `purpose`: each endpoint fixes its own `OtpPurpose` (`registration` | `password_reset`), and `OtpService` refuses a code minted for the other flow with an explicit 422. The send channel stays behind the `OtpProvider` interface, bound in `OtpServiceProvider` by `config('services.otp.driver')`. Only `MockOtpProvider` (logs the code) exists today — a `whatsapp` driver plugs in there later without the service or controller changing.
- **The member surface and the operations dashboard are sealed off from each other**, and it takes two checks, not one (`docs/decisions/member-auth-hybrid.md` §11). Every member auth endpoint serves accounts holding the `member` role *only* — login refuses an `operations`/`admin` account with the same generic 401 as a wrong password, because this surface has no second factor and would otherwise be a 2FA-free side door into the operations API; `password/forgot` sends nothing for them while keeping its neutral 200, since `password` is one column shared with Fortify. Separately, `TokenPairService` mints access tokens with the ability `member-app` rather than `['*']`, and the admin group carries `abilities:dashboard` — that second layer is what still holds when one person legitimately has both roles, since their roles pass any `role:` check. The dashboard is unaffected: Sanctum gives a session-authenticated user a `TransientToken` that satisfies every ability check.
- **Sessions are an access + refresh token pair**, minted only by `App\Services\Auth\TokenPairService` — registration, login and `auth/refresh` all call `issue()`, and none of them assemble a token themselves. Access tokens expire (`config/tokens.php`); refresh tokens are single-use, hashed at rest, and linked to the access token they were issued with, so logout ends one device rather than all of them. `User::deactivate()`/`block()` and a completed password reset revoke both halves — a refresh token is spendable without an access token, so deleting only the latter would leave a live way back in.
- **Operations/admin** use Laravel Fortify (email + password, 2FA-capable) for the admin dashboard. Public self-registration is intentionally disabled — every operations/admin account is created by an existing admin through `Admin\UserController`, except the very first one, which `AdminUserSeeder` creates for local dev only.
- Sanctum tokens are non-default: `User::createToken()` overrides Sanctum's `"{id}|{random}"` format with a plain 64-char hex token, SHA-256-hashed at rest — Sanctum's own hash-lookup fallback (triggered when there's no `|` in the token) still finds it, so nothing downstream needs to know.

**Authorization** is via `spatie/laravel-permission` with dynamic roles and granular permissions (`docs/decisions/rbac-permission-pilot.md`). The three built-in roles (`member`, `operations`, `admin`) are permanently protected — `App\Support\ProtectedRoles` blocks renaming or deleting them through `RoleController`, which now supports full CRUD for additional custom roles rather than just `index()`. `member` is also blocked from ever holding an admin-panel permission at all: `RoleController::update()` rejects a `permissions` key targeting the `member` role with a 422 (`api.role.member_out_of_scope`) — members never touch the admin dashboard, so member is entirely out of scope for this system; admin/operations permission edits are unaffected. Permissions are named `module.action` (e.g. `branches.delete`), auto-derived rather than hand-typed: `App\Services\Permissions\PermissionSyncService` reflects over every `AdminResourceController` subclass's actually-registered routes, plus a short manual list covering exactly four of the controllers that don't follow that pattern (`UserController`, `ErrorLogController`, `RoleController`, `SettingController`) — a deliberate, partial list for this pilot, not a claim of exhaustiveness: at least a dozen more admin controllers (`CompanyController`, `CompanyMemberController`, `CurrencyController`, `ExchangeRateController`, `ExchangeRateSuggestionController`, `PrivacyPolicyController`, and all six `Reception/*Controller` classes) also don't extend `AdminResourceController` and get zero permission coverage today — an acknowledged gap, not a silent one, since `permissions:sync` now prints an "uncovered controllers" warning listing the non-Reception half of it (see `docs/decisions/rbac-permission-pilot.md`'s "Explicitly not done here" for why Reception is excluded even from that visibility check, and the full list). Both the `permissions:sync` Artisan command and `PermissionSeeder` call `PermissionSyncService::sync()` as the single source of truth. `AppServiceProvider`'s old unconditional `Gate::before` admin-bypass is gone — `admin` is now a normal role that every sync run re-seeds with the full permission set, so it can't end up silently missing access to something new. This is a **pilot**, not a full rollout: only Branches' routes enforce `permission:`-based middleware today; every other admin resource still runs on the original `role:admin|operations`/narrower `role:admin` middleware, and converting the rest is deliberate follow-up work. On a fresh deploy, `permissions:sync`/`PermissionSeeder` must run before anyone (including `admin`) can reach Branches — an empty `permissions` table grants nothing. `Gate::policy(Company::class, CompanyPolicy::class)` is unchanged — still the only Policy in the app (D.8), a separate mechanism keyed on per-company `is_admin`/`door_access_enabled` pivot flags, not roles/permissions — but removing `Gate::before` means an admin/operations account no longer gets an automatic pass on `CompanyPolicy`'s `manageMembers`/`useDoorAccess` checks; it now needs a real `is_admin=true` row on that specific company's pivot like anyone else, a deliberate, accepted side effect (closes an unintended blanket-access gap), not an oversight. `operations` is the implementation name for the PRD's "موظف تشغيل" role (renamed from `staff` once that mapping was confirmed — see `docs/decisions/staff-operations-rename.md`).

**Physical hierarchy:** `Branch` → `Building` → `Floor` → `Zone` → `Space` → `Resource`/`SeatDesk`, plus `Device` → `DeviceCapability` off `Space`. `Branch` is the top level — there is no `District` above it; one existed briefly in Phase 1 and was removed deliberately (`docs/decisions/district-removed.md`), not left unwired. Users relate to branches via the `user_branch_memberships` pivot (`UserBranchMembership`), which carries `is_home_branch`. There is no allowed-hours concept anywhere — access is 24/7 by decision (PRD §7.1 #11); a prior `AccessHoursPolicy`/`config/access.php` implementing an 08:00–23:00 window was removed for this reason, not merely left unwired.

**i18n:** translatable fields (`Branch::name`, `Building::name`, `Founder::name/role/bio`, `Event::title/description/category`, ...) are a single JSON column cast to `array`, e.g. `{"en": "...", "ar": "..."}` — not a separate translations table. The `HasTranslations` concern's `translate($field, $locale)` reads current-locale-or-fallback (`app()->getLocale()` → `en` → `ar`) out of that array.

**Validation specifics:** phone numbers use `app/Rules/SyrianPhoneNumber` (`09XXXXXXXX`). Multi-word API resources (e.g. `community-members`) need an explicit `->parameters(['community-members' => 'communityMember'])` on the route, since Laravel's auto-derived snake_case placeholder won't implicit-bind a camelCase controller argument.
