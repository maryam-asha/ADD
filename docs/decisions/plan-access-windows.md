# Plan access windows and private plans

**Status:** resolved 2026-09-24. **Owner:** Maryam Asha.
**Type:** new capability on `Plan`, plus the first case of a membership
plan affecting a walk-in session's bill.

## What was asked for

Two commercial needs on the membership `Plan`:

1. An **evening package** — a monthly subscription whose hours are usable
   only between 20:00 and 00:00.
2. **Private plans** — packages negotiated for one company's employees, which
   must not appear in any catalogue and are activated for named members by
   operations from the dashboard.

## Decisions

### The window gates billing, not the door

A plan window decides what a session **costs**, never whether someone may
physically enter. Nothing under `app/Domain/Access/` is touched, TTLock
grants stay `Period`-typed, and `UnlockService` is unchanged.

This is the same distinction `business-hours-prd-11-partial-reversal.md`
already drew: gating *scheduling and billing* is a different question from
gating *a lock*. `tests/Guards/NoAccessHoursWindowTest.php` passes unchanged,
including its check that no source file matches
`/allowed_hours|ACCESS_HOURS_(START|END)|isWithinAllowedHours/i` — the naming
here (`access_window_start`, `windowOverlap()`) deliberately avoids those
substrings, exactly as `BusinessHoursService` avoided them before.

### Billing splits at the window boundary, per hour

A 19:00–22:00 session against a 20:00–00:00 plan is **one paid hour plus two
plan hours** — not a single whole-session classification taken at check-in.
This is why the window is evaluated at **checkout**, in
`SessionClosureService::finalizeClosure()`, which is already the one place a
session is priced and is reached by both `closeOut()` and `autoClose()`.

Only the uncovered portion is priced, by shortening the range handed to
`AmountCalculator::forRange()` rather than pricing the whole session and
subtracting. That keeps `AmountCalculator`'s documented ceil-to-whole-points
rounding (Invariant 2) applied once, to the real charge.

### `H:i` string comparison is deliberately not used

The repo's existing convention compares `H:i` strings lexically
(`BusinessHoursService`, `SessionClosureService`, `CloseOverdueReceptionSessions`).
That cannot express a window crossing midnight: `"23:30" <= "00:00"` is false,
so a naive implementation would cover nothing for precisely the 20:00–00:00
package this feature exists for.

`Plan::windowOverlap()` therefore converts to minutes since midnight and
handles the wrap explicitly, walking local calendar days so DST cannot drift
the boundary off midnight. `BusinessHoursService` is left exactly as it is —
its own windows do not wrap, and changing it was out of scope.

### Exhausted plan hours fall through to cash, never to the wallet

When the plan's hour quota runs out mid-window, the remaining time is billed
at the standard rate and the member pays like an ordinary visitor.

This is why plan hours are a `PlanResourceGrant` consumed through
`ResourceQuotaService::consume()`, and **not** a wallet balance spent through
`WalletService::debit()`. `debit()` silently falls back to the general balance
when a category pool is short (`WalletService.php:180-185`), which would spend
the member's own points without telling them. `ResourceQuotaService` instead
computes remaining as grant-quantity minus a ledger sum, locks the grant row,
and refuses an over-draw.

The pool is a grant against a seeded `space-hours` `ResourceType`. Its slug is
load-bearing — `PlanHourCoverageService::RESOURCE_TYPE_SLUG` looks the type up
by it. Because `resource_consumptions` is scoped per
`(plan_resource_grant_id, membership_id)`, a plan renewal creates a new
`Membership` and the quota resets with nothing to reset by hand.

`plans.included_hours` is **not** the pool and was left alone: it is converted
to a wallet credit at purchase under a `TODO(spec-C)` admitting the
hours-as-points conversion is a known-wrong placeholder.

### Outside the window a session still starts

Being outside the plan window is never a refusal. The session is created as an
ordinary paid walk-in and the 201 response carries `data.plan_applies: false`
plus a translated `message`. A caveat on a success, not an error — the same
`message`-beside-`data` shape `BookingController::storeBatch()` already uses.
`plan_applies` is included because `message` alone is ambiguous: everywhere
else in this API it accompanies a 4xx.

Since `Admin\Reception\ArrivalRequestController::confirm()` delegates to
`WalkInSessionController::store()` and propagates its response, the kiosk QR
path is covered by the same change.

### The membership is stamped at check-in

`walkin_sessions.membership_id` is written when the session starts rather than
re-resolved at checkout, so a membership that expires mid-session or is
changed before checkout cannot retroactively rewrite the bill.

### Bookings are deliberately excluded

Only walk-ins get plan-window coverage. Bookings already have their own plan
benefit in `PlanDiscountService`, applied at creation; stacking the two would
discount the same hour twice.

### Also evaluated at scan time

When a member starts a coworking session by scanning the kiosk
(`docs/decisions/coworking-self-service-sessions.md`),
`SessionCoverageEvaluator` reads the same window and the same hour grant
(`PlanHourCoverageService::hourGrantFor()`) *before* the session starts, so the
member can be told, and asked to accept, when the plan will not cover it. The
result is stored as `walkin_sessions.coverage_snapshot`, for every walk-in,
reception's included. It is a prediction for display and consent only: it
spends nothing and never changes what closure absorbs, which is still decided by
`PlanHourCoverageService::coverableSeconds()` / `consume()` at checkout, as
described above. Bookings stay excluded. The "No member-facing view of a running
session" item under "Explicitly not done here" is superseded for `co_space`
sessions started by scan.

### The window is also when the subscription holds its seat (2026-09-30)

A subscription holds `seats` seats in its branch's co-working pool, and only
while its plan is open ([coworking-seat-pool.md](coworking-seat-pool.md)). An
evening plan therefore holds nothing during the day, and a daytime plan and an
evening plan can share one seat. `Plan::windowSegments()` returns the open
stretches as intervals, and `windowOverlap()` is now their sum, so the
midnight-wrap rule stays defined in one place.

### Private plans are assigned, never bought

`is_public = false` removes a plan from the member catalogue, the public list,
the kiosk aggregate, and the member purchase path. Admin listings are
unaffected — operations must see a private plan in order to assign it.

One deliberate exception: the **holder** of a private plan can still open its
details. Operations assigns it from the dashboard, so the holder never browsed
to it, and without this their own subscription screen would 404.

Because a private plan is never purchased, `POST /api/v1/admin/memberships`
activates it with **no wallet debit**. The purchase transaction was extracted
into `MembershipActivationService` so both paths share one implementation —
the plan-balance granting loop must run either way, or an activated member
holds a subscription with nothing in it.

## Explicitly not done here

- No per-day windows. One window applies to every day of the week; a
  `plan_access_windows` table modelled on `business_hours` would be the shape
  if that is ever needed.
- No member-facing view of a running session. Walk-ins remain reception-driven,
  so the "outside your plan hours" notice reaches the member through the
  receptionist, not the app.
- No change to how bookings price plan benefits.
- No enforcement anywhere in `app/Domain/Access/`.
