# Reception kiosk: banner content, one aggregate public endpoint, and QR arrival requests

**Status:** resolved 2026-08-23. **Owner:** Maryam Asha.
**Type:** design doc for a new capability, added to R1 scope by explicit
decision — see "Relationship to the R1 QR exclusion" below.

## What this adds

Three small pieces, all additive, no existing table or service touched:

1. `App\Domain\Ecosystem\Announcement` — admin-managed banner content
   (news / event / offer), plus reuse of the existing `Plan` catalog, served
   together as one "banner" section.
2. `App\Domain\Booking\ArrivalRequest` — a lightweight "I'm here" signal a
   member's app sends after scanning a static kiosk QR. It never creates a
   booking or session by itself; reception confirms it manually, at which
   point the *existing* check-in / walk-in-creation endpoints run unchanged.
3. `Api\V1\Public\KioskController::show()` — one aggregate, unauthenticated
   endpoint (`GET /api/v1/public/kiosk`) that returns everything the kiosk
   app needs in a single call: banner content, social links, the app-download
   link, and the static arrival QR value.

## Relationship to the R1 QR exclusion

`ADDOSSprintsTasks.xlsx` → Releases sheet lists "طبقة QR" (`qr_points`,
`service_tickets`, scan-driven service requests) as explicitly **out** of
R1, deferred to R2. This feature is a deliberate, scoped exception, not a
silent reopening of that exclusion:

- No `qr_points` table, no per-seat/per-desk QR, no `service_tickets`.
- The kiosk QR is **one static value for the whole venue** (single branch
  today — see below), not a generated-per-scan or per-seat code.
- Scanning it **never authorizes entry, opens a lock, or starts a paid
  session on its own** — it only queues a request on reception's screen,
  exactly as if the member had walked up and given their name. Reception's
  existing judgment (identity check, physical presence, payment collection
  for a walk-in) is unchanged and remains the sole point of action.
- Because the actual state change (check-in / walk-in creation) is
  performed by calling the *existing* Reception Operations endpoints
  unchanged, this feature adds no new authority anywhere in the system —
  only a notification queue in front of authority that already exists.

If R2's real QR layer (`qr_points`, desk-level codes, service tickets) is
built later, `ArrivalRequest` is not superseded by it — it solves a
different problem (arrival signaling vs. desk/service identification) and
can coexist unchanged.

## Decision

### Banner (`announcements`)

- **One content type, not three.** `Announcement.type` is a plain open
  `string` (`news` | `event` | `offer`), following the exact precedent set
  by `ContactLink.type` (`docs/decisions/profile-fields-completion-score-
  contact-links.md`) — a new kind of announcement is a row, never a
  migration. Not a MySQL `ENUM`, not a PHP backed enum.
- **`event` here is display-only.** An announcement with `type=event` is a
  flyer — no FK to `App\Domain\Experience\Event`, no registration, no
  attendee tracking. It is fully decoupled from the real Events feature
  R2 will build. Do not add a relationship between the two.
