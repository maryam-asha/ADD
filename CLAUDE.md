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
php artisan db:seed                          # runs RoleSeeder only (see DatabaseSeeder)
php artisan db:seed --class=AdminUserSeeder  # bootstraps the first admin account (local only)

# Code style (Laravel Pint, no custom pint.json — uses Pint's defaults)
./vendor/bin/pint          # fix
./vendor/bin/pint --test   # check only, no changes

# Inspect routes
php artisan route:list
```

Tests run against an in-memory SQLite DB (`phpunit.xml`), not the MySQL connection configured in `.env`.

## Architecture

**Route composition.** `routes/api.php` only sets up the `/api/v1` prefix and middleware groups; it `require`s three split files instead of defining routes itself:
- `routes/api/v1/auth.php` — OTP request/verify/logout, unauthenticated + sanctum-authed `me`.
- `routes/api/v1/public.php` — unauthenticated reads for the marketing site.
- `routes/api/v1/admin.php` — everything here already sits behind `auth:sanctum` + `role:admin|staff`, applied once by the group wrapping the `require` in `api.php`. Don't re-add that middleware inside `admin.php`; do add narrower `role:admin` groups there for admin-only actions (e.g. user/role management).

**Two-tier abstract controller pattern** for content resources (`Founder`, `Partner`, `CommunityMember`, `Event`, ...):
- `Api\V1\Admin\AdminResourceController` — generic `index`/`show`/`destroy` (index auto-orders by an `order` column when `hasOrderColumn()` is true). Concrete controllers only implement `modelClass()`/`resourceClass()` plus their own `store`/`update`, each typed against a dedicated Form Request rather than a shared generic rules array.
- `Api\V1\Public\PublicResourceController` — generic `index` only. Concrete controllers override `scopeQuery()` to filter which rows are publicly visible (ordering, published/active flags, etc.) — that's the one axis public listings differ on.
- `Api\V1\Admin\UserController` deliberately does **not** extend `AdminResourceController`: users have no `order` column, and "removing" a user means deactivating `status`, not a hard delete — the shape genuinely differs.
- New content resource = model + migration + Resource + two Form Requests (Store/Update) + Admin controller (extends `AdminResourceController`) + Public controller (extends `PublicResourceController`) + routes registered in both `admin.php` (`apiResource`) and `public.php` (`index` only).

**Two separate auth systems share the one `users` table:**
- **Members** log in passwordless, via WhatsApp OTP (`MemberAuthController` in `auth.php`). `OtpService` (`app/Services/Otp`) generates/persists/verifies codes; the actual send channel is behind the `OtpProvider` interface, bound in `OtpServiceProvider` by `config('services.otp.driver')`. Only `MockOtpProvider` (logs the code) exists today — a `whatsapp` driver plugs in there later without the service or controller changing. On first successful verification a `User` is created and assigned the `member` role.
- **Staff/admin** use Laravel Fortify (email + password, 2FA-capable) for the admin dashboard. Public self-registration is intentionally disabled — every staff/admin account is created by an existing admin through `Admin\UserController`, except the very first one, which `AdminUserSeeder` creates for local dev only.
- Sanctum tokens are non-default: `User::createToken()` overrides Sanctum's `"{id}|{random}"` format with a plain 64-char hex token, SHA-256-hashed at rest — Sanctum's own hash-lookup fallback (triggered when there's no `|` in the token) still finds it, so nothing downstream needs to know.

**Authorization** is role-only via `spatie/laravel-permission` — three roles (`member`, `staff`, `admin`), no granular permissions yet (`RoleController::index` just lists role names). `AppServiceProvider` registers `Gate::before` so `admin` bypasses every ability check.

**Physical hierarchy:** `Branch` → `Building` → `Space` → `Device` → `DeviceCapability`. Users relate to branches via the `user_branch_memberships` pivot (`UserBranchMembership`), which carries `is_home_branch`. `AccessHoursPolicy` (`app/Services/Access`) centralizes the allowed-hours check (`config/access.php`) for future booking/check-in flows — it exists but isn't wired to any route yet.

**i18n:** translatable fields (`Branch::name`, `Building::name`, `Founder::name/role/bio`, `Event::title/description/category`, ...) are a single JSON column cast to `array`, e.g. `{"en": "...", "ar": "..."}` — not a separate translations table. The `HasTranslations` concern's `translate($field, $locale)` reads current-locale-or-fallback (`app()->getLocale()` → `en` → `ar`) out of that array.

**Validation specifics:** phone numbers use `app/Rules/SyrianPhoneNumber` (`09XXXXXXXX`). Multi-word API resources (e.g. `community-members`) need an explicit `->parameters(['community-members' => 'communityMember'])` on the route, since Laravel's auto-derived snake_case placeholder won't implicit-bind a camelCase controller argument.
