# Booking outcomes once its time passes, and extension rules per space

**Status:** resolved 2026-10-03. **Owner:** Maryam Asha.
**Type:** design doc for a new capability; closes two gaps
[booking-creation-approval-extension.md](booking-creation-approval-extension.md)
left open.

## The problem

Nothing ran when a booking's time came and went. A request nobody reviewed
stayed `pending` for ever — its points held if it was paid, and listed as a
debt at reception if it was not. A confirmed booking nobody came to stayed
`confirmed`, still owed at the desk for ever. A completed one looked exactly
like one that never happened. And `BookingCancellationService::cancel()`
turned a `rejected` booking into a `cancelled` one, refunding it a second time
on the legacy no-payment-row path.

Extension had no switch per space, no "only while it runs" rule, no way to ask
how far it could go, and did not stop at the branch's closing or keep the
next booking's buffer.

## Decision — what a booking becomes

Two statuses join `BookingStatus`: `completed` and `no_show`. Six in all,
four of them final:

```
          ┌─ approved ──────────► confirmed ─┬─ checked out (desk / end request / auto) ─► completed
create ─► pending ─┤                          ├─ ended, never checked in (system) ──────────► no_show
          ├─ rejected by staff ─► rejected     └─ cancelled before the deadline ─────────────► cancelled
          ├─ still pending at start_at (system) ─► rejected
          └─ cancelled ─────────► cancelled
```

| Case | Status | Money |
|---|---|---|
| Pending, paid, start passed | `rejected` (lapsed) | refunded, member notified (`booking.lapsed`) |
| Pending, unpaid, start passed | `rejected` (lapsed) | off the outstanding list |
| Attended, paid | `completed` | — |
| Attended, unpaid | `completed` | still owed at reception |
| Never came, paid | `no_show` | **not refunded** — cancelling before the deadline was the way out |
| Never came, unpaid | `no_show` | **owes nothing** — not payable, off the outstanding list |

The user's decisions behind the table: an unpaid no-show's debt lapses; a paid
no-show keeps its charge; an undecided request lapses at its **start** (an
unapproved booking cannot be checked in, so waiting to its end only holds the
slot for nothing).

- **`bookings:close-elapsed`** (every five minutes) does both transitions. A
  lapse goes through `BookingApprovalService::lapse()`, which locks and
  re-checks the row like `reject()` and refunds through the same
  `BookingRefunder`. The no-show is one conditional `UPDATE`.
- **A lapse records no operator and no reason** (`Booking::lapsedUnreviewed()`).
  The explanation is translated when shown, in the viewer's language, not
  stored in the server's — the events precedent
  (`events:expire-pending-registrations`) stores a string and so shows it in
  one language only.
- **A checkout writes `completed`** in `SessionClosureService::finalizeClosure()`,
  the one place every closure — desk, confirmed end request, auto-close — goes
  through.
- **Check-in is refused once `end_at` has passed**, before the job gets there
  too, so a late check-in and the no-show job can never race into a
  checked-in no-show.
- **The status lists live on the enum, not at each call site.**
  `BookingStatus::isOpen()` (cancellable: pending, confirmed),
  `payable()` (pending, confirmed, completed) and `slotHolding()` (those plus
  `no_show`) replaced five copies of `[Cancelled, Rejected]`. `cancel()` now
  refuses anything not open (`api.booking.not_cancellable`), which fixes the
  rejected → cancelled double refund.
- **`completed` and `no_show` still hold their slot.** An early checkout keeps
  the rest of the window held, as it did when the booking stayed `confirmed`,
  and the admin calendar still draws both. A no-show is only written after
  `end_at`, so it never blocks a future window.
- **The door still opens for a completed booking** until its grant expires
  (`UnlockService::isUsable`), as it did before a checkout changed the status.

## Decision — showing and filtering it in the app

The badge is the stored `status`; there is no second display layer. One
nullable `status_note` tells two bookings with the same status apart by what
happened to the money (`BookingPresentationService::statusNote()`):
`no_show_charged`, `no_show_free`, `payment_due` (completed, unpaid),
`refunded` (cancelled/rejected that was paid).

- `GET member/bookings/statuses` — every status and every tab, labelled in the
  request's language. The tabs are `BookingListFilter` (`all`, `upcoming`,
  `completed`), each defined by the statuses it shows; `upcoming` also needs
  `end_at` ahead, so a booking waiting for the job to mark it is in neither
  `upcoming` nor `completed`.
