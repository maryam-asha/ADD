# Business hours: branch-level schedule, exceptions, and resolution

**Status:** resolved 2026-08-15. **Owner:** Maryam Asha.
**Type:** design doc for a new capability (2026-08-15 decision session,
Phase 2), building on `settings` (Phase 1, merged `acb6a5c`). Covers the
capability only — Sprint 3 (bookings, sessions, session auto-closure)
consumes this and is out of scope here. See
[business-hours-prd-11-partial-reversal.md](business-hours-prd-11-partial-reversal.md)
for why this is NOT a reversal of PRD decision #11 (physical access).

## What this adds

Two tables under the existing `Foundation` domain:
- `business_hours` — a recurring weekly schedule per branch. Multiple rows
  for the same (branch, weekday) express a two-period day (e.g. a midday
  closure). Zero rows for a weekday means closed that day.
- `business_hour_exceptions` — date-specific overrides per branch, fully
  replacing (not merging with) the weekly schedule for that date. Supports
  both "closed entirely" (`is_closed = true`, the sole row for that date)
  and "different hours" (`is_closed = false` rows, same two-period
  support as the weekly schedule).

`App\Domain\Foundation\Services\BusinessHoursService` resolves both into
two consumer-facing methods: `isWithinBusinessHours(instant, branch): bool`
and `periodsFor(date, branch): array`. Admin CRUD exists for both tables.

## Decision

- **Scoped to branch, not space.** Business hours are a property of the
  branch (the physical building's operating schedule), not of individual
  bookable spaces — a per-space quiet-hours or availability concept, if
  ever needed, is a distinct, unbuilt feature.
- **No midnight-crossing shifts in v1.** `close_time` must be strictly
  greater than `open_time` (same-day comparison only), enforced by
  validation with a clear error, not left ambiguous. A branch open past
  midnight would need a different data model (e.g. a shift that spans two
  calendar dates) — deliberately out of scope for this phase.
- **Two periods per day are supported**, on both the weekly schedule and
  exceptions, via multiple rows for the same (branch, weekday) or
  (branch, date) rather than a single row with a "second period" pair of
  columns — this scales to any number of periods without a schema change,
  though the UI/API only needs to support two today.
- **`business_hour_exceptions` needs an explicit `is_closed` flag**,
  unlike `business_hours`. For the weekly schedule, "zero rows for this
  weekday" unambiguously means closed — there's nothing to fall back to.
  For exceptions, "zero rows for this date" means "no exception, use the
  weekly schedule" — a genuinely different meaning from "closed" — so
  "closed" needs its own explicit signal rather than reusing "absence of
  rows."
- **Resolution order:** an exception for the date, if any, is authoritative
  and fully replaces the weekly schedule (it does not merge with it — a
  single-period exception on a normally-two-period day means exactly one
  period that day, not the exception period plus a leftover weekly one).
  No exception → the weekday's schedule rows. No rows at either level →
  closed.
- **Boundary convention: both `open_time` and `close_time` are inclusive.**
  An instant exactly at either edge counts as within business hours. This
  mirrors the wider decision session's booking rule (a booking may start
  exactly at opening and end exactly at closing — neither is "before" nor
  "after"), so the single-instant check built here and the future
  start/end range check Sprint 3 builds share one convention rather than
  needing separate open/closed rules for each.
- **Single global timezone**, not per-branch. `app.timezone` (a `Setting`,
  default `Asia/Damascus`) is what every open/close comparison resolves
  through for every branch. `branches.timezone` is a pre-existing (Phase
  1), unrelated, unused plain string column — this phase does not read,
  write, or otherwise touch it; a future phase may reconcile or remove it,
  but that's out of this phase's scope.
- **Time-of-day values are plain `H:i` strings**, not a native `TIME`
  column — matching the precedent already established by
  `Setting`'s `SettingValueType::Time` handling, and avoiding introducing
  the first `TIME`-typed column in this codebase for a value that's
  simplest to validate and compare as a zero-padded string.
- **`DayOfWeek` is a string-backed enum** (`'sunday'`..`'saturday'`), not
  int-backed, matching every other enum in this codebase (17 existing
  examples, all string-backed) even though Carbon's own `dayOfWeek`
  accessor is numeric — the translation happens once, in
  `DayOfWeek::fromCarbon()`.

## Why

See "Decision" above — each bullet states its own reasoning inline.

## What this changed in code

- `App\Domain\Foundation\Enums\DayOfWeek`.
- `App\Domain\Foundation\Models\{BusinessHour,BusinessHourException}`,
  migrations, factories; `Branch::businessHours()` /
  `Branch::businessHourExceptions()` relationships.
- `App\Domain\Foundation\Services\BusinessHoursService`.
- `App\Rules\NoOverlappingPeriod`.
- `App\Http\Controllers\Api\V1\Admin\{BusinessHourController,BusinessHourExceptionController}`,
  their Form Requests and Resources.
- Routes: `GET|POST /api/v1/admin/business-hours`,
  `GET|PUT|PATCH|DELETE /api/v1/admin/business-hours/{businessHour}`, and
  the same shape for `business-hour-exceptions`.
- `app.timezone` added to `SettingSeeder`.

## Guard

No dedicated guard test — this is new, additive capability with no PRD
decision locking its specific shape (unlike PRD #11, which
[business-hours-prd-11-partial-reversal.md](business-hours-prd-11-partial-reversal.md)
addresses separately). `tests/Unit/Domain/Foundation/BusinessHoursServiceTest.php`
covers every enforcement rule (resolution order, closed-entirely,
two-period gaps, boundary inclusivity, branch isolation, timezone
correctness near a day boundary) by construction — reintroducing a bug in
any of these would fail that suite, not a schema-shape guard.
