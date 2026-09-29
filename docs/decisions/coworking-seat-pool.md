# The co-working seat pool: one count per branch

**Status:** decided 2026-09-29, built 2026-09-30.
**Enforced by:** `tests/Guards/SeatPoolIsCentralizedTest`,
`tests/Guards/AvailabilityRulesAreCentralizedTest` (`usesBranchPool`,
`exceedsPool`, `isInCoworkingMode`, and the co-working window overlap).

## The problem

A booking is always for one specific space: "Meeting Room 1", not "a meeting
room". That already held, because `bookings.space_id` points at one row.
Co-working is different. It is a set of seats that subscriptions, bookings and
walk-ins all draw from, and it was counted in three ways that did not agree:

- **Booking availability** counted only overlapping bookings against the
  co_space's `capacity`, and treated a missing capacity as 1.
- **Walk-in check-in** counted checked-in bookings plus open walk-ins, treated a
  missing capacity as unlimited, and ignored bookings not yet checked in.
- **Subscriptions** (Flexi Desk, Dedicated Desk) held no seat anywhere. They had
  no branch either, and a company's subscription covered any number of its
  employees.

Devices (`occupancy_sensor`) and `seats_desks` were never part of any count.
Since 2026-10-01 `seats_desks` *are* the count: see "Desks" below.

## Decision (product owner, 2026-09-29)

1. **Every active subscription holds a seat for its whole period**, Flexi and
   Dedicated alike. If the plan has an access window, the seat is held only
   inside it: the window applies every day and can wrap midnight, as
   `Plan::windowOverlap` already defined. With no window, the seat is held all
   day.
2. **The seats form one pool per branch.** Open seats are the sum of `capacity`
   of the branch's active co_spaces, plus the seats of rooms lent to co-working
   for the moment in question (point 5).
3. **A company subscription has `seats`** (default 1). It holds that many seats
   and covers that many of the company's people at once. Anyone beyond that
   takes a pool seat like any other walk-in.
4. **Selling a subscription is refused** if at any moment of its period, inside
   its plan's hours, it would take more seats than are free. An admin may
   override when selling from the dashboard; the override is recorded in the
   audit log.
5. **Staff lend a room to co-working** for a stretch, when the co-working space
   is overloaded. This is allowed only over a stretch in which the room has no
   bookings. While it lasts, the room refuses room bookings and its seats join
   the pool.
6. **A co_space's seats are its desks** (revised 2026-10-01; first built as a
   typed `spaces.capacity`). `capacity` stays the column the pool reads, but
   for a co_space it is the number of its `seats_desks` rows, kept by the
   SeatDesk model and never sent by the space form. A co_space with no desks
   has **0 seats**.
