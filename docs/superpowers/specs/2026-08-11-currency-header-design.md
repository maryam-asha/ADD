# `currency` request header + Postman documentation for `lang`/`currency`

## Problem

`preferred_currency` exists on `users` (nullable, no default) and is
consumed in exactly one place — `PlanResource`, which opportunistically
resolves the Sanctum user (even on routes with no `auth:sanctum`
middleware) and only adds `converted_amount`/`converted_currency` to a
plan's JSON when a preference is set and differs from the plan's own
pricing currency. There is no per-request override, no default currency,
and neither the `lang` header (already shipped) nor any currency
mechanism is documented in the team's Postman collection.

## Goals

- A `currency: USD|SYP` request header (case-insensitive) overrides
  `preferred_currency` for that request only, exactly the way `lang`
  overrides `preferred_language` — never causing a 4xx by itself.
- `SYP` becomes the system-wide default: new users get it at the database
  level, and `PlanResource` converts to it by default when nothing else
  is specified (header or stored preference), including for anonymous
  requests.
- The Postman collection gains `lang`/`currency` headers on every request
  (with environment-variable defaults `ar`/`SYP`) and two new requests for
  the existing-but-undocumented `PATCH .../preferences/language` and
  `PATCH .../preferences/currency` endpoints.

## Non-goals

- No general-purpose "current currency" mechanism (no middleware, no
  listener, no `App::setLocale()`-style global state). `preferred_currency`
  has exactly one consumer today (`PlanResource`); building broader
  infrastructure for a hypothetical second consumer is premature — YAGNI.
  If a second resource needs display-currency conversion later, extracting
  a shared mechanism at that point is cheap; guessing its shape now is not.
- No change to `CurrencyConversionService`'s USD/SYP-only conversion math,
  and no change to the "display-only, never mutates pricing records"
  guarantee (`tests/Guards/PreferredCurrencyIsDisplayOnlyTest.php`).

## 1. `CurrencyResolver`

New: `App\Domain\Finance\Services\CurrencyResolver`, alongside the
existing `CurrencyConversionService` in the same namespace:

```php
class CurrencyResolver
{
    public function resolve(Request $request, ?User $user): string
    {
        $header = strtoupper((string) $request->header('currency'));

        if (Currency::tryFrom($header) !== null) {
            return $header;
        }

        return $user?->preferred_currency ?? Currency::Syp->value;
    }
}
```

A plain resolver rather than middleware-plus-listener: unlike `lang`,
there is no auth-timing ordering trap here, because `PlanResource` already
resolves the user itself, synchronously, at the exact point it needs the
value (`$request->user('sanctum')`, inside `toArray()`). Introducing a
middleware would add a moving part that solves a problem this call site
doesn't have.

Header values are validated against `Currency` (`USD`, `SYP`) — the same
enum `UpdateCurrencyPreferenceRequest` already validates against — via
`Currency::tryFrom()`, uppercased first for case-insensitivity. An
invalid or absent header falls through silently to `preferred_currency`,
then to `Currency::Syp->value`, mirroring `SetLocaleFromHeader`'s
never-4xx contract exactly.

## 2. `PlanResource` — always-on conversion

Current behavior: conversion fields are present only when
`$user?->preferred_currency` is set and differs from `pricing_currency` —
otherwise omitted entirely. New behavior: a target currency is always
resolved (via `CurrencyResolver`, defaulting to `SYP`), so conversion is
attempted unconditionally:

```php
$targetCurrency = app(CurrencyResolver::class)->resolve($request, $user);

if ($targetCurrency !== $this->pricing_currency) {
    $converted = app(CurrencyConversionService::class)->convert(
        (float) $this->price,
        $this->pricing_currency,
        $targetCurrency
    );

    if ($converted !== null) {
        $data['converted_amount'] = number_format($converted, 2, '.', '');
        $data['converted_currency'] = $targetCurrency;
    }
}
```

**Confirmed API contract change:** `converted_amount`/`converted_currency`
now appear by default on every response that embeds a `PlanResource` —
not just direct plan-listing/show responses, but also the nested
`data.plan` on `MembershipResource` (e.g. the
`POST /api/v1/member/memberships` purchase response, which
`whenLoaded('plan', ...)`s a `PlanResource` — see
`docs/decisions/currency-header-conversion-scope.md`) — including for
anonymous requests with no token at all — rather than only when a member
had explicitly set a preference. They still silently disappear if
`ExchangeRate::current()` returns null (no rate ever seeded) — an
unchanged, pre-existing fallback, not a new failure mode.

## 3. Database migration

New migration file (the original `add_preferred_currency_to_users_table`
migration is never edited once shipped):

```php
public function up(): void
{
    DB::table('users')->whereNull('preferred_currency')->update(['preferred_currency' => 'SYP']);

    Schema::table('users', function (Blueprint $table) {
        $table->string('preferred_currency', 3)->default('SYP')->change();
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('preferred_currency', 3)->nullable()->default(null)->change();
    });
}
```

Backfill runs before the schema change so no row — existing or new — is
ever null afterward, matching how `preferred_language` already defaults
to `'ar'` at the column level.

## 4. Postman collection updates

The collection (`postman/ADD-OS.postman_collection.json`) has 66 requests
across three top-level folders (`Member (App)`, `Admin (Dashboard)`,
`Public (Site)`); `lang` was never added to Postman when it shipped in
code either. A one-off PHP script — written to the OS scratch/temp
directory, run once against the checked-out repo, and deleted afterward;
never committed to `postman/` or anywhere else in the repo — will:

1. Walk every request node and add `lang: {{lang}}` and
   `currency: {{currency}}` header entries wherever missing, preserving
   the file's existing JSON formatting (escaped slashes, etc.) so the diff
   stays limited to the actual additions.
2. Add two new variables to `postman/ADD-OS-Local.postman_environment.json`:
   `lang` (default `ar`) and `currency` (default `SYP`) — switching either
   for an entire run becomes a one-line edit.
3. Add two new requests under `Member (App)`: `PATCH
   /member/preferences/language` and `PATCH /member/preferences/currency`
   — both existing endpoints (`PreferencesController`), currently absent
   from the collection.

## 5. Testing plan

- `CurrencyResolver` unit tests: valid header wins over stored preference;
  invalid/absent header falls back to the preference; no preference falls
  back to `SYP`; header value is case-insensitive.
- Feature tests on the plan-listing endpoint: anonymous request with no
  header → `converted_currency: "SYP"`; `currency: usd` header → `USD`;
  authenticated member with `preferred_currency: "USD"` and no header →
  `USD`; header overrides a stored preference.
- Extend the existing `PreferredCurrencyIsDisplayOnlyTest` guard (or add a
  sibling test) confirming a newly created user gets `SYP` by default at
  the database level, and that resolving/overriding currency never
  mutates any pricing record — the guarantee that guard already locks in.
