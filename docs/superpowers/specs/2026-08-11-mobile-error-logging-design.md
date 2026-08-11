# Mobile error-logging service — design

## Purpose

The member mobile app needs to report client-side errors/crashes (Flutter or
native) back to ADD Core so operations/admin can review them. This is
separate from server-side PHP exception logging, which already goes to
`storage/logs` via the existing exception handler.

## Scope

- Source: member mobile app only (not AddDashboard, not server exceptions).
- Ingestion is unauthenticated — crashes can happen before login (e.g. on the
  login/splash screen itself), and the member app has no session yet at that
  point.
- Admin/operations can view logged errors from day one (not deferred).
- No automatic retention/pruning for this first pass — add later if the table
  becomes a real problem.

## Data model

`App\Domain\Identity\Models\ErrorLog` — same home as `NotificationLog`, the
existing "log optionally tied to a user" model in this domain.

| Column | Type | Notes |
|---|---|---|
| `error_type` | string, indexed | Free text (e.g. `NullPointerException`) — not a fixed enum, client-defined and unbounded |
| `message` | text | validated `max:5000` |
| `stack_trace` | longText, nullable | validated `max:20000` |
| `app_version` | string, nullable | |
| `build_number` | string, nullable | |
| `platform` | string(10), nullable, indexed | PHP-backed enum cast (`App\Domain\Identity\Enums\ErrorLogPlatform`: `android`, `ios`) — same DB-string/PHP-enum pattern as `PrivateOfficeRequestStatus` |
| `os` | string, nullable | e.g. "Android 14" |
| `device` | string, nullable | e.g. "SM-G991B" |
| `screen` | string, nullable | current screen/view name. (`route` from the original ChatGPT suggestion was dropped as redundant with `screen` in a mobile-only, routeless context.) |
| `user_id` | unsigned big integer, nullable, indexed, **no FK constraint** | Client-supplied on an unauthenticated endpoint — cannot be trusted/verified, so it's not a real foreign key, just a reference value |
| `session_id` | string, nullable, indexed | client-generated per-session identifier |
| `occurred_at` | timestamp, nullable | when the error happened on-device; `created_at` is when the server received it (may differ due to offline queuing on the client) |
| `metadata` | json/array, nullable | free-form extra context |

## Endpoints

**Ingestion (mobile app, unauthenticated):**

`POST /api/v1/errors`

Lives in a new `routes/api/v1/mobile.php` split file, required from
`routes/api.php` alongside `public.php` with no auth middleware. This is a
new architectural category distinct from the existing four files: routes the
member mobile app calls directly, unauthenticated, and unrelated to marketing
reads or the auth flow. Chosen over bolting onto `public.php` because more
unauthenticated mobile-app endpoints (e.g. public config/feature flags) are
expected later, and they deserve one clearly-named home rather than stretching
`public.php`'s documented "marketing site reads" purpose.

Protected by `throttle:60,1` (per IP). Deliberately **no** API-key middleware:
a key embedded in a mobile app binary is trivially extractable from the APK,
so it would add false confidence without real protection. Throttling is the
actual defense here.

Request validated by `App\Http\Requests\Mobile\StoreErrorLogRequest`
(`authorize() => true`, no auth required). Written synchronously (no queue) —
expected volume from a single mobile app doesn't justify the complexity yet.

**Admin viewing:**

- `GET /api/v1/admin/error-logs` — paginated, filterable by `platform`,
  `error_type`, `user_id`, and a date range on `occurred_at`.
- `GET /api/v1/admin/error-logs/{errorLog}` — full detail including
  `stack_trace`.
- `DELETE /api/v1/admin/error-logs/{errorLog}` — `role:admin` only (not
  `operations`).

`Api\V1\Admin\ErrorLogController` deliberately does **not** extend
`AdminResourceController`: that base class's `index()` returns every row
unpaginated, which fits small bounded tables (Founders, Partners) but not a
table that can grow quickly from client-reported crashes. Same reasoning
`UserController` already uses for not extending it.

## Out of scope (for now)

- AddDashboard / web frontend error ingestion.
- Automatic retention/pruning.
- Queue-based ingestion.
- API-key or any client-side "secret" gate.
