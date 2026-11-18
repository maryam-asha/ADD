# Design: Display Currency, User Profiles, Community Taxonomy

**Status:** approved for planning
**Owner:** Maryam Asha
**Date:** 2026-08-09

## Context

Three independent units of work, each touching a different part of the schema. They share no tables and can be implemented/tested in any order, but all three follow the same project conventions: diff-based migrations (never rebuild an existing table), a `docs/decisions/` entry for any reversal of a previously locked decision, an executable guard test in `tests/Guards/` for every new constraint, and the single-column JSON translation pattern (`{"ar": ..., "en": ...}`) for any bilingual field.

Two codebase-reality discoveries shaped this design and are recorded here so they aren't re-discovered later:
- `exchange_rates` and any money formatter do not exist yet — only proposed in docs (`docs/decisions/money-model.md` §A.4 recommends a `Domain\Finance\Money` value object, never built).
- `preferred_language` on `users` currently has no request-header input and no member-facing write endpoint at all — it's set once (hardcoded to `'ar'`) at OTP signup in `MemberAuthController::verifyOtp()`, and only readable via `GET /auth/me`.
- `partners.partner_type` (7-value enum) described in the ERD/build-plan docs was never actually implemented. The real column is `partners.category` enum(`local`, `global`) — a different, 2-value enum that predates the taxonomy proposal.

## Unit 1 — User Preferred Display Currency

**Goal:** let a member choose a currency for *displaying* prices, fully independent of the currency the system actually charges in.

### Schema

- Diff migration on `users`: add `preferred_currency` string(3) nullable, alongside existing `preferred_language`.
- New table `exchange_rates` (single canonical table, used both for live display conversion here and for transaction snapshots elsewhere in the Money Model — no duplicate table):
  - `id`
  - `rate_usd_to_syp` — `decimal(12,4)` (must be `decimal`, not `float`/`double` — enforced by the existing `MoneyIsDecimalOnlyTest` guard)
  - `effective_from` — timestamp, indexed
  - `set_by` — FK → `users`, nullable
  - `timestamps`
  - "Current rate" = latest row where `effective_from <= now()`. "History" = the full table ordered by `effective_from` desc — no extra column needed, the table is append-only by convention (rows are never updated or deleted, only inserted).
- New namespace `App\Domain\Finance` (first table to live there): `Models\ExchangeRate`.

### Currency scope

`preferred_currency` and all conversion logic are restricted to `USD`/`SYP` only — the only pair `exchange_rates` models. Any other ISO code is rejected at the request-validation layer (`in:USD,SYP`), not silently accepted with no conversion path.

### Endpoints

- `PATCH /api/v1/member/preferences/currency` and `PATCH /api/v1/member/preferences/language` — two separate endpoints (not a combined profile endpoint), one `Member\PreferencesController` with `updateCurrency`/`updateLanguage` actions, one Form Request each (`UpdateCurrencyPreferenceRequest`, `UpdateLanguagePreferenceRequest`). Both go in `routes/api/v1/member.php`.
  - Making `preferred_language` writable post-signup is a genuine reversal of prior behavior (previously set once, never exposed for update) — recorded as its own entry in `docs/decisions/` (see Decisions below), separate from the currency work.
- `Admin\ExchangeRateController`:
  - `index` — returns all rows ordered by `effective_from` desc (current + history falls out of this ordering; no separate "current" endpoint).
  - `store` — validates `rate_usd_to_syp` (numeric, > 0) and `effective_from` (date); inserts a new row; **never** mutates a past row. `set_by` is always `auth()->id()`, never client-supplied. Calls the existing `logSensitiveAction()` (`App\Concerns\LogsSensitiveActions`, already wraps spatie's `activity_log` — the physical table behind the PRD's conceptual "audit_logs"; no new table needed).
  - Doesn't extend `AdminResourceController` — no `order` column, no update/destroy, same reasoning as `UserController`.

### Non-authoritativeness

`preferred_currency` must never influence `transactions.currency`/`usd_equivalent`, `pricing_currency` on `plans`/`spaces`/`business_cafe_orders`, or any charge/payment/wallet-debit logic. Enforced by a guard test (see Guards below), not just left as a prose rule.

### Live conversion on price-exposing responses

`PlanResource` (currently the only resource exposing a price) gains optional `converted_amount`/`converted_currency` fields, populated only when: the request has an authenticated user, that user's `preferred_currency` is set and differs from the plan's `pricing_currency`, and a current `exchange_rate` row exists. Computed via a small `App\Domain\Finance\Services\CurrencyConversionService` (raw decimal math: multiply/divide by `rate_usd_to_syp` depending on direction) — **not stored, not snapshotted**. `exchange_rate_snapshot` remains reserved for actual financial transactions only.

**Formatter is explicitly out of scope for this unit.** No thousands separators, symbol placement, or decimal-place formatting — the converted amount is returned as a plain decimal + ISO currency code. A display-string formatting spec will be provided separately in a later round. (Earlier discussion of "Levantine month names/numerals" was a mix-up with an unrelated date formatter and does not apply to money output.)

### Guard test

`tests/Guards/PreferredCurrencyIsDisplayOnlyTest.php` — a feature test that PATCHes a member's `preferred_currency` and asserts no row in `transactions`, `wallets`/`wallet_transactions`, or the pricing columns on `plans`/`spaces`/`business_cafe_orders` changed.

