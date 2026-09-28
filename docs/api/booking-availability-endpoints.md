# Booking & Availability — API reference

The 14 endpoints added by the availability and calendar work. Every shape
below is copied from a real response, not paraphrased.

**Conventions used throughout**

- Auth: `Authorization: Bearer <token>`. Member routes need the `member` role;
  admin routes need `admin` or `operations` **and** a dashboard-ability token.
- `lang: en | ar` on every request. All labels, notices and error messages come
  back translated; Arabic is fully covered.
- **Times in, times out.** A bare `"2026-08-17 14:00"` is read as district local
  time (`Asia/Damascus`). A value carrying an offset (`+03:00`, `Z`) is taken at
  its word. Responses return ISO-8601 with the local offset.
- **Prices are points.** `points` is authoritative; `amount_usd` / `formatted`
  are derived for display.
- `PATCH`/`POST` command endpoints return `{"message": "..."}`, not the model.

---

## 1. Member — availability (the date & time picker)

Read-only and **advisory**. Nothing here holds a slot: a member who lingers on
the confirmation screen can still lose one, which is why `POST /bookings`
re-checks under a row lock. The payoff of both paths sharing one engine is that
a slot's `reason` is the *exact* message `POST /bookings` would return for that
same window.

### `GET /api/v1/member/spaces/{space}/availability`

The month grid. `from`/`to` are local dates (`Y-m-d`), default = current month,
**max 62 days** (else `422`).

```json
{
  "space": { "id": 1, "title": "Meeting Room A", "space_type": "room", "capacity": 6, "main_image": null },
  "branch_hours": {
    "title": "Main Branch Hours",
    "hours": "8:00 AM – 8:00 PM",
    "open_time": "08:00", "close_time": "20:00",
    "periods": [{ "open_time": "08:00", "close_time": "20:00" }],
    "days_label": "Sunday – Thursday",
    "open_weekdays": ["sunday","monday","tuesday","wednesday","thursday"],
    "upcoming_closures": [ { "date": "2026-10-06", "reason": "Public holiday" } ],
    "timezone": "Asia/Damascus",
    "timezone_label": "GMT+3",
    "timezone_note": "All times shown in your local time (GMT+3)"
  },
  "rules": {
    "granularity_minutes": 60, "min_duration_minutes": 60, "buffer_minutes": 0,
    "cancellation_window_minutes": 60,
    "requires_approval": true,
    "approval_notice": "Your request will be reviewed by operations before confirmation.",
    "max_selectable_dates": 10
  },
  "data": [
    {
      "date": "2026-08-17", "day": "17", "weekday": "MON", "month": "AUG",
      "date_formatted": "August 17, 2026",
      "state": "available", "is_selectable": true,
      "available_slot_count": 11, "total_slot_count": 12,
      "closed_banner": null
    }
  ],
  "meta": { "from": "2026-08-17", "to": "2026-08-19", "timezone": "Asia/Damascus", "total": 3 }
}
```

**`state` is one of `available | closed | full | past`.** The distinction that
matters most:

| state | meaning | `closed_banner` | tappable |
|---|---|---|---|
| `available` | open, has free slots | `null` | yes |
| `full` | **open**, every slot taken | `null` | no |
| `closed` | branch not open that day | present | no |
| `past` | date has gone | `null` | no |

A `full` day must **not** be shown as closed — it is open, just sold out.

`closed_banner`, when present:

```json
{
  "text": "Main Branch is closed on this date.",
  "hint": "Choose another day.",
  "reason": "Public holiday",
  "severity": "error"
}
```

`reason` is `null` for a weekday with no schedule (nothing to explain); it is
only non-null when an explicit business-hour exception supplied one.

`branch_hours.upcoming_closures` lists **every** all-day closure from today on
(not just those in `from`–`to`), so the date picker can grey out a holiday in a
month it has not loaded. A day with shortened hours is not listed — it is open.

> `timezone_label` is **computed per request**, not stored. Damascus observes
> DST, so hard-coding "GMT+3" is wrong for part of the year — render this field.

### `GET .../availability/slots?date=2026-08-17`

The time-chip grid for one date — **only what changes with the date**, in one
`data` object, the chips under `data.slots` (changed 2026-09-28). The calendar's
setup is **not** here. The branch's hours come once from the spaces list's
`data.working_hours_banner`. This space's `rules` and closures come from the space
details' `data.booking`. Both are described below. Call this endpoint again on
every date change.

