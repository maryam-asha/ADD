# Access control build-out (S4): passcode lifecycle + QR-scan as a new unlock channel

Status: resolved 2026-08-23. Owner: Maryam Asha. Type: design doc that (a) turns S4's already-locked PRD decisions (§5.4/§5.5, Document 4 §5) into an implementation plan, since none of it is built yet, and (b) adds one genuinely new decision on top: unlocking via an app scan of a QR fixed to the lock, as a second channel alongside keypad PIN entry.

## Why this doc exists

`ADDOSSprintsTasks.xlsx` marks every S4 backend task as `Not Started` or `Open Decision`. On inspection, only `App\Domain\Foundation\Device` + `DeviceCapability` exist in code — structure only, no `access_grants`, no `access_events`, no TTLock client, no passcode issuance, no reception activation, no unlock endpoint of any kind. Everything in this doc is new code. Confirmed in scope for R1 (2026-08-23 decision session): door opening is core to the launch experience, not deferrable to R2.

Nothing here reopens an already-locked PRD decision (Gateway-primary, Period passcodes, activation-at-reception, `lock_mac` as natural key, SDK restricted to staff/kiosk apps). This doc turns those into concrete tables/services, and adds §4 as the one new decision.

## §1 — Schema additions

`devices` table (existing, additive migration):

* `hardware_mac` (string, unique, required for `type IN (lock, gateway)`) — decision #14's natural key. `external_ref` (existing column) stays for any other provider-specific id; `hardware_mac` is what code keys off.
* `parent_device_id` (FK to `devices.id`, nullable) — links a `lock` to the `gateway` it pairs through.
* `qr_value` (string, unique, nullable) — new for this doc, only ever set on `type=lock` rows. A random, non-sequential opaque token (see §4) printed on the sticker physically affixed at that lock. Independent of `hardware_mac`/`lockId`: replacing a lock's sticker regenerates this column only, no Bluetooth proximity, no TTLock call, no effect on `access_grants` history.
* `type` enum gains `gateway` (already anticipated in `docs/decisions/access-control-tables.md`).

`access_grants` (new table — Document 4's shape, adopted as-is per `structure-reference.md`'s "Document 4 governs decision traceability" where ERD v2.0 is silent):

```
id, lock_id (FK devices), grantee_type [user|company], grantee_id,
source_type [booking|tenancy], source_id (FK bookings, nullable — null
  for a company tenancy code), allocation_model [booking_hourly|
  booking_daily|tenancy], passcode_type [period] (single value, kept as a
  column rather than hardcoded since Document 4 models it as one),
  passcode_value (string, encrypted at rest), issued_at, must_activate_by,
  activated_at (nullable), expires_at (nullable),
  status [issued|activated|expired|revoked], timestamps
```

`access_events` (new table):

```
id, device_id (FK devices), access_grant_id (FK access_grants, nullable),
  event_type [unlock|lock_auto|failed_attempt],
  channel [keypad_manual|qr_scan|reception_activation] (new column, not
    in the original ERD — needed once there are two unlock channels, so
    reporting can tell them apart),
  actor_user_id (FK users, nullable), occurred_at, timestamps
```

## §2 — Passcode lifecycle (already locked in the PRD; implement as-is)

1. Issuance — scheduled job fires when a confirmed booking's cancellation window closes (or immediately, if the booking is same-day — `docs/decisions/business-hours.md` territory, reuse its business-hours service rather than reimplementing). Calls TTLock's Cloud API to program a Period passcode on the booking's `space`'s lock. Creates one `access_grants` row: `status=issued`, `must_activate_by = issued_at + 24h`. Company tenancy codes are issued once at contract activation, not per-booking, with `allocation_model=tenancy` and no `source_id`.
2. Activation — reception scans the member's own identity QR (their existing in-app credential, unrelated to the lock's `qr_value` — do not confuse the two) via the kiosk app's TTLock SDK on first arrival. This is a Bluetooth-local SDK action performed by the kiosk, exactly per the PRD's SDK restriction (staff/kiosk only, never the member app). The kiosk then calls a backend endpoint to record `activated_at = now(), status = activated` on the matching `access_grants` row. Past `must_activate_by` with no activation → `status = expired`; a new grant must be issued manually by operations.
3. Revocation — a maintenance conflict on the space revokes any `issued`/`activated` grant for its lock immediately (`status = revoked`), per the already-locked "maintenance conflict revokes the code immediately and automatically" decision.

`App\Domain\Access\Services\TTLockClient` wraps every Cloud API call (passcode add/revoke, remote unlock — see §4). No controller or other service calls the TTLock HTTP API directly. Verify the exact endpoint names/parameters against TTLock's current Open Platform docs at implementation time — do not assume the names in this doc are letter-perfect API paths; they're not, and confirming against real documentation is standard procedure for API-integration work (see `CLAUDE.md`'s existing instruction to reason from a dependency's public docs rather than guessing).

## §3 — Where existing decisions place this in the wider system

