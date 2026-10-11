# Booking & Availability — API reference

The 12 endpoints added by the availability and calendar work. Every shape
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
re-checks under a row lock. Both paths share one engine, so a run the picker
offers is a run the POST accepts, and a refusal still met is explained by the
POST's own 422 message.

### Building the calendar — no month endpoint

There is **no member month calendar** (removed 2026-09-28). The app builds the
date picker from the setup it already holds, and asks the server only for the
date the member taps:

| a date is… | when | from |
|---|---|---|
| past | before today | the device's date |
| closed | its weekday is not in `open_weekdays`, or it is in `upcoming_closures` | the spaces list's `data.working_hours_banner.open_weekdays`; the space details' `data.booking.upcoming_closures` |
| beyond the window | more than `max_days_ahead` days from today (`null` = no limit) | the space details' `data.booking.rules` |
| otherwise | tappable | — |

Tapping a date calls the day endpoint below, whose `data.state` is the
authority for that date: a day can still turn out `full` (every slot taken by
other members), or be closed or shortened by an exception the setup did not
cover.
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
      { "start_time": "03:00 PM", "end_time": "04:00 PM", "state": "available", "is_available": true },
      { "start_time": "04:00 PM", "end_time": "05:00 PM", "state": "booked", "is_available": false }
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

**The day's `state`** — the authority for the tapped date:

| state | meaning | `closed_banner` |
|---|---|---|
| `available` | open, has free slots | `null` |
| `full` | **open**, every slot taken | `null` |
| `closed` | branch not open that day; `slots` is empty | present |
| `past` | the date has gone | `null` |
| `out_of_range` | beyond `max_days_ahead` | `null` |

A `full` day must **not** be shown as closed — it is open, just sold out.

`closed_banner`, when present:

```json
{
  "text": "Main Branch is closed on this date.",
  "hint": "Choose another day.",
  "reason": "Public holiday",

}
```

`reason` is `null` for a weekday with no schedule (nothing to explain); it is
only non-null when an explicit business-hour exception supplied one.

**Each slot is one of the space's own cells**, `meta.granularity_minutes` long
(changed 2026-09-28: there is no `duration` parameter any more).
A slot carries only its own fields: `start_time`, `end_time`, `state`,
`is_available`. There is no `reason`: grey a chip by its `state`.

**One time format, shown as sent** (changed 2026-09-29): `start_time` and
`end_time` are 12-hour `hh:mm AM|PM` ("03:00 PM"), with AM/PM in Latin
letters whatever the `lang`, and there is no `label` in another format beside
them. Show the chip as `start_time` – `end_time` and post the same values
back — `start_at = "2026-08-17 03:00 PM"` is accepted as-is — so nothing is
converted on either side. The dashboard does the same. **Never compare two of
these as text** to order them: "01:00 PM" sorts before "11:00 AM". Use the
slots' order in the list.

**Picking a booking = picking a run of cells.** The member taps a start cell
and an end cell; the booking runs from the first cell's `start_time` to the
last cell's `end_time`. Each cell is judged **on its own** — is this stretch
free — so the half hour right before another booking is `available`: it can
end a run even though no minimum-length booking could start there. Before
sending it, the app keeps the run:

- **unbroken** — every cell in it `available`, and each cell's `start_time`
  equal to the previous one's `end_time` (a lunch break leaves a gap);
- **at least `rules.min_duration_minutes`** long. There is no maximum: a
  member may book as long as the day's open cells allow.

Post `start_at = "{date} {first.start_time}"`,
`end_at = "{date} {last.end_time}"` (local time). The indicative price is the
space details' `data.details.price.hourly_rate_points` × hours; the amount
actually charged, after the plan discount and VAT, is what
`POST /bookings/preview` returns — show that on the summary. The server
re-checks the whole window (a buffer next to another booking, for one), and a
refusal arrives as the POST's own translated 422 `message`.
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


Slots are generated **only inside open periods**, so a day that closes for lunch
simply has no chips across the gap — do not render an empty range there.

> **The desk reads the very same response** (changed 2026-09-29):
> `GET /api/v1/admin/spaces/{space}/availability/slots` returns this body
> byte for byte — the same envelope, the same four slot fields, the same two
> states, no `reason`, no `rules`, no `branch_hours` — and a test asserts the
> two are identical. Its setup comes once, with the admin calendar
> (`booking_window.min_duration_minutes`, the window, `branch_hours`), the way
> the app's comes once with the space details. It takes no `duration` either:
> the desk picks a run of cells exactly as the app does.

### `GET /api/v1/member/spaces` — `working_hours_banner`, the branch's hours

