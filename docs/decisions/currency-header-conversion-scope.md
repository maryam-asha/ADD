# `converted_amount`/`converted_currency` Are Always-Present, and Reach the Nested `plan` Too

**Status:** resolved 2026-08-11
**Owner:** Maryam Asha

## What shipped

`PlanResource::toArray()` used to add `converted_amount`/`converted_currency`
only when `$user?->preferred_currency` was set and differed from the plan's
`pricing_currency` — otherwise the keys were omitted entirely. The new
`currency` request header (`docs/superpowers/specs/2026-08-11-currency-header-design.md`)
changed that: a target currency is now always resolved via
`CurrencyResolver` (header → stored preference → `SYP` default), so
conversion is attempted unconditionally on every response that embeds a
`PlanResource`.

## Decision

**`converted_amount`/`converted_currency` are now always-present fields
(when a conversion is possible), not conditional on a preference being
set — and this reaches every embedding of `PlanResource`, not just direct
plan-listing/show responses.** Concretely, that includes the nested
`data.plan` on `MembershipResource` (`app/Http/Resources/MembershipResource.php:18`,
`whenLoaded('plan', fn () => new PlanResource($this->plan))`), which is
returned by `POST /api/v1/member/memberships` — a request that also
performs a real wallet debit. The `currency` header therefore applies
there too, not only to "plan-listing responses" as an earlier draft of
the spec stated.

## Why

`PlanResource` has exactly one `toArray()` implementation; there is no
separate code path for "a plan shown standalone" versus "a plan nested
inside a membership". Building one just to keep the nested case exempt
from the new always-on behavior would be inconsistent scope-narrowing for
no product reason — a member who just bought a plan in USD still
benefits from seeing the SYP-converted amount on the confirmation
response, exactly as they would on the listing they bought it from.

## What this changed in code

- No code changed for this decision specifically — `PlanResource` was
  already shared between direct plan responses and
  `MembershipResource.data.plan` before the header shipped
  (`app/Http/Resources/MembershipResource.php`). What changed is scope
  documentation: `docs/superpowers/specs/2026-08-11-currency-header-design.md`
  §2 now names the nested case explicitly instead of implying the change
  was limited to plan-listing responses.
- New test: `tests/Feature/Membership/MembershipPurchaseTest.php` asserts
  `data.plan.converted_amount`/`data.plan.converted_currency` are present
  on the `POST /api/v1/member/memberships` response when a conversion is
  triggered.

## Guard

`tests/Guards/PreferredCurrencyIsDisplayOnlyTest.php` — the header path
gets its own test method (`test_the_currency_header_does_not_mutate_pricing_records`)
proving the `currency` header, like the stored preference before it,
never mutates any pricing, wallet, or wallet-transaction record. That
guard already covers the debit performed by the membership-purchase
endpoint, so the always-on conversion added here rides on the same
guarantee rather than needing a new one.
