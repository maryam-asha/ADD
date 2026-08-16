# Design: Reception Operations (Phase 3)

**Status:** approved for planning
**Owner:** Maryam Asha
**Date:** 2026-08-16

## Context

Phase 1 (Settings) and Phase 2 (business hours + exceptions) are merged on
`master`. This phase is the reception-facing operational layer sitting on
top of both: the actions a front-desk (`operations`-role) employee performs
on a member's physical presence and payment, end to end. Full booking rules
— slot granularity, extension requests, the `requires_approval` flow, guest
visits, groups, `contact_links` — are explicitly deferred to a later phase;
this phase builds only the minimum schema needed to support reception
operations, per the source decision session's own scope-discipline
instruction.

Two codebase-reality notes worth recording so they aren't re-discovered
later:

- `App\Domain\Booking` does not exist yet, but it is not a new decision —
  `docs/architecture/2026-08-08-backend-build-plan.md` §A.1 already reserves
  it for exactly `bookings`/`walkin_sessions`/capacity, and
  `tests/Guards/DomainLayerBoundaryTest.php` already lists `Booking` in its
  `FORBIDDEN` map for `Ecosystem`/`Experience`, years before this phase
  exists — the guard is already in force the moment the domain is created.
- `booking.cancellation_window_minutes` (global default, 60) is already
  seeded by `SettingSeeder` (2026-08-15 decision session). This phase adds
  only the per-space override column; the global fallback already exists
  and needs no new setting key.
- This is a scoped-down slice of what the build plan calls **Phase 5**
  ("Booking, walk-in sessions, capacity, affected bookings") plus a sliver
  of **Phase 4** ("Finance primitives" — specifically just a `PaymentMethod`
  enum, not the full `payment_methods`/`transactions`/`Money`/exchange-rate-
  snapshot system). Full Phase 4 and the rest of Phase 5
  (`space_capacity_slots`, `affected_bookings`, extension/approval) are not
  built here. This needs to be stated explicitly in the required decision
  doc so a future reader doesn't mistake this for the full Phase 5.

## Scope decisions made without a reply (flagged, not silently assumed)

Two clarifying questions were asked and went unanswered; per this session's
Auto Mode guidance ("make the reasonable call and keep going"), the
recommended option was taken for each. Both are cheap to reverse before
implementation if wrong:

1. **No booking-creation endpoint this phase.** Nothing in the phase's own
   endpoint list (§2.1–§2.7) creates a booking — §2.1 (check-in) and §2.6
   (cancel) both act on one that already exists. Bookings are creatable only
   via factory/seeder for this phase's tests; the real member-facing
   creation flow (with granularity/approval rules) is the next phase's job.