The branch's hours are the same for every space, so they come **once, with the
spaces list**, not in each space's details. Besides the fields it always had
(`text`, `hours`, `open_time`, `close_time`, `is_open` — today's), the banner
carries the branch's week. The upcoming closures are **not** here: they set up
the booking calendar, so they come with the space details (below).

**The response is a `data` object and a top-level `meta`** (changed
2026-09-28; `meta` moved out of `data` 2026-09-29):
`{data: {working_hours_banner, spaces}, meta}` — the list is `data.spaces`, the
banner `data.working_hours_banner`, the paging `meta`.

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
    "spaces": [ "...spaces..." ]
  },
  "meta": { "...": "..." }
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
| `max_days_ahead` | **how many days ahead** a booking may be made | `30` — on 1 October, nothing after 31 October | grey out later dates; the day endpoint marks them `out_of_range`. `null` = no limit |

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

> **No quote** (removed 2026-09-28, member and desk). The ladder of lengths
> from a chosen start served the duration-first pickers, which neither the
> app nor the dashboard has any more.
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

### `GET /api/v1/admin/calendar?view=day|week|month&date=&branch_id=&space_type=&space_id=&member_id=`

`member_id` (added 2026-10-02) narrows the bookings listed in each cell, and
`totals.bookings`/`by_status`, to that member's; `meta.member_id` echoes it.
Each cell's `occupancy` stays the space's own, from every booking, so a room
others filled never reads as free. An unknown id is a `422` on `member_id`.

Spaces as rows, days as cells. `view` defaults to `week` (Sunday–Saturday);
`date` is any date inside the period. Defaults to the default branch.
`booking_window` (added 2026-09-28) is the desk's booking setup, read once, as
the member app reads its rules once from the space details:
`min_duration_minutes` (added 2026-09-29) is the shortest run the booking form
accepts — the day's slots carry no rules — and `max_days_ahead` lets the date
picker refuse a date beyond it before asking for slots. With
`branch_hours.open_weekdays` and `upcoming_closures` it is the whole setup of
the booking calendar. `null` with `branch_hours` when no space matched the
filters.

```json
{
  "view": "day",
  "range": { "from": "2026-08-17", "to": "2026-08-17", "timezone": "Asia/Damascus" },
  "branch_hours": { "...as member..." },
  "booking_window": { "min_duration_minutes": 60, "min_notice_minutes": 60, "max_days_ahead": null },
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
`occupancy.percent` is booked ÷ open minutes, **clipped to the open window**.

**A co_space's occupancy is its branch's pool's** (changed 2026-09-30,
[coworking-seat-pool.md](../decisions/coworking-seat-pool.md)). It is seat-minutes
taken ÷ seat-minutes open inside the day's open periods, counting subscriptions
holding seats, bookings, and the walk-ins inside now. Every co_space row of a
branch therefore shows the same figure.

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

### The co-working seat pool (added 2026-09-30)

Co-working seats are counted **once per branch**, not per co_space
([coworking-seat-pool.md](../decisions/coworking-seat-pool.md)):

- **Seats open:** each active co_space's `capacity`, plus the seats of rooms
  lent to co-working at that moment.
- **Seats taken:**
  - every active subscription holds its `seats` while its plan is open;
  - each booking of a co_space takes one, unless the member's subscription
    already holds their seat;
  - each walk-in inside now takes one, on the same terms.

A co_space booking, a walk-in and a subscription sale are each refused when no
seat is left for them.

#### `GET /api/v1/admin/branches/{branch}/coworking-pool?from=&to=`

`from` / `to` are district wall-clock (`2026-10-05 08:00`). Leave them out for
now until the end of today. At most 62 days are read.

```json
{
  "data": {
    "from": "2026-10-01T09:00:00+03:00", "to": "2026-10-02T00:00:00+03:00",
    "capacity_now": 16, "usage_now": 11, "free_now": 5, "min_free": 2,
    "segments": [
      { "start": "2026-10-01T09:00:00+03:00", "end": "2026-10-01T14:00:00+03:00",
        "capacity": 16, "usage": 11, "free": 5 },
      { "start": "2026-10-01T14:00:00+03:00", "end": "2026-10-01T18:00:00+03:00",
        "capacity": 22, "usage": 20, "free": 2 }
    ]
  }
}
```

`min_free` is the fewest free seats anywhere in the range, and is negative
when overbooked. Read it before selling a subscription or when deciding
whether to lend a room.

#### Lending a room to co-working

- `GET /api/v1/admin/spaces/{space}/coworking-windows`: the room's stretches that
  have not ended, soonest first.
- `POST /api/v1/admin/spaces/{space}/coworking-windows`:
  `{ "starts_at": "2026-10-01 14:00", "ends_at": "2026-10-01 18:00", "seats": 6 }`
  returns `201` with `{ "data": { "id", "space_id", "starts_at", "ends_at", "seats" } }`.
  - Only a room can be lent, for at most its `capacity` seats.
  - Stretches of the same room may not overlap (validation, `starts_at`).
  - It is refused when the room has a booking in the stretch
    (`api.coworking.room_has_bookings`).
- `PATCH /api/v1/admin/coworking-windows/{id}`: any of the three fields, plus
  `override`. Returns `{ "message": ... }`.
- `DELETE /api/v1/admin/coworking-windows/{id}?override=1`: returns
  `{ "message": ... }`.

Shrinking or taking back seats the pool is counting on is refused
(`api.coworking.would_overbook`) unless `override` is sent.

While a room is lent:

- Its day cells are not available (`coworking_mode`, which reaches the member
  app and the day grid as `booked`).
- A booking of it is refused with `api.booking.room_in_coworking_mode`.
- A walk-in to it takes a pool seat.

#### `POST /api/v1/admin/memberships`: seats and branch

New optional fields:

- `branch_id`: the pool it holds seats in. Defaults to the company's branch,
  else the member's home branch, else the default branch.
- `seats`: a company only, default 1. A member's own subscription is always
  exactly 1.
- `override_capacity`: sell even when the pool is full. The override is
  recorded in the audit log.

When the pool has no room for the subscription over its period, the response
is:

```json
{ "message": "Every co-working seat in this branch is already taken …",
  "first_shortfall_at": "2026-10-03T20:00:00+00:00" }