```json
{
  "data": {
    "date": "2026-08-17",
    "state": "available",
    "closed_banner": null,
    "slots": [
      { "start_time": "15:00", "end_time": "16:00", "label": "03:00 PM",
        "state": "available", "is_available": true },
      { "start_time": "16:00", "end_time": "17:00", "label": "04:00 PM",
        "state": "booked", "is_available": false }
    ],
    "counts": { "available": 4, "booked": 1, "total": 5 },
    "meta": { "granularity_minutes": 60, "timezone": "Asia/Damascus" }
  }
}
```

**`date` is just the requested date**, echoed so a response that arrives after
the member has already tapped another date can be recognised and dropped. The
day's name, month and formatted label are the app's to render; the day's
`state` (`available | closed | full | past | out_of_range`) and slot counts are
in `state` and `counts`. Keep `state`: `full` depends on other members'
bookings and cannot be worked out from the setup.

**Each slot is one of the space's own cells**, `meta.granularity_minutes` long
(changed 2026-09-28: there is no `duration` parameter any more).
A slot carries only its own fields: `start_time`, `end_time`, `label`, `state`,
`is_available`. There is no `reason`: grey a chip by its `state`.

**Picking a booking = picking a run of cells.** The member taps a start cell
and an end cell; the booking runs from the first cell's `start_time` to the
last cell's `end_time`. Before sending it, the app keeps the run:

- **unbroken** — every cell in it `available`, and each cell's `start_time`
  equal to the previous one's `end_time` (a lunch break leaves a gap);
- **at least `rules.min_duration_minutes`** long. There is no maximum: a
  member may book as long as the day's open cells allow.

Post `start_at = "{date} {first.start_time}"`,
`end_at = "{date} {last.end_time}"` (local time). The indicative price is the
space details' `data.details.price.hourly_rate_points` × hours; the amount
actually charged, after the plan discount and VAT, is what
`POST /bookings/preview` returns — show that on the summary. The server re-checks the whole window (a buffer next to another
booking, for one), and a refusal arrives as the POST's own translated 422
`message`.
**Slot `state` — `available` or `booked`.** Only `available` is bookable, and
`is_available` is exactly `state === "available"`. `counts` is
`{available, booked, total}`, over the slots sent.

**A slot whose time to be booked has gone is not sent at all** (changed
2026-09-28): one that has already started, or that starts inside the minimum
notice (`rules.min_notice_minutes`, an hour by default). Today at 14:00 with an
hour's notice, the list starts at 15:00 — there is nothing to grey out before
it. A day whose every start has gone comes back with an empty `slots`.

| state | meaning | covers |
|---|---|---|
| `available` | bookable | — |
| `booked` | cannot be booked | taken by another booking (a room, office or hall); every seat taken (a co-working space); too close to a neighbouring booking (a space with a buffer); outside hours or beyond the booking horizon |

> The admin twin keeps the full eight (`available`, `booked`, `at_capacity`,
> `buffer`, `outside_hours`, `past`, `too_soon`, `too_far_ahead`) — "every seat
> is taken" and "one booking holds the room" are different facts to the desk.

Slots are generated **only inside open periods**, so a day that closes for lunch
simply has no chips across the gap — do not render an empty range there.

> The admin twin, `GET /api/v1/admin/spaces/{space}/availability/slots`, returns
> the same content **without** the envelope (top-level fields, chips under
> `data`) **and with** `branch_hours` / `rules` for that date — the
> dashboard reads that shape.

### `GET /api/v1/member/spaces` — `working_hours_banner`, the branch's hours