* Locks apply only to bookable spaces — private offices, meeting rooms, event halls. Co-Space has no lock, no code, unaffected by any of this (existing decision, unchanged).
* The main door has no lock and no code (existing decision, unchanged) — nothing in this doc puts a `qr_value` on anything but a bookable space's `type=lock` device.
* `lock_mac`/`hardware_mac` survives a lock replacement; `qr_value` does not need to — replacing a lock re-links `hardware_mac`/`parent_device_id` to the new physical unit (existing decision), and separately, if the old sticker is gone, operations regenerates `qr_value` on the same `devices` row. The two are independent operations for independent reasons.

## §4 — New decision: QR-scan as a second unlock channel

The mechanism. A QR sticker is fixed at the lock, encoding `devices.qr_value` — it identifies which lock, nothing else. A member (already authenticated in their own app) scans it. The app sends `{qr_value}` in an ordinary authenticated request to the backend — the member's identity comes from their existing session token, not from anything encoded in the QR. The backend resolves `qr_value → device`, checks for an `access_grants` row for `(grantee = this user or their company, lock_id = this device, status = activated, now() within its active window)`, and if found, calls `TTLockClient::remoteUnlock()` — a server-side Cloud API call through the lock's paired Gateway — then writes one `access_events` row (`event_type = unlock`, `channel = qr_scan`, on failure `event_type = failed_attempt`).

Why this doesn't touch the SDK restriction. The member's app never talks to TTLock directly and never receives `lockData` — it only ever calls the ADD backend, exactly like every other member-facing action in this system. The Cloud API call happens entirely server-side. This is the same shape as the passcode issuance flow in §2 — a backend service holding the TTLock credentials, doing the vendor call, and returning a plain success/failure to the client — not a new architectural pattern.

Why this doesn't replace the keypad PIN, and why both exist. Cloud API remote-unlock requires the lock's Gateway to be online — the exact "degraded mode" scenario the PRD's fallback row already anticipates. The programmed Period passcode keeps working by direct keypad entry with zero network dependency, Gateway or no Gateway. QR-scan is the convenient path when online; the keypad is what "documented degraded mode" already meant. Nothing about adding QR-scan changes the passcode fallback decision — it adds a channel, it doesn't retire one.

`qr_value` is static (per this session's decision), matching the `arrival_qr` reasoning in `kiosk-display.md`: a photographed or shared sticker value on its own unlocks nothing — the request still needs a valid, currently-`activated` `access_grants` row tied to the scanning user's own account. There is no privilege in knowing the value alone, so there's nothing to protect by rotating it.

Generation: `qr_value` is a CSPRNG-drawn opaque string (same principle as the PRD's general `qr_points` random-value rule, applied here to a single column rather than a full `qr_points` row, since a lock has exactly one active QR at a time — no scope/type variation to model). Regenerating it (sticker damaged, suspected compromise) is a plain admin `PATCH` on the `devices` row; the old value simply stops resolving to anything.

Endpoint: `POST /api/v1/member/access/unlock` (`auth:sanctum` + `role:member`), body `{ "qr_value": "..." }`. Returns `{ "message": ... }` on success/failure, matching this codebase's existing update-endpoint convention. No `Booking`/`Space` needs to be referenced by the client — the backend derives everything from `qr_value` + the authenticated user.

## Guard

* `tests/Guards/LockDataNeverReachesMemberRoleTest.php` (extend the existing "no member-role endpoint can reach lockData/SDK material" guard from the S4 acceptance criteria to explicitly cover the new unlock endpoint's response shape — it must never include TTLock credentials, raw passcode values, or SDK payloads).
* `tests/Guards/QrValueIsRandomNotSequentialTest.php` — generate N `qr_value`s, assert no arithmetic/lexicographic sequence.
* Behavioral coverage (not `tests/Guards/`, per this codebase's convention that behavior lives in `tests/Feature`): `tests/Feature/Access/UnlockViaQrTest.php` — activated grant + correct user unlocks and logs `channel=qr_scan`; expired/revoked/not-yet-activated grant is denied and logs `failed_attempt`; a user with no grant for that lock is denied; a maintenance-revoked grant is denied even if it was `activated` moments earlier.

## What this changes in code

* `database/migrations/*_add_hardware_mac_qr_value_to_devices_table.php`
* `database/migrations/*_create_access_grants_table.php`
* `database/migrations/*_create_access_events_table.php`
* `App\Domain\Access\Models\{AccessGrant,AccessEvent}` (new domain, matching the build plan's original `Domain/Access` namespace)
* `App\Domain\Access\Enums\{AccessGrantStatus,AccessEventType, AccessEventChannel}`
* `App\Domain\Access\Services\{TTLockClient,PasscodeIssuanceService, UnlockService}`
* `App\Http\Controllers\Api\V1\Member\AccessUnlockController::unlock()`
* `App\Http\Controllers\Api\V1\Admin\Reception\AccessActivationController` (kiosk calls this after its own SDK activation succeeds)
* `App\Console\Commands\IssueAccessGrantsOnCancellationWindowClose`, `RevokeAccessGrantsOnMaintenance`, `ExpireUnactivatedAccessGrants`
* `routes/api/v1/{member,admin}.php`
* `lang/{en,ar}/api.php` (`access` group)