```

The member purchase (`POST /api/v1/member/memberships`) gives the same `422`,
and nothing is charged.

#### Desks: a co_space's seats, and Dedicated Desk subscriptions

A co_space's seats are its desks (`seats_desks`); its `capacity` is their
count and is never sent to `POST|PUT admin/spaces` (a `422` on `capacity` if
it is). A branch has one co_space at most: a second one, created or turned into
one from another type, is a `422` on `space_type`.

- `POST|PUT /api/v1/admin/seats-desks` take `space_id`, `code` (required,
  unique within the branch) and `label`.
- `GET /api/v1/admin/seats-desks?branch_id=&free=1` returns each desk with
  `code` and `membership_id`, the live subscription holding it or `null`.
  `free=1` keeps only the desks nobody holds. `free_for_plan={plan}` keeps the
  desks free during that plan's hours over a period sold now, and
  `free_for_membership={membership}` those free during that subscription's
  hours and dates (its own desks included): a desk is held only inside its
  plan's hours, so a morning and an evening plan may share one.
- `POST /api/v1/admin/memberships` takes `seat_desk_ids` for a Dedicated Desk
  plan, and its response carries `desks: [{id, code, label}]`.
- `PUT /api/v1/admin/memberships/{membership}/desks` `{"seat_desk_ids": [4]}`
  replaces the subscription's desks and returns `{message}`.

Both refuse with a `422` on `seat_desk_ids` when:

- the plan is not a Dedicated Desk plan;
- there are more desks than the subscription's `seats`;
- a desk is not in a co_space of the subscription's branch;
- another live subscription holds a desk at some moment this one would: their
  dates overlap and their plans' hours meet.

Deleting a desk a live subscription holds, or deleting its co_space, gives a
`422` with `{message}`; moving it to another space is a `422` on `space_id`. A
co_space with desks cannot change type (a `422` on `space_type`).

- `GET /api/v1/admin/memberships?dedicated=1&live=1&without_desk=1&branch_id=`
  lists subscriptions newest first and paginated, each with
  `owner: {type, id, name}` and `desks[]`. `without_desk=1` is how staff find a
  Dedicated Desk plan bought in the app that is still owed its desk.
- `GET /api/v1/member/memberships/current` carries `desks`: `[{code, label}]`
  for a Dedicated Desk plan (empty until one is tied), `null` for any other.

#### Reception's active sessions

`GET /api/v1/admin/reception/sessions/active` rows now carry `seat_covered`,
`membership_id` and `desk_codes` (the codes of the desks the member's own or
their company's live Dedicated Desk subscription ties them to; `[]` otherwise). `seat_covered` is `true` when the walk-in sits in a seat their
subscription holds, and `false` when it took a free one. Both are `null` for a
booking.

---

## 4. Error keys worth handling

| HTTP | key | when |
|---|---|---|
| 422 | `slot_unavailable` | window overlaps an existing booking, or (a co_space) no co-working seat is left in the branch |
| 422 | `room_in_coworking_mode` | the room is lent to co-working in that window |
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
4. **Render the 422 `message` verbatim** — it is already translated and names
   the rule the window broke.
5. **Points vs money.** Compare and total using `points`; display
   `formatted`/`amount_usd`.