## Unit 2 — Personal + Professional User Profiles

**Goal:** let a member self-fill two simple profiles from their own account, and optionally expose them on a new public directory.

### Schema

- `user_personal_profiles`: `id`, `user_id` (FK → users, unique — 1:1), `bio` text nullable, `city` string nullable, `avatar_url` string nullable, `timestamps`.
- `user_professional_profiles`: `id`, `user_id` (FK → users, unique — 1:1), `job_title` string nullable, `company_name` string nullable, `industry` string nullable, `linkedin_url` string nullable, `timestamps`.
- Plain strings, **not** the `{ar, en}` JSON translation pattern — that convention is for admin-curated bilingual marketing copy (`community_members`, `partners`); this is a member typing about themselves in whichever single language they use.
- No functional gating: filling either profile is not wired to any membership-ladder logic (Community → ADD Members → ADD Club).

### Endpoints

- `Member\PersonalProfileController@show/@update` and `Member\ProfessionalProfileController@show/@update` — `update` upserts (creates the row on first write, since filling a profile is optional). One Form Request per controller.
- Consent: reuse the existing `consents` table as-is — `subject_type = user`, `consent_type = public_directory` — no schema change. Add `scopeActive()` and `grant()`/`revoke()` helpers to the existing `Consent` model (append-only history, same pattern as `exchange_rates`: granting when no active row exists inserts a new row; revoking sets `revoked_at` on the currently active row; re-granting after a revoke inserts a fresh row rather than reusing the old one).
- New endpoint `PATCH /api/v1/member/consents/public-directory` (`{granted: true|false}`) using those helpers.

### Public Member Directory

- New, separate public surface — **does not touch `community_members` or its `CommunityMembersNoUserLinkTest` guard** (that table can never link to `user_id`; this is a different table entirely).
- `GET /api/v1/public/member-directory` (`Public\MemberDirectoryController@index`), `MemberDirectoryResource`.
- Includes any user with an active `public_directory` consent grant **and** at least one profile row (`user_personal_profiles` and/or `user_professional_profiles` — "filled" = the row exists, no per-field completeness threshold).
- No membership-tier gating (verification status in `add_members` is irrelevant), no categorization (the `community_categories` taxonomy from Unit 3 is scoped to `community_members`/`partners` only).
- Computed fresh on every request — revoking consent removes the user from the listing on the next read, no caching.
- Response never includes phone/email regardless of consent — `public_directory` consent governs directory visibility, not contact-detail exposure. Only name + profile fields are shown.

## Unit 3 — Unified Community Categories (reverses two locked enums)

**Goal:** replace two separate hardcoded enums with one shared, admin-manageable lookup table.

### Schema

- New table `community_categories`: `id`, `key` (string, unique), `label` (json, `{ar, en}`), `icon` (string, nullable), `sort_order` (int), `is_active` (bool, default true — deactivate, never hard-delete, to preserve FK integrity), `created_by` (FK → users, nullable), `timestamps`. No final category values seeded — schema/migration only.
- Diff migration on `community_members`: drop `category` enum, add `category_id` (nullable FK → `community_categories` — nullable because there's no seed data yet to backfill existing rows against).
- Diff migration on `partners`: drop the *real* `category` enum(`local`, `global`) — confirmed as the actual target despite the docs referring to a never-implemented `partner_type` — add `category_id` (nullable FK → `community_categories`).
- Both FKs point at the same table — one shared taxonomy, not two.
- Model: `App\Domain\Ecosystem\Models\CommunityCategory`.
- "All / view all" aggregate filters are UI-only and must never be persisted as a row here.

### Guard test

`tests/Guards/SharedCommunityTaxonomyTest.php` — asserts: (1) `community_members.category_id` and `partners.category_id` both have a foreign key to `community_categories` (same table, not divergent lookups); (2) attempting to create either model with a `category_id` pointing at an `is_active = false` category fails.

### Decisions doc

One new `docs/decisions/unified-community-categories.md`, following the exact structure of `district-removed.md` (Status/Owner → What each source said → Decision → Why → What this changed in code → Guard), covering both enum reversals as a single decision (they're one initiative). Add a catalog entry + PRD §7.1 decision-map row in `docs/decisions/README.md`.

## Cross-cutting: Decision docs to add/amend

1. **New:** `docs/decisions/preferred-language-mutable.md` — records that `preferred_language` becomes member-writable post-signup for the first time (Unit 1).
2. **Amend (not new):** `docs/decisions/money-model.md` — add a short note that `exchange_rates` is now implemented (Unit 1), rather than opening a second decision doc for infrastructure that fulfills an already-locked decision.
3. **New:** `docs/decisions/unified-community-categories.md` — the two enum reversals (Unit 3).
4. `docs/decisions/README.md` gets a catalog/table entry for each new doc.

## Out of scope (explicitly)

- Actual transaction/pricing currency logic — the Money Model itself is unchanged.
- ADD Club modeling; the referral system.
- Final `community_categories` seed values.
- Any profile field list beyond the minimal draft above.
- Money display-string formatting (separators, symbol placement, decimals) — deferred to a later round.
- Any currency beyond `USD`/`SYP`.