- **Every announcement is one designed image**, not structured text. Fields
  are deliberately minimal: `image_url` + optional `link_url` ("اعرف
  أكثر"). No `title`/`description` columns — the design lives in the image
  itself, per the source conversation.
- **Full scheduling from day one:** `starts_at`/`ends_at` (both nullable),
  in addition to `is_active`, so an offer or event flyer can be scheduled
  ahead of time and disappear automatically, not just be toggled by hand.
  An announcement is "live" when `is_active = true` AND
  (`starts_at` is null OR `starts_at <= now()`) AND
  (`ends_at` is null OR `ends_at >= now()`).
- **Domain placement:** `App\Domain\Ecosystem`, mirroring `Founder` /
  `Partner` / `ContactLink` exactly — public-facing, admin-managed content,
  same `AdminResourceController` / `PublicResourceController` two-tier
  pattern, same permission tier (`role:admin|operations`).
- **Plans are not duplicated.** The banner's "باقات" section reads directly
  from the existing `Plan` catalog (`is_active = true`, ordered by `order`)
  — no new table, no new admin screen.

### Aggregate endpoint (`GET /api/v1/public/kiosk`)

- **One endpoint, no parameters.** ADD is single-branch today
  (`docs/decisions/kiosk-display.md` §"Single branch" below); the endpoint
  resolves the one `Branch` row itself. It does **not** accept or require
  `branch_id`. This is a recorded assumption, not a permanent constraint —
  see "Known follow-up" below.
- **Response is sectioned, not flat**, so the kiosk app makes exactly one
  request and switches on top-level keys. `banner` is revised (2026-08-24)
  to be one flat, mixed-type list — the client switches on each item's own
  `type`, rather than being handed three separately-keyed lists — and
  `plans` moved out of `banner` to its own top-level key, since it isn't
  banner content:
  ```json
  {
    "banner": [
      { "id": 1, "type": "news",  "image_url": "...", "link_url": null },
      { "id": 2, "type": "event", "image_url": "...", "link_url": null },
      { "id": 3, "type": "offer", "image_url": "...", "link_url": "..." }
    ],
    "plans": [{ "id": 1, "name": {"ar": "...", "en": "..."}, "price": "50.00", "pricing_currency": "USD", "duration_days": 30, "included_hours": "10.00" }],
    "social_links": [{ "type": "instagram", "value": "https://...", "label": "..." }],
    "app_download": { "app_store": "https://...", "google_play": "https://..." },
    "arrival_qr": { "value": "..." }
  }
  ```
  `banner` is ordered by `sort_order` across every type together, not
  grouped by type first. `app_download` is revised (2026-08-25) from a
  single `url` to two named links — `app_store` and `google_play` — since
  the kiosk needs to show separate QR codes/buttons per store, not one
  generic download link.
- **`social_links` is `ContactLink::where('is_visible', true)` unchanged.**
  Zero new backend work for this section — it already exists and is
  already public (`Api\V1\Public\ContactLinkController`).
- **`app_download.{app_store,google_play}` and `arrival_qr.value` are
  `Setting` rows**, not new columns or a new table —
  `kiosk.app_store_url`, `kiosk.google_play_url`, and
  `kiosk.arrival_qr_value`, all global scope, following
  `docs/decisions/settings-key-value-store.md` exactly, editable through
  the existing generic `Admin\SettingController` (no dedicated endpoint
  needed — settings admin is already a key/value list). All three are
  seeded placeholder defaults, flagged for Maryam to fill in the real
  values before launch.
- **`banner` and `social_links` ship with seeded placeholder rows** too
  (`AnnouncementSeeder`, `ContactLinkSeeder` — 2026-08-25), so the kiosk
  screen isn't empty before real content is entered through
  `Admin\AnnouncementController` / `Admin\ContactLinkController`: one
  `news`/`event`/`offer` banner each, and one link each for `instagram`,
  `facebook`, `linkedin`, `website`. Both seeders key their `firstOrCreate`
  lookup on a natural identifier (`image_url`, `type`) so re-running
  `db:seed` never duplicates rows or clobbers an admin edit.
- **The backend never generates a QR image.** Every "QR" in this feature
  (social links, app download, arrival) is a plain string/URL; the kiosk
  frontend renders the QR client-side from that value with a standard JS
  QR library. This keeps the endpoint a plain JSON aggregator with no
  image-generation dependency.

### Arrival requests (`arrival_requests`)

- **The kiosk QR value is static — no rotation, no expiry.** Scanning it
  identifies no one and grants nothing by itself; the only thing it can
  ever produce is a pending entry on reception's own screen, which a human
  must act on. There is no scenario where a screenshotted or reused QR
  value causes an unwanted state change, so there is nothing to protect by
  rotating it. (An earlier draft of this decision proposed a 30–60s
  rotating QR; reversed after the "reception always confirms" model made
  the threat model moot — see this doc's discussion history if the
  question resurfaces.)
- **Scan direction:** the kiosk displays the QR; the member's own app
  scans it and calls the arrival-request endpoint authenticated as
  themselves. The kiosk never scans anything.
- **Creating an arrival request never changes booking/session state.**
  `POST /api/v1/member/arrival-requests` (auth:sanctum, role:member)
  creates one `ArrivalRequest` row: `user_id`, `requested_at = now()`, and
  `matched_booking_id` — set by looking up the member's own bookings for
  *today*, at the (single) branch, with `status` in
  (`confirmed`, `pending`) and no `checked_in_at` yet. This match is
  informational only, shown to reception as a hint — it decides nothing.
- **Reception owns the decision, end to end**, exactly as if the member had
  walked up and stated their name with no app involved:
  - `GET /api/v1/admin/reception/arrival-requests` — list `status=pending`,
    each row showing the member's name/phone and `matched_booking_id` if
    any (eager-load `booking.space` for display).
  - `POST /api/v1/admin/reception/arrival-requests/{arrivalRequest}/confirm`
    — reception's one required decision point. If `matched_booking_id` is
    present, this **calls the existing**
    `BookingReceptionController::checkIn()` **unchanged** for that booking.
    If absent, the request body must include `space_id`, and this **calls
    the existing** `WalkInSessionController::store()` **unchanged** for
    that member/space. Either way, `ArrivalRequest.status` flips to
    `confirmed` and `confirmed_by_user_id`/`confirmed_space_id` are
    recorded. No new booking/walk-in logic is written for this endpoint —
    it is purely a thin dispatch to code that already exists and is
    already tested.
  - **A matched booking that is still `pending` approval cannot be
    confirmed through this endpoint.** The match set intentionally includes
    `pending` bookings (previous bullet), but `checkIn()` — called
    unchanged — rejects a `pending` booking with a 409 and an actionable
    message ("must be approved before it can be checked in"). This is
    correct, not a bug: it forces the existing approval flow
    (`POST .../bookings/{booking}/approve`) to run first, exactly as it
    would for a walk-up member with a pending booking and no kiosk
    involved. `confirm()` does not fall back to treating a `space_id` in
    the request body as a walk-in override when a booking is matched —
    honoring that would let reception route around an unapproved booking's
    guard through this endpoint alone. Reception's remedy: approve the
    booking first, then retry confirm; or reject the arrival request and
    handle the member as an ordinary walk-in.
  - `POST /api/v1/admin/reception/arrival-requests/{arrivalRequest}/reject`
    — flips `status` to `rejected`. No refund/cancellation logic applies
    (nothing was ever charged or reserved by the arrival request itself).
  - A scheduled sweep (mirroring the existing
    `CloseOverdueReceptionSessions` command pattern) marks any
    `pending` request older than a configurable window (default 30
    minutes, via `kiosk.arrival_request_expiry_minutes` in `Setting`) as
    `expired`, so reception's queue doesn't accumulate stale entries from
    members who scanned and then left.
- **Domain placement:** `App\Domain\Booking`, alongside `Booking` /
  `WalkinSession` — it exists to feed those two flows and has no meaning
  outside that context, unlike `Announcement`/`ContactLink` which are
  Ecosystem content.

### Single branch (recorded assumption)

ADD operates one branch today. The aggregate endpoint and the arrival-
request matching logic both resolve "the branch" as
`Branch::query()->first()` (or the sole active row) rather than accepting
a parameter. **Known follow-up, not built now:** if a second branch is
ever added, both call sites need a real resolution strategy (kiosk device
→ branch mapping, most likely a `branch_id` column on a future `kiosks`
registration record) before they'll behave correctly. Flagged here so it
isn't silently assumed away later.

## Guard

No dedicated `tests/Guards/` entry — like `reception-operations-scope.md`,
this is new additive capability rather than a schema-shape invariant.
Coverage instead:

- `tests/Feature/Ecosystem/AnnouncementTest.php` — admin CRUD; public
  listing excludes inactive/out-of-window rows; `type` accepts an
  arbitrary new string without a migration.
- `tests/Feature/Booking/ArrivalRequestTest.php` — creating a request
  correctly matches (or doesn't match) today's booking; confirming a
  matched request calls check-in and leaves the booking's own guards
  (already-checked-in, wrong status, outside business hours) fully intact;
  confirming an unmatched request without `space_id` is rejected 422;
  confirming an unmatched request with `space_id` creates a walk-in
  session via the existing service; the expiry sweep only touches
  `pending` rows past the window.
- `tests/Feature/Public/KioskControllerTest.php` — response shape; `banner`
  respects `is_active`/window filtering and carries the right `type` per
  item; `plans`/`social_links`/`app_download`/`arrival_qr` are present even
  when `announcements` is empty.

## What this changes in code

- `App\Domain\Ecosystem\Models\Announcement` (+ migration, factory)
- `App\Domain\Booking\Models\ArrivalRequest` (+ migration, factory)
- `App\Http\Controllers\Api\V1\Admin\AnnouncementController` (extends
  `AdminResourceController`)
- `App\Http\Controllers\Api\V1\Public\KioskController` (new, single
  `show()` action — does not extend `PublicResourceController`, since it
  aggregates multiple sources rather than listing one resource)
- `App\Http\Controllers\Api\V1\Member\ArrivalRequestController` (`store`
  only)
- `App\Http\Controllers\Api\V1\Admin\Reception\ArrivalRequestController`
  (`index`, `confirm`, `reject`)
- `App\Console\Commands\ExpireStaleArrivalRequests` +
  `routes/console.php` entry
- Three `Setting` rows seeded: `kiosk.app_store_url`,
  `kiosk.google_play_url`, `kiosk.arrival_qr_value`, plus
  `kiosk.arrival_request_expiry_minutes`
- `Database\Seeders\AnnouncementSeeder`, `Database\Seeders\ContactLinkSeeder`
  (placeholder banner/social-link rows, both called from `DatabaseSeeder`)
- `routes/api/v1/public.php`, `routes/api/v1/member.php`,
  `routes/api/v1/admin.php` (`reception/arrival-requests/*`)
- `lang/{en,ar}/api.php` (`kiosk` group)
- `postman/ADD-OS.postman_collection.json` (`Kiosk` folder)
