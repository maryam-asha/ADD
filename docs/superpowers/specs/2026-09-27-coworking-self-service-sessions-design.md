# Design: Self-service coworking sessions (scan to start, request to end)

**Status:** approved for planning
**Owner:** Maryam Asha
**Date:** 2026-09-27

## Context

Today a member's app can never start a session. Scanning the kiosk's static
arrival QR (`POST member/arrival-requests`) only queues a request; reception
confirms it, and only then do the existing check-in / walk-in endpoints run
(`docs/decisions/kiosk-display.md`). Every session is ended by reception
entering a checkout time, or by the auto-close command at branch closing.

This design lets a member start and end a **coworking** session themselves:

- Scanning a **rotating** QR on the kiosk opens the session immediately; the
  timer runs from the scan.
- Reception sees who started it, their identity, and their plan state.
- If the plan will not cover the session (hours used up, plan expired,
  outside the plan window, no plan), the member is told first and must
  accept a regular paid session before it starts. Reception sees that too.
- The member ends it with a button; reception confirms; both get a session
  report listing the time and every transaction recorded during the session.

### Scope

In scope: `co_space` spaces only. Rooms, business spaces and event halls keep
their existing reception-driven process and the existing static arrival QR,
unchanged.

Out of scope, each deliberately:

- **Café / drink orders** — no ordering system exists. It is stage two, its
  own spec. This design's report is built so orders plug in as another line
  kind, with no redesign.
- **Paid printing/internet** (consumption with no plan, or beyond the plan's
  allowance) — needs a per-unit price list; it moves to stage two's priced
  catalog alongside drinks. Here, consumption stays plan-allowance only,
  exactly as `ResourceConsumptionController` works today.
- **Live push to the dashboard** — broadcasting is off
  (`BROADCAST_CONNECTION=log`) and admins have no notification inbox.
  Reception polls, as it already does for arrival requests.
- **A push when plan coverage runs out mid-session** — the app counts down
  from `covered_until` instead.
- **Booking checkout billing** — see "Known pre-existing issue" below.

## Decisions taken while designing

| # | Question | Decision |
|---|----------|----------|
| 1 | Which space does a scan open a session on? | Only the coworking space. The kiosk row is bound to one `co_space` space. |
| 2 | How is physical presence proven without reception confirming? | A rotating QR on the kiosk screen (30 s steps). |
| 3 | Member has a coworking booking today and scans? | The scan checks that booking in; a coworking booking is a walk-in with a pre-set time. |
| 4 | Plan will not cover the session? | The member confirms first; a covered session opens with no extra step. |
| 5 | How does a session end? | A button in the app creates an end request; reception confirms it. |
| 6 | Which transactions belong to a session? | Resource consumption now; café orders in stage two. |
| 7 | Paid printing for non-plan members? | Deferred to stage two. |
| 8 | Structural approach? | Keep `Booking` and `WalkinSession`; add a coordination layer on top (below). |
| 9 | Kiosk codes? | Two on screen: the rotating coworking code, and the existing static arrival code for rooms. |
| 10 | How does the kiosk get the rotating code? | TOTP generated locally on the kiosk from a per-kiosk secret, authenticator-app style; no server call, the backend verifies with the same secret. |
| 11 | How does the secret reach the kiosk? | Straight from the server, once, through a short-lived single-use pairing code an admin issues; every pairing rotates the secret. |

### Approaches considered for #8

- **A — coordination layer (chosen).** Keep both session models; a new
  service orchestrates start / end / report and calls the existing, tested
  check-in, capacity and closure code. Transactions link to either model
  through a polymorphic column.
- **B — every coworking session is a `WalkinSession`, booking attached.**
  Rejected: needs a new "bill only outside the booked window" rule, and a
  booking would then have two check-in paths with different outcomes.
- **C — a new unified `CoworkingSession` model.** Rejected: rewrites
  reception, closure, auto-close and capacity and migrates data, for no
  capability A lacks.