The branch's hours are the same for every space, so they come **once, with the
spaces list**, not in each space's details. Besides the fields it always had
(`text`, `hours`, `open_time`, `close_time`, `is_open` — today's), the banner
carries the branch's week. The upcoming closures are **not** here: they set up
the booking calendar, so they come with the space details (below).

**The response is one `data` object** (changed 2026-09-28):
`{data: {working_hours_banner, spaces, meta}}` — the list is `data.spaces`, the
banner `data.working_hours_banner`, the paging `data.meta`.

```json
{
  "data": {
    "working_hours_banner": {
      "text": "...", "hours": "8:00 AM – 10:00 PM",
      "open_time": "08:00", "close_time": "22:00", "is_open": true,
      "title": "Main Branch Hours",
      "periods": [ { "open_time": "08:00", "close_time": "22:00" } ],
      "schedule": [
        { "days": ["saturday","monday","tuesday","wednesday","thursday"],
          "days_label": "Saturday, Monday – Thursday",
          "periods": [ { "open_time": "08:00", "close_time": "22:00" } ],
          "hours": "8:00 AM – 10:00 PM" },
        { "days": ["sunday"], "days_label": "Sunday",
          "periods": [ { "open_time": "02:00", "close_time": "20:00" } ],
          "hours": "2:00 AM – 8:00 PM" },
        { "days": ["friday"], "days_label": "Friday",
          "periods": [ { "open_time": "14:00", "close_time": "22:00" } ],
          "hours": "2:00 PM – 10:00 PM" }
      ],
      "days_label": "Saturday – Friday",
      "open_weekdays": ["saturday","sunday","monday","tuesday","wednesday","thursday","friday"],
      "timezone": "Asia/Damascus", "timezone_label": "GMT+3",
      "timezone_note": "All times shown in your local time (GMT+3)"
    },
    "spaces": [ "...spaces..." ],
    "meta": { "...": "..." }
  }
}
```

**Two different things live here:**

- `hours` / `open_time` / `close_time` / `periods` / `is_open` are **today's**
  hours only. Never show them as the whole week's: a branch can keep different
  hours on different days.
- `schedule` is **the ordinary week**, days grouped by the hours they share,
  each group with its own `periods` and `hours`, in week order from Saturday
  (the same grouping the dashboard's business-hours page shows).
  `open_weekdays` / `days_label` are the open days of that week. A weekday with
  no group is closed. For a given weekday's hours, find the group whose `days`
  contains it. `days_label` and each group's `days_label` are translated
  ("السبت، الاثنين – الخميس").
- A single date can still differ from its weekday: an exception may close it
  (the details' `booking.upcoming_closures`) or change its hours. The slots
  for that date are always the authority.

Keep the banner from the list and use it on the booking screen: the hours card
from `schedule`, and the closed weekdays in the picker from `open_weekdays`.

### `GET /api/v1/member/spaces/{space}` — `data.booking`, this space's setup

The space details carry, beside the existing fields, what the booking screen
needs **about this space**. The branch's hours are not repeated here — they are
in the list's `data.working_hours_banner` above.

```json
{
  "data": {
    "id": 1, "title": "Meeting Room A", "...": "...every existing details field...",
    "booking": {
      "rules": {
        "granularity_minutes": 60, "min_duration_minutes": 60,
        "min_notice_minutes": 60, "max_days_ahead": null,
        "buffer_minutes": 0, "cancellation_window_minutes": 60,
        "requires_approval": false, "approval_notice": null,
        "max_selectable_dates": 10
      },
      "upcoming_closures": ["2026-09-30T00:00:00", "2026-10-06T00:00:00"]
    }
  }
}
```

No legend: the app draws its own from the two slot states.

**`rules` — the length and the booking window** (renamed 2026-09-28 from
`min_advance_minutes` / `max_advance_days`; `max_duration_minutes` removed):

| field | what it limits | example | what the app does |
|---|---|---|---|
| `min_duration_minutes` | the **length** of a booking: none may be shorter. There is no maximum | `60` — a 30-minute booking is refused | keep the selected run at least this long |
| `min_notice_minutes` | the **notice**: a booking must be made at least this long before it starts | `60` (the seeded default) — at 14:00, the earliest bookable start is 15:00 | nothing to enforce: slots inside the notice are not sent. Show it as a hint if wanted. `0` = no notice |
| `max_days_ahead` | **how many days ahead** a booking may be made | `30` — on 1 October, nothing after 31 October | grey out later dates; the month marks them `out_of_range`. `null` = no limit |

There is no list of lengths to offer: the member picks a run of cells at least
`min_duration_minutes` long.

**`upcoming_closures`** is every date from today on on which the branch is
closed all day (a business-hour exception), in order. Grey these out in the
date picker, in any month, before any slots call. Each is an ISO-8601
date-time at **local midnight with no offset**, to pass straight to
`DateTime.parse` (JSON has no date type; every date in this API travels as an
ISO-8601 string). Without an offset it parses as local time, so the 30th is
the 30th on any device. With `+03:00`, a device in another timezone would
move it to the 29th. A day that is only shortened is not listed, since it is
open. The closure's reason is not included.

> **No member quote** (removed 2026-09-28). The ladder of lengths from a chosen
> start served the duration-first picker, which the member app no longer has.
> The desk keeps its twin, `GET /api/v1/admin/spaces/{space}/availability/quote`.
---

## 2. Member — bookings

### `GET /api/v1/member/bookings?filter=upcoming|past|all&per_page=15&page=1`

`filter` defaults to `upcoming` and splits on the booking's **end** time, so a
booking under way still counts as upcoming. `counts` covers every tab regardless
of the active filter, so the tab badges never need a second request.

```json
{
  "counts": { "upcoming": 1, "past": 0, "pending": 1, "total": 1 },
  "data": [
    {
      "id": 1,
      "space": { "id": 1, "title": "Meeting Room A", "space_type": "room",
                 "main_image": null, "location": "Main Branch" },
      "when": { "date": "2026-08-17", "date_formatted": "August 17, 2026",
                "weekday": "MON", "day": "17", "month": "AUG",
                "start_at": "2026-08-17T14:00:00+03:00", "end_at": "2026-08-17T15:00:00+03:00",
                "start_time": "02:00 PM", "end_time": "03:00 PM",
                "range": "02:00 PM – 03:00 PM",
                "duration_minutes": 60, "duration_label": "1h" },
      "status": "pending",
      "status_label": "Under Review",
      "payment": { "state": "unpaid", "source": null, "label": "Pay Cash at Arrival" },
      "is_upcoming": true,
      "can_cancel": true
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1, "filter": "upcoming" }
}
```

### `GET /api/v1/member/bookings/{booking}`

Adds `breakdown`, `request_status` (the stepper) and `actions`.
`403` for another member's booking.

```json
{
  "data": {
    "id": 1,
    "space": { "id": 1, "title": "Meeting Room A", "space_type": "room", "capacity": 6,
               "main_image": null, "images": [],
               "location": { "branch": "Main Branch", "building": "...", "floor": "...",
                             "zone": "...", "formatted": "Main Branch" } },
    "when": { "...as above..." },
    "status": "pending",
    "status_label": "Under Review",
    "note": "Client presentation",
    "breakdown": {
      "lines": [ { "key": "base", "label": "1h × $12.00 / hour",
                   "points": 120, "amount": "12.00", "formatted": "$12.00" } ],
      "subtotal": { "points": 120, "amount": "12.00", "formatted": "$12.00" },
      "vat":      { "rate_percent": 0, "label": "VAT (0%)", "points": 0,
                    "amount": "0.00", "formatted": "$0.00" },
      "total":    { "points": 120, "amount": "12.00", "formatted": "$12.00" },
      "currency": "USD", "currency_symbol": "$"
    },
    "payment": { "state": "unpaid", "source": null, "label": "Pay Cash at Arrival" },
    "request_status": {
      "current": "under_review",
      "steps": [
        { "key": "submitted",    "label": "Submitted",    "state": "complete", "at": "2026-08-17T08:00:00+03:00" },
        { "key": "under_review", "label": "Under Review", "state": "current",  "at": null },
        { "key": "approved",     "label": "Approved",     "state": "upcoming", "at": null }
      ],
      "rejection_reason": null
    },
    "actions": {
      "can_cancel": true,
      "cancel_deadline_at": "2026-08-17T13:00:00+03:00",
      "cancel_note": "You can cancel this booking up to 1h before the start time.",
      "can_extend": false,
      "extend_disabled_reason": "This session has not been checked in yet."
    },
    "checked_in_at": null,
    "checked_out_at": null
  }
}
```

**The stepper is derived, never stored.** Render `steps` as given — do not
build it client-side. Step `state` is `complete | current | upcoming`. A space
that **auto-confirms** never had a review, so its stepper comes back with only
**two** steps (`submitted`, `confirmed`); don't assume three.

**`actions.can_cancel` is authoritative.** It is the same predicate the cancel
endpoint enforces, so a shown button always works and a hidden one would have
422'd. Never re-derive it from `status` and the clock.

### `POST /api/v1/member/bookings/preview`

Builds the confirmation screen and **creates nothing**.

```json
// request
{ "space_id": 1, "start_at": "2026-08-17 16:00", "end_at": "2026-08-17 18:00" }
```

Returns `data.space`, `data.when`, `data.breakdown` (as above), plus:

```json
{
  "payment_methods": [
    { "key": "wallet", "owner_type": "user", "owner_id": 1, "owner_label": "Personal",
      "label": "Use Wallet Balance", "usable_balance": 500,
      "usable_balance_formatted": "500 pts", "sufficient": true, "selected": true },
    { "key": "cash", "owner_type": null, "owner_id": null, "owner_label": null,
      "label": "Pay Cash at Arrival", "usable_balance": null,
      "usable_balance_formatted": null, "sufficient": true, "selected": false }
  ],
  "requires_approval": true,
  "approval_notice": "Your request will be reviewed by operations before confirmation.",
  "note_field": { "key": "note", "label": "Add a Note (Optional)",
                  "placeholder": "e.g. Meeting purpose, special requests…",
                  "max_length": 500, "required": false },
  "is_available": true,
  "unavailable_reason": null
}
```

A wallet that cannot cover the cost is returned with `"sufficient": false`
**rather than omitted** — show it greyed out. `selected` marks the sensible
default (first wallet that can pay, else cash). `cash` is always present.

### `POST /api/v1/member/bookings`

```json
{ "space_id": 1, "start_at": "2026-08-17 14:00", "end_at": "2026-08-17 15:00",
  "wallet_owner_type": "user", "wallet_owner_id": 1, "note": "optional, ≤500 chars" }
```

`201` → `{ "data": { ...BookingResource } }`. Omit the wallet fields to let the
server route payment; if the member has **more than one** eligible wallet it
returns `422` with `wallet_options` and you must re-post with a choice.

### `POST /api/v1/member/bookings/batch` — multi-date

Up to **10 windows**, **all or nothing**.

```json
{
  "windows": [
    { "space_id": 1, "start_at": "2026-08-17 10:00", "end_at": "2026-08-17 11:00" },
    { "space_id": 1, "start_at": "2026-08-18 10:00", "end_at": "2026-08-18 11:00" }
  ],
  "note": "Weekly standup"
}
```

`201` → `{ "message": "2 bookings created.", "data": [ ...BookingResource ] }`.
The wallet is debited **once** for the summed total.

On failure, `422`, nothing created, nothing charged:

```json
{
  "message": "Some of the selected dates are not available. Nothing was booked.",
  "errors": [
    { "index": 1, "start_at": "2026-08-17T07:00:00+00:00",
      "reason": "slot_unavailable",
      "message": "This space is not available for the requested time." }
  ]
}
```

`index` is the position in the `windows` array you sent — use it to mark the
offending chip. Note that two windows that overlap **each other** are rejected
too.

### `POST /api/v1/member/bookings/{booking}/cancel`

`200` → `{ "message": "Booking cancelled." }`. A wallet-paid booking is refunded
to the member's personal wallet and the slot is released.
`422` past the cancellation window · `409` after check-in · `403` not yours.

### `POST /api/v1/member/bookings/{booking}/extend`

Unchanged, pre-existing: `{ "additional_minutes": 60 }` → `{ "message": "Booking extended." }`.

---

## 3. Admin / operations

### `GET /api/v1/admin/calendar?view=day|week|month&date=&branch_id=&space_type=&space_id=`

Spaces as rows, days as cells. `view` defaults to `week` (Sunday–Saturday);
`date` is any date inside the period. Defaults to the default branch.

```json
{
  "view": "day",
  "range": { "from": "2026-08-17", "to": "2026-08-17", "timezone": "Asia/Damascus" },
  "branch_hours": { "...as member..." },
  "resources": [
    {
      "space": { "id": 1, "title": "Meeting Room A", "space_type": "room",
                 "capacity": 6, "concurrency_ceiling": 1,
                 "status": "active", "requires_approval": true },
      "days": [
        {
          "date": "2026-08-17", "weekday": "MON", "day": "17",
          "state": "open",
          "periods": [ { "open_time": "08:00", "close_time": "20:00" } ],
          "occupancy": { "booked_minutes": 60, "open_minutes": 720, "percent": 8 },
          "bookings": [
            { "id": 1,
              "start_at": "2026-08-17T14:00:00+03:00", "end_at": "2026-08-17T15:00:00+03:00",
              "start_time": "02:00 PM", "end_time": "03:00 PM",
              "status": "pending", "status_label": "Under Review",
              "payment_state": "unpaid", "checked_in_at": null,
              "member": { "id": 1, "name": "Layla Haddad", "phone": "+963505249728" } }
          ]
        }
      ]
    }
  ],
  "totals": { "spaces": 1, "bookings": 1, "occupancy_percent": 8, "by_status": { "pending": 1 } },
  "meta": { "view": "day", "date": "2026-08-17", "branch_id": 1, "spaces": 1 }
}
```

Day `state` here is `open | closed` (simpler than the member calendar's four).
`occupancy.percent` is booked ÷ open minutes, **clipped to the open window**, and
a multi-occupant space is measured against its `concurrency_ceiling` — so a hot
desk with 20 seats never reads above 100%.

### `GET /api/v1/admin/calendar/occupancy?...`

Same filters, figures only — for dashboard tiles. Each day carries
`{ date, state, occupancy, booking_count }`, no per-booking detail.

### `GET /api/v1/admin/bookings`

Filters: `status`, `payment_state`, `space_id`, `branch_id`, `user_id`,
`from`, `to` (on the booking's own `start_at`, not `created_at`), `q` (member
name or phone), `per_page` (default 25, max 100), `page`.

```json
{
  "data": [
    { "id": 1,
      "space": { "id": 1, "title": "Meeting Room A", "space_type": "room", "branch": "Main Branch" },
      "member": { "id": 1, "name": "Layla Haddad", "phone": "+963505249728" },
      "start_at": "2026-08-17T11:00:00+00:00", "end_at": "2026-08-17T12:00:00+00:00",
      "status": "pending", "status_label": "Under Review",
      "payment_state": "unpaid", "payment_source": null,
      "amount_owed": null, "currency": null,
      "checked_in_at": null, "checked_out_at": null,
      "note": "Client presentation", "rejection_reason": null,
      "created_at": "2026-08-17T05:00:00+00:00" }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 1 }
}
```

> `amount_owed` is `null` until checkout — it is written at settlement, not at
> creation. For the expected price before then, use `breakdown` on the detail
> endpoint.

### `GET /api/v1/admin/bookings/{booking}`

The index row **plus** `when`, `breakdown`, `request_status` and `actions` —
the same blocks the member sees, so the two surfaces can never disagree.

### `POST /api/v1/admin/reception/bookings` — book on a member's behalf

```json
{ "user_id": 12, "space_id": 1, "start_at": "2026-08-17 14:00",
  "end_at": "2026-08-17 15:00", "note": "Booked at the desk" }
```

`201` → `{ "data": { ...BookingResource } }`. Runs the **identical** rules as the
member endpoint — reception cannot double-book a room the member endpoint would
have refused, and cannot back-date either.

---

## 4. Error keys worth handling

| HTTP | key | when |
|---|---|---|
| 422 | `slot_unavailable` | window overlaps an existing booking |
| 422 | `space_at_capacity` | shared space at its seat ceiling |
| 422 | `buffer_conflict` | too close to a neighbouring booking |
| 422 | `outside_business_hours` | outside the branch's open periods, or spans two days |
| 422 | `invalid_start_time` | start is off the granularity grid |
| 422 | `duration_too_short` | below the minimum duration |
| 422 | `duration_invalid_granularity` | duration not a valid step above the minimum |
| 422 | `start_in_the_past` | start is before now |
| 422 | `too_soon` | inside `booking.min_notice_minutes` of now (an hour by default) |
| 422 | `too_far_ahead` | beyond `booking.max_days_ahead` (off by default) |
| 422 | `space_not_bookable` | the space is under maintenance or retired — e.g. it was taken out of service after the booking screen opened. The read endpoints 404 such a space; show the message and leave the booking flow. |
| 422 | `cancellation_window_passed` | too late to cancel |
| 422 | `wallet_choice_required` | several eligible wallets; body carries `wallet_options` |
| 409 | `already_checked_in` / `already_cancelled` | state conflict |
| 403 | — | booking belongs to another member |
| 404 | — | space not active, or booking not found |

All arrive as `{"message": "<translated sentence>"}`, already in the requested
`lang`. Show `message` directly.

---

## 5. Integration notes

1. **Never re-implement the rules client-side.** Granularity, minimum duration,
   buffer and capacity all live server-side and vary per space. Drive the UI
   from `rules`, `state` and `available`.
2. **Trust the flags.** `is_available`, `can_cancel`, `can_extend` and
   `available` are computed by the same code the write endpoints enforce.
3. **Slots are not reservations.** Always handle a `422` on confirm even if the
   slot looked free seconds earlier.
4. **Render `reason` verbatim** — it is already translated and matches what the
   write endpoint would say.
5. **Points vs money.** Compare and total using `points`; display
   `formatted`/`amount_usd`.
