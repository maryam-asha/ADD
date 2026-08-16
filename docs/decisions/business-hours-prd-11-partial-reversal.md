# Business hours (booking) is not a reversal of PRD #11 (physical access)

**Status:** resolved 2026-08-15. **Owner:** Maryam Asha.
**Type:** clarification of guard scope, alongside a genuinely new,
separately-approved capability — not a reversal, partial or otherwise, of
a locked decision.

## What PRD decision #11 actually locked

Decision #11 states physical access is 24/7, with no time-of-day
restriction. The abolished implementation it replaced
(`config/access.php` + `App\Services\Access\AccessHoursPolicy`) gated the
BUILDING'S DOOR — whether a person's credential would open a lock during
a given window. `tests/Guards/NoAccessHoursWindowTest.php` was written to
make sure nothing re-imposes that specific restriction, anywhere, under
any name.

## What this phase adds

A business-hours concept that gates BOOKING and SCHEDULING — whether a
member can reserve a space for a given time. This is a different question
about a different system: no `Device`, lock, or TTLock code is touched by
this phase (Access/TTLock is Phase 6, not yet built), and physical entry
continues to require nothing beyond a valid access grant, unrestricted by
time — decision #9 keeps TTLock grants `Period`-typed specifically because
the main door has no lock at all, so time-restricting a room's own code
would have no enforcement effect on when someone enters the building.

## Decision

**This is not a reversal of decision #11.** Decision #11 was never about
booking. Business hours restricting *when a booking may be made* and
"access is 24/7" restricting *when a door may open* are orthogonal
questions; enforcing the first does not relax the second. The guard test's
docblock is amended to state this scope explicitly (see the guard file
itself) — its three assertions (no `config/access.php`, no
`AccessHoursPolicy` class, no reintroduction of `allowed_hours` /
`ACCESS_HOURS_(START|END)` / `isWithinAllowedHours` anywhere in `app`,
`config`, `database`, `routes`) are unchanged, unweakened, and still pass
against this phase's code — verified directly, not assumed.

## Why

The original mega-prompt for this decision session described this as a
"partial reversal" of decision #11, and asked for a decision record
saying so. On inspection, decision #11's own PRD language ("access is
24/7") and this guard's own docblock ("PRD decision #11: **access** is
24/7") are unambiguously about physical/door access, not booking hours —
so nothing in decision #11 is actually being reversed, partially or
otherwise. Recording it as a reversal would misstate what changed and
could mislead a future reader into thinking decision #11 itself was
renegotiated. Recording the actual relationship (orthogonal, guard scope
clarified) here instead avoids that.

## What this changed in code

- `tests/Guards/NoAccessHoursWindowTest.php`'s class docblock gained a
  "Scope, clarified 2026-08-15" paragraph. No assertion was added,
  removed, or altered.
- Nothing else — Business Hours' own tables, model, and service are
  recorded in [business-hours.md](business-hours.md), not here.

## Guard

[`NoAccessHoursWindowTest`](../../tests/Guards/NoAccessHoursWindowTest.php)
— unchanged assertions, confirmed still green against this phase's code.