## Amendments at planning time (2026-09-28)

- **Own `App\Support\Security\Totp` instead of Google2FA.**
  `pragmarx/google2fa` reads the system clock, which `Carbon::setTestNow()`
  cannot move, so "a code from 30 s ago" could not be tested. `Totp` takes
  the time from the caller (`now()->getTimestamp()`) and is pinned to the
  RFC 6238 SHA1 test vectors. Base32 comes from
  `paragonie/constant_time_encoding`, already installed transitively, and
  is required explicitly.
- **`Kiosk` and `KioskPairingService` live in `App\Domain\Booking`,** next
  to `ArrivalRequest`, not in `Foundation`. The kiosk exists to feed
  coworking sessions and shares `ReceptionActionException`.
- **Reception's walk-in notice (`plan_applies` / `outside_plan_window`) is
  left exactly as it is.** Instead, every walk-in (reception or scan)
  stores `coverage_snapshot` from `SessionCoverageEvaluator`, so the
  reception list shows the same plan info for both paths.
- **A scan also runs two pre-checks before any confirmation is asked
  for:** a kiosk whose space is not an active `co_space` → 422
  `api.coworking.unavailable`; outside business hours → the existing
  `api.reception.outside_business_hours`. So a member is never asked to
  accept a paid session that would then be refused.
- **The Postman collection update is deferred.** The main working tree has
  uncommitted edits to `postman/ADD-OS.postman_collection.json`; it gets
  updated after those land.
- **Environment.** Implemented in worktree
  `.claude/worktrees/coworking-sessions` (not a sibling `../ADDCore-coworking`
  directory). PHP on PATH is 8.2.4 while `composer.json` requires `^8.3`, so
  every Composer command runs with `--ignore-platform-reqs`.

## 1. Kiosk identity and the rotating code

### `kiosks` table — `App\Domain\Booking\Models\Kiosk`

`id` · `name` · `space_id` (FK `spaces`, restrict) · `is_active` (bool,
default true) · `totp_secret` (text, nullable until first paired) ·
`pairing_code_hash` (nullable, unique) · `pairing_code_expires_at`
(nullable timestamp) · `paired_at` (nullable timestamp) · `last_used_at`
(nullable timestamp) · timestamps.

- `space_id` must reference a space whose `space_type` is `co_space`
  (enforced in the Store/Update Form Requests, same place
  `StoreSeatDeskRequest` enforces its own `co_space` rule). The branch is
  derived from the space, which also closes the "kiosk device → branch
  mapping" follow-up recorded in `kiosk-display.md` §"Single branch".
- `totp_secret` uses the `encrypted` cast; it and `pairing_code_hash` are
  in `$hidden`, so neither is ever serialized, by `KioskResource` or
  anything else. The kiosk is not a Sanctum tokenable: it calls the API
  exactly once, to pair, and never to obtain a code.
- `last_used_at` is touched when a code from this kiosk is accepted — the
  only signal the backend gets that the kiosk is alive, since it does not
  call in after pairing.
- Admin management: `Api\V1\Admin\KioskController` (extends
  `AdminResourceController`, `admin.php` behind `role:admin`). Pairing and
  revocation below are admin-only too.

### Pairing — how the secret reaches the kiosk

Like adding an account to an authenticator app, except the secret travels
from the server straight to the kiosk app instead of being shown on a
screen. Implemented in `App\Domain\Booking\Services\KioskPairingService`.

1. **Admin issues a pairing code** —
   `POST admin/kiosks/{kiosk}/pairing-code`. 8 characters from an alphabet
   without confusable characters (no `O/0`, `I/1`), valid **10 minutes**,
   returned **once** with its `expires_at`. Only its SHA-256 hash is stored
   (`pairing_code_hash`), the same way passwords and refresh tokens are kept
   at rest. Issuing a new code replaces any unused previous one.
