# Settings: a new domain for cached, typed runtime config

**Status:** resolved 2026-08-15. **Owner:** Maryam Asha.
**Type:** design doc, written ahead of the phase that implements it — the
2026-08-15 decision session names `settings` as the enabler five other
subsystems (business hours, booking, guests, profile completion, module
toggles) depend on, so it is built first.

## What this adds

A new `App\Domain\Settings` domain: one `settings` table (key, scope,
typed value), a `SettingService` that is the only read/write path, and an
admin endpoint to list/update the seeded global keys. Not anticipated in
the 2026-08-08 backend build plan's original 10-domain list — this session
adds an 11th.

## Decision

- **Domain placement:** `App\Domain\Settings`, not a non-domain
  `App\Services\*` class. Unlike Otp (a cross-domain *service* whose model
  lives in Identity), `Setting` the model and `SettingService` are tightly
  coupled — one wraps the other — so they belong together, mirroring how
  Membership holds `Wallet` + `WalletService` together.
- **Not added to `DomainLayerBoundaryTest`'s `FORBIDDEN` map.** Ecosystem
  and Experience must be able to read settings (e.g. `module.cafe.is_enabled`
  is read from the Experience-domain café code) — Settings is deliberately
  left as a domain everything may depend on, not a Core domain.
- **Scope column, not a scoped row per space this session.** The `settings`
  table supports `scope_type`/`scope_id` for a future per-entity override,
  but every key this session needs is global. Per-space booking overrides
  (`slot_granularity_minutes`, `cancellation_window_minutes`,
  `requires_approval`, `buffer_minutes`) are plain columns on `spaces`
  itself, built by the Booking plan — not scoped `Setting` rows.
- **`scope_type`/`scope_id` default to `'global'`/`0`, not `null`.** MySQL
  allows repeated `NULL`s in a unique index, so `(key, scope_type,
  scope_id)` with nullable scope columns would let two "global" rows for
  the same key coexist silently. A non-null sentinel keeps the unique
  index meaningful.
- **Cache-aside with no TTL.** `SettingService::get()` calls
  `Cache::rememberForever()`; `set()` always calls `Cache::forget()` on
  its own key right after writing. A TTL would mean a booking request
  occasionally reads a stale value for no reason, when exact invalidation
  on write is no harder to build.
- **Seeded defaults for keys with no spec-given value are assumptions, not
  locked decisions** — flagged here for confirmation before this reaches
  production:
  - `booking.cancellation_window_minutes` → 60
  - `booking.slot_granularity_minutes` → 30
  - `booking.overrun_grace_minutes` → 10
  - `profile.completion_threshold` → 80
  - `module.cafe.is_enabled` → `true`

  The other three keys have spec-given defaults: `booking.min_duration_minutes`
  = 60, `booking.buffer_minutes` = 0, `guest.host_approval_timeout_seconds` = 120.

## Why

See "Decision" above — each bullet states its own reasoning inline; there
is no single overriding rationale to separate out.

## What this changed in code

- New migration `2026_08_15_090000_create_settings_table.php`.
- New `App\Domain\Settings\{Models\Setting,Enums\SettingScope,Enums\SettingValueType,Services\SettingService}`.
- `database/seeders/SettingSeeder.php`, called from `DatabaseSeeder::run()`.
- `App\Http\Controllers\Api\V1\Admin\SettingController` (`index`, `update`),
  `App\Http\Requests\Admin\UpdateSettingRequest`, `App\Http\Resources\SettingResource`.
- Routes: `GET /api/v1/admin/settings` (admin + operations), `PATCH
  /api/v1/admin/settings/{key}` (admin only).
- `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` extended with the
  `Setting::class` entry.

## Guard

[`EnumColumnsHaveBackedEnumCastsTest`](../../tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php)
covers the `scope_type`/`type` enum casts. No dedicated guard exists for
the scope-column design itself (e.g. "no scoped `Setting` row exists yet")
— there is nothing to regress until a later domain adds one, at which
point that domain's own plan is where a guard (if warranted) belongs.