- `GET member/bookings?filter=` takes a tab; `counts` has one per tab. The
  legacy `past` filter and the `past`/`pending`/`total` counts stay for the
  app version still reading them.
  **Reversed later on 2026-10-03 (user decision): no `counts` at all.** The
  response is the tab's page and its `meta` (`meta.total` is that tab's
  total). The screen shows no count per tab, and the six counts cost a query
  each on every load. Given up: the per-tab numbers, and the legacy
  `past`/`pending`/`total` keys with them — an app version still reading
  `counts` gets nothing there. The legacy `past` *filter* stays.
  **Reversed again, same day (user decision): no `filter` key at all — the
  list filters on `status`.** `?status=completed`, or several comma-separated
  (`?status=pending,confirmed` is the app's "upcoming" in one request), or
  none for every booking; an unknown status is a 422, and a `filter` sent
  anyway is ignored. `BookingListFilter` and the catalog's `filters` went with
  it; `GET member/bookings/statuses` is `{statuses}` only. Bookings not yet
  started come first, soonest first, then the rest, latest first, and each
  card carries `is_upcoming` — true until the booking **starts** (not ends:
  one under way is no longer upcoming). `meta.status` echoes what was
  filtered on. Given up: the `past` filter (ended by the clock, any status)
  and the server-defined tabs; the app now composes its tabs from statuses.
- **The list card, trimmed later on 2026-10-03** to what the app's card draws
  (user decision): `id`, `space {title, space_type, main_image}`, `date_label`
  ("Today, …" / "Tomorrow, …" / the date — the district's calendar, the
  viewer's language), `time_label` ("01:00 PM - 02:00 PM (1h)"), `status`,
  `status_label`, `payment_state` (`paid | unpaid`). `status_note`, the
  payment block, `can_cancel`, `can_extend`, `is_upcoming` and the long
  `when` block left the card; all but `is_upcoming` were already on the
  detail, which is where they now live. The list reads only `space` per page.
  Given up: a card cannot show a cancel or extend button, or the money note,
  without opening the detail. "Pending payment" in the design is `confirmed`
  with `payment_state = unpaid`, not a status. Enforced by
  `tests/Feature/Booking/MemberBookingCardTest`.
  **Then, same day:** `space_type` left the card too (user decision) — it is
  `space {title, main_image}`. And `title` was `null` for every space created
  before `name` was wired up (two of the six local spaces, most of the
  bookings): the booking screens read `name` raw, while the space browser
  falls back to a per-type title. That fallback moved from
  `SpaceBrowseService::resolveTitle()` to `Space::displayTitle()`, and the
  list card, the detail and the confirmation screen now use it, so a space
  reads the same everywhere a member sees it. Other surfaces still reading
  `name` raw (admin calendar, outstanding items, session report, wallet
  receipts) are unchanged; naming those spaces fixes them all at once.

## Decision — extension

- **`spaces.allows_extension`** (default true, so every existing space keeps
  extending) and **`spaces.cancellation_window_minutes`** (the column existed
  with no API; null falls back to `booking.cancellation_window_minutes`) are
  settable through `StoreSpaceRequest`/`UpdateSpaceRequest` and returned by
  `SpaceResource`.
- **A booking extends only while it runs, its member inside:** confirmed,
  checked in and not out, `start_at ≤ now < end_at`, on a space that allows
  it (user decision: keep the check-in condition alongside the time window).
- **How far:** the earliest of the next booking less the buffer it is owed,
  a co-working loan of the room, the seat pool running out (co_space), and the
  close of the branch's open period. The lengths offered are the ones
  `isValidDurationInMinutes()` accepts — the minimum booking length, then
  steps of the space's granularity — each priced exactly as the extension
  charges it, plan discount included.
- **One definition:** `BookingExtensionPlanner`. `GET member/bookings/{id}/extension-options`
  (and `GET admin/reception/bookings/{id}/extension-options`), each booking's
  `can_extend`, the detail's `actions.extend_disabled_reason` and
  `BookingExtensionService::extend()` — which calls it again under its locks —
  all read it, so the app is never offered an extension the POST refuses.
  Reasons are `ExtensionBlockReason`: `space_disallows`, `not_checked_in`,
  `outside_booking_time`, `next_slot_booked`, `branch_closing`.
- The POST keeps `api.reception.not_checked_in` and
  `api.booking.extension_conflict` (+ `latest_end_at`) for the app already
  handling them, and adds `extension_not_allowed`,
  `extension_outside_booking_time` and `extension_past_closing`.

## Decision — the booking detail and its number (2026-10-04)

`GET member/bookings/{id}` was trimmed, as the card was, to what the booking
screens draw (submitted, confirmed, rejected, the cancel sheet): `id`,
`reference`, `status`, `status_label`, `status_note`, `space {id, title,
main_image, branch, capacity, hourly_price}`, `seat`, `date_label`,
`time_label`, `total {points, price}`, `checked_in_at`, `checked_out_at`,
`payment_state`, `payment_id`, `awaiting_payment`, `request_status`,
`actions {can_cancel, can_extend, extend_disabled_reason}` and
`cancellation`.

- **Each screen's headline and wording is the app's**, chosen from `status`
  (user decision): no `headline` or `notice` from the server.
- **`payment_id` and `awaiting_payment` stay** (user decision) for an app
  following a card payment; the rest of the payment block, the itemised
  `breakdown`, the long `when`, `note` and `space.space_type/images/location`
  left. `space.id` stays because `seat` is compared with it (the co-working
  seat placement feature). `checked_in_at`/`checked_out_at` were dropped in
  the first cut and **restored the same day** (user's question): the status
  stays `confirmed` until checkout, so they are what tell a booking under way
  from one still to come. They now carry the district's offset (`+03:00`)
  like every other time the app reads, where before they were UTC.
- **`total` is the booking's own `price_points`** — what payment settles and
  refunds — priced again from the window only for a booking made before that
  column existed.
- **`cancellation`** (null when it cannot be cancelled): the deadline, and
  what cancelling gives back and to which wallet ("Your wallet" or the
  company's `legal_name`). The amount is `BookingRefunder::quote()`, which
  shares its rows and arithmetic with `refund()`, so the promise is the refund.
- **A pending booking paid from the wallet is still charged when it is made**
  (user decision), not at approval as the design's copy suggested; the app's
  wording should say the points come back if the request is not approved.
- **No alternative times on a rejected request** (user decision, for now).
- **Every booking has a number, `BK-2026-00041`**: a yearly, gap-free series
  through `ReferenceSequence`, given in `Booking::booted()` whichever path
  creates the booking, exactly as service requests get `REQ-…` — with its own
  prefix, so the two never share a counter or read alike. Existing bookings
  were numbered by `2026_10_04_100000_add_reference_to_bookings_table` in the
  order they were made. The space's area in the design (m²) has no column, so
  it is not sent.

## Enforced by

`tests/Feature/Booking/BookingOutcomesTest`,
`tests/Feature/Booking/MemberBookingStatusesTest`,
`tests/Feature/Booking/MemberBookingCardTest`,
`tests/Feature/Booking/MemberBookingDetailTest`,
`tests/Feature/Booking/BackfillBookingReferencesMigrationTest`,
`tests/Feature/Booking/BookingExtensionOptionsTest`,
`tests/Feature/Booking/BookingExtensionServiceTest`,
`tests/Feature/Foundation/SpaceControllerTest` (the space settings).

## Not done here

- **Notifications are still `NotificationLog` rows**, as everywhere else in
  booking; a no-show sends none, since nothing in the member's balance changes.
- **The remaining per-space booking columns** (`requires_approval`,
  `slot_granularity_minutes`, `buffer_minutes`) still have no admin API; only
  the two this work needed were exposed.
- **A card payment still in flight when its booking becomes a no-show**, if it
  later succeeds, lands as points in the wallet with the payment in
  `NeedsReview` — the existing "payable already settled" path — rather than
  paying for a booking that no longer owes anything.

## Since sale prices and coupons (2026-10-05)

See [pricing-offers-and-coupons.md](pricing-offers-and-coupons.md).

- **An extension keeps the booking's locked pricing choice:** its plan
  discount if that was applied, otherwise the space's sale by usage time —
  the extra minutes inside a sale window at the sale rate, the rest at the
  normal rate. Best-of is not re-run and no coupon applies to an extension.
  `BookingExtensionPlanner::price()` delegates to
  `PriceQuoteService::forExtension()`, so the options and the POST still
  quote the same number. The extension adds to both `price_points` and
  `list_price_points`.
- **The space form also takes a sale:** `sale_hourly_rate_points`,
  `sale_starts_at`, `sale_ends_at` on `StoreSpaceRequest`/`UpdateSpaceRequest`
  (all three or none, below `hourly_rate_points`), returned by
  `SpaceResource`.
