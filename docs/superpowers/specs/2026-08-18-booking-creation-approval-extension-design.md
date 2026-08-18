# Design: Booking Creation, Granularity, Approval, Extension (Phase 4)

**Status:** approved for planning
**Owner:** Maryam Asha
**Date:** 2026-08-18

## Context

Reception Operations (previous phase, merged on `master`) deliberately
deferred booking creation itself — reception can check in, check out,
cancel, and settle payment on a `Booking` row, but nothing in that phase
creates one outside a factory. This phase closes that gap: a member
actually creating a booking, with slot granularity, buffer, an approval
flag and its states, and an extension mechanism. Everything reception
already does continues to act on whatever this phase creates; none of
those endpoints' contracts change.

Source: approved decision session, 2026-08-15, decisions #1–#5, #17, #18.

## Scope decisions made without a reply (flagged, not silently assumed)

Three clarifying questions were asked and went unanswered; per this
session's Auto Mode guidance ("make the reasonable call and keep going"),
the recommended option was taken for each. All three are cheap to reverse
before implementation if wrong:

1. **Notifications are `NotificationLog` rows only, no send channel.**
   Nothing in the codebase sends a real notification today — no
   `app/Notifications/`, no `NotificationService`, and the existing
   `notification_logs` table (`channel`/`template_key`/`status`, `User`
   has a `notificationLogs()` relation) has zero writers anywhere. Rather
   than building a swappable provider interface for a mechanism nothing
   yet needs to swap, each notify point in this phase writes one
   `NotificationLog` row and stops there — the same "build the real piece
   now, leave the channel for later" shape as `MockOtpProvider`.
2. **Buffer boundary is inclusive.** A gap exactly equal to a space's
   `buffer_minutes` passes (does not get rejected). Matches
   `BusinessHoursService::isWithinBusinessHours`'s existing inclusive
   convention (an instant exactly at `open_time`/`close_time` counts as
   within hours) rather than inventing a different boundary rule for a
   sibling concept.
3. **Extension is two thin routes sharing one service**, not one
   role-branching endpoint. `member.php` and `admin.php` are each
   wholesale role-gated at the file level (no per-route role checks
   exist anywhere in either file today); a single endpoint would need to
   branch on caller role inside the controller, which nothing else in
   this codebase does. `POST member/bookings/{booking}/extend` and
   `POST admin/reception/bookings/{booking}/extend` both delegate to the
   same `BookingExtensionService`.

## Domain & schema

All additions land in the existing `App\Domain\Booking` namespace
(`Models/`, `Enums/`, `Services/`) — extending it, not forking a parallel
structure, per the phase brief's own instruction.

### `spaces` (additive migration on an already-shipped table)

```
slot_granularity_minutes   int, nullable   — per-space override; falls
                                              back to the global
                                              booking.slot_granularity_minutes
                                              setting (already seeded, 30)
buffer_minutes              int, nullable   — per-space override; falls
                                              back to the global
                                              booking.buffer_minutes
                                              setting (already seeded, 0)
requires_approval           boolean, default false   — no global fallback;
                                              a per-space toggle, not a
                                              value worth centralizing
```

Same pattern as the existing `cancellation_window_minutes` column: a
plain nullable column, null-coalesced at the consuming call site
(`$space->slot_granularity_minutes ?? $settings->get('booking.slot_granularity_minutes', 30)`)
— no new accessor method on `Space`, no new `SettingScope` case, per
that enum's own documented convention.

`booking.slot_granularity_minutes` (30), `booking.buffer_minutes` (0),
and `booking.overrun_grace_minutes` (10) are **already seeded** by
`SettingSeeder` (2026-08-15 decision session) with no consumer yet —
confirmed by reading the seeder; this phase is exactly their first
consumer. Nothing to add there.

### `bookings` (additive migration)

```
status              extends BookingStatus enum: adds pending, rejected
                     alongside the existing confirmed, cancelled
rejection_reason     string, nullable — required whenever status is set
                     to rejected; enforced in BookingApprovalService, not
                     just a request-validation rule
approved_by          FK users, nullable — mirrors paid_by
approved_at          datetime, nullable — mirrors paid_at
```

Column stays a plain `string` (not a MySQL `ENUM` type) — required by
`tests/Guards/NoNewMysqlEnumColumnsTest.php`, which the existing column
already satisfies; adding enum cases doesn't change the column type.

