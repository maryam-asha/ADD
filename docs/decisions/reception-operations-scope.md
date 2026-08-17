# Reception Operations: scope cut, defaults chosen without a reply, and PRD #5's status

**Status:** resolved 2026-08-16. **Owner:** Maryam Asha.
**Type:** design doc for a new capability, alongside a scope note on an
existing locked decision.

## What this phase adds

A new `App\Domain\Booking` namespace: `bookings` and `walkin_sessions`
tables, three services (`WalkInCapacityService`, `SessionClosureService`,
`BookingCancellationService`), three admin controllers under
`Api\V1\Admin\Reception\`, and a scheduled auto-closure command — the
reception-facing check-in/check-out/settlement/cancellation/wallet-top-up
layer. Full details: [the design spec](../superpowers/specs/2026-08-16-reception-operations-design.md).

## This is a slice of the backend build plan's Phase 5, not the whole thing

`docs/architecture/2026-08-08-backend-build-plan.md` calls this domain's
full scope "Phase 5 — Booking, walk-in sessions, capacity, affected
bookings," with tables `bookings · walkin_sessions · space_capacity_slots ·
affected_bookings`. This phase builds only `bookings` and `walkin_sessions`,
and only reception's own actions on them — no slot-granularity capacity
table, no `affected_bookings`, no extension/approval flow, no booking
creation endpoint. A `PaymentMethod` enum is also pulled forward from the
still-unbuilt "Phase 4 — Finance primitives" (payment methods only, not the
full `payment_methods`/`transactions`/`Money`/exchange-rate-snapshot
system). Anyone extending this domain toward the full Phase 5/4 scope should
read this doc first.

## Decision

- **No booking-creation endpoint this phase.** Nothing in the operations
  list creates a booking — check-in and cancel both act on one that already
  exists. This was asked as a clarifying question and went unanswered; the
  recommended default was taken. Bookings are creatable only via
  factory/tinker for this phase's tests; the real member-facing creation
  flow (with granularity/approval rules) is the next phase's job. If that
  next phase instead wants reception itself to originate bookings (e.g. a
  phone booking), this needs revisiting.
- **Denormalized operator columns, not audit-log-only.** `paid_by` on
  `bookings`/`walkin_sessions`, `performed_by_user_id` on
  `wallet_transactions` (additive migration on an already-shipped table).
  Also asked and unanswered; taken because the source spec's wording
  ("record the operator... and write an audit log entry") reads as two
  distinct requirements, and because every other reception timestamp
  (`checked_in_at`, `checked_out_at`) is already a queryable column rather
  than audit-log-only.
- **Capacity counts physical presence, not reservation.** "Space has
  available capacity right now" (walk-in start) counts currently
  checked-in-and-not-checked-out bookings + walk-ins against `Space.capacity`
  — a confirmed booking that hasn't checked in yet does not count against
  a walk-in's capacity check. A future capacity-slot system (Phase 5 proper)
  might instead reserve capacity from booking creation, not check-in; this
  will need reconciling when that lands.
- **No reconciliation between a pre-checkout payment and the actual
  checkout-computed amount.** If a booking is already `paid` (e.g. by
  wallet, via the out-of-scope creation flow) and the actual checked-in-to-
  checked-out duration differs from what was originally paid for,
  `amount_owed`/`currency` are recorded for reporting only — no partial
  refund or extra charge is raised. Reconciling this is exactly the kind of
  extension/granularity logic explicitly deferred to the next phase.
- **A booking cancellation refund uses the planned window
  (`start_at`-`end_at`), not `amount_owed`.** `amount_owed` is only ever set
  at checkout, and cancellation can only happen before check-in — so it's
  always null at the point a refund is computed. The refund amount is
  whatever the (out-of-scope) creation flow would have charged for the full
  planned duration.
- **`PaymentMethod` lives in `App\Domain\Finance`,** not `Booking`, even
  though nothing else in Finance is built yet — the backend build plan
  already earmarks that domain for payment methods, so this avoids
  relocating the enum when Phase 4 eventually lands.

## PRD decision #5's status

Decision #5 ("Booking = prepaid + cancellable; session without booking =
postpaid + not cancellable") is genuinely refined here, not violated: this
phase's `payment_state`/`payment_source` model allows a booking to be
created unpaid and settled later by reception rather than strictly
requiring payment at creation — the 2026-08-15 decision session's own
framing ("payment is a state, never a precondition for creation") is the
authority for this refinement. Cancellability still applies only to
bookings; walk-in sessions expose no cancellation path at all, matching the
PRD's decision unchanged.

## Why

See "Decision" above — each bullet states its own reasoning inline.

## What this changed in code

- `App\Domain\Booking\{Enums,Models,Services,Exceptions}\*`
- `App\Domain\Finance\Enums\PaymentMethod`
- `App\Domain\Membership\Enums\WalletTransactionSource::Refund`
- `database/migrations/2026_08_16_*` (four migrations: `bookings`,
  `walkin_sessions`, `spaces.cancellation_window_minutes`,
  `wallet_transactions.{performed_by_user_id,payment_method}`)
- `App\Http\Controllers\Api\V1\Admin\Reception\*`, their Form Requests
- `routes/api/v1/admin.php` (`reception/*` routes)
- `app/Console/Commands/CloseOverdueReceptionSessions.php`,
  `routes/console.php`
- `lang/{en,ar}/api.php` (`reception` group)
- `postman/ADD-OS.postman_collection.json` (`Reception Operations` folder)

## Guard

No dedicated `tests/Guards/` entry — like `business-hours.md`, this is new
additive capability rather than a schema-shape invariant. Every enforcement
rule (precondition, failure mode, boundary, the auto-close/manual-close
cross-check) is covered by `tests/Feature/Booking/*` and
`tests/Unit/Domain/Booking/*` instead — see this plan's tasks for the exact
list. `docs/decisions/README.md`'s PRD §7.1 table row for decision #5 is
updated to point here rather than leaving it silently stale at "— (Phase 5)".
