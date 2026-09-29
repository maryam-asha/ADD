# Self-service coworking sessions: scan to start, confirm before paid, request to end

**Status:** resolved 2026-09-28. **Owner:** Maryam Asha.
**Type:** design doc for a new capability. Design:
`docs/superpowers/specs/2026-09-27-coworking-self-service-sessions-design.md`
(including its "Amendments at planning time"); this file describes the code as
shipped, including the changes made while implementing it.

## What this adds

A member can start and end a **coworking** session from the app, without
reception entering anything. Four pieces, all on top of the existing
`Booking` / `WalkinSession` models and the existing closure and billing code:

1. **Scan to start.** The kiosk shows a rotating QR; scanning it opens the
   session immediately and the timer runs from the scan
   (`POST member/coworking-sessions`, `CoworkingSessionController::start()` →
   `CoworkingSessionService::start()`).
2. **Confirm before paid.** If the plan will not cover the session, the
   member is told why and what the space charges per hour, and must accept
   before anything starts. A covered session opens with no extra step.
3. **Request to end, reception confirms.** The member's "end" press creates a
   `session_end_requests` row; reception confirms it and, by default, the
   session is billed up to the moment the member pressed
   (`SessionEndService`, `SessionEndRequestController::confirm()`).
4. **The session report.** Time plus every transaction linked to the session,
   for the member and for reception (`SessionReportBuilder`).

**`co_space` only.** A `kiosks` row is bound to one space, and the kiosk
Form Requests (`RequiresCoworkingSpace`) refuse any other `space_type`.
Rooms, business spaces and event halls keep their reception-driven flows and
the static arrival QR, unchanged.

Reception sees the scan on its usual poll of `GET admin/reception/sessions/active`
(about every 15 s; nothing is pushed): `ActiveReceptionSessionResource` now
carries `started_via`, a `member` block (id, name, phone, avatar, company),
`plan` (the coverage snapshot taken at start), `accepted_uncovered` and any
pending `end_request`.

Schema: `kiosks`, `session_end_requests`; `walkin_sessions.started_via` /
`kiosk_id` / `accepted_uncovered` / `coverage_snapshot`;
`bookings.checked_in_via` / `kiosk_id`; `resource_consumptions.sessionable_*`
(migrations `2026_09_28_100000` to `100400`). `booking` and `walkin_session`
are new `MorphMapServiceProvider` aliases, shipped with the `activity_log`
rewrite migration `2026_09_28_100300` (precedent `2026_09_27_100005`); `User`
is still never aliased.

## Relationship to `kiosk-display.md`

`kiosk-display.md` says scanning the kiosk QR "never authorizes entry, opens a
lock, or starts a paid session on its own". That holds for the **static arrival
QR** (`POST member/arrival-requests`, `arrival_qr.value`): it is unchanged, and
still only queues a request for reception.

The **rotating** code is a documented exception, scoped to `co_space`: a
valid code starts the session directly, because it proves presence in a way a
static value cannot (it exists only on the kiosk's screen and changes every 30
seconds, so a screenshot goes stale in about a minute and a half). Everything a
desk check-in enforces is still enforced, by the same services (business hours,
capacity, booking status; see "Starting"), so the scan adds a way in, not a way
around.

The kiosk now has an identity (`kiosks.space_id`), so the scan path resolves its
branch from the kiosk's space. The arrival flow still uses the single-branch
assumption recorded in `kiosk-display.md`.

## Kiosk pairing and codes

### Pairing: how the secret reaches the kiosk

`App\Domain\Booking\Services\KioskPairingService`; models and DTOs in
`App\Domain\Booking`.