2. **Denormalized operator columns**, not audit-log-only. §2.5 and §2.7 both
   say to "record the operator" *in addition to* writing an audit-log entry
   — read literally as two distinct requirements, not one. `paid_by` lands
   on `bookings`/`walkin_sessions`; a new nullable `performed_by_user_id`
   lands on the already-shipped `wallet_transactions` table (additive
   migration, doesn't touch any existing row).

## Domain & schema

New `App\Domain\Booking` namespace: `Models/`, `Enums/`, `Services/`,
matching the domain-per-namespace convention (`Actions/`/`Policies/`/`Data/`
created only if actually needed — not anticipated here).

### `bookings`

```
id
space_id            FK spaces
user_id              FK users (the member — always a User row; a company
                     member is still just a member role-wise, per D.8)
start_at             datetime
end_at               datetime
status               string, BookingStatus enum: confirmed | cancelled
payment_state        string, PaymentState enum: paid | unpaid
payment_source       string, nullable, PaymentSource enum: wallet | cash
checked_in_at        datetime, nullable
checked_out_at       datetime, nullable
termination_source   string, nullable, TerminationSource enum: reception | auto
amount_owed          decimal(10,2), nullable — filled at checkout
currency             string, nullable — copied from space.pricing_currency
                     at checkout (money-model.md: never forced to USD, no
                     usd_equivalent column)
payment_method       string, nullable, App\Domain\Finance\Enums\PaymentMethod:
                     cash | sham | mtn | syriatel — filled at settlement
paid_by              FK users, nullable — the reception/admin operator
paid_at              datetime, nullable
cancelled_at         datetime, nullable
timestamps
```

### `walkin_sessions`

Same shape minus `start_at`/`end_at`/`status`/`cancelled_at` — a walk-in has
no planned window and, per PRD decision #5 ("session without a booking =
postpaid and not cancellable"), no cancellation path at all:

```
id
space_id, user_id
checked_in_at         datetime (set at creation)
checked_out_at        datetime, nullable
payment_state, payment_source, termination_source,
amount_owed, currency, payment_method, paid_by, paid_at   — identical convention to bookings
timestamps
```

### `spaces` (additive migration on an already-shipped table)

```
cancellation_window_minutes   int, nullable   — per-space override; falls
                                                 back to the global
                                                 booking.cancellation_window_minutes
                                                 setting (decision #4)
```

### `wallet_transactions` (additive migration on an already-shipped table)

```
performed_by_user_id   FK users, nullable   — the reception/admin operator
                                               for a manually-keyed top-up;
                                               null for any self-service
                                               wallet action (unaffected)
```

## Enums

- `App\Domain\Booking\Enums\BookingStatus`: `Confirmed = 'confirmed'`, `Cancelled = 'cancelled'`
- `App\Domain\Booking\Enums\PaymentState`: `Paid = 'paid'`, `Unpaid = 'unpaid'`
- `App\Domain\Booking\Enums\PaymentSource`: `Wallet = 'wallet'`, `Cash = 'cash'`
- `App\Domain\Booking\Enums\TerminationSource`: `Reception = 'reception'`, `Auto = 'auto'`
- `App\Domain\Finance\Enums\PaymentMethod`: `Cash = 'cash'`, `Sham = 'sham'`, `Mtn = 'mtn'`, `Syriatel = 'syriatel'`
  (placed in `Finance` because the build plan explicitly earmarks that
  domain for payment methods — Phase 4 — even though the rest of Phase 4
  isn't built here; this avoids relocating it later.)

All string-backed, matching the existing 17-enum convention.

## Shared session-lifecycle logic

`Booking` and `WalkinSession` share an identical closeout/settlement shape
by design (the spec states walk-ins follow "the same convention as
bookings"). Rather than duplicating checkout/settlement logic per model,
both models implement a small shared contract
(`App\Domain\Booking\Concerns\HasReceptionSession` — the checked_in_at/
checked_out_at/payment_*/termination_source accessors), and one
`App\Domain\Booking\Services\SessionClosureService` handles both:

- `closeOut(Booking|WalkinSession $session, Carbon $enteredTime, User $operator): void`
  — validates `$enteredTime >= checked_in_at`, `$enteredTime <= branch's
  closing time for that day` (via `BusinessHoursService`, using
  `Space→Building→Branch` to resolve the branch), not already checked out.
  Sets `checked_out_at`, `termination_source = reception`. Computes
  `amount_owed` from **actual** `checked_out_at − checked_in_at` duration ×
  `Space.hourly_rate`, `currency = Space.pricing_currency` — the actual
  elapsed time, not the booking's originally planned window, so early
  departure or overrun is billed correctly for both entity types uniformly.
- `autoClose(Booking|WalkinSession $session): void` — identical effect,
  `termination_source = auto`, closing time as the entered time, no
  operator.
- `settlePayment(Booking|WalkinSession $session, PaymentMethod $method, User $operator): void`
  — validates checked out (`amount_owed` not null) and currently unpaid.
  Sets `payment_state = paid`, `payment_method`, `paid_by`, `paid_at`.
  "Write the same wallet-transaction-style audit trail used elsewhere" is
  read as: use the same `LogsSensitiveActions` pattern every wallet-adjacent
  admin action already uses (`logSensitiveAction('payment_settled', ...)`),
  **not** create a real `WalletTransaction` row — no wallet is touched by a
  cash/sham/mtn/syriatel settlement, only by the wallet top-up endpoint and
  by a wallet-sourced booking payment (out of scope here, since booking
  creation is deferred).
- If a booking/session is already `paid` (e.g. paid by wallet before this
  phase's out-of-scope creation flow) and the checkout-computed actual
  amount differs from what was already collected, no reconciliation
  (partial refund / extra charge) happens — `amount_owed`/`currency` are
  recorded for reporting only, payment_state is untouched. Reconciling
  prepaid-vs-actual is exactly the kind of extension/granularity logic this
  phase explicitly defers; flagged in the decision doc.

`Booking::cancel()` (§2.6) and capacity accounting live directly on
`SessionClosureService`'s sibling, `BookingCancellationService`, and
`WalkInCapacityService` respectively — kept separate since neither is
shared with `WalkinSession`.

## Capacity accounting

"Space has available capacity right now" (§2.2) = count of currently
checked-in-and-not-checked-out rows across **both** `bookings` and
`walkin_sessions` for that space, compared against `Space.capacity` — a
physical-presence count, not a reservation/slot-overlap count (the latter
is `space_capacity_slots`, explicitly out of scope). A confirmed booking
that hasn't checked in yet does not count against a walk-in's capacity
check in this phase — flagged as an assumption in the decision doc, since a
future capacity-slot system might instead reserve capacity from booking
creation, not from check-in.

Concurrency: `DB::transaction()` wrapping a `Space::where('id', ...)
->lockForUpdate()->first()`, then the occupancy count, then the insert —
serializes two simultaneous walk-in check-ins racing for the last unit of
capacity, matching `WalletService`'s existing `lockForUpdate()` pattern.

## Routes & controllers

New `routes/api/v1/admin.php` block, nested under the file's existing
`role:admin|operations` group (no new middleware — reception staff use the
already-seeded `operations` role, per `staff-operations-rename.md`):

```
Route::prefix('reception')->group(function () {
    Route::post('bookings/{booking}/check-in', [BookingReceptionController::class, 'checkIn']);
    Route::post('bookings/{booking}/check-out', [BookingReceptionController::class, 'checkOut']);
    Route::post('bookings/{booking}/cancel', [BookingReceptionController::class, 'cancel']);
    Route::post('bookings/{booking}/settle-payment', [BookingReceptionController::class, 'settlePayment']);

    Route::post('walk-ins', [WalkInSessionController::class, 'store']);
    Route::post('walk-ins/{walkinSession}/check-out', [WalkInSessionController::class, 'checkOut']);
    Route::post('walk-ins/{walkinSession}/settle-payment', [WalkInSessionController::class, 'settlePayment']);

    Route::post('wallet-top-ups', [WalletTopUpController::class, 'store']);
});
```

Controllers under `Api\V1\Admin\Reception\`, following the `UserController`
precedent (cohesive controller per resource, several named actions,
**not** extending `AdminResourceController` — matches build plan §A.3's
explicit call-out that transactional/command-shaped resources opt out).
Each method: Form Request validation → delegate to the relevant
`Domain\Booking\Services\*` call → `logSensitiveAction(...)` → `response()->json(['message' => ...])`,
per this repo's "update endpoints return a message" convention.

`WalletTopUpController::store` reuses `WalletService::creditGeneral()` +
`WalletTransactionSource::TopUp` (both already exist, already tested) — no
parallel top-up mechanism, per the spec's own instruction.

## Business rules — preconditions / effects / failure modes

Restated per operation with the ambiguities above resolved; these match
§2.1–§2.7 of the source spec exactly except where a resolution was needed:

- **2.1 Check-in**: booking `confirmed`, `now` within business hours
  (`BusinessHoursService::isWithinBusinessHours`, resolved via
  `Booking→Space→Building→Branch`), `checked_in_at` null. 404 not found,
  409 already checked in, 422 outside business hours, 409 cancelled.
- **2.2 Start walk-in**: capacity available (locked check, above), within
  business hours. 422 no capacity, 422 outside business hours.
- **2.3 Check-out**: checked in, not checked out, entered time
  `>= checked_in_at` and `<=` that day's closing time (last period from
  `BusinessHoursService::periodsFor`). 422 time before check-in, 422 time
  past closing, 409 already checked out.
- **2.4 Auto-closure**: scheduled artisan command
  (`reception:close-overdue-sessions`), `Schedule::command(...)->everyFiveMinutes()`
  added to `routes/console.php` beside the existing `model:prune` line —
  no new scheduler file, matching the one existing pattern. Queries every
  `checked_in_at IS NOT NULL AND checked_out_at IS NULL` row whose branch is
  now past closing, closes each via `SessionClosureService::autoClose()`.
- **2.5 Settle payment**: checked out (`amount_owed` not null),
  `payment_state = unpaid`. 409 already paid, 422 not yet checked out.
- **2.6 Cancel**: `confirmed`, `checked_in_at` null, within the per-space
  (fallback global) cancellation window. 422 past window, 409 already
  checked in, 409 already cancelled. Wallet refund only if
  `payment_source = wallet` and `payment_state = paid` (calls
  `WalletService::creditGeneral()` for the refund amount). This needs a new
  `WalletTransactionSource::Refund = 'refund'` case — no existing case fits;
  reusing `TopUp` would mislabel every refund as a top-up in reporting. A
  one-line, additive enum change, no schema change (the column is already a
  plain string).
- **2.7 Wallet top-up**: `WalletTopUpController::store` — amount, `PaymentMethod`,
  target (`OwnerType::User` or `OwnerType::Company` + id, reusing the
  existing enum) → `WalletService::creditGeneral()` + `performed_by_user_id`
  + `logSensitiveAction('wallet_top_up', ...)`.

## Testing plan

- `tests/Feature/Booking/*` — one file per operation, happy path + every
  named failure mode as its own test method (per §3's instruction, not just
  happy path).
- Concurrency: two simultaneous `WalkInSessionController::store` requests
  against a space with `capacity = 1` and no existing occupant — assert
  exactly one succeeds (201) and one fails (422), never both succeeding.
- Boundary: checkout entered time exactly equal to closing time succeeds
  (inclusive, matching `BusinessHoursService`'s own boundary convention);
  one minute past fails.
- Cross-check: a session auto-closed by 2.4 returns 409 (already checked
  out) if 2.3 is then called on it manually.
- No new `tests/Guards/` entries anticipated — none of PRD §7.1's numbered
  decisions get a *new* guard here (decision #5's guard is still "—
  (Phase 5)" in `docs/decisions/README.md`; this phase is a slice of it, not
  the whole thing, so that row is updated to point at this phase's
  acceptance tests instead of leaving it silently stale).

## Postman audit

Per the source spec's §4: audit `postman/ADD-OS.postman_collection.json`
against `routes/api/v1/*.php` on `master` first, report stale/gap/outdated
findings as a table in the PR description, *then* fix. New folder
`Admin (Dashboard)/Reception Operations/` with `Check-in & Check-out/`,
`Payment Settlement/`, `Wallet Top-up/` subfolders, following the existing
`Business Hours/` nested-subfolder precedent, with a realistic example body
and one documented error-response example per failure mode above.

## Decision doc (implementation deliverable, not this design doc)

A new `docs/decisions/reception-operations-scope.md`, modeled on
`business-hours.md`'s structure, recording: this phase is a deliberate
minimum slice of the build plan's Phase 5 (+ a `PaymentMethod` sliver of
Phase 4) — not the full thing; the two flagged assumptions above (no
creation endpoint, denormalized operator columns); the capacity-counts-
presence-not-reservation choice; and the no-reconciliation choice for a
booking paid in full pre-checkout with a different actual duration. Also
updates `docs/decisions/README.md`'s PRD §7.1 table row for decision #5 to
point at this phase's tests rather than leaving "— (Phase 5)" unqualified.
