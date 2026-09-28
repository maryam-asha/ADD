# Booking & Availability — API reference

The 15 endpoints added by the availability and calendar work. Every shape
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
    "min_duration_note": "60-min start slots • Min. 1h",
    "slots_note": "Greyed-out slots are already booked.",
    "concurrency_ceiling": 1,
    "max_selectable_dates": 10
  },
  "legend": [
    { "key": "available",     "label": "Available",     "style": "solid"  },
    { "key": "booked",        "label": "Booked",        "style": "muted"  },
    { "key": "outside_hours", "label": "Outside hours", "style": "dashed" }
  ],
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

### `GET .../availability/slots?date=2026-08-17[&duration=120]`

The time-chip grid for one date. **Everything is inside one `data` object, and
the chip list is `data.slots`** (changed 2026-09-28; it used to be the
top-level fields with the chips under a top-level `data` array).

```json
{
  "data": {
    "date": { "date": "2026-08-17", "day": "17", "weekday": "MON", "month": "AUG",
              "date_formatted": "August 17, 2026", "state": "available",
              "is_selectable": true, "available_slot_count": 11, "total_slot_count": 12 },
    "state": "available",
    "closed_banner": null,
    "branch_hours": { "...same as above, including upcoming_closures..." },
    "rules": { "...same as above..." },
    "legend": [ "...same as above..." ],
    "slots": [
      { "start_at": "2026-08-17T08:00:00+03:00", "end_at": "2026-08-17T10:00:00+03:00",
        "label": "08:00 AM", "start_time": "08:00", "end_time": "10:00",
        "state": "available", "is_available": true, "reason": null,
        "duration_minutes": 120, "points": 240, "amount_usd": "24.00" },
      { "start_at": "2026-08-17T14:00:00+03:00", "end_at": "2026-08-17T16:00:00+03:00",
        "label": "02:00 PM", "start_time": "14:00", "end_time": "16:00",
        "state": "booked", "is_available": false,
        "reason": "This space is not available for the requested time.",
        "duration_minutes": 120, "points": 240, "amount_usd": "24.00" }
    ],
    "counts": { "available": 9, "booked": 2, "outside_hours": 0, "buffer": 0,
                "at_capacity": 0, "past": 0, "too_soon": 0, "too_far_ahead": 0, "total": 11 },
    "meta": { "granularity_minutes": 60, "timezone": "Asia/Damascus", "duration_minutes": 120 }
  }
}
```

**`duration` (optional, minutes) — the duration-first flow.** Pick a length
from `rules.duration_options_minutes` (the lengths this space accepts), then ask
for the day with it: each slot is then the **whole booking** — `end_time` is
where it ends, and it carries `points` / `amount_usd` — stepping by the space's
granularity, and a start that cannot hold the full length before closing is not
listed. Post `start_at`/`end_at` straight from the chosen slot. A length the
space refuses is a `422` on `duration` with the same sentence `POST /bookings`
would give. Without `duration`, each slot is one granularity-sized cell with no
price (the example above is the duration form).

**Slot `state`:** `available` · `booked` · `outside_hours` · `buffer` ·
`at_capacity` (shared space at its seat ceiling) · `past` · `too_soon`
(inside the minimum notice) · `too_far_ahead` (beyond the booking horizon).
The last two only occur once `booking.min_advance_minutes` /
`booking.max_advance_days` are set; both are off by default.

Slots are generated **only inside open periods**, so a day that closes for lunch
simply has no chips across the gap — do not render an empty range there.

> The admin twin, `GET /api/v1/admin/spaces/{space}/availability/slots`, returns
> the same content **without** the envelope (top-level fields, chips under
> `data`) — the dashboard reads that shape.

### `GET .../availability/quote?start_at=2026-08-17 10:00`

Feeds the duration selector, computed end time, price and running total.

```json
{
  "start_at": "2026-08-17T10:00:00+03:00",
  "start_time": "10:00 AM",
  "hourly_rate_points": 120,
  "hourly_rate_usd": "12.00",
  "durations": [
    { "minutes": 60,  "label": "1h",   "end_at": "2026-08-17T11:00:00+03:00",
      "end_time": "11:00 AM", "points": 120, "amount_usd": "12.00",
      "available": true,  "reason": null },
    { "minutes": 90,  "label": "1.5h", "end_at": "2026-08-17T11:30:00+03:00",
      "end_time": "11:30 AM", "points": 180, "amount_usd": "18.00",
      "available": false,
      "reason": "The booking duration must be a multiple of this space's slot granularity above the minimum." },
    { "minutes": 120, "label": "2h",   "...": "..." },
    { "minutes": 180, "label": "3h",   "...": "..." }
  ],
  "meta": { "min_duration_minutes": 60, "granularity_minutes": 60, "timezone": "Asia/Damascus" }
}
```

The ladder is configurable (`booking.duration_options_minutes`, seeded
`[60,90,120,180]`). An option is **returned disabled with a reason rather than
hidden** — grey the chip out and show the reason. The example above is a space
with 60-minute granularity, so 1.5h cannot be booked there.

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
| 422 | `duration_too_long` | above `booking.max_duration_minutes` (off by default) |
| 422 | `too_soon` | inside `booking.min_advance_minutes` of now (off by default) |
| 422 | `too_far_ahead` | beyond `booking.max_advance_days` (off by default) |
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