- **Issue.** `POST admin/kiosks/{kiosk}/pairing-code`
  (`KioskPairingController::issue()`, admin-only). A code is 8 characters from
  `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (no `0/O`, `1/I`), valid **10 minutes**,
  returned once with its `expires_at`. Only its **SHA-256** is stored
  (`pairing_code_hash`, unique), computed after uppercasing and stripping
  spaces and hyphens so a code read off one screen and typed into another
  still matches. Issuing a new code replaces any unused one.
- **Pair.** `POST /api/v1/kiosk/pair` (`routes/api/v1/kiosk.php`,
  `KioskPairController`), unauthenticated because the code is the proof,
  `throttle:5,1`. In one transaction, under a row lock on the kiosk, it finds
  the kiosk by hash, requires the code unexpired and the kiosk `is_active`,
  generates a fresh secret (`Totp::generateSecret()`, 160 random bits as 32
  Base32 characters), stores it encrypted, clears the code (**single use**) and
  sets `paired_at`. A wrong, expired, already-used or inactive-kiosk code all
  get the same 422 `api.kiosk.invalid_pairing_code`, so the response never
  says which part was right. The response carries `Cache-Control: no-store`:

  ```json
  {"data": {"kiosk_id": 3, "name": "Main entrance", "secret": "BASE32...",
            "totp": {"algorithm": "SHA1", "digits": 8, "period": 30},
            "qr_prefix": "add-cowork:v1"}}
  ```

- **The secret is delivered exactly once.** No endpoint returns it again, not
  to the kiosk and not to an admin (`totp_secret` uses the `encrypted` cast and
  is in `$hidden` with `pairing_code_hash`, so `KioskResource` and anything
  else that serializes a `Kiosk` omits both).
- **Every pairing rotates the secret.** Replacing a device means pairing the
  new one; the old one's codes stop verifying the moment it pairs. Nothing is
  ever pushed to a device that was not just paired, which is what a lost device
  needs.
- **Revocation is separate from `is_active`.** `DELETE admin/kiosks/{kiosk}/secret`
  clears the secret, any pending code and `paired_at` at once. Deactivating and
  later reactivating a kiosk would otherwise bring back the secret a stolen
  device still holds.

### The kiosk-app contract

The kiosk app lives outside this repository. It must:

- take the pairing code on a setup screen, call `POST kiosk/pair` once, and keep
  `kiosk_id`, the secret and the returned parameters, hard-coding none of them;
- keep the secret in the device's secure storage (Android Keystore / iOS
  Keychain);
- generate a standard TOTP locally, **SHA1, 30-second period, 8 digits**, and
  render `{qr_prefix}:{kiosk_id}:{code}` (that is `add-cowork:v1:3:12345678`),
  re-rendered at each 30-second step, client-side (the backend never generates
  a QR image); it makes **no server call** for a code, so it keeps working when
  its own connection drops;
- keep its **clock on automatic time (NTP)**. A code is accepted at its own
  step and one step either side, so drift beyond about 30 s fails verification;
- show its setup screen again when an operator re-enters a pairing code after a
  revocation or re-pairing.

### Why `App\Support\Security\Totp` and not Google2FA

`pragmarx/google2fa` reads the system clock, which `Carbon::setTestNow()`
cannot move, so "a code from 30 s ago" could not be tested. `Totp` takes the
time from the caller (`now()->getTimestamp()`), is pinned to the RFC 6238 SHA1
test vectors (`tests/Unit/Support/TotpTest.php`), and takes Base32 from
`paragonie/constant_time_encoding` (already installed transitively, now
required explicitly in `composer.json`). It is also deliberately **not**
Fortify's `TwoFactorAuthenticationProvider`: that one caches each accepted code
and refuses it a second time, which is right for a personal 2FA code and wrong
here, where many members scan the same code in the same step.

`KioskCodeVerifier::verify()` is stateless. It accepts a `qr_value` when the
prefix and version match, the kiosk exists, is active, has a secret, and the
code verifies within ±1 step; it then touches `last_used_at` (the only sign of
life the backend gets from a kiosk after pairing). Anything else is 422
`api.coworking.invalid_code`.

### Accepted residual risks

- **A photographed code.** Someone on site can photograph the code and send it
  to a friend who uses it within the ~90 s it stays valid (its step plus one
  either side). It opens a session on the friend's *own* account, which shows on
  reception's list at once; no other account can be affected, because the
  request is authenticated as the scanner.
- **Weaker storage on a web kiosk.** If the kiosk is a web app, browser storage
  is weaker than a Keystore: anyone with hands on the unlocked device could read
  the secret. Mitigated by revocation. A leaked *secret* (not just a code) is
  handled by revoking it from the dashboard and pairing the kiosk again.

## Starting

`CoworkingSessionService::start()`. Body is `{qr_value}` (first call) or
`{scan_ticket, accept_uncovered: true}` (the confirmation call); the Form
Request requires exactly one of them. `throttle:10,1`.

### The order of checks

1. **Code or ticket.** A `qr_value` goes through `KioskCodeVerifier`; a
   `scan_ticket` through `ScanTicketService::redeem()`. Either yields the kiosk.
2. **Space available.** The kiosk's space must be an *active* `co_space`, else
   422 `api.coworking.unavailable`.
3. **Business hours.** Else 422 `api.reception.outside_business_hours`. Checked
   here as well as inside the walk-in and check-in services (which stay
   authoritative, under their row lock), so a member is never asked to accept a
   paid session that would then be refused for the hour.
4. **Open session.** From here on, inside a transaction that first locks the
   member's own `users` row (a double tap cannot open two sessions). A member
   with an open coworking session (a checked-in, not checked-out walk-in, or
   confirmed booking, on any `co_space`) gets it back with **200**. Idempotent:
   the app just shows the timer.
5. **Today's booking.** See "Booking mode" below.
6. **Coverage**, then 7. **walk-in.**

Capacity, business hours and the membership stamp still live only inside
`WalkInCapacityService::start()` and `BookingCheckInService`; the reception desk
and the scan share one code path, so they cannot disagree
(`BookingReceptionController::checkIn()` and `ArrivalRequestController::confirm()`
were refactored onto `BookingCheckInService` with their tests untouched).

### Booking mode

`ArrivalRequestMatcher::matchBookingFor()` (today in the branch's timezone, a
blocking status, not yet ended, not yet checked in), given a new optional
`?Space` that narrows it to the kiosk's own space; the arrival flow still calls
it without one.

- `confirmed`: checked in through `BookingCheckInService` with
  `checked_in_via = scan` and `kiosk_id`; **201**. No coverage check. A
  booking's price and plan discount were fixed at creation, and bookings are
  deliberately excluded from plan-hour coverage (`plan-access-windows.md`).
- `pending`: **409** `api.coworking.booking_pending_approval`. **No walk-in
  fallback**, for the reason `kiosk-display.md` refuses one: starting a walk-in
  on the same space would route around the approval guard.

### Coverage: the reasons

`SessionCoverageEvaluator::evaluate()` returns a `SessionCoverage`
(`status` covered / not_covered, `reason`, `plan`, `hours_total`,
`hours_remaining`, `window`, `covered_until`). It reads through
`MembershipResolver` and `PlanHourCoverageService::hourGrantFor()` (renamed from
the private `grantFor` and made public for this) and spends nothing. Reasons,
first match wins:

| Reason | When |
|---|---|
| `no_plan` | No active membership (personal or through a company) and none ever held. |
| `plan_expired` | No active membership now, but one was held before (`MembershipResolver::hasHeldAnyFor()`). |
| `plan_without_hours` | An active plan, but `hourGrantFor()` returns null: no access window, or no `space-hours` grant. |
| `hours_exhausted` | The `space-hours` grant has 0 remaining. |
| `outside_window` | Hours remain, but the plan's access window is closed right now (`Plan::currentWindowEndsAt()` is null). |

When covered, `covered_until` is the earlier of the end of the current access
window (wrap-past-midnight aware, same rules as `Plan::windowOverlap()`) and
`now + hours_remaining`. What is actually absorbed is still decided at checkout
by `PlanHourCoverageService`, exactly as before; the evaluator only predicts it.

### Walk-in mode and the confirmation

- **Covered:** started through `WalkInCapacityService::start()` with
  `started_via = scan`, `kiosk_id`; **201**.
- **Not covered, no ticket:** **422** `api.coworking.confirmation_required`
  with `reason`, `coverage`, `hourly_rate_points`, `scan_ticket` and
  `ticket_expires_at` (the same message-plus-data 422 shape as
  `api.booking.wallet_choice_required`). A `co_space` with no hourly rate still
  asks, with `hourly_rate_points` `0`; it does not crash.
- **Ticket plus `accept_uncovered: true`:** coverage is **re-evaluated** (it may
  have changed, for example a renewal in between), then the session starts with
  `accepted_uncovered = true` if it is still uncovered; **201**.

The 201 responses are `CoworkingSessionResource` (type, id, space,
`checked_in_at`, `started_via`, `hourly_rate_points`, `coverage`,
`accepted_uncovered`, `end_request`). `GET member/coworking-sessions/current`
returns the open session or `null` and is the timer; the app counts down from
`coverage.covered_until`. Nothing is pushed when coverage runs out mid-session.

### The 120 s scan ticket

`ScanTicketService`: `Crypt::encryptString(json {u: user id, k: kiosk id,
e: expiry})`, valid **120 seconds** (the rotating code may expire while the
member reads the message), nothing stored, authenticated with `APP_KEY`.
Refused as 422 `api.coworking.ticket_expired` when expired, tampered with, or
issued to a different member. The kiosk is **re-read** on redemption (active,
still paired), so a kiosk revoked or deactivated in the last two minutes stops
starting sessions (422 `api.coworking.invalid_code`). A ticket is not consumed:
presenting it a second time finds the session the first acceptance opened and
returns it (200) instead of starting another.

### `coverage_snapshot` is stored for every walk-in

`WalkInCapacityService::start()` stores `walkin_sessions.coverage_snapshot`
(`SessionCoverage::toArray()`) for **every** walk-in, reception's or a scan's; a
scan passes the coverage it already showed the member, so the snapshot is
exactly what they agreed to. It is stored, not recomputed on read, so what the
member and reception see never drifts. Reception's own walk-in response
(`plan_applies` / `outside_plan_window`) is left exactly as it was; the snapshot
is what lets the reception list show the same plan picture for both paths.

## Ending

### The end request

`session_end_requests` (`SessionEndRequest`, morph `sessionable` to a
`Booking` or `WalkinSession`; status `SessionEndRequestStatus`):

- `POST member/coworking-sessions/current/end-request`: **201** with a new
  pending request, **200** with the existing one (a double tap is harmless),
  422 `api.coworking.no_open_session` when nothing is open.
- `DELETE member/coworking-sessions/current/end-request`: withdraws it, message
  `api.coworking.end_request_cancelled`. Two refusals: **422**
  `api.coworking.no_open_session` when the member has no open session (the normal
  state once reception has confirmed or a desk checkout has closed it), and
  **409** `api.coworking.end_request_not_pending` when the session is open but
  has no pending request, or the request resolved in a race with the withdrawal.
- `POST admin/reception/session-end-requests/{sessionEndRequest}/confirm`
  (`role:admin|operations`): optional `checked_out_at`, interpreted through
  `InterpretsLocalTimes` and at most now.

### What confirming does

- **Defaults to `requested_at`.** The member does not pay for reception's delay.
- **Reception may override** it (the member is still seated). The bounds are
  `closeOut()`'s own, unchanged: not before `checked_in_at`
  (`api.reception.checkout_before_checkin`), not past the branch's closing time
  (`api.reception.checkout_past_closing`), plus the Form Request's "not after
  now". Billing and plan-hour deduction are exactly today's, because
  confirmation calls the same `SessionClosureService::closeOut()` reception's
  own checkout uses.
- Marks the request `confirmed` with resolver, time and the close time used
  (`checked_out_at`), in the same transaction as the closure, so a closure that
  refuses undoes the mark too; logs `session_end_confirmed`; notifies the member
  through `NotificationService::sendToUser()` (template
  `coworking.session_ended`, `extraData` carrying the session type and id so the
  app opens the report). Responds with a message only (`api.coworking.session_ended`).

### Concurrency

`SessionEndService::confirm()` locks the **session row first, then the
end-request row**; `request()` locks the session row before it reads or inserts
the end request. That is the order `SessionClosureService::autoClose()` uses
(session row, then the pending request it supersedes), so the three cannot
deadlock and a closure that lands between a lookup and a write is always seen.
`request()` re-checks under that lock that the session is still open, so a
closure cannot leave an orphan pending request.
`cancel()` is a **conditional update** on `status = pending` (409
`end_request_not_pending` if it already resolved), so a withdrawal racing a
confirmation cannot turn `confirmed` into `cancelled`.

### The statuses, and the other ways a session closes

`pending`, `confirmed`, `cancelled`, `superseded`. There is at most one pending
row per session (enforced in the service, under the lock).

- **Direct checkout supersedes.** Reception's existing `check-out` endpoints
  still work; `SessionClosureService::finalizeClosure()` turns any request still
  pending on that session into `superseded`, with the close time recorded.
- **Auto-close supersedes, and bills to `requested_at`.**
  `reception:close-overdue-sessions` (`CloseOverdueReceptionSessions`) closes a
  session with a pending end request at the **earlier** of the request's
  `requested_at` and the branch's closing instant, never past closing:
  `autoClose()` runs no closing check, and a press at 20:03 that the job only
  sees at 20:05 must not be billed the extra minutes. The request becomes
  `superseded`. The member pressed end; billing them until closing would be
  wrong.

Payment is unchanged: reception collects any remainder through the existing
`settle-payment`, and a fully plan-covered walk-in is marked paid at closure.

## Report

`GET member/coworking-sessions/{type}/{id}/report` (the caller's own sessions;
anyone else's is a **404**, not a 403) and
`GET admin/reception/sessions/{type}/{id}/report` (any session). `{type}` is
`booking` or `walkin`, `{id}` numeric, both constrained in the route.
`CoworkingSessionService::find()` returns **only sessions that started**: a
booking never checked in has no report (404), the same as an unknown id.

```json
{"data": {
  "session": {"type": "walkin", "id": 12, "space": {"id": 1, "name": "Hot Desk"},
              "started_via": "scan", "checked_in_at": "...", "checked_out_at": "...",
              "duration_seconds": 10800, "termination_source": "reception"},
  "lines": [ ... ],
  "plan": {"name": "...", "hours_used": 3, "hours_remaining": 47},
  "totals": {"amount_owed": "3.00", "currency": "USD", "payment_state": "unpaid",
             "payment_method": null, "paid_at": null}
}}
```

- **The line shape.** Every line is
  `{kind, label, quantity, unit, source, points, amount, recorded_at}`; `lines`
  is a generic list on purpose, so café orders arrive later as another `kind`
  with no redesign. A walk-in's time is up to two `time` lines: `source: plan`
  for the seconds the plan absorbed (`plan_covered_seconds`) and `source: charge`
  for the rest, carrying `points` and the session's `amount_owed`. A session the
  plan covered in full is one plan line and a zero total. Linked resource
  consumption (reception's printing or internet entries, recorded while the
  member's session was open) is `kind: resource_consumption`, `source: plan`,
  with `recorded_at`.
- **The plan-hours row is excluded from the consumption lines.** Closure writes
  a `space-hours` consumption row linked to the session
  (`PlanHourCoverageService::consume()`); the time lines already represent it,
  so listing it too would show the hours twice.
- **Open sessions have no totals.** `totals` is `null` and the time line is
  "time so far" with no `source`, so reception can review before confirming.
- **Bookings are shown as stored.** A booking's report has one time line with
  `source: charge` and its stored `amount_owed`, and no `plan` block; it is only
  as accurate as that record (see "Booking checkout billing" below).
- `plan` appears only for a walk-in that had a membership with an hour grant;
  `hours_remaining` is what the plan has left when the report is read.

**For client developers:** whole-number values (hours, quantities, plan hour
totals) arrive as plain JSON numbers, for example `40`, not `40.0`. A client that
types these fields as decimals must accept integers too.

## Explicitly not done here

- **Café orders** (stage two, its own spec). The report is built so orders plug
  in as another line kind.
- **Paid printing and internet.** Consumption without a plan, or beyond the
  plan's allowance, needs a per-unit price list; it moves to stage two's priced
  catalog alongside drinks. Consumption stays plan-allowance only, exactly as
  `ResourceConsumptionController` works today. Company-owned consumption is not
  linked to a session.
- **Live dashboard push.** Broadcasting is off (`BROADCAST_CONNECTION=log`) and
  admins have no notification inbox; reception polls, as it already does for
  arrival requests.
- **A push when coverage runs out mid-session.** The app counts down from
  `covered_until` instead.
- **Booking checkout billing** (a separate, already queued task).
  `SessionClosureService::finalizeClosure()` recomputes a booking's
  `amount_owed` from the actual duration and overwrites the creation-time price,
  so the plan discount drops out of the record, and because `payment_state` stays
  `paid` for a wallet-prepaid booking, overrun cannot be collected and early
  departure is not refunded. The report shows a booking's stored values as they
  are.
- **Permission coverage.** Neither `KioskPairingController` nor
  `SessionEndRequestController` extends `AdminResourceController`, so
  `permissions:sync` derives nothing for them and no `permission:` middleware can
  be wired to them (the kiosk routes sit behind `role:admin`, the end-request
  confirmation behind `role:admin|operations`). `KioskPairingController` has no
  permission coverage and is listed by `permissions:sync`'s uncovered-controllers
  warning. `SessionEndRequestController` is in the `Reception/` group that check
  skips by design (see "Explicitly not done here" in `rbac-permission-pilot.md`),
  so it never appears in that warning. `rbac-permission-pilot.md`'s named list of
  uncovered controllers was not updated for either. `KioskController` does extend
  `AdminResourceController`, so `kiosks.*` permissions are derived, but nothing
  enforces them yet, like every admin resource except Branches.
- **The Postman collection.** The `Kiosks` and `Coworking sessions` folders are
  deferred: the main working tree has uncommitted edits to
  `postman/ADD-OS.postman_collection.json`, so it is updated after those land.

Known limitations, recorded so they are not rediscovered (the first two are
pre-existing, and only more reachable now):

- **Desk checkout can race a member-confirmed or auto close.** The desk
  endpoints (`WalkInSessionController::checkOut()`,
  `BookingReceptionController::checkOut()`) call `closeOut()` without locking
  the session, unlike `autoClose()` and `SessionEndService::confirm()`, so a desk
  checkout can race a member-confirmed or an auto close of the same session and
  both can pass the "still open" check. Pre-existing.
- **`CloseOverdueReceptionSessions` does not catch a `ReceptionActionException`
  per session.** One session closed concurrently between the command's read and
  `autoClose()`'s locked re-read aborts the rest of that run (the next run picks
  up the remainder). Pre-existing, and more reachable now that members and
  reception close sessions outside the schedule.
- **Report `points` are recomputed from the space's current rate.** Closure
  stores only `amount_owed`, so a rate change after a session closed makes a
  `charge` line's `points` differ from what was billed at the time; the
  `amount` shown is the stored one.

## Guard

No dedicated `tests/Guards/` entry: like `kiosk-display.md` and
`reception-operations-scope.md`, this is new additive capability, not a
schema-shape invariant. The one guard that grew is
`EnumColumnsHaveBackedEnumCastsTest::EXPECTED_CASTS` (`started_via`,
`checked_in_via`, `session_end_requests.status`), and the existing
`UserSuppliedTimesAreInterpretedLocallyTest` now also holds
`ConfirmSessionEndRequest` to interpreting `checked_out_at` locally.
Coverage, all in-memory SQLite, with the clock set in UTC:

- `tests/Unit/Support/TotpTest.php`: the RFC 6238 SHA1 vectors, the ±1 step
  window, malformed codes, secret shape.
- `tests/Feature/Booking/KioskAdminTest.php`: admin-only management (operations
  refused), the `co_space` rule, the secret and hash never in any listing.
- `tests/Feature/Booking/KioskPairingTest.php`: a ten-minute code stored only as
  a hash, the secret delivered once with its parameters, single use, one answer
  for expired / wrong / inactive-kiosk, lowercase-with-separators accepted, a
  new code replacing the unused one, every pairing rotating the secret,
  revocation, admin-only, throttle.
- `tests/Feature/Booking/KioskCodeVerifierTest.php`: accepted at ±1 step and
  refused two steps away, another kiosk's code, inactive or revoked kiosks,
  malformed values, the same code serving several members, `last_used_at`.
- `tests/Feature/Membership/SessionCoverageEvaluatorTest.php`: each reason,
  `covered_until` (window end, hours running out first, wrapping windows), a
  company plan, the snapshot shape.
- `tests/Feature/Booking/WalkInStartSourceTest.php`: `started_via` and the
  snapshot on reception's own walk-ins, the scan's kiosk and acceptance, and a
  pre-existing booking reading as reception.
- `tests/Feature/Booking/BookingCheckInServiceTest.php`: a scan check-in records
  its kiosk; a pending booking is refused with the existing key.
- `tests/Feature/Booking/CoworkingSessionStartTest.php`: covered, each refusal,
  ticket rules (expiry, another member's, tampered, kiosk revoked since),
  re-evaluation on accept, booking mode, pending booking, open session,
  business hours, capacity, rate-less space.
- `tests/Feature/Booking/CoworkingSessionEndTest.php`: request, idempotence,
  withdrawal, confirm defaults and override, refusal rolls back, direct checkout
  and auto-close superseding, auto-close never past closing.
- `tests/Feature/Booking/ResourceConsumptionSessionLinkTest.php`: printing
  during an open session and the closure's plan hours link to the session;
  printing with no open session and company-owned consumption do not.
- `tests/Feature/Booking/SessionReportTest.php`: plan-only, partly covered,
  consumption lines, open session, booking, another member's report, a booking
  that never started, reception.
- `tests/Feature/Booking/ReceptionActiveSessionsDetailTest.php`: the enriched
  reception list for both session kinds.
- `tests/Support/InteractsWithCoworking.php`: the shared fixture; not a test.

The desk, arrival and existing walk-in tests (`BookingReceptionControllerTest`,
`ArrivalRequestTest`, `WalkInSessionControllerTest`, ...) pass untouched, which
is the reception-parity check for the check-in refactor.