2. **The kiosk app pairs** — `POST /api/v1/kiosk/pair` with
   `{ pairing_code }`. Unauthenticated (the code is the proof),
   `throttle:5,1`. In one transaction, under a row lock on the kiosk, the
   server: finds the kiosk by the code's hash, requiring it unexpired and
   the kiosk `is_active`; generates a **fresh** secret
   (Base32-encoded via `paragonie/constant_time_encoding`) and stores it
   encrypted; clears the
   pairing code (single use); sets `paired_at`;
   `logSensitiveAction('kiosk_paired', ...)`. Response, sent with
   `Cache-Control: no-store`:

   ```json
   {
     "data": {
       "kiosk_id": 3, "name": "Main entrance",
       "secret": "BASE32…",
       "totp": {"algorithm": "SHA1", "digits": 8, "period": 30},
       "qr_prefix": "add-cowork:v1"
     }
   }
   ```

   Every parameter the kiosk needs comes from the server, so none is
   hard-coded in the kiosk app. A wrong, expired or already-used code, or
   an inactive kiosk, gets the same 422 `api.kiosk.invalid_pairing_code`,
   so the response does not reveal which.
3. **The secret is delivered exactly once.** No endpoint returns it again —
   not to the kiosk, not to an admin. A kiosk that loses it is simply
   paired again.

**Rotation is re-pairing.** Every pairing generates a new secret, so
replacing a device means pairing the new one, and the old one's codes stop
verifying the moment the new one pairs. Nothing is pushed to a device
automatically, which is what a lost device needs: it must not receive the
new secret.

**Revocation** — `DELETE admin/kiosks/{kiosk}/secret` clears `totp_secret`
(and any pending pairing code) at once; the kiosk accepts no codes until it
is paired again. Separate from `is_active` on purpose: deactivating and
later reactivating a kiosk would otherwise bring back the secret a stolen
device still holds.

**Routes:** the pairing endpoint lives in a new `routes/api/v1/kiosk.php`
for the kiosk app's unauthenticated endpoints — a distinct client from the
member app, so not in `mobile.php` — required from `routes/api.php` inside
the `/api/v1` prefix with no auth middleware.

### The code — TOTP generated on the kiosk

The kiosk app generates the code **locally**, with no call to the server,
so it keeps working when the kiosk's own connection drops. Standard TOTP
(RFC 6238) with the kiosk's secret:

- SHA1 · 30-second period · **8 digits**. Eight rather than six because the
  code is shared by every member and is not consumed on use, so the extra
  digits are what makes guessing impractical. Every JavaScript TOTP library
  supports it.
- `qr_value` = `add-cowork:v1:{kiosk_id}:{code}`.
- The kiosk re-renders the QR at each 30-second step, client-side; the
  backend never generates an image (same rule as `kiosk-display.md`).
- Stateless on the backend: nothing is persisted per code.

**Operational requirement:** the kiosk device's clock must be set
automatically (NTP / automatic time). Drift beyond ~30 s makes its codes
fail verification.

**Kiosk-app contract** (the kiosk app lives outside this repo):

- Setup screen takes the pairing code, calls `POST kiosk/pair` once, and
  stores `kiosk_id`, the secret and the returned parameters.
- Stores the secret in the device's secure storage (Android Keystore / iOS
  Keychain). If the kiosk is a web app, browser storage is weaker — anyone
  with hands on the unlocked device could read it. Recorded as an accepted
  risk, mitigated by revocation.
- Generates TOTP with the returned parameters and renders
  `{qr_prefix}:{kiosk_id}:{code}`; makes no server call for the code.
- When the admin revokes or re-pairs, the kiosk's codes stop verifying; the
  kiosk shows its setup screen again when an operator re-enters a pairing
  code.

### Validation — `App\Domain\Booking\Services\KioskCodeVerifier`