7. **A walk-in with no held seat is refused when the pool is full.** A
   subscriber inside their plan's hours always gets in, because their seat is
   already held (up to a company's `seats`).

Chosen defaults, which can be changed:

- **Walk-ins count only at the current moment.** Nobody knows when they will
  leave, so they are not projected forward.
- **Pending bookings count,** as they do everywhere (`Booking::scopeBlocking`).
- **Cutting a space's capacity or status is not blocked.** Taking back or
  shrinking a lent stretch is refused if it would leave the pool shorter than it
  already was (`api.coworking.would_overbook`), unless `override` is sent.
- **A membership's branch**, unless one is given: a company's own
  `branch_id`; otherwise the member's home branch
  (`user_branch_memberships.is_home_branch`); otherwise the default branch.
  `MembershipBranchResolver` is the single definition of this rule, and
  migration `2026_09_30_100001` applied the same rule to existing rows.
- **Out of scope:** renewals (no renewal flow exists) and changing a company's
  seats mid-period.

## The count

At any instant *t*:

```
usage(t) = Σ over the branch's memberships m of
               (held_m(t) ? max(seats_m, present_m(t)) : present_m(t))
           + people present on no membership
```

- `held_m(t)`: the membership is active, its period contains *t*, and its plan
  is open at *t*.
- `present_m(t)`: bookings and walk-ins at *t* by people it covers. That is
  their own membership first, otherwise their company's, counting only
  memberships held in this branch.

What this gives:

- A subscriber's own booking or walk-in sits in the seat already held, so it
  is not counted twice.
- A company with more people inside than seats spills the excess into the
  pool.
- Someone present outside their plan's hours counts like anyone else.

`PoolTimeline::build()` computes this as a sweep over interval boundaries,
never a loop over minutes. `CoworkingPoolService::timeline()` gathers the
intervals in about five queries, whatever the range. A candidate (a booking, a
walk-in, or a subscription being sold) is folded in as a `PoolDemand`, and the
question becomes "does anything overflow once it is in".

## Where it applies

| Decision | Where | Refusal |
|---|---|---|
| A co_space cell in the day grid | `AvailabilityService::stateFor()` (one timeline per day or range) | `at_capacity`, shown as `booked` |
| A room cell during a lent stretch | same | `coworking_mode`, shown as `booked` |
| Booking a co_space (single, batch, preview) | `BookingCreationService`, `BookingBatchCreationService`, `Member\BookingController::preview` | `api.booking.slot_unavailable` |
| Booking a lent room | same | `api.booking.room_in_coworking_mode` |
| Extending a co_space booking or a room booking | `BookingExtensionService` | `api.booking.extension_conflict` with `latest_end_at` |
| A walk-in to a co_space or a currently lent room | `WalkInCapacityService` | `api.reception.no_capacity` |
| Selling a subscription | `MembershipActivationService` | `api.membership.seat_pool_full` with `first_shortfall_at` |
| Lending, changing or taking back a room | `CoworkingWindowService` | `api.coworking.room_has_bookings` / `would_overbook` |
| A co_space's occupancy in the admin calendar | `BookingCalendarService::poolOccupancy()` | taken seat-minutes / open seat-minutes |

- **Walk-ins:** every walk-in into the pool records `seat_covered`, meaning it
  sat in a seat its subscription holds. Reception's active-sessions list shows
  it together with `membership_id`.
- **Rooms:** a room outside a lent stretch keeps its own exclusive rules: the
  ceiling of 1, the buffer, and the live count of people in the room
  (`assertOccupancyLeavesRoom`, now for rooms only).

## Locking

Every pool decision locks the **branch** row, then the space. That serializes
bookings, walk-ins, sales and lending that touch the same seats:

- **Extension:** booking → branch → space.
- **Batch creation:** the distinct branches in id order, then the spaces in id
  order.
- **Sale:** branch, then wallet. The check runs before any debit, so a refused
  sale charges nothing.

SQLite in the tests does not exercise these locks.

## `space_capacity_slots`

The derived per-space slot counter had no reader. It counted the wrong unit (a
space, not a branch's pool) and saw neither subscriptions nor walk-ins.

- **Removed now:** its writer (`CapacitySlotSynchronizer`), its model, and its
  nightly reconcile command.
- **Kept for now:** the table, so this release can be rolled back. A follow-up
  migration drops it.
- **If a cache is ever needed,** it should hold pool segments per branch.

## Desks (2026-10-01)

This revises PRD §7.1 decision #4 ("Seat/Desk is an address, not a seat map"):
a desk is now a seat.

- **Every seat of a co_space is a `seats_desks` row** with a `code` (what its QR
  carries and a member is told, unique within the branch — the database holds
  it per space, `StoreSeatDeskRequest` per branch) and a display `label`.
- **One co_space per branch.** The branch's seats are the desks of its one
  co_space, so `StoreSpaceRequest` refuses a second one, created or turned
  into one from another type (`api.admin.co_space_exists`, on `space_type`).
  More seats are more desks. A retired co_space no longer counts and can be
  replaced. Rooms lent to co-working are unaffected: they stay rooms.
- **Capacity follows the desks.** `SeatDesk` recounts its co_space's
  `capacity` whenever a desk is added, moved or removed; `SpaceController`
  recounts when a space becomes a co_space. The pool code is unchanged.
- **A Dedicated Desk subscription is tied to specific desks** through
  `membership_seat_desk`, as many as its `seats` at most. A plan is Dedicated
  Desk when its module's slug is `dedicated_desk` (`Plan::isDedicatedDesk()`).
  Only staff tie desks: at activation (`seat_desk_ids`) or later (`PUT
  admin/memberships/{membership}/desks`), which is how a plan bought in the app
  gets its desk.
- **A desk is its owner's only inside their plan's hours and dates**
  (product owner, 2026-10-01). Two subscriptions conflict over a desk only
  when their dates overlap *and* their plans' hours meet within that overlap
  (`DedicatedDeskService::takenDeskIds`, built on `Plan::windowSegments`, the
  same rule the pool uses). So a morning plan and an evening plan may share a
  desk, and a plan with no hours shares it with nobody.
- **`DedicatedDeskService` is the one place** that ties desks and says who holds
  one. It refuses a non-Dedicated plan, more desks than seats, a desk outside a
  co_space of the subscription's branch, and a desk held by another active
  subscription whose period overlaps (`api.membership.desk*`). It locks the
  branch row, like every other seat decision. An ended subscription keeps its
  row as history and frees the desk.
- **The pool does not change.** The subscription already holds its seat; the
  desk only says which. Walk-ins and co_space bookings never pick a desk.
- **What a held desk cannot lose:** a desk a live subscription holds cannot be
  deleted or moved to another space (`api.admin.seat_desk_held`), and its
  co_space cannot be deleted (`api.admin.co_space_desks_held`). A co_space with
  any desks cannot change type (`api.admin.co_space_has_desks`), since its desks
  would sit in a space that has none. `SpaceController` re-checks "one co_space
  per branch" on the locked branch row, so two requests at once cannot both pass.
- **The member sees their desk:** `memberships/current` carries `desks`
  (`[{code, label}]`; empty until staff tie one; `null` for any other plan), and
  so does the purchase response.
- **Staff find subscriptions still owed a desk** in `GET admin/memberships`
  (`dedicated`, `live`, `without_desk`, `branch_id`), where each one carries
  its owner's name and desks.
- The Foundation domain stays free of Membership: the tie lives on
  `Membership::seatDesks()` and in `DedicatedDeskService`, never on `SeatDesk`.

## Endpoints

- `GET admin/branches/{branch}/coworking-pool?from&to` (local times, at most 62
  days): `capacity_now`, `usage_now`, `free_now`, `min_free`, and `segments[]`.
- `GET|POST admin/spaces/{space}/coworking-windows`
- `PATCH|DELETE admin/coworking-windows/{coworkingWindow}` (`override` to take
  seats back regardless).
- `POST admin/memberships` gains `branch_id`, `seats` (company only),
  `override_capacity` and `seat_desk_ids`; its response carries `desks[]`
  (`id`, `code`, `label`).
- `GET admin/memberships?dedicated=1&live=1&without_desk=1&branch_id=&per_page=`:
  newest first, paginated, each with `owner {type, id, name}` and `desks[]`.
- `PUT admin/memberships/{membership}/desks` `{seat_desk_ids: []}` replaces a
  Dedicated Desk subscription's desks (empty unties them).
- `GET admin/seats-desks` gains `code` and `membership_id` (the live holder) on
  every desk, and the filters `branch_id` and `free=1`. `code` is required on
  create and update.

## Deploying

`2026_10_01_100002` sets every co_space's capacity to its number of desks, so
a co_space with no desks becomes unbookable and refuses walk-ins. Before
deploying, list them and add their desks:

```sql
SELECT s.id, COUNT(d.id) AS desks FROM spaces s
LEFT JOIN seats_desks d ON d.space_id = s.id
WHERE s.space_type = 'co_space' GROUP BY s.id HAVING desks = 0;
```

Existing desks get the code `D-<id>` (`2026_10_01_100000`); rename them as needed.

A branch with no co_space has no seats, so no subscription can be sold into it
without an override.
