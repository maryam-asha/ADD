# Booking availability and calendar: one rule engine, a derived capacity counter, and two deliberate behaviour changes

**Status:** resolved 2026-09-19 (Phases 1–5). **Owner:** Maryam Asha.
**Type:** design doc for a new capability, plus two scoped reversals of previously recorded decisions.

## What this phase adds

`App\Domain\Booking\Services\AvailabilityService` — the single definition of every rule that decides whether a booking window is legal — together with the derived per-slot counter `space_capacity_slots` that ERD v2.0 named and three prior phases deferred. No API surface changes in Phase 1; its entire value is that the member calendar and admin calendar built on top of it cannot disagree with `POST /bookings`.

## The problem this solves

Every availability rule lived as a private `assert*` on `BookingCreationService`, written to throw. That shape answers exactly one question — "is THIS window legal?" — and the calendar screens need the opposite one: "WHICH windows are legal?" across a month.

Answering the second question by writing the rules a second time would have been the obvious move and the wrong one. The two copies would drift — not loudly, but in whichever direction someone edited one and not the other — and the symptom would be a member tapping an available-looking slot and receiving a 422, or worse, a slot shown as taken that was free. Nothing would fail; the product would just quietly lie.

## Decision

- **The rules live in `AvailabilityService`, exactly once, as predicates that return `bool`.** `BookingCreationService` keeps its `assert*` methods, but each is now a one-line delegation that turns `false` into the same `ReceptionActionException`, with the same message key, in the same order as before. The POST contract is byte-for-byte unchanged — every pre-existing test in `BookingCreationServiceTest`, `BookingControllerTest`, `BookingExtensionServiceTest` and `BookingTimezoneTest` passes untouched.
- **`tests/Guards/AvailabilityRulesAreCentralizedTest` makes it permanent.** It greps for the *shape* of the overlap predicate, the blocking-status set and the granularity modulo, and fails if any appears outside `AvailabilityService` and the two model scopes it composes. This is the structural answer to "they will drift"; a convention would not have survived the first deadline. The guard was verified to fail when the predicate is pasted back into `BookingCreationService`, and it caught two real duplicates while being written (`BookingExtensionService`'s inline overlap and duration arithmetic, `ArrivalRequestMatcher`'s copy of the blocking-status set).
- **The read path is advisory and never a reservation.** The contended predicates take an already-fetched `Collection<Booking>`, so a day costs one query instead of ~48 and a month three instead of ~3000 (both pinned by query-count tests). The write path calls those same predicates but passes a collection re-read *inside* its own `DB::transaction` after `Space::lockForUpdate()`. The lock and the re-check have not moved; nothing the calendar computed is ever trusted to create a booking.
- **`BusinessHoursService` gained `periodsForRange()`, and `periodsFor()` is now a one-day call to it.** The resolution-order rule ("an exception fully replaces the weekly schedule") therefore still exists in exactly one place, while a 31-day calendar resolves hours in two queries rather than 62.

## Two behaviour changes, stated rather than slipped in

### 1. A co_space's capacity is now a real concurrency ceiling

Previously *any* overlapping booking refused the window, for every space type. A `co_space` with `capacity = 20` therefore refused the second member to book 10:00 while nineteen desks sat empty — which directly contradicts the PRD's "no overbooking in Co-Space — the capacity ceiling is strict," where the ceiling is plainly N, not 1.

`AvailabilityService::concurrencyCeilingFor()` now returns `capacity` for `co_space` and `1` for every other type. Because `overlapCount(...) >= 1` is exactly equivalent to the old `exists()`, rooms, private offices and event halls are unchanged and no existing test needed editing.

- A `co_space` with `capacity = null` keeps a ceiling of 1 — an unconfigured space is not an unlimited one.
- Buffer is skipped entirely when the ceiling exceeds 1: two people at two different desks need no turnover gap between them.
- **Deployment check, mandatory:** audit `spaces WHERE space_type = 'co_space' AND capacity IS NOT NULL` before release. A capacity set for *display* becomes a real ceiling on day one. The seeded Hot Desk has `capacity = 1` and is unaffected; note that `SpaceFactory` defaults co_space capacity to 4–40.

### 2. The live-occupancy check now applies only to the current window

`assertOccupancyLeavesRoom` counts people physically present (checked-in bookings plus open walk-in sessions). That is the right question for someone arriving now and the wrong one for next Tuesday: a booking for tomorrow afternoon was being refused because the room happened to be full as the request was made, and no amount of waiting would clear it.

It also made a correct forward-looking calendar impossible, since the read path cannot know a future slot's *present* occupancy. It now runs only when the requested window actually contains `now()`. Future windows are governed by overlap-versus-ceiling instead — the reservation-based model `space_capacity_slots` records.

This is a deliberate loosening. `WalkInCapacityService` is untouched: present-moment is exactly right there.

## `space_capacity_slots`: built, as a derived source with double-checking

ERD v2.0 calls this table load-bearing for the strict no-overbooking ceiling; D.9 assumed its absence was an omission; three phases deferred it. The owner's call was to build it now rather than reverse D.9.

The design question that mattered was what happens when it drifts, and the answer is **nothing that can oversell a slot**:

- `bookings` remains the source of truth. `BookingCreationService` still decides availability by counting overlapping `bookings` rows under `Space::lockForUpdate()`. **The lock, not the table, is what makes the ceiling strict.**
- The counter is written inside that same transaction and lock, by `CapacitySlotSynchronizer` alone, on create / cancel / reject / extend. Approval deliberately does not touch it — a `pending` booking already held the slot.
- Rows exist only for slots that carry a booking, and are deleted when the count returns to zero, so the table stays proportional to real bookings rather than to spaces × time.
- `booking:reconcile-capacity-slots` (nightly, plus `--dry-run`) rebuilds from `bookings` and reports drift, covering what a transaction cannot: a restored backup, a manual SQL fix, a future writer that forgets the synchronizer.
- Two tests pin the safety property directly: with the counter zeroed, and with its rows deleted outright, an overlapping booking is *still* refused.

The cost is honest and accepted: a second write path, a reconciliation command, and a new class of drift bug whose blast radius is a stale calendar cell.

## What the later phases added

- **Phase 2 — the member calendar.** `GET spaces/{space}/availability`, `.../slots`, `.../quote`. A closed day and a full day are distinct states: closed carries the red banner and, when a `BusinessHourException` gave one, its reason; a full day is open and must not claim otherwise. The duration ladder comes from a new `booking.duration_options_minutes` setting seeded to 60/90/120/180, because deriving it from `min_duration` + `granularity` yields a 2.5h fourth chip rather than the design's 3h. `timezone_label` is derived per request — Damascus observes DST, so the design's fixed "GMT+3" is wrong part of the year.
- **Phase 3 — the member's own bookings.** List with tab counts, detail with the approval stepper, self-service cancellation (previously reception-only), and a preview that builds the confirmation screen without creating anything. The stepper is derived from `status`/`approved_at` and collapses to two steps for an auto-confirming space, which never had a review. A wallet that cannot cover the cost is returned `sufficient: false` rather than omitted. Adds a nullable `bookings.note`.
- **Phase 4 — multi-date and the past-date rule.** `POST bookings/batch` takes up to ten windows, all or nothing: partial success would leave a member part-charged for a booking they never got. Windows already accepted earlier in the same batch are checked against too — two picked dates that overlap each other are both absent from the database, so a check against stored rows alone passes both. Spaces are locked in ascending id order so two concurrent batches cannot deadlock, and payment is routed once for the summed total. A booking can no longer start in the past, behind `booking.allow_past_bookings`.
- **Phase 5 — the operations calendar.** `GET admin/calendar` (spaces as rows, days as cells), `.../occupancy`, a general `GET admin/bookings` index, and `POST admin/reception/bookings` for booking a member in at the desk. Occupancy is booked minutes over open minutes, computed from the same `BusinessHoursService` periods the member calendar uses, so the two can never disagree about what "open" means; booked minutes are clipped to the open window, and a multi-occupant space is measured against its ceiling rather than reading 2000% full. Reception creation reuses `BookingCreationService` unchanged, so it cannot double-book a room the member endpoint would have refused.

## Known gaps, deliberately not addressed in Phase 1

- **No "booking must be in the future" validation yet.** Still possible to create a past-dated booking that can then never be cancelled (`BookingCancellationService` refuses once the window has passed). Phase 4 closes it behind a `booking.allow_past_bookings` setting.
- **`affected_bookings`** (maintenance activated over a confirmed booking, PRD #8) remains unbuilt.
- **`requires_approval`, `slot_granularity_minutes`, `buffer_minutes`, `cancellation_window_minutes` still have no admin API surface.** This stops being merely "consistent drift" once the calendar makes their effect visible to members; scheduled for a later phase.
- **`AvailabilityService` does not yet read `space_capacity_slots`.** The counter is written and reconciled but the projections still count bookings directly — correct, and one less thing to invalidate. Reading from it is a performance change to make when there is a measurement to justify it.

## What this changed in code

- `App\Domain\Booking\{Enums\SlotState, Enums\DayState, ValueObjects\*, Services\AvailabilityService, Services\CapacitySlotSynchronizer, Models\SpaceCapacitySlot}` (new)
- `App\Domain\Booking\Services\{BookingCreationService, BookingExtensionService, BookingCancellationService, BookingApprovalService, ArrivalRequestMatcher}` (delegation, slot sync, `canCancel()`/`deadlineFor()` extracted)
- `App\Domain\Booking\Models\Booking` — `scopeBlocking`, `scopeOverlapping`, `scopeForSpace`
- `App\Domain\Foundation\Services\BusinessHoursService` — `periodsForRange()`
- `App\Console\Commands\ReconcileCapacitySlots` + `routes/console.php`
- `database/migrations/2026_09_19_100000` (range index on `bookings`), `2026_09_19_100100` (`space_capacity_slots`)

## Guard

`tests/Guards/AvailabilityRulesAreCentralizedTest` — four checks, described above. Everything else is covered by `tests/Unit/Domain/Booking/{AvailabilityServiceTest, CapacitySlotSynchronizerTest}` (30 tests) and the extended `tests/Feature/Booking/BookingCreationServiceTest`.