Uses `App\Support\Security\Totp` (RFC 6238, SHA1, verified against the
RFC's test vectors) with the caller's clock — `now()->getTimestamp()` — so
tests can move time.

Deliberately **not** Fortify's `TwoFactorAuthenticationProvider`: it caches
each accepted code and refuses to accept it again, which is right for a
personal 2FA code and wrong here, where many members scan the same code in
the same step.

Accepts a `qr_value` when the prefix/version match, the kiosk exists, is
`is_active` and has a secret, and the code verifies against that secret.
Returns the `Kiosk` and touches `last_used_at`; otherwise throws
`ReceptionActionException('api.coworking.invalid_code')` (422).

**Accepted residual risk:** someone on site can photograph the code and
send it to a friend who uses it within the ~90 s it stays valid. It opens a
session on the friend's own account, which appears on reception's list at
once. No other account can be affected, since the request is authenticated
as the scanner. A leaked **secret** (not just a code) is handled by
revoking it from the dashboard and pairing the kiosk again.

## 2. Starting a session

### Endpoint

`POST member/coworking-sessions` (member.php, `throttle:10,1`, same as
`access/unlock`). Body is either `{ qr_value }` (first call) or
`{ scan_ticket, accept_uncovered: true }` (confirmation call).

### Orchestration — `App\Domain\Booking\Services\CoworkingSessionService::start()`

1. Resolve the kiosk: from `qr_value` via `KioskCodeVerifier`, or from the
   `scan_ticket`.
2. **Open session already?** If the member has an open coworking session
   (a checked-in, not checked-out booking or walk-in on any `co_space`),
   return it with 200. Idempotent — the app just shows the timer.
3. **Booking mode.** If the member has a booking today on the kiosk's space
   with no `checked_in_at` — `ArrivalRequestMatcher`'s existing rule (today
   in the branch's timezone, `blocking()` status, not yet ended), given a new
   optional `?Space` argument that narrows it to that one space; the arrival
   flow keeps calling it without one:
   - `confirmed` → check it in through `BookingCheckInService` with
     `checked_in_via = scan`; 201. No coverage check: a booking's price and
     plan discount were fixed at creation, and bookings are deliberately
     excluded from plan-hour coverage (`plan-access-windows.md`).
   - `pending` → 409 `api.coworking.booking_pending_approval`. No fallback
     to a walk-in, for the same reason `kiosk-display.md` refuses one: it
     would route around the approval guard.
4. **Walk-in mode.** Evaluate coverage (below).
   - `covered` → start through `WalkInCapacityService::start()` with
     `started_via = scan`, `kiosk_id`; 201.
   - not covered and no `accept_uncovered` → **422**
     `api.coworking.confirmation_required`, carrying the coverage payload,
     the space's `hourly_rate_points`, and a `scan_ticket`. Same
     message-plus-data 422 shape as `api.booking.wallet_choice_required`.
   - not covered with a valid ticket and `accept_uncovered: true` → coverage
     is **re-evaluated** (it may have changed, e.g. a renewal in between),
     then start with `accepted_uncovered = true`; 201.

Business hours, capacity (with its row lock) and membership stamping all stay
inside `WalkInCapacityService::start()` — unchanged rules, one code path for
reception and scan.

### Scan ticket

`Crypt::encryptString(json_encode(['user_id', 'kiosk_id', 'expires_at']))`,
valid **2 minutes** — the QR code may expire while the member reads the
message. Rejected (422 `api.coworking.ticket_expired`) if expired, or if its
`user_id` is not the caller.

### Coverage — `App\Domain\Membership\Services\SessionCoverageEvaluator`

Returns a `SessionCoverage` value object: `status` (`covered` |
`not_covered`), `reason`, `plan` (id, name), `hours_total`,
`hours_remaining`, `window` (start/end, local time), `covered_until`.

Reasons, checked in this order (first match wins):

| Reason | Meaning |
|--------|---------|
| `no_plan` | No active membership, and none ever held (personal or company). |
| `plan_expired` | No active membership, but the member held one before. |
| `plan_without_hours` | Active plan with no access window or no `space-hours` grant — `PlanHourCoverageService::grantFor()` returns null. |
| `hours_exhausted` | The `space-hours` grant has 0 remaining. |
| `outside_window` | Hours remain, but the plan's access window is closed right now. |

`covered_until` = the earlier of (a) the end of today's access window
(wrap-past-midnight aware, same rules as `Plan::windowOverlap()`) and
(b) `now + hours_remaining`.

The evaluator reads through the existing `MembershipResolver` and
`PlanHourCoverageService`; it does not re-implement either. The existing
reception walk-in response's `plan_applies`/`outside_plan_window` notice is
derived from it too, so reception and scan can never disagree.

### Timer — `GET member/coworking-sessions/current`

The open session (either kind) or `null`: type, id, space, `checked_in_at`,
`started_via`, the coverage snapshot taken at start, and the state of any end
request.

The coverage snapshot is stored on the session at start (below), not
recomputed on read, so what the member and reception see never drifts from
what the member agreed to.

### Schema additions

- `walkin_sessions`: `started_via` (string, `reception` | `scan`, default
  `reception`) · `kiosk_id` (nullable FK, null-on-delete) ·
  `accepted_uncovered` (bool, default false) · `coverage_snapshot` (nullable
  JSON — the `SessionCoverage` at start).
- `bookings`: `checked_in_via` (nullable string, `reception` | `scan`) ·
  `kiosk_id` (nullable FK, null-on-delete).

### Refactor

The body of `BookingReceptionController::checkIn()` moves into
`App\Domain\Booking\Services\BookingCheckInService::checkIn(Booking, via, ?Kiosk)`,
which throws `ReceptionActionException` with the same keys and statuses. The
controller and `Admin\Reception\ArrivalRequestController::confirm()` call the
service. Behavior for reception is unchanged; existing tests must pass
untouched.

## 3. What reception sees

The dashboard polls the existing `GET admin/reception/sessions/active` (about
every 15 s). `ActiveReceptionSessionResource` gains:

- `started_via` — `scan` | `reception`, so scan-started sessions can be
  flagged.
- `member` — `id`, `name`, `phone`, `profile_image`, and company if any: the
  identity reception checks against the person in front of it.
- `plan` — from `coverage_snapshot`: plan name, `coverage_status` + `reason`,
  `hours_total`, `hours_remaining_at_start`, `covered_until`. Null for
  booking sessions' coverage (not applicable), with the plan name still
  shown.
- `accepted_uncovered` — the member was told and agreed to pay.
- `end_request` — `{ id, requested_at }` when one is pending, else null.

Plan hours are only written at closure (unchanged), so "remaining" during
the session is the start snapshot; `covered_until` is what tells the real
remaining time. Nothing is deducted early, so voiding a session needs no
rollback.

## 4. Ending a session

### `session_end_requests` table — `App\Domain\Booking\Models\SessionEndRequest`

`id` · `sessionable_type` / `sessionable_id` (morph: `booking` |
`walkin_session`) · `user_id` (FK) · `requested_at` · `status`
(`SessionEndRequestStatus`: `pending` | `confirmed` | `cancelled` |
`superseded`) · `resolved_by_user_id` (nullable FK, null-on-delete) ·
`resolved_at` (nullable) · `checked_out_at` (nullable — the close time
actually used) · timestamps. At most one `pending` row per session, enforced
in the service under a row lock on the session.

### Member

- `POST member/coworking-sessions/current/end-request` → creates the pending
  request, or returns the existing one. 201 / 200.
- `DELETE member/coworking-sessions/current/end-request` → `cancelled`, only
  while `pending`. Returns a message (update-style convention).

### Reception

`POST admin/reception/session-end-requests/{sessionEndRequest}/confirm`,
optional body `{ checked_out_at }`:

- Defaults to the request's `requested_at` — the member does not pay for
  reception's delay. Reception may override it (member still seated), but
  not before `checked_in_at` and not after now.
- Calls the existing `SessionClosureService::closeOut()` unchanged, so billing
  and plan-hour deduction are exactly today's. Its errors (e.g.
  `checkout_past_closing`) propagate as today.