## Enums

- `App\Domain\Booking\Enums\BookingStatus` gains `Pending = 'pending'`,
  `Rejected = 'rejected'` (existing `Confirmed`/`Cancelled` unchanged).

No other new enums. All string-backed, matching the existing convention;
`tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` continues to pass
since `Booking::casts()` already maps `status` through the enum.

## Services (new, `App\Domain\Booking\Services`, alongside the existing three)

### `BookingCreationService`

Single entry point for member-facing creation. In order:

1. `BusinessHoursService::isWithinBusinessHours` for the window's start
   and end instants against the booking's branch (`Space→Building→Branch`)
   — both boundaries must fall within a period; reject otherwise.
2. `start_at` is a multiple of the space's effective
   `slot_granularity_minutes` (measured from local midnight) — reject
   non-conforming start times with a clear validation error, never
   silently round.
3. Duration `>= booking.min_duration_minutes` (already seeded, 60) and a
   multiple of the same granularity above that minimum.
4. Lock the `Space` row (`Space::where('id', ...)->lockForUpdate()->first()`
   — identical to `WalkInCapacityService::start()`), then inside that same
   transaction:
   - No `confirmed` or `pending` booking on the same space overlapping the
     requested `[start_at, end_at)` window.
   - Current live occupancy (checked-in-and-not-checked-out `bookings` +
     not-yet-checked-out `walkin_sessions` for the space, reusing
     `WalkInCapacityService`'s exact counting query) leaves room against
     `Space.capacity`. This is a present-moment physical-presence check,
     not a check against the requested future window — the same
     limitation `WalkInCapacityService`'s own docblock already flags as
     "an assumption to revisit when the full capacity-slot system lands";
     inherited here rather than re-solved, since building
     `space_capacity_slots` is explicitly out of scope for this phase.
   - If the space's effective `buffer_minutes` is non-zero: the window
     must not fall within that buffer of an adjacent `confirmed`/`pending`
     booking on the same space. A gap exactly equal to `buffer_minutes`
     passes (§ "Scope decisions" #2).
5. Price via `AmountCalculator::forRange()` (existing, reused as-is).
6. Payment routing via `WalletService::spendOptions()` (existing, reused
   as-is) against the appropriate spend category:
   - 0 spendable wallets → create as `unpaid`. Creation never fails for
     this reason.
   - 1 spendable wallet → debit it via `WalletService::debit()`/
     `debitGeneral()`, mark `paid`.
   - 2+ spendable wallets → **do not create the booking**; return the
     options list (same shape `WalletController::options()` already
     returns) so the client re-submits with an explicit
     `wallet_owner_type`/`wallet_owner_id` chosen from that list. This is
     "prompt for an explicit choice" read literally: the choice happens
     before creation, not as a follow-up mutation.
7. Status: `confirmed` if `Space.requires_approval` is `false`;
   `pending` if `true`. A `pending` booking already passed step 4's
   overlap/capacity check and is itself now counted by that same check for
   any later request — no separate "hold" mechanism needed, the status
   value itself is the hold.
8. If created `pending`, write one `NotificationLog` row per relevant
   `operations`/`admin` recipient (`template_key = 'booking.pending_approval'`).

### `BookingApprovalService`

`approve(Booking $booking, User $operator)` / `reject(Booking $booking,
User $operator, string $reason)`. Both lock a fresh copy of the `Booking`
row first (`Booking::whereKey(...)->lockForUpdate()->firstOrFail()` —
identical shape to `BookingCancellationService::cancel()`), then act on
the locked row, never the caller's in-memory `$booking`:

- Reject if not currently `pending` (already decided, or cancelled by the
  member in the meantime) — one shared failure mode for both actions.
- Reject `reject()` if `$reason` is empty — enforced here, not only by the
  Form Request, so the rule holds even if this service is ever called
  from somewhere other than the HTTP layer.
- Approve: `status = confirmed`, `approved_by`/`approved_at` set. Write a
  `NotificationLog` row to the member (`template_key = 'booking.approved'`).
- Reject: `status = rejected`, `rejection_reason` set,
  `approved_by`/`approved_at` still set (same audit trail on the
  rejecting operator as approval, per the phase brief). Capacity is
  released implicitly — a `rejected` booking no longer matches step 4's
  `confirmed`/`pending` overlap query, so no separate release step exists.
  Write a `NotificationLog` row to the member with the reason
  (`template_key = 'booking.rejected'`).

### `BookingExtensionService`

`extend(Booking $booking, int $additionalMinutes, User $actor)` — used by
both the member and reception routes.

1. Precondition: `checked_in_at` set, `checked_out_at` null. Reject
   otherwise.
2. `$additionalMinutes` follows the same granularity/minimum rules as
   creation (step 2/3 above), evaluated against the *extension* duration,
   not the booking's total elapsed time.
3. Lock the `Space` row (same pattern as creation), then check the
   immediately following interval (`[end_at, end_at + $additionalMinutes)`)
   on that space for a conflicting `confirmed`/`pending` booking.
   - Conflict → reject with the latest possible `end_at` stated explicitly
     in the error message (computed from the conflicting booking's
     `start_at`).
   - Free → extend `end_at`. If `payment_state = paid` and
     `payment_source = wallet`, debit the cost difference via
     `WalletService` and keep `paid`; otherwise leave/set `unpaid` for
     reception to settle at checkout — same convention as the rest of
     this domain, no new payment-state value introduced.

### `BookingCancellationService` (no code fork — verified, not assumed)

Read `cancel()`'s existing preconditions: it only rejects an
already-`Cancelled` booking or one with `checked_in_at` already set; it
never checks for `status === Confirmed`. A `pending` booking that hasn't
been checked in and isn't cancelled already satisfies both existing
guards and cancels correctly through the unmodified method. This phase
adds a test proving it (§ Testing plan), not new service code — the brief
explicitly asks to confirm existing logic already handles `pending`
before extending it, and it does.

## Concurrency — lesson applied from the start, not retrofitted

Every new read-check-write sequence against shared state locks the row
that sequence depends on and re-reads it fresh, before deciding, inside
one `DB::transaction()` — the exact shape `SessionClosureService::autoClose()`
was fixed to use after the previous phase's review:

| Operation | Locked row | Re-checked against |
|---|---|---|
| `BookingCreationService::create()` | `Space` | overlap, buffer, live occupancy — all re-queried after the lock |
| `BookingApprovalService::approve()`/`reject()` | `Booking` | current `status` |
| `BookingExtensionService::extend()` | `Space` | conflicting booking in the following interval |

New concurrency tests mirror
`CloseOverdueReceptionSessionsCommandTest::test_autoclose_does_not_overwrite_a_session_closed_concurrently_since_it_was_loaded`
exactly: two independently-fetched instances of the same row, one mutates
first via a completed request, the second (stale) instance drives the
method under test and must observe the committed state, not its own
loaded-earlier snapshot.

## Routes & controllers

`member.php` (new `App\Http\Controllers\Api\V1\Member\BookingController`):

```
Route::post('bookings', [BookingController::class, 'store']);
Route::post('bookings/{booking}/extend', [BookingController::class, 'extend']);
```

`admin.php`, nested in the existing `reception` group next to the current
booking actions (`App\Http\Controllers\Api\V1\Admin\Reception\BookingReceptionController`
gains three methods — one controller per resource, matching the existing
`checkIn`/`checkOut`/`cancel`/`settlePayment` shape, not a new controller):

```
Route::post('reception/bookings/{booking}/approve', [BookingReceptionController::class, 'approve']);
Route::post('reception/bookings/{booking}/reject', [BookingReceptionController::class, 'reject']);
Route::post('reception/bookings/{booking}/extend', [BookingReceptionController::class, 'extend']);
```

Both surfaces stay at `admin|operations` (no admin-only narrowing) —
approval/rejection is exactly the kind of front-desk action `operations`
already performs for every other reception endpoint; nothing in the
phase brief asks to restrict it further.

Form Requests, following the existing `<Verb><Noun>Request` /
`App\Http\Requests\<Admin|Member>\<Feature>\...` convention
(`authorize()` always `true`, authorization is route middleware, not the
request):

- `App\Http\Requests\Member\Booking\StoreBookingRequest` — `space_id`,
  `start_at`, `end_at`, optional `wallet_owner_type`/`wallet_owner_id`.
- `App\Http\Requests\Member\Booking\ExtendBookingRequest` — `additional_minutes`.
- `App\Http\Requests\Admin\Reception\RejectBookingRequest` — `rejection_reason` (`required`).
- `App\Http\Requests\Admin\Reception\ExtendBookingRequest` — `additional_minutes`
  (separate from the member one only because it lives in a different
  namespace by convention; same rule).

Every action: Form Request → delegate to the relevant
`Domain\Booking\Services\*` call → `response()->json(['message' => __('api.booking.<key>')])`,
per this repo's "update endpoints return a message" convention — `store()`
is the one exception and returns the created `Booking` (via a
`BookingResource`, since the client needs the new booking's id/status/
payment outcome back), matching the documented `store()`-is-the-exception
rule.

## Lang keys

New top-level `booking` group in `lang/{en,ar}/api.php`, sitting beside
`member`/`admin`/`mobile`/`reception` — covers every validation/failure
message from §2–§4 of the phase brief (outside business hours, granularity
mismatch, duration too short/wrong multiple, overlap, buffer conflict, no
capacity, not pending, rejection reason required, not checked in, extension
conflict with latest-possible-end stated) plus success messages (created,
pending, approved, rejected, extended). The existing `reception` group is
untouched — it covers check-in/checkout only. A new
`tests/Unit/Domain/Booking/BookingLangKeysTest.php`, structured like the
existing `ReceptionLangKeysTest.php`, asserts every new key exists in both
locales.

## Testing plan

`tests/Feature/Booking/` — one file per service (`BookingCreationServiceTest`,
`BookingApprovalServiceTest`, `BookingExtensionServiceTest`) plus one per
controller for the HTTP layer, happy path + every named failure mode as
its own test method:

- Start time not matching granularity → rejected, not rounded.
- Duration below minimum; duration not matching granularity above minimum.
- Buffer: adjacent booking within buffer window rejected; exactly at the
  buffer boundary passes (both asserted explicitly, per the phase brief).
- A `pending` booking blocks a second request for the same slot.
- `reject()` without a reason is rejected by the service itself — tested
  by calling the service directly, not only through the Form Request.
- Member cancels their own `pending` booking before Operations decides —
  `BookingCancellationServiceTest` gains a case proving capacity releases
  (a second creation request for the same slot succeeds afterward).
- Extension race: two extension requests for the same booking's following
  slot, concurrently (two independently-fetched instances, per the
  concurrency section above) — exactly one succeeds.
- `WalkInCapacityServiceTest`-style boundary case: current occupancy at
  capacity blocks a new booking even when the requested future window has
  no overlap (documents the present-moment-check limitation explicitly,
  rather than leaving it to be rediscovered as a "bug").

No new `tests/Guards/` entries — this is additive capability, not a
schema-shape invariant, matching `reception-operations-scope.md`'s same
call on the previous phase.

## Postman

Audit `postman/ADD-OS.postman_collection.json` against `routes/api/v1/*.php`
first (per the phase brief's §7 instruction), then add:

- New `Member (App) > Bookings` folder (sibling to `Wallet/`/`Memberships/`):
  create (happy path + one example per validation failure), extend (happy
  path + conflict error example).
- New `Admin (Dashboard) > Reception Operations > Booking Approvals`
  subfolder: approve, reject (+ missing-reason error example),
  reception-initiated extend (+ conflict error example) — nested the same
  way `Business Hours/` nests its own subfolders.

Creation and extension requests are member-facing; approval/rejection are
operations-facing — this is why they split across `Member/` and the
existing `Admin (Dashboard) > Reception Operations` rather than all
landing in one place, stated here so the PR description doesn't need to
re-derive it.

## Decision doc (implementation deliverable, not this design doc)

New `docs/decisions/booking-creation-approval-extension.md`, modeled on
`reception-operations-scope.md`'s structure, recording: the inclusive
buffer-boundary convention and why; how `pending` interacts with existing
capacity counting (including the inherited present-moment-check
limitation, explicitly not re-solved here); and citing which three
operations (table above) got the lock-and-recheck treatment from the
start, as confirmation the previous phase's review lesson was applied
proactively rather than retrofitted.

## Out of scope (unchanged from the phase brief)

Guest visits, member/company groups, `contact_links`, any TTLock/access-
grant change, a public-facing settings endpoint, `space_capacity_slots`
(the future capacity-slot system referenced above), and any real
notification-delivery channel (mail/SMS/push) — only `NotificationLog`
rows, per this doc's first flagged decision.