- Marks the request `confirmed` with resolver, time and `checked_out_at`;
  `logSensitiveAction('session_end_confirmed', ...)`.
- Notifies the member through `NotificationService::sendToUser()` (database +
  FCM), template `coworking.session_ended`, `extraData` carrying the session
  type and id so the app opens the report.
- Responds with a message only (`api.coworking.session_ended`), per the
  update-endpoint convention; the report is its own GET.

### Other ways a session closes — still supported

- Reception's existing `check-out` endpoints: any pending end request on that
  session becomes `superseded`.
- Auto-close (`reception:close-overdue-sessions`): **one change** — if the
  session has a pending end request, it closes at `requested_at` instead of
  the branch closing time, and the request becomes `superseded`. The member
  pressed end; billing them until closing would be wrong.

Payment is unchanged: reception collects any remainder through the existing
`settle-payment`; a fully plan-covered walk-in is marked paid at closure, as
today.

## 5. Transactions and the session report

### Linking

- `resource_consumptions` gains nullable `sessionable_type` /
  `sessionable_id` (indexed).
- `ResourceConsumptionController::store()` (reception, unchanged request
  shape): when the owner is a **user** with an open coworking session, the
  row is linked to it. Company-owned consumption is not linked.
- `PlanHourCoverageService::consume()` links the hour-consumption row it
  writes at closure to its walk-in session (today that link is only the note
  text "Walk-in session #id").
- `booking` and `walkin_session` aliases added to `MorphMapServiceProvider`.
- Stage two's orders link through the same columns' convention.

### Report — `App\Domain\Booking\Services\SessionReportBuilder`

Served at:

- `GET member/coworking-sessions/{type}/{id}/report` — the member's own
  sessions only (404 otherwise).
- `GET admin/reception/sessions/{type}/{id}/report`.

`{type}` is `booking` | `walkin`, constrained in the route.

Shape:

```json
{
  "data": {
    "session": {
      "type": "walkin", "id": 12, "space": {"id": 1, "name": "Hot Desk"},
      "started_via": "scan", "checked_in_at": "…", "checked_out_at": "…",
      "duration_seconds": 10800, "termination_source": "reception"
    },
    "lines": [
      {"kind": "time", "label": "…", "quantity": 3, "unit": "hour",
       "source": "plan", "points": null, "amount": null},
      {"kind": "time", "label": "…", "quantity": 1, "unit": "hour",
       "source": "charge", "points": 30, "amount": "3.00"},
      {"kind": "resource_consumption", "label": "Printing", "quantity": 20,
       "unit": "page", "source": "plan", "points": null, "amount": null,
       "recorded_at": "…"}
    ],
    "plan": {"name": "…", "hours_used": 3, "hours_remaining_after": 47},
    "totals": {"amount_owed": "3.00", "currency": "USD",
               "payment_state": "unpaid", "payment_method": null, "paid_at": null}
  }
}
```

- `lines` is a generic list; stage two adds `kind: "order"` with
  `source: "charge"`.
- A fully plan-covered session with no transactions reduces to one `time`
  line with `source: "plan"` and a zero total — "3 hours from your plan,
  47 remaining".
- Available while the session is open too (lines so far, no totals), so
  reception can review before confirming.

## Known pre-existing issue (not fixed here)

On **booking** checkout, `SessionClosureService::finalizeClosure()`
recomputes `amount_owed` from the actual duration and overwrites the
creation-time price: the plan discount drops out of the record, and because
`payment_state` stays `paid` for a wallet-prepaid booking, overrun cannot be
collected and early departure is not refunded. The report shows a booking's
stored values as-is, so it is only as accurate as that record. Tracked as a
separate task; this design does not change booking billing.

## Error keys (new, `lang/{en,ar}/api.php`, `coworking` group)

`invalid_code` · `ticket_expired` · `confirmation_required` ·
`booking_pending_approval` · `unavailable` (kiosk's space is not an active
`co_space`) · `not_a_coworking_space` (kiosk Form Request: `space_id` is
not a `co_space`) · `no_open_session` (end-request calls with nothing
open) · `end_request_not_pending` · `session_ended`.
Coverage reason labels under `coworking.reasons.*`. Notification template
`coworking.session_ended` in `lang/{en,ar}/notifications.php`. In the
existing `kiosk` group: `invalid_pairing_code`, `secret_revoked`.

## Testing

Feature tests (in-memory SQLite; baseline-diff against the known
pre-existing audit-log failures):

- **Code:** real codes generated in the test with
  `App\Support\Security\Totp` under `Carbon::setTestNow()`. Accepted at the
  current step and at ±30 s; rejected at ±60 s; another kiosk's code,
  an inactive kiosk, a kiosk with no secret and a malformed `qr_value`
  rejected; after re-pairing, codes from the old secret rejected; after
  `DELETE admin/kiosks/{kiosk}/secret`, every code rejected; the same code
  accepted for two different members in the same step; `last_used_at`
  touched on success.
- **Pairing:** a valid code returns the secret and TOTP parameters with
  `Cache-Control: no-store`; a code works once; expired, wrong and
  inactive-kiosk codes all get the same 422; issuing a new pairing code
  invalidates the unused previous one; revocation also clears a pending
  code; pairing and revocation are admin-only; `totp_secret` and
  `pairing_code_hash` never appear in any `KioskResource` response, and no
  endpoint returns the secret after pairing; the pairing route is
  throttled.
- **Start:** covered → 201 walk-in with `started_via = scan` and snapshot;
  each reason → 422 with ticket; ticket + accept → 201 with
  `accepted_uncovered`; expired or other user's ticket → 422; coverage
  changed between calls → re-evaluated; confirmed booking → checked in with
  `checked_in_via = scan`; pending booking → 409; open session → 200 with
  that session; business hours and capacity still enforced.
- **Reception parity:** existing `BookingReceptionController` and
  `ArrivalRequestController` tests pass unchanged after the check-in
  refactor.
- **End:** request create / idempotent / cancel; confirm defaults to
  `requested_at`; override bounds; direct checkout supersedes; auto-close
  uses `requested_at` and supersedes; member notified.
- **Transactions and report:** reception consumption auto-links to the open
  session; closure hour consumption links; report shape for plan-only,
  partly covered, fully paid, with consumption lines, and for an open
  session; member cannot read another member's report.
- **Reception list:** enriched fields present for both session kinds.

## Documentation

- New decision doc `docs/decisions/coworking-self-service-sessions.md`
  (and its line in `docs/decisions/README.md`), including the pairing
  flow, the kiosk-app contract and the kiosk clock requirement from §1.
- `docs/decisions/kiosk-display.md`: the "scanning never starts a paid
  session" statement gains a documented exception for the rotating
  `co_space` code, linking the new doc; the "Single branch" follow-up
  notes that kiosks are now bound to a space.
- `docs/decisions/plan-access-windows.md`: coverage now also evaluated at
  scan time, through `SessionCoverageEvaluator`.
- `CLAUDE.md`: `routes/api/v1/kiosk.php` added to the route-composition
  list (unauthenticated endpoints the kiosk app calls — today only `pair`).
- Postman collection: `Kiosks` (admin CRUD, pairing code, revoke, and the
  kiosk's `pair` call) and `Coworking sessions` folders.
