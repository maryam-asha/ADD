# Self-service Coworking Sessions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A member starts a coworking session by scanning a rotating TOTP code on the kiosk, is told up front when their plan will not cover it, ends it from the app with reception confirming, and both sides get a session report.

**Architecture:** Keep `Booking` and `WalkinSession` as the two session models and add a thin coordination layer (`CoworkingSessionService`, `SessionEndService`, `SessionReportBuilder`) that calls the existing, tested check-in / capacity / closure code. The kiosk is paired once through a server-issued pairing code, then generates RFC 6238 codes offline; the backend verifies them statelessly. Transactions (resource consumption now, café orders later) link to a session polymorphically.

**Tech Stack:** Laravel 12, PHP 8.3, Sanctum, spatie/permission, spatie/activitylog, PHPUnit 11 on in-memory SQLite, `paragonie/constant_time_encoding` (Base32).

**Spec:** `docs/superpowers/specs/2026-09-27-coworking-self-service-sessions-design.md`

### ملخص

- **العمل:** 14 مهمة متتالية. كل مهمة تبدأ باختبارات تفشل، ثم الكود، ثم commit.
- **مكان التنفيذ:** worktree منفصل على فرع جديد، متفرّع من `feat/add-point-system`، لأن الكود الذي نبني عليه موجود هناك فقط. هكذا لا نلمس تعديلاتك غير المحفوظة (Paymera وOTP).
- **الترتيب:**
  1. خوارزمية TOTP.
  2. الكشك.
  3. الاقتران.
  4. التحقق من الرمز.
  5. فحص الباقة.
  6. أعمدة بدء الجلسة.
  7. خدمة تسجيل دخول الحجز.
  8. بدء الجلسة بالمسح.
  9. إنهاء الجلسة.
  10. ربط الاستهلاك.
  11. التقرير.
  12. شاشة الاستقبال.
  13. التوثيق والتحقق النهائي.
- **انحرافات بسيطة عن التصميم:** مذكورة أدناه مع السبب، وتُسجَّل في ملف التصميم في المهمة 0.

## Global Constraints

- Only `co_space` spaces start sessions by scan; rooms, business and event-hall flows are untouched.
- TOTP: SHA1 · 30 s period · 8 digits · verification window ±1 step. QR value: `add-cowork:v1:{kiosk_id}:{code}`.
- Pairing code: 8 chars from `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`, valid 10 minutes, single use, stored only as SHA-256, `POST kiosk/pair` throttled `5,1`.
- Scan ticket valid 120 s; `POST member/coworking-sessions` throttled `10,1`.
- Update-style endpoints return `{"message": __('api.<group>.<key>')}`; create endpoints return data. Every new message key exists in both `lang/en/api.php` and `lang/ar/api.php`.
- Every user-supplied wall-clock time goes through `InterpretsLocalTimes` (guarded by `tests/Guards/UserSuppliedTimesAreInterpretedLocallyTest`).
- Never alias `User` in `MorphMapServiceProvider`; a new alias ships with an `activity_log` rewrite migration (precedent `2026_09_27_100005`).
- Never read or grep `vendor/`, `storage/logs`, `storage/framework`, `bootstrap/cache`.
- Tests run on in-memory SQLite. ~16 audit-log failures pre-exist on SQLite: compare against the Task 0 baseline, never against "all green".
- New tests set the clock in UTC — `Carbon::setTestNow(Carbon::parse('… local …', 'Asia/Damascus')->utc())` — and write datetimes as UTC (`->utc()`). Eloquent's datetime cast stores a Carbon's own wall-clock and reads it back as UTC (see the comment in `SessionClosureService::finalizeClosure`), so a Damascus-zoned `now()` would shift every stored `now()` by three hours and break the expiry and billing assertions.
- Every new enum-backed column is registered in `tests/Guards/EnumColumnsHaveBackedEnumCastsTest::EXPECTED_CASTS`.
- Commit trailer on every commit: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Deviations from the spec (recorded in the spec in Task 0)

1. **Own `App\Support\Security\Totp` instead of Google2FA.** `pragmarx/google2fa` reads the system clock, which `Carbon::setTestNow()` cannot move, so "a code from 30 s ago" could not be tested. `Totp` takes the time from the caller (`now()->getTimestamp()`) and is pinned to the RFC 6238 SHA1 test vectors. Base32 comes from `paragonie/constant_time_encoding`, already installed transitively, and is required explicitly.
2. **`Kiosk` and `KioskPairingService` live in `App\Domain\Booking`,** next to `ArrivalRequest`, not in `Foundation`. The kiosk exists to feed coworking sessions and shares `ReceptionActionException`.
3. **Reception's walk-in notice (`plan_applies` / `outside_plan_window`) is left exactly as it is.** Instead, every walk-in (reception or scan) stores `coverage_snapshot` from `SessionCoverageEvaluator`, so the reception list shows the same plan info for both paths.
4. **A scan also runs two pre-checks before any confirmation is asked for:**
   - A kiosk whose space is not an active `co_space` → 422 `api.coworking.unavailable`.
   - Outside business hours → the existing `api.reception.outside_business_hours`.
   So a member is never asked to accept a paid session that would then be refused.
5. **The Postman collection update is deferred.** The main working tree has uncommitted edits to `postman/ADD-OS.postman_collection.json`; it gets updated after those land.

## Review Focus

1. **Pairing code typed in lowercase or with a space/dash** → accepted, since normalization strips separators and upper-cases. Test: Task 3.
2. **Scan at a kiosk whose space was put under maintenance** → 422 `unavailable`, no session. Test: Task 8.
3. **Member taps "accept" twice with the same ticket** → the second call returns the session already started (200), not a second one. Test: Task 8.
4. **Member presses "end" after reception already checked them out** → 422 `no_open_session`, not a dangling request. Test: Task 9.
5. **Reception confirms an end request whose time falls after branch closing** → 422 `checkout_past_closing`; the request stays `pending` and the session stays open (transaction rolled back). Test: Task 9.

---

## File map

| Area | Create | Modify |
|---|---|---|
| TOTP | `app/Support/Security/Totp.php` | `composer.json` |
| Kiosk | `app/Domain/Booking/Models/Kiosk.php`, `database/factories/KioskFactory.php`, `database/migrations/2026_09_28_100000_create_kiosks_table.php`, `app/Http/Controllers/Api/V1/Admin/KioskController.php`, `app/Http/Requests/Admin/{Store,Update}KioskRequest.php`, `app/Http/Requests/Concerns/RequiresCoworkingSpace.php`, `app/Http/Resources/KioskResource.php` | `routes/api/v1/admin.php`, `lang/{en,ar}/api.php`, `lang/{en,ar}/notifications.php` |
| Pairing | `app/Domain/Booking/Services/KioskPairingService.php`, `app/Domain/Booking/DataTransferObjects/{IssuedPairingCode,KioskPairing}.php`, `app/Http/Controllers/Api/V1/Admin/KioskPairingController.php`, `app/Http/Controllers/Api/V1/Kiosk/KioskPairController.php`, `app/Http/Requests/Kiosk/PairKioskRequest.php`, `routes/api/v1/kiosk.php` | `routes/api.php`, `routes/api/v1/admin.php` |
| Code check | `app/Domain/Booking/Services/KioskCodeVerifier.php` | — |
| Coverage | `app/Domain/Membership/Enums/{CoverageStatus,CoverageReason}.php`, `app/Domain/Membership/ValueObjects/SessionCoverage.php`, `app/Domain/Membership/Services/SessionCoverageEvaluator.php` | `Plan.php`, `PlanHourCoverageService.php`, `MembershipResolver.php` |
| Start source | `app/Domain/Booking/Enums/SessionStartSource.php`, migration `2026_09_28_100100_add_start_source_to_sessions.php` | `WalkinSession.php`, `Booking.php`, `WalkInCapacityService.php` |
| Check-in | `app/Domain/Booking/Services/BookingCheckInService.php` | `BookingReceptionController.php`, `Admin/Reception/ArrivalRequestController.php` |
| Start | `CoworkingSessionService.php`, `ScanTicketService.php`, `DataTransferObjects/{CoworkingStartResult,IssuedScanTicket}.php`, `Enums/CoworkingStartOutcome.php`, `Api/V1/Member/CoworkingSessionController.php`, `Requests/Member/StartCoworkingSessionRequest.php`, `Resources/{CoworkingSessionResource,SessionCoverageResource}.php` | `ArrivalRequestMatcher.php`, `routes/api/v1/member.php` |
| End | `Models/SessionEndRequest.php`, `Enums/SessionEndRequestStatus.php`, `Concerns/HasSessionActivity.php`, `Services/SessionEndService.php`, `Admin/Reception/SessionEndRequestController.php`, `Requests/Admin/Reception/ConfirmSessionEndRequest.php`, `Resources/SessionEndRequestResource.php`, migrations `…100200_create_session_end_requests_table.php`, `…100300_alias_session_models_in_activity_log.php` | `SessionClosureService.php`, `CloseOverdueReceptionSessions.php`, `MorphMapServiceProvider.php` |
| Linking | migration `…100400_add_sessionable_to_resource_consumptions_table.php` | `ResourceConsumption.php`, `ResourceConsumptionController.php`, `PlanHourCoverageService.php` |
| Report | `app/Domain/Booking/Services/SessionReportBuilder.php` | `CoworkingSessionService.php`, `CoworkingSessionController.php`, `ReceptionSessionsController.php` |
| Reception list | — | `ReceptionSessionsController.php`, `ActiveReceptionSessionResource.php` |
| Tests | `tests/Support/InteractsWithCoworking.php` plus one test file per task | — |

(Model/service paths above are under `app/Domain/Booking/` unless stated.)

---

### Task 0: Workspace, spec amendments, baseline

**Files:** `docs/superpowers/specs/2026-09-27-coworking-self-service-sessions-design.md`, `composer.json`, `composer.lock`

- [ ] **Step 1: Create the worktree.** Use superpowers:using-git-worktrees. Branch `feat/coworking-self-service-sessions` from `feat/add-point-system` (the forms engine, `PlanHourCoverageService` and the plan-access-window work exist only there, not on `master`/`origin/main`).

```bash
git worktree add "../ADDCore-coworking" -b feat/coworking-self-service-sessions feat/add-point-system
cd "../ADDCore-coworking"
cp "../ADDCore/.env" .env
composer install
```

- [ ] **Step 2: Bring the spec and this plan across.** Both are untracked in the main working tree. Copy them into the worktree:

```bash
mkdir -p docs/superpowers/specs docs/superpowers/plans
cp "../ADDCore/docs/superpowers/specs/2026-09-27-coworking-self-service-sessions-design.md" docs/superpowers/specs/
cp "../ADDCore/docs/superpowers/plans/2026-09-27-coworking-self-service-sessions.md" docs/superpowers/plans/
```

- [ ] **Step 3: Amend the spec** with the five "Deviations from the spec" above:
  - Add a `## Amendments at planning time (2026-09-28)` section right after "Decisions taken while designing", with one bullet per deviation.
  - In §1 "Validation", replace the Google2FA paragraph with: "`App\Support\Security\Totp` (RFC 6238, SHA1, verified against the RFC's test vectors) with the caller's clock — `now()->getTimestamp()` — so tests can move time."
  - Replace every `App\Domain\Foundation\Models\Kiosk` / `App\Domain\Foundation\Services\KioskPairingService` with the `App\Domain\Booking\…` equivalent.
  - Add `unavailable` to the error-key list.

- [ ] **Step 4: Require Base32 explicitly.** It is already locked transitively; this only records the direct use.

```bash
composer require paragonie/constant_time_encoding:^3.1
```

- [ ] **Step 5: Record the baseline.**

```bash
php artisan test > ../coworking-baseline.txt 2>&1; tail -5 ../coworking-baseline.txt
```

Expected: a summary line with the pre-existing failures (about 16 audit-log tests on SQLite). Keep the file; Task 13 diffs against it.

- [ ] **Step 6: Commit.**

```bash
git add docs/superpowers/specs/2026-09-27-coworking-self-service-sessions-design.md docs/superpowers/plans/2026-09-27-coworking-self-service-sessions.md composer.json composer.lock
git commit -m "docs(coworking): self-service coworking sessions design and plan

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 1: TOTP generator

**Files:**
- Create: `app/Support/Security/Totp.php`
- Test: `tests/Unit/Support/TotpTest.php`

**Interfaces — Produces:**
- `Totp::__construct(int $digits = 8, int $period = 30)`, public readonly `$digits`, `$period`
- `Totp::ALGORITHM = 'SHA1'`
- `static Totp::generateSecret(): string` (32 Base32 chars)
- `Totp::codeAt(string $secret, int $unixTime): string`
- `Totp::verify(string $secret, string $code, int $unixTime, int $window = 1): bool`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Support;

use App\Support\Security\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** Base32 of the ASCII seed "12345678901234567890" used by RFC 6238 Appendix B. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return list<array{int, string}> RFC 6238 Appendix B, SHA1 column (8 digits). */
    public static function rfcVectors(): array
    {
        return [
            [59, '94287082'],
            [1111111109, '07081804'],
            [1111111111, '14050471'],
            [1234567890, '89005924'],
            [2000000000, '69279037'],
            [20000000000, '65353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_it_matches_the_rfc_6238_sha1_vectors(int $time, string $expected): void
    {
        $this->assertSame($expected, (new Totp)->codeAt(self::RFC_SECRET, $time));
    }

    public function test_verify_accepts_one_step_either_side_and_nothing_further(): void
    {
        $totp = new Totp;
        $t = 1_000_000_020; // exactly on a 30 s step boundary
        $code = $totp->codeAt(self::RFC_SECRET, $t);

        $this->assertTrue($totp->verify(self::RFC_SECRET, $code, $t));
        $this->assertTrue($totp->verify(self::RFC_SECRET, $code, $t + 30));
        $this->assertTrue($totp->verify(self::RFC_SECRET, $code, $t - 30));
        $this->assertFalse($totp->verify(self::RFC_SECRET, $code, $t + 60));
        $this->assertFalse($totp->verify(self::RFC_SECRET, $code, $t - 60));
    }

    public function test_verify_rejects_malformed_codes(): void
    {
        $totp = new Totp;

        $this->assertFalse($totp->verify(self::RFC_SECRET, '9428708', 59));   // too short
        $this->assertFalse($totp->verify(self::RFC_SECRET, '942870821', 59)); // too long
        $this->assertFalse($totp->verify(self::RFC_SECRET, '9428708a', 59));  // not digits
    }

    public function test_generated_secrets_are_32_base32_characters_and_distinct(): void
    {
        $a = Totp::generateSecret();
        $b = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $a);
        $this->assertNotSame($a, $b);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php artisan test tests/Unit/Support/TotpTest.php`
Expected: FAIL, class `App\Support\Security\Totp` not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Support\Security;

use ParagonIE\ConstantTime\Base32;

/**
 * RFC 6238 TOTP with HMAC-SHA1 — what every authenticator app speaks, so
 * the kiosk app can use any stock TOTP library
 * (docs/decisions/coworking-self-service-sessions.md).
 *
 * Written here rather than taken from pragmarx/google2fa because the time
 * has to come from the caller: google2fa reads the system clock, which
 * Carbon::setTestNow() cannot move. Callers pass now()->getTimestamp().
 *
 * Deliberately not replay-protected: a kiosk code is shared by every member
 * who scans it within the same step.
 */
final class Totp
{
    public const ALGORITHM = 'SHA1';

    public function __construct(
        public readonly int $digits = 8,
        public readonly int $period = 30,
    ) {}

    /** 160 random bits — RFC 4226's recommended length — as 32 Base32 chars. */
    public static function generateSecret(): string
    {
        return Base32::encodeUpper(random_bytes(20));
    }

    public function codeAt(string $secret, int $unixTime): string
    {
        $key = Base32::decodeUpper(strtoupper(str_replace(['=', ' '], '', $secret)));
        $hash = hash_hmac('sha1', pack('J', intdiv($unixTime, $this->period)), $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** $this->digits)), $this->digits, '0', STR_PAD_LEFT);
    }

    /** True when $code matches the step containing $unixTime or $window steps either side. */
    public function verify(string $secret, string $code, int $unixTime, int $window = 1): bool
    {
        if (strlen($code) !== $this->digits || ! ctype_digit($code)) {
            return false;
        }

        for ($step = -$window; $step <= $window; $step++) {
            if (hash_equals($this->codeAt($secret, $unixTime + ($step * $this->period)), $code)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run and confirm it passes**

Run: `php artisan test tests/Unit/Support/TotpTest.php`
Expected: PASS (9 assertions groups, 6 data-provider cases).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Support/Security/Totp.php tests/Unit/Support/TotpTest.php
git commit -m "feat(coworking): RFC 6238 TOTP generator with a caller-supplied clock

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Kiosk model and admin CRUD (plus all new translations)

**Files:**
- Create:
  - `database/migrations/2026_09_28_100000_create_kiosks_table.php`
  - `app/Domain/Booking/Models/Kiosk.php`
  - `database/factories/KioskFactory.php`
  - `app/Http/Requests/Concerns/RequiresCoworkingSpace.php`
  - `app/Http/Requests/Admin/StoreKioskRequest.php`
  - `app/Http/Requests/Admin/UpdateKioskRequest.php`
  - `app/Http/Resources/KioskResource.php`
  - `app/Http/Controllers/Api/V1/Admin/KioskController.php`
  - `tests/Support/InteractsWithCoworking.php`
- Modify:
  - `routes/api/v1/admin.php` (inside the existing `Route::middleware('role:admin')->group(...)`, around line 317)
  - `lang/en/api.php`, `lang/ar/api.php`
  - `lang/en/notifications.php`, `lang/ar/notifications.php`
- Test: `tests/Feature/Booking/KioskAdminTest.php`

**Interfaces — Produces:**
- `Kiosk` model: `QR_PREFIX = 'add-cowork:v1'`, `space(): BelongsTo`, `isPaired(): bool`
- Hidden attributes: `totp_secret` (encrypted cast), `pairing_code_hash`
- Test trait `Tests\Support\InteractsWithCoworking`: `coworkingSpace(?int $capacity = 10, int $hourlyRatePoints = 100): Space`, `actingAsMember(): User`, `actingAsOperations(): User`, `actingAsAdmin(): User`

- [ ] **Step 1: Add every translation this feature uses** (one edit now, so no later task touches these files).

In `lang/en/api.php`:
- Append to the existing `'kiosk' => [...]` group:

```php
        'not_a_coworking_space' => 'A kiosk can only serve a coworking space.',
        'invalid_pairing_code' => 'This pairing code is invalid or has expired.',
        'secret_revoked' => 'The kiosk has been unpaired. Pair it again to use it.',
```

- Append to the `'admin' => [...]` group: `'kiosk_updated' => 'Kiosk updated.',`
- Add a new top-level group after `'kiosk'`:

```php
    'coworking' => [
        'invalid_code' => 'This code is not valid. Scan the code currently shown on the kiosk.',
        'ticket_expired' => 'This confirmation has expired. Scan the kiosk code again.',
        'unavailable' => 'Coworking sessions are not available at this kiosk right now.',
        'confirmation_required' => 'Your plan will not cover this session (:reason). It will be billed at :rate points per hour.',
        'booking_pending_approval' => 'Your booking is awaiting approval. Please see reception.',
        'no_open_session' => 'You have no active session.',
        'end_request_not_pending' => 'This end request is no longer pending.',
        'end_request_cancelled' => 'End request withdrawn.',
        'session_ended' => 'Session ended.',
        'reasons' => [
            'no_plan' => 'you have no active plan',
            'plan_expired' => 'your plan has expired',
            'plan_without_hours' => 'your plan does not include coworking hours',
            'hours_exhausted' => "your plan's hours are used up",
            'outside_window' => "it is outside your plan's hours",
        ],
        'report' => [
            'time_so_far' => 'Time so far',
            'time_from_plan' => 'Time covered by your plan',
            'time_charged' => 'Charged time',
            'unit_hour' => 'hour',
        ],
    ],
```

In `lang/ar/api.php`, the same keys:
- `kiosk` group:

```php
        'not_a_coworking_space' => 'لا يمكن ربط الكشك إلا بمساحة عمل مشتركة.',
        'invalid_pairing_code' => 'رمز الاقتران غير صالح أو منتهي الصلاحية.',
        'secret_revoked' => 'تم إلغاء اقتران الكشك. يجب اقترانه من جديد لاستخدامه.',
```

- `admin` group: `'kiosk_updated' => 'تم تحديث الكشك.',`
- New group:

```php
    'coworking' => [
        'invalid_code' => 'هذا الرمز غير صالح. امسح الرمز الظاهر حالياً على الكشك.',
        'ticket_expired' => 'انتهت مهلة التأكيد. امسح رمز الكشك من جديد.',
        'unavailable' => 'جلسات العمل المشترك غير متاحة على هذا الكشك حالياً.',
        'confirmation_required' => 'باقتك لن تغطي هذه الجلسة (:reason). سيتم احتسابها بسعر :rate نقطة للساعة.',
        'booking_pending_approval' => 'حجزك بانتظار الموافقة. يرجى مراجعة الاستقبال.',
        'no_open_session' => 'لا توجد لديك جلسة فعّالة.',
        'end_request_not_pending' => 'طلب الإنهاء هذا لم يعد قيد الانتظار.',
        'end_request_cancelled' => 'تم سحب طلب الإنهاء.',
        'session_ended' => 'تم إنهاء الجلسة.',
        'reasons' => [
            'no_plan' => 'لا توجد لديك باقة فعّالة',
            'plan_expired' => 'انتهت صلاحية باقتك',
            'plan_without_hours' => 'باقتك لا تتضمن ساعات عمل مشترك',
            'hours_exhausted' => 'نفدت ساعات باقتك',
            'outside_window' => 'الوقت الحالي خارج أوقات باقتك',
        ],
        'report' => [
            'time_so_far' => 'الوقت حتى الآن',
            'time_from_plan' => 'وقت مغطى من باقتك',
            'time_charged' => 'وقت مدفوع',
            'unit_hour' => 'ساعة',
        ],
    ],
```

In `lang/en/notifications.php`, add a top-level group:

```php
    'coworking' => [
        'session_ended' => [
            'title' => 'Session Ended',
            'body' => 'Your session at :space has ended. Tap to see the report.',
        ],
    ],
```

In `lang/ar/notifications.php`:

```php
    'coworking' => [
        'session_ended' => [
            'title' => 'انتهت الجلسة',
            'body' => 'انتهت جلستك في :space. اضغط لعرض التقرير.',
        ],
    ],
```

- [ ] **Step 2: Create the shared test trait**

```php
<?php

namespace Tests\Support;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Fixtures for the self-service coworking tests
 * (docs/decisions/coworking-self-service-sessions.md). The using test seeds
 * RoleSeeder (and ResourceTypeSeeder where plans with hours are involved).
 */
trait InteractsWithCoworking
{
    /** A coworking space (the factory default type), open 08:00–20:00 every day, in its own branch. */
    protected function coworkingSpace(?int $capacity = 10, int $hourlyRatePoints = 100): Space
    {
        $space = Space::factory()->create([
            'capacity' => $capacity,
            'hourly_rate_points' => $hourlyRatePoints,
        ]);

        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $space->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }

        return $space;
    }

    protected function actingAsMember(): User
    {
        return $this->actingAsRole('member');
    }

    protected function actingAsOperations(): User
    {
        return $this->actingAsRole('operations');
    }

    protected function actingAsAdmin(): User
    {
        return $this->actingAsRole('admin');
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }
}
```

- [ ] **Step 3: Write the failing test** `tests/Feature/Booking/KioskAdminTest.php`

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Kiosk;
use App\Domain\Foundation\Models\Space;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class KioskAdminTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_an_admin_can_create_a_kiosk_on_a_coworking_space(): void
    {
        $this->actingAsAdmin();
        $space = $this->coworkingSpace();

        $this->postJson('/api/v1/admin/kiosks', ['name' => 'Main entrance', 'space_id' => $space->id])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Main entrance')
            ->assertJsonPath('data.space_id', $space->id)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_paired', false);
    }

    public function test_a_kiosk_cannot_serve_a_room(): void
    {
        $this->actingAsAdmin();
        $room = Space::factory()->room()->create();

        $this->withHeader('lang', 'en')
            ->postJson('/api/v1/admin/kiosks', ['name' => 'Lobby', 'space_id' => $room->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('space_id');
        $this->assertSame(0, Kiosk::count());
    }

    public function test_an_admin_can_update_a_kiosk(): void
    {
        $this->actingAsAdmin();
        $kiosk = Kiosk::factory()->create();

        $this->withHeader('lang', 'en')
            ->patchJson("/api/v1/admin/kiosks/{$kiosk->id}", ['is_active' => false])
            ->assertOk()
            ->assertExactJson(['message' => 'Kiosk updated.']);
        $this->assertFalse($kiosk->fresh()->is_active);
    }

    public function test_operations_cannot_manage_kiosks(): void
    {
        $this->actingAsOperations();

        $this->postJson('/api/v1/admin/kiosks', ['name' => 'X', 'space_id' => $this->coworkingSpace()->id])
            ->assertForbidden();
    }

    public function test_no_listing_exposes_the_secret_or_the_pairing_code_hash(): void
    {
        $this->actingAsAdmin();
        $kiosk = Kiosk::factory()->create();
        $kiosk->forceFill([
            'totp_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
            'pairing_code_hash' => str_repeat('a', 64),
            'paired_at' => now(),
        ])->save();

        $this->getJson('/api/v1/admin/kiosks')
            ->assertOk()
            ->assertJsonPath('data.0.is_paired', true)
            ->assertJsonMissingPath('data.0.totp_secret')
            ->assertJsonMissingPath('data.0.pairing_code_hash');

        $body = $this->getJson("/api/v1/admin/kiosks/{$kiosk->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $body);
        $this->assertStringNotContainsString(str_repeat('a', 64), $body);
    }
}
```

- [ ] **Step 4: Run it and confirm it fails**

Run: `php artisan test tests/Feature/Booking/KioskAdminTest.php`
Expected: FAIL, class `App\Domain\Booking\Models\Kiosk` not found.

- [ ] **Step 5: Migration** `2026_09_28_100000_create_kiosks_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A kiosk device bound to the one coworking space it starts sessions on
 * (docs/decisions/coworking-self-service-sessions.md). The TOTP secret is
 * delivered to the device once, through a single-use pairing code, and is
 * never readable again through the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('space_id')->constrained('spaces')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('totp_secret')->nullable();
            $table->string('pairing_code_hash', 64)->nullable()->unique();
            $table->dateTime('pairing_code_expires_at')->nullable();
            $table->dateTime('paired_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosks');
    }
};
```

- [ ] **Step 6: Model** `app/Domain/Booking/Models/Kiosk.php`

```php
<?php

namespace App\Domain\Booking\Models;

use App\Domain\Foundation\Models\Space;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lives in Booking next to ArrivalRequest: it exists only to start
 * coworking sessions (docs/decisions/coworking-self-service-sessions.md).
 * The secret and pairing-code hash are written by KioskPairingService via
 * forceFill, never mass-assigned, and never serialized.
 */
class Kiosk extends Model
{
    use HasFactory;

    public const QR_PREFIX = 'add-cowork:v1';

    protected $fillable = ['name', 'space_id', 'is_active'];

    protected $hidden = ['totp_secret', 'pairing_code_hash'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'totp_secret' => 'encrypted',
            'pairing_code_expires_at' => 'datetime',
            'paired_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function isPaired(): bool
    {
        return $this->paired_at !== null;
    }
}
```

- [ ] **Step 7: Factory** `database/factories/KioskFactory.php`

```php
<?php

namespace Database\Factories;

use App\Domain\Booking\Models\Kiosk;
use App\Domain\Foundation\Models\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Kiosk>
 */
class KioskFactory extends Factory
{
    protected $model = Kiosk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Kiosk '.fake()->unique()->numberBetween(1, 99999),
            'space_id' => Space::factory(), // SpaceFactory's default type is co_space
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 8: Form Requests**

`app/Http/Requests/Concerns/RequiresCoworkingSpace.php`:

```php
<?php

namespace App\Http\Requests\Concerns;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use Illuminate\Contracts\Validation\Validator;

/** A kiosk may only be bound to a co_space — same after-hook shape as StoreSeatDeskRequest. */
trait RequiresCoworkingSpace
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $spaceId = $this->input('space_id');

            if (! $spaceId) {
                return;
            }

            $space = Space::find($spaceId);

            if ($space !== null && $space->space_type !== SpaceType::CoSpace) {
                $validator->errors()->add('space_id', __('api.kiosk.not_a_coworking_space'));
            }
        });
    }
}
```

`app/Http/Requests/Admin/StoreKioskRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\RequiresCoworkingSpace;
use Illuminate\Foundation\Http\FormRequest;

class StoreKioskRequest extends FormRequest
{
    use RequiresCoworkingSpace;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

`app/Http/Requests/Admin/UpdateKioskRequest.php`: identical, except that every rule starts with `'sometimes'`:

```php
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'space_id' => ['sometimes', 'required', 'integer', 'exists:spaces,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
```

- [ ] **Step 9: Resource and controller**

`app/Http/Resources/KioskResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KioskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'space_id' => $this->space_id,
            'is_active' => $this->is_active,
            'is_paired' => $this->resource->isPaired(),
            'paired_at' => $this->paired_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

`app/Http/Controllers/Api/V1/Admin/KioskController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Booking\Models\Kiosk;
use App\Http\Requests\Admin\StoreKioskRequest;
use App\Http\Requests\Admin\UpdateKioskRequest;
use App\Http\Resources\KioskResource;
use Illuminate\Http\JsonResponse;

class KioskController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Kiosk::class;
    }

    protected function resourceClass(): string
    {
        return KioskResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    public function store(StoreKioskRequest $request): KioskResource
    {
        return new KioskResource(Kiosk::create(array_merge(['is_active' => true], $request->validated())));
    }

    public function update(UpdateKioskRequest $request, Kiosk $kiosk): JsonResponse
    {
        $kiosk->update($request->validated());

        return response()->json(['message' => __('api.admin.kiosk_updated')]);
    }
}
```

- [ ] **Step 10: Route.** In `routes/api/v1/admin.php`, add `use App\Http\Controllers\Api\V1\Admin\KioskController;` and, inside the existing `Route::middleware('role:admin')->group(function () { ... })` block:

```php
    // Kiosk devices that start self-service coworking sessions
    // (docs/decisions/coworking-self-service-sessions.md). Admin-only: a
    // kiosk's pairing is a credential.
    Route::apiResource('kiosks', KioskController::class);
```

- [ ] **Step 11: Run the new test and the permission-sync tests.** `KioskController` extends `AdminResourceController`, so `PermissionSyncService` now derives `kiosks.*`.

Run: `php artisan test tests/Feature/Booking/KioskAdminTest.php tests/Feature/Console/SyncPermissionsCommandTest.php`
(Never run `migrate:fresh` here: there is no `.env.testing`, so it would fall back to `.env`'s MySQL database. `RefreshDatabase` already migrates the in-memory SQLite.)
Expected: PASS.

- [ ] **Step 12: Commit**

```bash
./vendor/bin/pint --dirty
git add database/migrations/2026_09_28_100000_create_kiosks_table.php app/Domain/Booking/Models/Kiosk.php database/factories/KioskFactory.php app/Http/Requests/Concerns/RequiresCoworkingSpace.php app/Http/Requests/Admin/StoreKioskRequest.php app/Http/Requests/Admin/UpdateKioskRequest.php app/Http/Resources/KioskResource.php app/Http/Controllers/Api/V1/Admin/KioskController.php routes/api/v1/admin.php lang tests/Support/InteractsWithCoworking.php tests/Feature/Booking/KioskAdminTest.php
git commit -m "feat(coworking): kiosk devices bound to a coworking space

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Pairing — delivering the secret once

**Files:**
- Create:
  - `app/Domain/Booking/Services/KioskPairingService.php`
  - `app/Domain/Booking/DataTransferObjects/IssuedPairingCode.php`
  - `app/Domain/Booking/DataTransferObjects/KioskPairing.php`
  - `app/Http/Controllers/Api/V1/Admin/KioskPairingController.php`
  - `app/Http/Controllers/Api/V1/Kiosk/KioskPairController.php`
  - `app/Http/Requests/Kiosk/PairKioskRequest.php`
  - `routes/api/v1/kiosk.php`
- Modify:
  - `routes/api.php` (require the new file)
  - `routes/api/v1/admin.php` (two routes in the `role:admin` group)
  - `tests/Support/InteractsWithCoworking.php` (add `pairedKiosk`)
- Test: `tests/Feature/Booking/KioskPairingTest.php`

**Interfaces:**
- Consumes: `Totp::generateSecret()`, `Totp::ALGORITHM`, `->digits`, `->period`; `Kiosk::QR_PREFIX`
- Produces:
  - `KioskPairingService::issueCode(Kiosk): IssuedPairingCode{string $code, CarbonInterface $expiresAt}`
  - `KioskPairingService::pair(string $code): KioskPairing{Kiosk $kiosk, string $secret}`, which throws `ReceptionActionException('api.kiosk.invalid_pairing_code')`
  - `KioskPairingService::revoke(Kiosk): void`
  - Trait `pairedKiosk(Space $space): array{0: Kiosk, 1: string}`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Kiosk;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class KioskPairingTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function issueCodeFor(Kiosk $kiosk): string
    {
        $this->actingAsAdmin();

        return $this->postJson("/api/v1/admin/kiosks/{$kiosk->id}/pairing-code")->assertCreated()->json('data.pairing_code');
    }

    private function pair(string $code): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/kiosk/pair', ['pairing_code' => $code]);
    }

    public function test_an_admin_issues_a_ten_minute_code_stored_only_as_a_hash(): void
    {
        $kiosk = Kiosk::factory()->create();

        $code = $this->issueCodeFor($kiosk);

        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/', $code);
        $kiosk->refresh();
        $this->assertSame(hash('sha256', $code), $kiosk->pairing_code_hash);
        $this->assertTrue($kiosk->pairing_code_expires_at->equalTo(now()->addMinutes(10)));
    }

    public function test_the_kiosk_pairs_and_receives_its_secret_and_parameters_once(): void
    {
        $kiosk = Kiosk::factory()->create(['name' => 'Main entrance']);

        $response = $this->pair($this->issueCodeFor($kiosk));

        $response->assertOk()
            ->assertJsonPath('data.kiosk_id', $kiosk->id)
            ->assertJsonPath('data.name', 'Main entrance')
            ->assertJsonPath('data.totp', ['algorithm' => 'SHA1', 'digits' => 8, 'period' => 30])
            ->assertJsonPath('data.qr_prefix', 'add-cowork:v1');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($response->json('data.secret'), $kiosk->fresh()->totp_secret);
        $this->assertNotNull($kiosk->fresh()->paired_at);
        $this->assertNull($kiosk->fresh()->pairing_code_hash);
    }

    public function test_a_pairing_code_works_only_once(): void
    {
        $code = $this->issueCodeFor(Kiosk::factory()->create());
        $this->pair($code)->assertOk();

        $this->pair($code)->assertStatus(422)->assertExactJson(['message' => 'This pairing code is invalid or has expired.']);
    }

    public function test_an_expired_code_gets_the_same_answer_as_a_wrong_one(): void
    {
        $code = $this->issueCodeFor(Kiosk::factory()->create());
        $this->travel(11)->minutes();

        $this->pair($code)->assertStatus(422)->assertExactJson(['message' => 'This pairing code is invalid or has expired.']);
        $this->pair('ZZZZZZZZ')->assertStatus(422)->assertExactJson(['message' => 'This pairing code is invalid or has expired.']);
    }

    public function test_an_inactive_kiosk_cannot_pair(): void
    {
        $kiosk = Kiosk::factory()->create();
        $code = $this->issueCodeFor($kiosk);
        $kiosk->update(['is_active' => false]);

        $this->pair($code)->assertStatus(422);
        $this->assertNull($kiosk->fresh()->totp_secret);
    }

    public function test_a_code_typed_in_lowercase_with_separators_is_accepted(): void
    {
        $code = $this->issueCodeFor(Kiosk::factory()->create());

        $this->pair(' '.strtolower(substr($code, 0, 4)).'-'.strtolower(substr($code, 4)).' ')->assertOk();
    }

    public function test_issuing_a_new_code_replaces_the_unused_one(): void
    {
        $kiosk = Kiosk::factory()->create();
        $first = $this->issueCodeFor($kiosk);
        $second = $this->issueCodeFor($kiosk);

        $this->pair($first)->assertStatus(422);
        $this->pair($second)->assertOk();
    }

    public function test_every_pairing_rotates_the_secret(): void
    {
        $kiosk = Kiosk::factory()->create();
        $old = $this->pair($this->issueCodeFor($kiosk))->json('data.secret');

        $new = $this->pair($this->issueCodeFor($kiosk))->json('data.secret');

        $this->assertNotSame($old, $new);
        $this->assertSame($new, $kiosk->fresh()->totp_secret);
    }

    public function test_revoking_clears_the_secret_and_any_pending_code(): void
    {
        $kiosk = Kiosk::factory()->create();
        $this->pair($this->issueCodeFor($kiosk))->assertOk();
        $pending = $this->issueCodeFor($kiosk);

        $this->withHeader('lang', 'en')->deleteJson("/api/v1/admin/kiosks/{$kiosk->id}/secret")
            ->assertOk()
            ->assertExactJson(['message' => 'The kiosk has been unpaired. Pair it again to use it.']);

        $kiosk->refresh();
        $this->assertNull($kiosk->totp_secret);
        $this->assertNull($kiosk->paired_at);
        $this->pair($pending)->assertStatus(422);
    }

    public function test_operations_can_neither_issue_codes_nor_revoke(): void
    {
        $kiosk = Kiosk::factory()->create();
        $this->actingAsOperations();

        $this->postJson("/api/v1/admin/kiosks/{$kiosk->id}/pairing-code")->assertForbidden();
        $this->deleteJson("/api/v1/admin/kiosks/{$kiosk->id}/secret")->assertForbidden();
    }

    public function test_the_pairing_endpoint_is_throttled(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->pair('WRONGONE')->assertStatus(422);
        }

        $this->pair('WRONGONE')->assertStatus(429);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php artisan test tests/Feature/Booking/KioskPairingTest.php`
Expected: FAIL, 404 on `/api/v1/admin/kiosks/{id}/pairing-code`.

- [ ] **Step 3: DTOs**

```php
<?php

namespace App\Domain\Booking\DataTransferObjects;

use Carbon\CarbonInterface;

/** The plain pairing code, handed to the admin once; only its hash is stored. */
class IssuedPairingCode
{
    public function __construct(
        public readonly string $code,
        public readonly CarbonInterface $expiresAt,
    ) {}
}
```

```php
<?php

namespace App\Domain\Booking\DataTransferObjects;

use App\Domain\Booking\Models\Kiosk;

/** The result of a successful pairing: the only moment the plain secret leaves the server. */
class KioskPairing
{
    public function __construct(
        public readonly Kiosk $kiosk,
        public readonly string $secret,
    ) {}
}
```

- [ ] **Step 4: Service** `app/Domain/Booking/Services/KioskPairingService.php`

```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\DataTransferObjects\IssuedPairingCode;
use App\Domain\Booking\DataTransferObjects\KioskPairing;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Kiosk;
use App\Support\Security\Totp;
use Illuminate\Support\Facades\DB;

/**
 * How a kiosk gets its TOTP secret (docs/decisions/coworking-self-service-sessions.md):
 * an admin issues a short single-use code, the kiosk app trades it once for
 * a freshly generated secret. Every pairing rotates the secret, so replacing
 * a device is re-pairing, and nothing is ever pushed to a device that was
 * not just paired.
 */
class KioskPairingService
{
    public const CODE_LENGTH = 8;

    public const CODE_TTL_MINUTES = 10;

    /** No 0/O or 1/I: the code is read off one screen and typed into another. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function issueCode(Kiosk $kiosk): IssuedPairingCode
    {
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $expiresAt = now()->addMinutes(self::CODE_TTL_MINUTES);

        // Replaces any unused previous code: only the latest one can pair.
        $kiosk->forceFill([
            'pairing_code_hash' => $this->hash($code),
            'pairing_code_expires_at' => $expiresAt,
        ])->save();

        return new IssuedPairingCode($code, $expiresAt);
    }

    public function pair(string $code): KioskPairing
    {
        return DB::transaction(function () use ($code) {
            $kiosk = Kiosk::query()
                ->where('pairing_code_hash', $this->hash($code))
                ->lockForUpdate()
                ->first();

            // One answer for wrong, expired, used and inactive alike, so the
            // response never tells a guesser which part was right.
            if ($kiosk === null
                || ! $kiosk->is_active
                || $kiosk->pairing_code_expires_at === null
                || $kiosk->pairing_code_expires_at->isPast()) {
                throw new ReceptionActionException('api.kiosk.invalid_pairing_code');
            }

            $secret = Totp::generateSecret();

            $kiosk->forceFill([
                'totp_secret' => $secret,
                'pairing_code_hash' => null,
                'pairing_code_expires_at' => null,
                'paired_at' => now(),
            ])->save();

            return new KioskPairing($kiosk, $secret);
        });
    }

    /**
     * Separate from is_active on purpose: deactivating and later
     * reactivating a kiosk would otherwise bring back the secret a lost
     * device still holds.
     */
    public function revoke(Kiosk $kiosk): void
    {
        $kiosk->forceFill([
            'totp_secret' => null,
            'pairing_code_hash' => null,
            'pairing_code_expires_at' => null,
            'paired_at' => null,
        ])->save();
    }

    private function hash(string $code): string
    {
        return hash('sha256', strtoupper((string) preg_replace('/[\s-]+/', '', $code)));
    }
}
```

- [ ] **Step 5: Controllers and request**

`app/Http/Controllers/Api/V1/Admin/KioskPairingController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Services\KioskPairingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class KioskPairingController extends Controller
{
    use LogsSensitiveActions;

    public function issue(Kiosk $kiosk, KioskPairingService $pairings): JsonResponse
    {
        $issued = $pairings->issueCode($kiosk);

        $this->logSensitiveAction('kiosk_pairing_code_issued', $kiosk);

        return response()->json(['data' => [
            'pairing_code' => $issued->code,
            'expires_at' => $issued->expiresAt->toIso8601String(),
        ]], 201);
    }

    public function revoke(Kiosk $kiosk, KioskPairingService $pairings): JsonResponse
    {
        $pairings->revoke($kiosk);

        $this->logSensitiveAction('kiosk_secret_revoked', $kiosk);

        return response()->json(['message' => __('api.kiosk.secret_revoked')]);
    }
}
```

`app/Http/Requests/Kiosk/PairKioskRequest.php`:

```php
<?php

namespace App\Http\Requests\Kiosk;

use Illuminate\Foundation\Http\FormRequest;

class PairKioskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pairing_code' => ['required', 'string', 'max:20'],
        ];
    }
}
```

`app/Http/Controllers/Api/V1/Kiosk/KioskPairController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Kiosk;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Services\KioskPairingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\PairKioskRequest;
use App\Support\Security\Totp;
use Illuminate\Http\JsonResponse;

class KioskPairController extends Controller
{
    use LogsSensitiveActions;

    public function pair(PairKioskRequest $request, KioskPairingService $pairings, Totp $totp): JsonResponse
    {
        try {
            $pairing = $pairings->pair($request->validated('pairing_code'));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('kiosk_paired', $pairing->kiosk);

        // The only response that ever carries the secret; nothing between
        // here and the kiosk may keep a copy.
        return response()->json(['data' => [
            'kiosk_id' => $pairing->kiosk->id,
            'name' => $pairing->kiosk->name,
            'secret' => $pairing->secret,
            'totp' => [
                'algorithm' => Totp::ALGORITHM,
                'digits' => $totp->digits,
                'period' => $totp->period,
            ],
            'qr_prefix' => Kiosk::QR_PREFIX,
        ]])->header('Cache-Control', 'no-store');
    }
}
```

- [ ] **Step 6: Routes**

`routes/api/v1/kiosk.php`:

```php
<?php

use App\Http\Controllers\Api\V1\Kiosk\KioskPairController;
use Illuminate\Support\Facades\Route;

// Unauthenticated endpoints the kiosk app calls
// (docs/decisions/coworking-self-service-sessions.md). The kiosk holds no
// token: the single-use pairing code is the proof here, and after pairing
// the kiosk never calls in — it generates its codes offline.
Route::post('kiosk/pair', [KioskPairController::class, 'pair'])->middleware('throttle:5,1');
```

In `routes/api.php`, after `require base_path('routes/api/v1/mobile.php');`:

```php
    require base_path('routes/api/v1/kiosk.php');
```

In `routes/api/v1/admin.php`, add `use App\Http\Controllers\Api\V1\Admin\KioskPairingController;` and, right under the `kiosks` apiResource line from Task 2:

```php
    Route::post('kiosks/{kiosk}/pairing-code', [KioskPairingController::class, 'issue']);
    Route::delete('kiosks/{kiosk}/secret', [KioskPairingController::class, 'revoke']);
```

- [ ] **Step 7: Add `pairedKiosk` to the test trait.**
  - Add the imports `use App\Domain\Booking\Models\Kiosk;` and `use App\Domain\Booking\Services\KioskPairingService;`.
  - Add the method:

```php
    /** @return array{0: Kiosk, 1: string} the paired kiosk and its plain secret */
    protected function pairedKiosk(Space $space): array
    {
        $pairings = app(KioskPairingService::class);
        $kiosk = Kiosk::factory()->create(['space_id' => $space->id]);
        $pairing = $pairings->pair($pairings->issueCode($kiosk)->code);

        return [$pairing->kiosk->fresh(), $pairing->secret];
    }
```

- [ ] **Step 8: Run and confirm it passes**

Run: `php artisan test tests/Feature/Booking/KioskPairingTest.php tests/Feature/Booking/KioskAdminTest.php`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Domain/Booking/Services/KioskPairingService.php app/Domain/Booking/DataTransferObjects/IssuedPairingCode.php app/Domain/Booking/DataTransferObjects/KioskPairing.php app/Http/Controllers/Api/V1/Admin/KioskPairingController.php app/Http/Controllers/Api/V1/Kiosk/KioskPairController.php app/Http/Requests/Kiosk/PairKioskRequest.php routes tests/Support/InteractsWithCoworking.php tests/Feature/Booking/KioskPairingTest.php
git commit -m "feat(coworking): pair a kiosk once through a single-use code

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Verifying a scanned code

**Files:**
- Create: `app/Domain/Booking/Services/KioskCodeVerifier.php`
- Modify: `tests/Support/InteractsWithCoworking.php` (add `qrFor`)
- Test: `tests/Feature/Booking/KioskCodeVerifierTest.php`

**Interfaces:**
- Consumes: `Totp::verify()`, `Totp::codeAt()`, `Kiosk::QR_PREFIX`, `KioskPairingService::revoke()`
- Produces:
  - `KioskCodeVerifier::verify(string $qrValue): Kiosk`, which throws `ReceptionActionException('api.coworking.invalid_code')` and touches `last_used_at` on success
  - Trait `qrFor(Kiosk $kiosk, string $secret, int $offsetSeconds = 0): string`

- [ ] **Step 1: Add `qrFor` to the trait.**
  - Add the import `use App\Support\Security\Totp;`.
  - Add the method:

```php
    /** The QR value the kiosk would show $offsetSeconds from now. */
    protected function qrFor(Kiosk $kiosk, string $secret, int $offsetSeconds = 0): string
    {
        return Kiosk::QR_PREFIX.':'.$kiosk->id.':'.app(Totp::class)->codeAt($secret, now()->getTimestamp() + $offsetSeconds);
    }
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Services\KioskCodeVerifier;
use App\Domain\Booking\Services\KioskPairingService;
use App\Support\Security\Totp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class KioskCodeVerifierTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private KioskCodeVerifier $codes;

    protected function setUp(): void
    {
        parent::setUp();
        // 07:00:00 UTC — exactly on a 30 s step boundary.
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
        $this->codes = app(KioskCodeVerifier::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function assertRefused(string $qrValue): void
    {
        try {
            $this->codes->verify($qrValue);
            $this->fail("Accepted {$qrValue}");
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.coworking.invalid_code', $e->messageKey);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_a_code_is_accepted_for_its_step_and_one_either_side(): void
    {
        [$kiosk, $secret] = $this->pairedKiosk($this->coworkingSpace());

        foreach ([0, 30, -30] as $offset) {
            $this->assertTrue($this->codes->verify($this->qrFor($kiosk, $secret, $offset))->is($kiosk));
        }
    }

    public function test_a_code_two_steps_away_is_refused(): void
    {
        [$kiosk, $secret] = $this->pairedKiosk($this->coworkingSpace());

        $this->assertRefused($this->qrFor($kiosk, $secret, 60));
        $this->assertRefused($this->qrFor($kiosk, $secret, -60));
    }

    public function test_another_kiosks_code_is_refused(): void
    {
        $space = $this->coworkingSpace();
        [$a] = $this->pairedKiosk($space);
        [, $secretB] = $this->pairedKiosk($space);

        $this->assertRefused(Kiosk::QR_PREFIX.':'.$a->id.':'.app(Totp::class)->codeAt($secretB, now()->getTimestamp()));
    }

    public function test_an_inactive_or_revoked_kiosk_is_refused(): void
    {
        $space = $this->coworkingSpace();
        [$inactive, $secretI] = $this->pairedKiosk($space);
        [$revoked, $secretR] = $this->pairedKiosk($space);
        $inactive->update(['is_active' => false]);
        app(KioskPairingService::class)->revoke($revoked);

        $this->assertRefused($this->qrFor($inactive, $secretI));
        $this->assertRefused($this->qrFor($revoked, $secretR));
    }

    public function test_malformed_values_are_refused(): void
    {
        [$kiosk] = $this->pairedKiosk($this->coworkingSpace());

        $this->assertRefused('hello');
        $this->assertRefused("add-cowork:v1:{$kiosk->id}:abcdefgh");
        $this->assertRefused("add-cowork:v2:{$kiosk->id}:12345678");
        $this->assertRefused('add-cowork:v1:999999:12345678');
    }

    public function test_the_same_code_serves_several_members_and_marks_the_kiosk_used(): void
    {
        [$kiosk, $secret] = $this->pairedKiosk($this->coworkingSpace());
        $qr = $this->qrFor($kiosk, $secret);

        $this->codes->verify($qr);
        $this->codes->verify($qr);

        $this->assertTrue($kiosk->fresh()->last_used_at->equalTo(now()));
    }
}
```

- [ ] **Step 3: Run it and confirm it fails**

Run: `php artisan test tests/Feature/Booking/KioskCodeVerifierTest.php`
Expected: FAIL, class `KioskCodeVerifier` not found.

- [ ] **Step 4: Implement** `app/Domain/Booking/Services/KioskCodeVerifier.php`

```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Kiosk;
use App\Support\Security\Totp;

/**
 * Turns a scanned `add-cowork:v1:{kiosk_id}:{code}` into the kiosk that
 * showed it, or refuses (docs/decisions/coworking-self-service-sessions.md).
 * Stateless: the same code serves every member who scans it within its
 * step, and nothing is stored per code.
 */
class KioskCodeVerifier
{
    public function __construct(private readonly Totp $totp) {}

    public function verify(string $qrValue): Kiosk
    {
        $pattern = '/^'.preg_quote(Kiosk::QR_PREFIX, '/').':(\d+):(\d+)$/';

        if (preg_match($pattern, trim($qrValue), $matches) !== 1) {
            throw new ReceptionActionException('api.coworking.invalid_code');
        }

        $kiosk = Kiosk::query()->whereKey((int) $matches[1])->where('is_active', true)->first();

        if ($kiosk === null
            || $kiosk->totp_secret === null
            || ! $this->totp->verify($kiosk->totp_secret, $matches[2], now()->getTimestamp())) {
            throw new ReceptionActionException('api.coworking.invalid_code');
        }

        // The only sign of life the backend gets from a kiosk after pairing.
        $kiosk->forceFill(['last_used_at' => now()])->save();

        return $kiosk;
    }
}
```

- [ ] **Step 5: Run and confirm it passes**

Run: `php artisan test tests/Feature/Booking/KioskCodeVerifierTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Domain/Booking/Services/KioskCodeVerifier.php tests/Support/InteractsWithCoworking.php tests/Feature/Booking/KioskCodeVerifierTest.php
git commit -m "feat(coworking): verify a scanned kiosk code statelessly

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Plan coverage at scan time

**Files:**
- Create:
  - `app/Domain/Membership/Enums/CoverageStatus.php`
  - `app/Domain/Membership/Enums/CoverageReason.php`
  - `app/Domain/Membership/ValueObjects/SessionCoverage.php`
  - `app/Domain/Membership/Services/SessionCoverageEvaluator.php`
- Modify:
  - `app/Domain/Membership/Models/Plan.php` (add `currentWindowEndsAt`)
  - `app/Domain/Membership/Services/PlanHourCoverageService.php` (`private function grantFor` → `public function hourGrantFor`, updating its two call sites in the same file)
  - `app/Domain/Membership/Services/MembershipResolver.php` (add `hasHeldAnyFor`)
  - `tests/Support/InteractsWithCoworking.php` (add `planWithHours`, `subscribe`)
- Test: `tests/Feature/Membership/SessionCoverageEvaluatorTest.php`

**Interfaces — Produces:**
- `Plan::currentWindowEndsAt(CarbonInterface $at): ?CarbonImmutable` — UTC end of the window occurrence containing `$at`, or null
- `PlanHourCoverageService::hourGrantFor(?Membership): ?PlanResourceGrant`
- `MembershipResolver::hasHeldAnyFor(User): bool`
- `SessionCoverageEvaluator::evaluate(User $member, CarbonInterface $at): SessionCoverage`
- `SessionCoverage`:
  - `isCovered(): bool`
  - public readonly `status`, `reason`, `plan`, `hoursTotal`, `hoursRemaining`, `coveredUntil`
  - `toArray(): array{status, reason, plan: ?array{id, name: array}, hours_total, hours_remaining, window: ?array{start, end}, covered_until: ?string}`
- `CoverageReason` values: `no_plan`, `plan_expired`, `plan_without_hours`, `hours_exhausted`, `outside_window`
- Trait:
  - `planWithHours(float $hours, ?string $windowStart = '08:00', ?string $windowEnd = '20:00'): Plan`
  - `subscribe(User $member, Plan $plan, ?CarbonInterface $periodEnd = null): Membership`

- [ ] **Step 1: Add the trait helpers.**
  - Add the imports: `App\Domain\Membership\Enums\MembershipStatus`, `App\Domain\Membership\Enums\OwnerType`, `App\Domain\Membership\Models\Membership`, `App\Domain\Membership\Models\Plan`, `App\Domain\Membership\Models\ResourceType`, `App\Domain\Membership\Services\PlanHourCoverageService`, `Carbon\CarbonInterface`.
  - Add the methods:

```php
    /** A plan with a coworking-hours grant. Needs ResourceTypeSeeder (space-hours). */
    protected function planWithHours(float $hours, ?string $windowStart = '08:00', ?string $windowEnd = '20:00'): Plan
    {
        $plan = Plan::factory()->create([
            'access_window_start' => $windowStart,
            'access_window_end' => $windowEnd,
        ]);

        $plan->resourceGrants()->create([
            'resource_type_id' => ResourceType::where('slug', PlanHourCoverageService::RESOURCE_TYPE_SLUG)->value('id'),
            'quantity' => $hours,
            'order' => 0,
        ]);

        return $plan;
    }

    protected function subscribe(User $member, Plan $plan, ?CarbonInterface $periodEnd = null): Membership
    {
        return Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => $periodEnd ?? now()->addDays(20),
        ]);
    }
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Membership;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\CoverageReason;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\PlanHourCoverageService;
use App\Domain\Membership\Services\ResourceQuotaService;
use App\Domain\Membership\Services\SessionCoverageEvaluator;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class SessionCoverageEvaluatorTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private SessionCoverageEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
        $this->evaluator = app(SessionCoverageEvaluator::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function local(string $time): Carbon
    {
        return Carbon::parse($time, 'Asia/Damascus');
    }

    public function test_the_current_window_end_handles_same_day_and_wrapping_windows(): void
    {
        $day = Plan::factory()->create(['access_window_start' => '08:00', 'access_window_end' => '16:00']);
        $evening = Plan::factory()->evening()->create();
        $overnight = Plan::factory()->create(['access_window_start' => '22:00', 'access_window_end' => '02:00']);

        $this->assertTrue($day->currentWindowEndsAt($this->local('2026-08-17 10:00'))->equalTo($this->local('2026-08-17 16:00')));
        $this->assertNull($day->currentWindowEndsAt($this->local('2026-08-17 16:00')));
        $this->assertTrue($evening->currentWindowEndsAt($this->local('2026-08-17 21:00'))->equalTo($this->local('2026-08-18 00:00')));
        $this->assertNull($evening->currentWindowEndsAt($this->local('2026-08-17 14:00')));
        $this->assertTrue($overnight->currentWindowEndsAt($this->local('2026-08-17 23:00'))->equalTo($this->local('2026-08-18 02:00')));
        $this->assertTrue($overnight->currentWindowEndsAt($this->local('2026-08-18 01:00'))->equalTo($this->local('2026-08-18 02:00')));
        $this->assertNull(Plan::factory()->create()->currentWindowEndsAt($this->local('2026-08-17 10:00')));
    }

    public function test_a_member_who_never_subscribed_has_no_plan(): void
    {
        $coverage = $this->evaluator->evaluate(User::factory()->create(), now());

        $this->assertFalse($coverage->isCovered());
        $this->assertSame(CoverageReason::NoPlan, $coverage->reason);
    }

    public function test_a_lapsed_membership_reads_as_expired(): void
    {
        $member = User::factory()->create();
        $this->subscribe($member, $this->planWithHours(40), now()->subDay());

        $this->assertSame(CoverageReason::PlanExpired, $this->evaluator->evaluate($member, now())->reason);
    }

    public function test_a_plan_without_a_window_or_without_hours_does_not_cover(): void
    {
        $noWindow = User::factory()->create();
        $this->subscribe($noWindow, $this->planWithHours(40, null, null));
        $noHours = User::factory()->create();
        $this->subscribe($noHours, Plan::factory()->create(['access_window_start' => '08:00', 'access_window_end' => '20:00']));

        $this->assertSame(CoverageReason::PlanWithoutHours, $this->evaluator->evaluate($noWindow, now())->reason);
        $this->assertSame(CoverageReason::PlanWithoutHours, $this->evaluator->evaluate($noHours, now())->reason);
    }

    public function test_used_up_hours_do_not_cover(): void
    {
        $member = User::factory()->create();
        $membership = $this->subscribe($member, $this->planWithHours(1));
        app(ResourceQuotaService::class)->consume(app(PlanHourCoverageService::class)->hourGrantFor($membership), $membership, 1.0, $member);

        $coverage = $this->evaluator->evaluate($member, now());

        $this->assertSame(CoverageReason::HoursExhausted, $coverage->reason);
        $this->assertSame(0.0, $coverage->hoursRemaining);
    }

    public function test_outside_the_window_the_remaining_hours_are_still_reported(): void
    {
        $member = User::factory()->create();
        $this->subscribe($member, $this->planWithHours(40, '20:00', '00:00'));

        $coverage = $this->evaluator->evaluate($member, now());

        $this->assertSame(CoverageReason::OutsideWindow, $coverage->reason);
        $this->assertSame(40.0, $coverage->hoursRemaining);
    }

    public function test_covered_until_is_the_window_end_when_the_hours_outlast_it(): void
    {
        $member = User::factory()->create();
        $this->subscribe($member, $this->planWithHours(40));

        $coverage = $this->evaluator->evaluate($member, now());

        $this->assertTrue($coverage->isCovered());
        $this->assertNull($coverage->reason);
        $this->assertTrue($coverage->coveredUntil->equalTo($this->local('2026-08-17 20:00')));
    }

    public function test_covered_until_is_when_the_hours_run_out_when_that_comes_first(): void
    {
        $member = User::factory()->create();
        $this->subscribe($member, $this->planWithHours(2));

        $this->assertTrue($this->evaluator->evaluate($member, now())->coveredUntil->equalTo($this->local('2026-08-17 12:00')));
    }

    public function test_a_company_plan_covers_its_members(): void
    {
        $member = User::factory()->create();
        $company = Company::factory()->create();
        $member->companies()->attach($company->id);
        Membership::create([
            'plan_id' => $this->planWithHours(40)->id,
            'owner_type' => OwnerType::Company,
            'owner_id' => $company->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]);

        $this->assertTrue($this->evaluator->evaluate($member, now())->isCovered());
    }

    public function test_the_snapshot_shape(): void
    {
        $member = User::factory()->create();
        $plan = $this->planWithHours(40);
        $this->subscribe($member, $plan);

        $snapshot = $this->evaluator->evaluate($member, now())->toArray();

        $this->assertSame('covered', $snapshot['status']);
        $this->assertNull($snapshot['reason']);
        $this->assertSame($plan->id, $snapshot['plan']['id']);
        $this->assertSame($plan->name, $snapshot['plan']['name']);
        $this->assertSame(40.0, $snapshot['hours_total']);
        $this->assertSame(40.0, $snapshot['hours_remaining']);
        $this->assertSame(['start' => '08:00', 'end' => '20:00'], $snapshot['window']);
        $this->assertSame($this->local('2026-08-17 20:00')->utc()->toIso8601String(), $snapshot['covered_until']);
    }
}
```

- [ ] **Step 3: Run it and confirm it fails**

Run: `php artisan test tests/Feature/Membership/SessionCoverageEvaluatorTest.php`
Expected: FAIL (undefined method `Plan::currentWindowEndsAt`).

- [ ] **Step 4: Plan window end.** In `app/Domain/Membership/Models/Plan.php`:
  - Add `use Carbon\CarbonImmutable;`.
  - Add, below `windowOverlap()`:

```php
    /**
     * The end, as a UTC instant, of the window occurrence containing $at —
     * or null when $at is outside the window or there is no window. Same
     * wrap rules as windowOverlap(): 20:00–00:00 at 21:00 ends at the next
     * midnight; 22:00–02:00 at 01:00 ends at 02:00 that morning.
     */
    public function currentWindowEndsAt(CarbonInterface $at): ?CarbonImmutable
    {
        if (! $this->hasAccessWindow()) {
            return null;
        }

        $windowStart = $this->minutesSinceMidnight($this->access_window_start);
        $windowEnd = $this->minutesSinceMidnight($this->access_window_end);

        if ($windowStart === null || $windowEnd === null) {
            return null;
        }

        $timezone = app(SettingService::class)->get('app.timezone', 'Asia/Damascus');
        $local = CarbonImmutable::instance($at)->setTimezone($timezone);
        $minute = ($local->hour * 60) + $local->minute;
        $midnight = $local->startOfDay();

        if ($windowEnd > $windowStart) {
            return $minute >= $windowStart && $minute < $windowEnd
                ? $midnight->addMinutes($windowEnd)->utc()
                : null;
        }

        if ($minute >= $windowStart) {
            return $midnight->addDay()->addMinutes($windowEnd)->utc();
        }

        return $minute < $windowEnd ? $midnight->addMinutes($windowEnd)->utc() : null;
    }
```

- [ ] **Step 5: Expose the hour grant.** In `PlanHourCoverageService`:
  - Rename `private function grantFor(?Membership $membership): ?PlanResourceGrant` to `public function hourGrantFor(?Membership $membership): ?PlanResourceGrant`.
  - Replace both `$this->grantFor(` calls with `$this->hourGrantFor(`.
  - Keep the docblock.

- [ ] **Step 6: "Ever held a plan".** In `MembershipResolver`, add:

```php
    /**
     * Whether this user ever held any membership, personal or through one
     * of their companies, whatever its status or period — what tells an
     * expired plan apart from never having had one.
     */
    public function hasHeldAnyFor(User $user): bool
    {
        $companyIds = $user->companies()->pluck('companies.id');

        return Membership::query()
            ->where(function ($query) use ($user, $companyIds) {
                $query->where(fn ($q) => $q->where('owner_type', OwnerType::User)->where('owner_id', $user->id));

                if ($companyIds->isNotEmpty()) {
                    $query->orWhere(fn ($q) => $q->where('owner_type', OwnerType::Company)->whereIn('owner_id', $companyIds));
                }
            })
            ->exists();
    }
```

- [ ] **Step 7: Enums and value object**

```php
<?php

namespace App\Domain\Membership\Enums;

enum CoverageStatus: string
{
    case Covered = 'covered';
    case NotCovered = 'not_covered';
}
```

```php
<?php

namespace App\Domain\Membership\Enums;

/** Why a plan will not cover a coworking session, in the order they are checked. */
enum CoverageReason: string
{
    case NoPlan = 'no_plan';
    case PlanExpired = 'plan_expired';
    case PlanWithoutHours = 'plan_without_hours';
    case HoursExhausted = 'hours_exhausted';
    case OutsideWindow = 'outside_window';
}
```

`app/Domain/Membership/ValueObjects/SessionCoverage.php`:

```php
<?php

namespace App\Domain\Membership\ValueObjects;

use App\Domain\Membership\Enums\CoverageReason;
use App\Domain\Membership\Enums\CoverageStatus;
use App\Domain\Membership\Models\Plan;
use Carbon\CarbonImmutable;

/**
 * What a member's plan will do for a coworking session starting now
 * (docs/decisions/coworking-self-service-sessions.md). toArray() is what
 * walkin_sessions.coverage_snapshot stores: taken once at start, so what
 * the member agreed to never drifts afterwards.
 */
final class SessionCoverage
{
    private function __construct(
        public readonly CoverageStatus $status,
        public readonly ?CoverageReason $reason,
        public readonly ?Plan $plan,
        public readonly ?float $hoursTotal,
        public readonly ?float $hoursRemaining,
        public readonly ?CarbonImmutable $coveredUntil,
    ) {}

    public static function covered(Plan $plan, float $hoursTotal, float $hoursRemaining, CarbonImmutable $coveredUntil): self
    {
        return new self(CoverageStatus::Covered, null, $plan, $hoursTotal, $hoursRemaining, $coveredUntil);
    }

    public static function notCovered(CoverageReason $reason, ?Plan $plan = null, ?float $hoursTotal = null, ?float $hoursRemaining = null): self
    {
        return new self(CoverageStatus::NotCovered, $reason, $plan, $hoursTotal, $hoursRemaining, null);
    }

    public function isCovered(): bool
    {
        return $this->status === CoverageStatus::Covered;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reason' => $this->reason?->value,
            // Both languages kept: an old snapshot still renders in whichever
            // language the reader asks for (SessionCoverageResource picks).
            'plan' => $this->plan === null ? null : ['id' => $this->plan->id, 'name' => $this->plan->name],
            'hours_total' => $this->hoursTotal,
            'hours_remaining' => $this->hoursRemaining,
            'window' => $this->plan?->hasAccessWindow()
                ? ['start' => $this->plan->access_window_start, 'end' => $this->plan->access_window_end]
                : null,
            'covered_until' => $this->coveredUntil?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 8: Evaluator** `app/Domain/Membership/Services/SessionCoverageEvaluator.php`

```php
<?php

namespace App\Domain\Membership\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\CoverageReason;
use App\Domain\Membership\ValueObjects\SessionCoverage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Answers, before a coworking session starts, whether the member's plan
 * will cover it and why not (docs/decisions/coworking-self-service-sessions.md).
 * Read-only: it spends nothing. What is actually absorbed is still decided
 * at checkout by PlanHourCoverageService, exactly as before.
 */
class SessionCoverageEvaluator
{
    public function __construct(
        private readonly MembershipResolver $memberships,
        private readonly PlanHourCoverageService $planHours,
        private readonly ResourceQuotaService $quotas,
    ) {}

    public function evaluate(User $member, CarbonInterface $at): SessionCoverage
    {
        $membership = $this->memberships->activeFor($member);

        if ($membership === null || $membership->plan === null) {
            return SessionCoverage::notCovered(
                $this->memberships->hasHeldAnyFor($member) ? CoverageReason::PlanExpired : CoverageReason::NoPlan
            );
        }

        $plan = $membership->plan;
        $grant = $this->planHours->hourGrantFor($membership);

        if ($grant === null) {
            return SessionCoverage::notCovered(CoverageReason::PlanWithoutHours, $plan);
        }

        $total = (float) $grant->quantity;
        $remaining = (float) ($this->quotas->remainingFor($membership, $grant->resourceType) ?? 0);

        if ($remaining <= 0) {
            return SessionCoverage::notCovered(CoverageReason::HoursExhausted, $plan, $total, 0.0);
        }

        $windowEnd = $plan->currentWindowEndsAt($at);

        if ($windowEnd === null) {
            return SessionCoverage::notCovered(CoverageReason::OutsideWindow, $plan, $total, $remaining);
        }

        $hoursRunOut = CarbonImmutable::instance($at)->utc()->addSeconds((int) floor($remaining * 3600));

        return SessionCoverage::covered(
            $plan,
            $total,
            $remaining,
            $windowEnd->lessThan($hoursRunOut) ? $windowEnd : $hoursRunOut,
        );
    }
}
```

- [ ] **Step 9: Run the new test and the existing coverage suites**

Run: `php artisan test tests/Feature/Membership/SessionCoverageEvaluatorTest.php tests/Feature/Booking/WalkInPlanCoverageTest.php tests/Feature/Booking/WalkInPlanNoticeTest.php`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Domain/Membership tests/Support/InteractsWithCoworking.php tests/Feature/Membership/SessionCoverageEvaluatorTest.php
git commit -m "feat(coworking): evaluate plan coverage before a session starts

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Recording how a session started

**Files:**
- Create:
  - `app/Domain/Booking/Enums/SessionStartSource.php`
  - `database/migrations/2026_09_28_100100_add_start_source_to_sessions.php`
- Modify:
  - `app/Domain/Booking/Models/WalkinSession.php`
  - `app/Domain/Booking/Models/Booking.php`
  - `app/Domain/Booking/Services/WalkInCapacityService.php`
- Test: `tests/Feature/Booking/WalkInStartSourceTest.php`

**Interfaces:**
- Consumes: `SessionCoverageEvaluator::evaluate()`, `SessionCoverage`
- Produces:
  - `SessionStartSource` (`Reception = 'reception'`, `Scan = 'scan'`)
  - `WalkInCapacityService::start(Space $space, User $member, SessionStartSource $via = SessionStartSource::Reception, ?Kiosk $kiosk = null, bool $acceptedUncovered = false, ?SessionCoverage $coverage = null): WalkInStartResult`
  - On both models: `coworkingType(): string` (`'walkin'` / `'booking'`), `startSource(): SessionStartSource`, `kiosk(): BelongsTo`
  - WalkinSession casts: `started_via`, `accepted_uncovered` (bool), `coverage_snapshot` (array)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class WalkInStartSourceTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_reception_walk_in_records_its_source_and_the_members_coverage(): void
    {
        $member = User::factory()->create();
        $this->subscribe($member, $this->planWithHours(40));

        $session = app(WalkInCapacityService::class)->start($this->coworkingSpace(), $member)->session->fresh();

        $this->assertSame(SessionStartSource::Reception, $session->started_via);
        $this->assertNull($session->kiosk_id);
        $this->assertFalse($session->accepted_uncovered);
        $this->assertSame('covered', $session->coverage_snapshot['status']);
        $this->assertSame('walkin', $session->coworkingType());
    }

    public function test_a_scan_walk_in_records_the_kiosk_and_the_acceptance(): void
    {
        $space = $this->coworkingSpace();
        [$kiosk] = $this->pairedKiosk($space);

        $session = app(WalkInCapacityService::class)
            ->start($space, User::factory()->create(), SessionStartSource::Scan, $kiosk, true)
            ->session->fresh();

        $this->assertSame(SessionStartSource::Scan, $session->started_via);
        $this->assertSame($kiosk->id, $session->kiosk_id);
        $this->assertTrue($session->accepted_uncovered);
        $this->assertSame('no_plan', $session->coverage_snapshot['reason']);
    }

    public function test_a_booking_checked_in_before_this_feature_reads_as_reception(): void
    {
        $booking = Booking::factory()->checkedIn()->create();

        $this->assertSame(SessionStartSource::Reception, $booking->startSource());
        $this->assertSame('booking', $booking->coworkingType());
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php artisan test tests/Feature/Booking/WalkInStartSourceTest.php`
Expected: FAIL (enum `SessionStartSource` not found).

- [ ] **Step 3: Enum and migration**

```php
<?php

namespace App\Domain\Booking\Enums;

/** Who started a session: reception at the desk, or the member scanning the kiosk. */
enum SessionStartSource: string
{
    case Reception = 'reception';
    case Scan = 'scan';
}
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a session started (docs/decisions/coworking-self-service-sessions.md).
 * `coverage_snapshot` is the plan coverage evaluated at start — stored, not
 * recomputed, so what the member agreed to never drifts afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->string('started_via', 20)->default('reception')->after('checked_in_at');
            $table->foreignId('kiosk_id')->nullable()->after('started_via')->constrained('kiosks')->nullOnDelete();
            $table->boolean('accepted_uncovered')->default(false)->after('kiosk_id');
            $table->json('coverage_snapshot')->nullable()->after('accepted_uncovered');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('checked_in_via', 20)->nullable()->after('checked_in_at');
            $table->foreignId('kiosk_id')->nullable()->after('checked_in_via')->constrained('kiosks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kiosk_id');
            $table->dropColumn('checked_in_via');
        });

        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kiosk_id');
            $table->dropColumn(['started_via', 'accepted_uncovered', 'coverage_snapshot']);
        });
    }
};
```

- [ ] **Step 4: Models.**

In `WalkinSession`:
- Add `'started_via', 'kiosk_id', 'accepted_uncovered', 'coverage_snapshot'` to `$fillable`.
- Add these casts:

```php
            'started_via' => SessionStartSource::class,
            'accepted_uncovered' => 'boolean',
            'coverage_snapshot' => 'array',
```

- Add the methods (with imports for `Kiosk` and `SessionStartSource`):

```php
    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }

    public function coworkingType(): string
    {
        return 'walkin';
    }

    public function startSource(): SessionStartSource
    {
        return $this->started_via ?? SessionStartSource::Reception;
    }
```

In `Booking`:
- Add `'checked_in_via', 'kiosk_id'` to `$fillable`.
- Add the cast `'checked_in_via' => SessionStartSource::class`.
- Add the methods:

```php
    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }

    public function coworkingType(): string
    {
        return 'booking';
    }

    /** Bookings checked in before scan check-in existed have no value; reception did those. */
    public function startSource(): SessionStartSource
    {
        return $this->checked_in_via ?? SessionStartSource::Reception;
    }
```

- [ ] **Step 5: `WalkInCapacityService`.**
  - Add the constructor dependency `private readonly SessionCoverageEvaluator $coverage,` with its imports.
  - Change the signature and the create call. Everything else in `start()` (lock, business hours, capacity, membership stamp, the `plan_applies` notice) stays exactly as it is.

```php
    public function start(
        Space $space,
        User $member,
        SessionStartSource $via = SessionStartSource::Reception,
        ?Kiosk $kiosk = null,
        bool $acceptedUncovered = false,
        ?SessionCoverage $coverage = null,
    ): WalkInStartResult {
        return DB::transaction(function () use ($space, $member, $via, $kiosk, $acceptedUncovered, $coverage) {
```

and:

```php
            $session = WalkinSession::create([
                'space_id' => $locked->id,
                'user_id' => $member->id,
                'membership_id' => $membership?->id,
                'checked_in_at' => now(),
                'payment_state' => PaymentState::Unpaid,
                'started_via' => $via,
                'kiosk_id' => $kiosk?->id,
                'accepted_uncovered' => $acceptedUncovered,
                // Taken for every walk-in, reception or scan, so the reception
                // list shows the same plan picture for both. A scan passes the
                // coverage it already showed the member, so the snapshot is
                // exactly what they agreed to.
                'coverage_snapshot' => ($coverage ?? $this->coverage->evaluate($member, now()))->toArray(),
            ]);
```

- [ ] **Step 6: Register the new enum columns in the guard.** In `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`:
  - Add `use App\Domain\Booking\Enums\SessionStartSource;`.
  - Add `'checked_in_via' => SessionStartSource::class,` to the `Booking::class` entry.
  - Add `'started_via' => SessionStartSource::class,` to the `WalkinSession::class` entry.

- [ ] **Step 7: Run the new test, every walk-in suite and the guard**

Run: `php artisan test tests/Feature/Booking/WalkInStartSourceTest.php tests/Feature/Booking/WalkInCapacityServiceTest.php tests/Feature/Booking/WalkInSessionControllerTest.php tests/Feature/Booking/WalkInPlanNoticeTest.php tests/Feature/Booking/ArrivalRequestTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Domain/Booking database/migrations/2026_09_28_100100_add_start_source_to_sessions.php tests/Feature/Booking/WalkInStartSourceTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php
git commit -m "feat(coworking): record how each session started and its plan coverage

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: One booking check-in path for desk and scan

**Files:**
- Create: `app/Domain/Booking/Services/BookingCheckInService.php`
- Modify:
  - `app/Http/Controllers/Api/V1/Admin/Reception/BookingReceptionController.php` (`checkIn`)
  - `app/Http/Controllers/Api/V1/Admin/Reception/ArrivalRequestController.php` (`confirm`, matched branch)
- Test: `tests/Feature/Booking/BookingCheckInServiceTest.php`

**Interfaces — Produces:** `BookingCheckInService::checkIn(Booking $booking, SessionStartSource $via = SessionStartSource::Reception, ?Kiosk $kiosk = null): void`. It throws `ReceptionActionException` with the same keys and statuses the controller returns today.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCheckInService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class BookingCheckInServiceTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_scan_check_in_records_the_kiosk(): void
    {
        $space = $this->coworkingSpace();
        [$kiosk] = $this->pairedKiosk($space);
        $booking = Booking::factory()->create(['space_id' => $space->id]);

        app(BookingCheckInService::class)->checkIn($booking, SessionStartSource::Scan, $kiosk);

        $booking->refresh();
        $this->assertTrue($booking->checked_in_at->equalTo(now()));
        $this->assertSame(SessionStartSource::Scan, $booking->checked_in_via);
        $this->assertSame($kiosk->id, $booking->kiosk_id);
    }

    public function test_a_pending_booking_is_refused_with_the_existing_key(): void
    {
        $booking = Booking::factory()->pending()->create(['space_id' => $this->coworkingSpace()->id]);

        try {
            app(BookingCheckInService::class)->checkIn($booking);
            $this->fail('Checked in a pending booking');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.check_in_requires_approval', $e->messageKey);
            $this->assertSame(409, $e->status);
        }
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php artisan test tests/Feature/Booking/BookingCheckInServiceTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement** `app/Domain/Booking/Services/BookingCheckInService.php` (the body moved from `BookingReceptionController::checkIn`)

```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Foundation\Services\BusinessHoursService;

/**
 * The one place a booking is checked in — by reception at the desk, via a
 * confirmed arrival request, or by the member scanning the kiosk
 * (docs/decisions/coworking-self-service-sessions.md) — so all three apply
 * exactly the same rules.
 */
class BookingCheckInService
{
    public function __construct(private readonly BusinessHoursService $businessHours) {}

    public function checkIn(Booking $booking, SessionStartSource $via = SessionStartSource::Reception, ?Kiosk $kiosk = null): void
    {
        if ($booking->status === BookingStatus::Cancelled) {
            throw new ReceptionActionException('api.reception.already_cancelled', 409);
        }

        if ($booking->status === BookingStatus::Pending) {
            throw new ReceptionActionException('api.booking.check_in_requires_approval', 409);
        }

        if ($booking->status === BookingStatus::Rejected) {
            throw new ReceptionActionException('api.booking.check_in_rejected', 409);
        }

        if ($booking->checked_in_at !== null) {
            throw new ReceptionActionException('api.reception.already_checked_in', 409);
        }

        if (! $this->businessHours->isWithinBusinessHours(now(), $booking->space->building->branch)) {
            throw new ReceptionActionException('api.reception.outside_business_hours');
        }

        $booking->forceFill([
            'checked_in_at' => now(),
            'checked_in_via' => $via,
            'kiosk_id' => $kiosk?->id,
        ])->save();
    }
}
```

- [ ] **Step 4: Point the controllers at it.**

In `BookingReceptionController`, replace `checkIn()`:

```php
    public function checkIn(Booking $booking, BookingCheckInService $checkIns): JsonResponse
    {
        try {
            $checkIns->checkIn($booking);
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('booking_checked_in', $booking);

        return response()->json(['message' => __('api.reception.checked_in')]);
    }
```

Add `use App\Domain\Booking\Services\BookingCheckInService;`, then remove `use App\Domain\Foundation\Services\BusinessHoursService;` if nothing else in the file uses it (`grep -n BusinessHoursService` on that file).

In `Admin\Reception\ArrivalRequestController::confirm()`:
- Add the parameter: `public function confirm(Request $request, ArrivalRequest $arrivalRequest, BookingCheckInService $checkIns): JsonResponse`.
- Replace the matched-booking branch, keeping the same audit entry the controller call used to write:

```php
        if ($arrivalRequest->matched_booking_id !== null) {
            try {
                $checkIns->checkIn($arrivalRequest->matchedBooking);
            } catch (ReceptionActionException $e) {
                return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
            }

            $this->logSensitiveAction('booking_checked_in', $arrivalRequest->matchedBooking);

            $confirmedSpaceId = $arrivalRequest->matchedBooking->space_id;
        } else {
```

- Add the imports `ReceptionActionException` and `BookingCheckInService`.
- Remove the now-unused `BusinessHoursService` import.

- [ ] **Step 5: Run the new test and the unchanged reception suites**

Run: `php artisan test tests/Feature/Booking/BookingCheckInServiceTest.php tests/Feature/Booking/BookingReceptionControllerTest.php tests/Feature/Booking/ArrivalRequestTest.php`
Expected: PASS, with no edits to the two existing test files.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Domain/Booking/Services/BookingCheckInService.php app/Http/Controllers/Api/V1/Admin/Reception/BookingReceptionController.php app/Http/Controllers/Api/V1/Admin/Reception/ArrivalRequestController.php tests/Feature/Booking/BookingCheckInServiceTest.php
git commit -m "refactor(booking): one check-in service for desk, arrival and scan

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Starting a session by scan

**Files:**
- Create:
  - `app/Domain/Booking/Enums/CoworkingStartOutcome.php`
  - `app/Domain/Booking/DataTransferObjects/IssuedScanTicket.php`
  - `app/Domain/Booking/DataTransferObjects/CoworkingStartResult.php`
  - `app/Domain/Booking/Services/ScanTicketService.php`
  - `app/Domain/Booking/Services/CoworkingSessionService.php`
  - `app/Http/Requests/Member/StartCoworkingSessionRequest.php`
  - `app/Http/Resources/SessionCoverageResource.php`
  - `app/Http/Resources/CoworkingSessionResource.php`
  - `app/Http/Controllers/Api/V1/Member/CoworkingSessionController.php`
- Modify:
  - `app/Domain/Booking/Services/ArrivalRequestMatcher.php`
  - `routes/api/v1/member.php`
- Test: `tests/Feature/Booking/CoworkingSessionStartTest.php`

**Interfaces:**
- Consumes: `KioskCodeVerifier::verify`, `BookingCheckInService::checkIn`, `SessionCoverageEvaluator::evaluate`, `WalkInCapacityService::start(…, $coverage)`, `startSource()`, `coworkingType()`
- Produces:
  - `CoworkingSessionService::start(User $member, ?string $qrValue = null, ?string $scanTicket = null): CoworkingStartResult`
  - `CoworkingSessionService::openSessionFor(User $member): Booking|WalkinSession|null`
  - `ArrivalRequestMatcher::matchBookingFor(User, Branch, CarbonInterface, ?Space $space = null)`
  - `ScanTicketService::issue(User, Kiosk): IssuedScanTicket{string $ticket, CarbonInterface $expiresAt}`
  - `ScanTicketService::redeem(string, User): Kiosk`
  - `CoworkingSessionResource` (wraps either model); `SessionCoverageResource` (wraps a snapshot array)
  - Routes `POST member/coworking-sessions`, `GET member/coworking-sessions/current`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Services\PlanHourCoverageService;
use App\Domain\Membership\Services\ResourceQuotaService;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class CoworkingSessionStartTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private Space $space;

    private Kiosk $kiosk;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
        $this->space = $this->coworkingSpace();
        [$this->kiosk, $this->secret] = $this->pairedKiosk($this->space);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>|null  $body  defaults to scanning the kiosk's current code */
    private function scan(?array $body = null): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson(
            '/api/v1/member/coworking-sessions',
            $body ?? ['qr_value' => $this->qrFor($this->kiosk, $this->secret)],
        );
    }

    private function accept(string $ticket): TestResponse
    {
        return $this->scan(['scan_ticket' => $ticket, 'accept_uncovered' => true]);
    }

    public function test_a_member_the_plan_covers_starts_at_once(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));

        $this->scan()
            ->assertCreated()
            ->assertJsonPath('data.type', 'walkin')
            ->assertJsonPath('data.started_via', 'scan')
            ->assertJsonPath('data.coverage.status', 'covered')
            ->assertJsonPath('data.accepted_uncovered', false);

        $session = WalkinSession::sole();
        $this->assertSame($member->id, $session->user_id);
        $this->assertSame($this->kiosk->id, $session->kiosk_id);
    }

    public function test_a_member_without_a_plan_is_asked_first_and_nothing_starts(): void
    {
        $this->actingAsMember();

        $response = $this->scan();

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'no_plan')
            ->assertJsonPath('hourly_rate_points', 100)
            ->assertJsonPath('message', 'Your plan will not cover this session (you have no active plan). It will be billed at 100 points per hour.');
        $this->assertNotEmpty($response->json('scan_ticket'));
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_accepting_with_the_ticket_starts_a_paid_session(): void
    {
        $this->actingAsMember();

        $this->accept($this->scan()->json('scan_ticket'))
            ->assertCreated()
            ->assertJsonPath('data.accepted_uncovered', true)
            ->assertJsonPath('data.coverage.reason', 'no_plan');
    }

    public function test_accepting_the_same_ticket_twice_does_not_start_a_second_session(): void
    {
        $this->actingAsMember();
        $ticket = $this->scan()->json('scan_ticket');

        $id = $this->accept($ticket)->assertCreated()->json('data.id');
        $this->accept($ticket)->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, WalkinSession::count());
    }

    public function test_used_up_hours_are_explained_before_starting(): void
    {
        $member = $this->actingAsMember();
        $membership = $this->subscribe($member, $this->planWithHours(1));
        app(ResourceQuotaService::class)->consume(app(PlanHourCoverageService::class)->hourGrantFor($membership), $membership, 1.0, $member);

        $this->scan()
            ->assertStatus(422)
            ->assertJsonPath('reason', 'hours_exhausted')
            ->assertJsonPath('coverage.hours_remaining', 0.0);
    }

    public function test_the_ticket_expires_after_two_minutes(): void
    {
        $this->actingAsMember();
        $ticket = $this->scan()->json('scan_ticket');
        $this->travel(121)->seconds();

        $this->accept($ticket)->assertStatus(422)->assertExactJson(['message' => 'This confirmation has expired. Scan the kiosk code again.']);
    }

    public function test_another_members_ticket_is_refused(): void
    {
        $this->actingAsMember();
        $ticket = $this->scan()->json('scan_ticket');
        $this->actingAsMember();

        $this->accept($ticket)->assertStatus(422);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_coverage_is_evaluated_again_when_the_member_accepts(): void
    {
        $member = $this->actingAsMember();
        $ticket = $this->scan()->json('scan_ticket');
        $this->subscribe($member, $this->planWithHours(40)); // renewed in between

        $this->accept($ticket)
            ->assertCreated()
            ->assertJsonPath('data.coverage.status', 'covered')
            ->assertJsonPath('data.accepted_uncovered', false);
    }

    public function test_todays_coworking_booking_is_checked_in_instead_of_a_walk_in(): void
    {
        $member = $this->actingAsMember();
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => $this->space->id,
            'start_at' => Carbon::parse('2026-08-17 11:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 13:00', 'Asia/Damascus')->utc(),
        ]);

        $this->scan()->assertCreated()->assertJsonPath('data.type', 'booking')->assertJsonPath('data.id', $booking->id);

        $booking->refresh();
        $this->assertNotNull($booking->checked_in_at);
        $this->assertSame(SessionStartSource::Scan, $booking->checked_in_via);
        $this->assertSame($this->kiosk->id, $booking->kiosk_id);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_a_booking_awaiting_approval_is_not_bypassed(): void
    {
        $member = $this->actingAsMember();
        $booking = Booking::factory()->pending()->create([
            'user_id' => $member->id,
            'space_id' => $this->space->id,
            'start_at' => Carbon::parse('2026-08-17 11:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 13:00', 'Asia/Damascus')->utc(),
        ]);

        $this->scan()->assertStatus(409)->assertExactJson(['message' => 'Your booking is awaiting approval. Please see reception.']);
        $this->assertNull($booking->fresh()->checked_in_at);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_scanning_with_a_session_already_open_returns_it(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $id = $this->scan()->assertCreated()->json('data.id');
        $this->travel(5)->minutes();

        $this->scan()->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, WalkinSession::count());
    }

    public function test_a_kiosk_whose_space_is_under_maintenance_starts_nothing(): void
    {
        $this->actingAsMember();
        $this->space->forceFill(['status' => OperationalStatus::Maintenance])->save();

        $this->scan()->assertStatus(422)->assertExactJson(['message' => 'Coworking sessions are not available at this kiosk right now.']);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_an_invalid_code_is_refused(): void
    {
        $this->actingAsMember();

        $this->scan(['qr_value' => 'add-cowork:v1:'.$this->kiosk->id.':00000000'])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This code is not valid. Scan the code currently shown on the kiosk.']);
    }

    public function test_outside_business_hours_nothing_is_offered(): void
    {
        $this->actingAsMember();
        Carbon::setTestNow(Carbon::parse('2026-08-17 21:00:00', 'Asia/Damascus')->utc());

        $this->scan()->assertStatus(422)->assertExactJson(['message' => 'This action is not available outside business hours.']);
    }

    public function test_a_full_space_refuses_the_walk_in(): void
    {
        $space = $this->coworkingSpace(capacity: 1);
        [$kiosk, $secret] = $this->pairedKiosk($space);
        WalkinSession::factory()->create(['space_id' => $space->id]);
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));

        $this->scan(['qr_value' => $this->qrFor($kiosk, $secret)])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This space has no available capacity right now.']);
    }

    public function test_the_timer_endpoint_returns_the_open_session_or_null(): void
    {
        $member = $this->actingAsMember();
        $this->getJson('/api/v1/member/coworking-sessions/current')->assertOk()->assertJsonPath('data', null);

        $this->subscribe($member, $this->planWithHours(40));
        $id = $this->scan()->json('data.id');

        $this->getJson('/api/v1/member/coworking-sessions/current')
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.coverage.covered_until', Carbon::parse('2026-08-17 20:00', 'Asia/Damascus')->utc()->toIso8601String());
    }

    public function test_only_members_can_start_sessions(): void
    {
        $this->actingAsOperations();

        $this->postJson('/api/v1/member/coworking-sessions', ['qr_value' => 'x'])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php artisan test tests/Feature/Booking/CoworkingSessionStartTest.php`
Expected: FAIL, 404 on `/api/v1/member/coworking-sessions`.

- [ ] **Step 3: Narrow the matcher to one space.** In `ArrivalRequestMatcher::matchBookingFor`:
  - Add the import `use App\Domain\Foundation\Models\Space;`.
  - Add the trailing parameter `?Space $space = null`.
  - Add, before `->orderBy('start_at')`:

```php
            // The kiosk scan looks only at its own coworking space; the
            // arrival flow keeps matching across the whole branch.
            ->when($space !== null, fn ($query) => $query->where('space_id', $space->id))
```

- [ ] **Step 4: Enum and DTOs**

```php
<?php

namespace App\Domain\Booking\Enums;

enum CoworkingStartOutcome: string
{
    case Started = 'started';
    case Existing = 'existing';
    case ConfirmationRequired = 'confirmation_required';
}
```

```php
<?php

namespace App\Domain\Booking\DataTransferObjects;

use Carbon\CarbonInterface;

class IssuedScanTicket
{
    public function __construct(
        public readonly string $ticket,
        public readonly CarbonInterface $expiresAt,
    ) {}
}
```

```php
<?php

namespace App\Domain\Booking\DataTransferObjects;

use App\Domain\Booking\Enums\CoworkingStartOutcome;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Membership\ValueObjects\SessionCoverage;

/** What a scan produced: a new session, the one already open, or a question for the member. */
final class CoworkingStartResult
{
    private function __construct(
        public readonly CoworkingStartOutcome $outcome,
        public readonly Booking|WalkinSession|null $session = null,
        public readonly ?SessionCoverage $coverage = null,
        public readonly ?IssuedScanTicket $ticket = null,
        public readonly ?int $hourlyRatePoints = null,
    ) {}

    public static function started(Booking|WalkinSession $session, ?SessionCoverage $coverage = null): self
    {
        return new self(CoworkingStartOutcome::Started, $session, $coverage);
    }

    public static function existing(Booking|WalkinSession $session): self
    {
        return new self(CoworkingStartOutcome::Existing, $session);
    }

    public static function confirmationRequired(SessionCoverage $coverage, IssuedScanTicket $ticket, int $hourlyRatePoints): self
    {
        return new self(CoworkingStartOutcome::ConfirmationRequired, null, $coverage, $ticket, $hourlyRatePoints);
    }
}
```

- [ ] **Step 5: Scan tickets** `app/Domain/Booking/Services/ScanTicketService.php`

```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\DataTransferObjects\IssuedScanTicket;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * The two minutes a member gets to read "your plan won't cover this" and
 * accept — longer than the kiosk code itself stays valid. Encrypted and
 * authenticated with APP_KEY; nothing stored.
 */
class ScanTicketService
{
    public const TTL_SECONDS = 120;

    public function issue(User $member, Kiosk $kiosk): IssuedScanTicket
    {
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        return new IssuedScanTicket(
            Crypt::encryptString(json_encode(['u' => $member->id, 'k' => $kiosk->id, 'e' => $expiresAt->getTimestamp()])),
            $expiresAt,
        );
    }

    public function redeem(string $ticket, User $member): Kiosk
    {
        try {
            $payload = json_decode(Crypt::decryptString($ticket), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new ReceptionActionException('api.coworking.ticket_expired');
        }

        if (($payload['u'] ?? null) !== $member->id || ($payload['e'] ?? 0) < now()->getTimestamp()) {
            throw new ReceptionActionException('api.coworking.ticket_expired');
        }

        // Re-read, not trusted from the ticket: a kiosk revoked or
        // deactivated in the last two minutes must stop starting sessions.
        $kiosk = Kiosk::query()
            ->whereKey($payload['k'] ?? 0)
            ->where('is_active', true)
            ->whereNotNull('paired_at')
            ->first();

        return $kiosk ?? throw new ReceptionActionException('api.coworking.invalid_code');
    }
}
```

- [ ] **Step 6: Orchestration** `app/Domain/Booking/Services/CoworkingSessionService.php`

```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\DataTransferObjects\CoworkingStartResult;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Services\SessionCoverageEvaluator;
use Illuminate\Support\Facades\DB;

/**
 * Self-service coworking sessions (docs/decisions/coworking-self-service-sessions.md).
 * Orchestration only: every rule that decides whether a session may start —
 * business hours, capacity, booking status — stays in the service that
 * already enforces it for reception, so the desk and the scan cannot
 * disagree.
 */
class CoworkingSessionService
{
    public function __construct(
        private readonly KioskCodeVerifier $codes,
        private readonly ScanTicketService $tickets,
        private readonly ArrivalRequestMatcher $bookings,
        private readonly BookingCheckInService $checkIns,
        private readonly SessionCoverageEvaluator $coverage,
        private readonly WalkInCapacityService $walkIns,
        private readonly BusinessHoursService $businessHours,
    ) {}

    /**
     * Exactly one of $qrValue (the first scan) or $scanTicket (the member
     * accepting an uncovered session) is given — StartCoworkingSessionRequest
     * enforces it. A ticket is only issued after the member was shown why the
     * plan will not cover them, so presenting one is the acceptance.
     */
    public function start(User $member, ?string $qrValue = null, ?string $scanTicket = null): CoworkingStartResult
    {
        $kiosk = $qrValue !== null
            ? $this->codes->verify($qrValue)
            : $this->tickets->redeem($scanTicket, $member);

        $space = $kiosk->space;

        if ($space->space_type !== SpaceType::CoSpace || $space->status !== OperationalStatus::Active) {
            throw new ReceptionActionException('api.coworking.unavailable');
        }

        // Checked here as well as inside the walk-in/check-in services, so a
        // member is never asked to accept a paid session that would then be
        // refused for the hour.
        if (! $this->businessHours->isWithinBusinessHours(now(), $space->building->branch)) {
            throw new ReceptionActionException('api.reception.outside_business_hours');
        }

        return DB::transaction(function () use ($member, $kiosk, $space, $scanTicket) {
            // Serializes two scans by the same member (a double tap), so the
            // open-session check below cannot be raced into two sessions.
            User::query()->whereKey($member->id)->lockForUpdate()->first();

            if (($open = $this->openSessionFor($member)) !== null) {
                return CoworkingStartResult::existing($open);
            }

            $booking = $this->bookings->matchBookingFor($member, $space->building->branch, now(), $space);

            if ($booking !== null) {
                // No fallback to a walk-in: that would route around approval
                // (docs/decisions/kiosk-display.md, same rule).
                if ($booking->status === BookingStatus::Pending) {
                    throw new ReceptionActionException('api.coworking.booking_pending_approval', 409);
                }

                $this->checkIns->checkIn($booking, SessionStartSource::Scan, $kiosk);

                return CoworkingStartResult::started($booking->fresh());
            }

            $coverage = $this->coverage->evaluate($member, now());

            if (! $coverage->isCovered() && $scanTicket === null) {
                return CoworkingStartResult::confirmationRequired(
                    $coverage,
                    $this->tickets->issue($member, $kiosk),
                    $space->hourly_rate_points,
                );
            }

            $result = $this->walkIns->start($space, $member, SessionStartSource::Scan, $kiosk, ! $coverage->isCovered(), $coverage);

            return CoworkingStartResult::started($result->session->fresh(), $coverage);
        });
    }

    /** The member's checked-in, not yet checked-out session on a coworking space, if any. */
    public function openSessionFor(User $member): Booking|WalkinSession|null
    {
        $onCoworking = fn ($query) => $query->where('space_type', SpaceType::CoSpace);

        return WalkinSession::query()
            ->where('user_id', $member->id)
            ->whereNull('checked_out_at')
            ->whereHas('space', $onCoworking)
            ->latest('checked_in_at')
            ->first()
            ?? Booking::query()
                ->where('user_id', $member->id)
                ->where('status', BookingStatus::Confirmed)
                ->whereNotNull('checked_in_at')
                ->whereNull('checked_out_at')
                ->whereHas('space', $onCoworking)
                ->latest('checked_in_at')
                ->first();
    }
}
```

- [ ] **Step 7: Request and resources**

`app/Http/Requests/Member/StartCoworkingSessionRequest.php`:

```php
<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class StartCoworkingSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Either the scanned code, or the ticket from a confirmation_required
     * answer together with the member's explicit acceptance.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'qr_value' => ['required_without:scan_ticket', 'prohibits:scan_ticket', 'string', 'max:255'],
            'scan_ticket' => ['required_without:qr_value', 'string'],
            'accept_uncovered' => ['exclude_without:scan_ticket', 'accepted'],
        ];
    }
}
```

`app/Http/Resources/SessionCoverageResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renders a SessionCoverage::toArray() snapshot. The plan name is stored in
 * both languages; the reader's is picked here with HasTranslations' own
 * fallback order (current locale → en → ar).
 */
class SessionCoverageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->resource;
        $plan = $snapshot['plan'] ?? null;

        if (is_array($plan) && is_array($plan['name'] ?? null)) {
            $names = $plan['name'];
            $plan['name'] = $names[app()->getLocale()] ?? $names['en'] ?? $names['ar'] ?? null;
        }

        return array_merge($snapshot, ['plan' => $plan]);
    }
}
```

`app/Http/Resources/CoworkingSessionResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Domain\Booking\Models\WalkinSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The member app's view of a coworking session — either a walk-in or a checked-in booking. */
class CoworkingSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $session = $this->resource;
        $isWalkin = $session instanceof WalkinSession;

        return [
            'type' => $session->coworkingType(),
            'id' => $session->id,
            'space' => [
                'id' => $session->space->id,
                'name' => $session->space->translate('name'),
            ],
            'checked_in_at' => $session->checked_in_at?->toIso8601String(),
            'checked_out_at' => $session->checked_out_at?->toIso8601String(),
            'started_via' => $session->startSource()->value,
            'hourly_rate_points' => $session->space->hourly_rate_points,
            // Bookings carry their plan benefit in the price fixed at creation.
            'coverage' => $isWalkin && $session->coverage_snapshot !== null
                ? new SessionCoverageResource($session->coverage_snapshot)
                : null,
            'accepted_uncovered' => $isWalkin && $session->accepted_uncovered,
        ];
    }
}
```

- [ ] **Step 8: Controller** `app/Http/Controllers/Api/V1/Member/CoworkingSessionController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Enums\CoworkingStartOutcome;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Services\CoworkingSessionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StartCoworkingSessionRequest;
use App\Http\Resources\CoworkingSessionResource;
use App\Http\Resources\SessionCoverageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** docs/decisions/coworking-self-service-sessions.md */
class CoworkingSessionController extends Controller
{
    use LogsSensitiveActions;

    public function start(StartCoworkingSessionRequest $request, CoworkingSessionService $sessions): JsonResponse
    {
        try {
            $result = $sessions->start(
                $request->user(),
                $request->validated('qr_value'),
                $request->validated('scan_ticket'),
            );
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        if ($result->outcome === CoworkingStartOutcome::ConfirmationRequired) {
            // Same message-plus-data 422 shape as api.booking.wallet_choice_required:
            // the client must answer, then re-submit.
            return response()->json([
                'message' => __('api.coworking.confirmation_required', [
                    'reason' => __('api.coworking.reasons.'.$result->coverage->reason->value),
                    'rate' => $result->hourlyRatePoints,
                ]),
                'reason' => $result->coverage->reason->value,
                'coverage' => new SessionCoverageResource($result->coverage->toArray()),
                'hourly_rate_points' => $result->hourlyRatePoints,
                'scan_ticket' => $result->ticket->ticket,
                'ticket_expires_at' => $result->ticket->expiresAt->toIso8601String(),
            ], 422);
        }

        if ($result->outcome === CoworkingStartOutcome::Existing) {
            return response()->json(['data' => new CoworkingSessionResource($result->session)]);
        }

        $this->logSensitiveAction('coworking_session_started', $result->session, ['via' => 'scan']);

        return response()->json(['data' => new CoworkingSessionResource($result->session)], 201);
    }

    public function current(Request $request, CoworkingSessionService $sessions): JsonResponse
    {
        $open = $sessions->openSessionFor($request->user());

        return response()->json(['data' => $open === null ? null : new CoworkingSessionResource($open)]);
    }
}
```

- [ ] **Step 9: Routes.** In `routes/api/v1/member.php`, add `use App\Http\Controllers\Api\V1\Member\CoworkingSessionController;` and, after the `access/unlock` route:

```php
// Self-service coworking sessions (docs/decisions/coworking-self-service-sessions.md):
// scan the kiosk's rotating code to start, read the running session for the
// timer. Static segments are declared before any {type}/{id} wildcard.
Route::post('coworking-sessions', [CoworkingSessionController::class, 'start'])->middleware('throttle:10,1');
Route::get('coworking-sessions/current', [CoworkingSessionController::class, 'current']);
```

- [ ] **Step 10: Run the new test and the arrival suite (matcher changed)**

Run: `php artisan test tests/Feature/Booking/CoworkingSessionStartTest.php tests/Feature/Booking/ArrivalRequestTest.php`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Domain/Booking app/Http/Requests/Member/StartCoworkingSessionRequest.php app/Http/Resources/SessionCoverageResource.php app/Http/Resources/CoworkingSessionResource.php app/Http/Controllers/Api/V1/Member/CoworkingSessionController.php routes/api/v1/member.php tests/Feature/Booking/CoworkingSessionStartTest.php
git commit -m "feat(coworking): start a coworking session by scanning the kiosk

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Ending a session — member request, reception confirmation

**Files:**
- Create:
  - `database/migrations/2026_09_28_100200_create_session_end_requests_table.php`
  - `database/migrations/2026_09_28_100300_alias_session_models_in_activity_log.php`
  - `app/Domain/Booking/Enums/SessionEndRequestStatus.php`
  - `app/Domain/Booking/Models/SessionEndRequest.php`
  - `app/Domain/Booking/Concerns/HasSessionActivity.php`
  - `app/Domain/Booking/Services/SessionEndService.php`
  - `app/Http/Resources/SessionEndRequestResource.php`
  - `app/Http/Requests/Admin/Reception/ConfirmSessionEndRequest.php`
  - `app/Http/Controllers/Api/V1/Admin/Reception/SessionEndRequestController.php`
- Modify:
  - `app/Providers/MorphMapServiceProvider.php`
  - `app/Domain/Booking/Models/{WalkinSession,Booking}.php` (use the trait)
  - `app/Domain/Booking/Services/SessionClosureService.php` (supersede)
  - `app/Console/Commands/CloseOverdueReceptionSessions.php` (close at `requested_at`)
  - `CoworkingSessionController.php` (`requestEnd`, `cancelEnd`)
  - `CoworkingSessionResource.php` (`end_request`)
  - `routes/api/v1/member.php`, `routes/api/v1/admin.php`
- Test: `tests/Feature/Booking/CoworkingSessionEndTest.php`

**Interfaces:**
- Consumes: `CoworkingSessionService::openSessionFor`, `SessionClosureService::closeOut`, `NotificationService::sendToUser`, `coworkingType()`
- Produces:
  - `SessionEndRequestStatus`: `pending|confirmed|cancelled|superseded`
  - Trait `HasSessionActivity`: `endRequests(): MorphMany`, `pendingEndRequest(): MorphOne`
  - `SessionEndService::request(User): SessionEndRequest` (`wasRecentlyCreated` tells new from existing)
  - `SessionEndService::cancel(User): void`
  - `SessionEndService::confirm(SessionEndRequest, User $operator, ?CarbonInterface $checkedOutAt): SessionEndRequest`
  - Morph aliases `booking`, `walkin_session`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Models\SessionEndRequest;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Notifications\GeneralNotification;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class CoworkingSessionEndTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
        $this->space = $this->coworkingSpace(hourlyRatePoints: 100);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A member with a walk-in on the coworking space since 08:00, acting as that member. */
    private function memberInSession(): array
    {
        $member = $this->actingAsMember();
        $session = WalkinSession::factory()->create([
            'space_id' => $this->space->id,
            'user_id' => $member->id,
            'checked_in_at' => Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc(),
        ]);

        return [$member, $session];
    }

    private function requestEnd(): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/coworking-sessions/current/end-request');
    }

    private function confirm(SessionEndRequest $endRequest, array $body = []): \Illuminate\Testing\TestResponse
    {
        $this->actingAsOperations();

        return $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/session-end-requests/{$endRequest->id}/confirm", $body);
    }

    public function test_a_member_requests_an_end_once(): void
    {
        [, $session] = $this->memberInSession();

        $id = $this->requestEnd()->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->requestEnd()->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, $session->endRequests()->count());
        $this->getJson('/api/v1/member/coworking-sessions/current')->assertJsonPath('data.end_request.id', $id);
    }

    public function test_without_an_open_session_there_is_nothing_to_end(): void
    {
        $this->actingAsMember();

        $this->requestEnd()->assertStatus(422)->assertExactJson(['message' => 'You have no active session.']);
    }

    public function test_pressing_end_after_reception_already_checked_out_changes_nothing(): void
    {
        [, $session] = $this->memberInSession();
        $session->forceFill(['checked_out_at' => now()])->save();

        $this->requestEnd()->assertStatus(422)->assertExactJson(['message' => 'You have no active session.']);
        $this->assertSame(0, SessionEndRequest::count());
    }

    public function test_the_member_can_withdraw_a_pending_request(): void
    {
        $this->memberInSession();
        $this->requestEnd()->assertCreated();

        $this->withHeader('lang', 'en')->deleteJson('/api/v1/member/coworking-sessions/current/end-request')
            ->assertOk()->assertExactJson(['message' => 'End request withdrawn.']);

        $this->assertSame(SessionEndRequestStatus::Cancelled, SessionEndRequest::sole()->status);
    }

    public function test_confirming_bills_up_to_the_moment_the_member_pressed(): void
    {
        [$member, $session] = $this->memberInSession();
        $this->requestEnd();
        $this->travel(20)->minutes(); // reception is busy

        $this->confirm(SessionEndRequest::sole())->assertOk()->assertExactJson(['message' => 'Session ended.']);

        $session->refresh();
        $this->assertTrue($session->checked_out_at->equalTo(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')));
        $this->assertSame('20.00', (string) $session->amount_owed); // 2 h at 100 points, 0.10 USD/point
        $this->assertSame(SessionEndRequestStatus::Confirmed, SessionEndRequest::sole()->status);
        Notification::assertSentTo($member, GeneralNotification::class);
    }

    public function test_reception_can_set_a_different_time(): void
    {
        [, $session] = $this->memberInSession();
        $this->requestEnd();
        $this->travel(30)->minutes();

        $this->confirm(SessionEndRequest::sole(), ['checked_out_at' => '2026-08-17 10:30:00'])->assertOk();

        $this->assertTrue($session->fresh()->checked_out_at->equalTo(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus')));
    }

    public function test_a_confirmed_request_cannot_be_confirmed_again(): void
    {
        $this->memberInSession();
        $this->requestEnd();
        $endRequest = SessionEndRequest::sole();
        $this->confirm($endRequest)->assertOk();

        $this->confirm($endRequest)->assertStatus(409)->assertExactJson(['message' => 'This end request is no longer pending.']);
    }

    public function test_a_confirmation_the_closure_refuses_leaves_everything_as_it_was(): void
    {
        [, $session] = $this->memberInSession();
        $this->requestEnd();
        $endRequest = SessionEndRequest::sole();
        $endRequest->forceFill(['requested_at' => Carbon::parse('2026-08-17 20:30:00', 'Asia/Damascus')->utc()])->save();
        Carbon::setTestNow(Carbon::parse('2026-08-17 20:45:00', 'Asia/Damascus')->utc());

        $this->confirm($endRequest)->assertStatus(422);

        $this->assertSame(SessionEndRequestStatus::Pending, $endRequest->fresh()->status);
        $this->assertNull($session->fresh()->checked_out_at);
    }

    public function test_a_direct_checkout_at_the_desk_supersedes_the_request(): void
    {
        [, $session] = $this->memberInSession();
        $this->requestEnd();
        $this->actingAsOperations();

        $this->postJson("/api/v1/admin/reception/walk-ins/{$session->id}/check-out", ['checked_out_at' => '2026-08-17 10:00:00'])->assertOk();

        $this->assertSame(SessionEndRequestStatus::Superseded, SessionEndRequest::sole()->status);
    }

    public function test_auto_close_bills_to_the_pending_request_not_to_closing_time(): void
    {
        [, $session] = $this->memberInSession();
        $this->travel(5)->hours(); // 15:00
        $this->requestEnd();
        Carbon::setTestNow(Carbon::parse('2026-08-17 20:30:00', 'Asia/Damascus')->utc());

        $this->artisan('reception:close-overdue-sessions')->assertExitCode(0);

        $this->assertTrue($session->fresh()->checked_out_at->equalTo(Carbon::parse('2026-08-17 15:00:00', 'Asia/Damascus')));
        $this->assertSame(SessionEndRequestStatus::Superseded, SessionEndRequest::sole()->status);
    }

    public function test_a_member_cannot_confirm(): void
    {
        $this->memberInSession();
        $this->requestEnd();

        $this->postJson('/api/v1/admin/reception/session-end-requests/'.SessionEndRequest::sole()->id.'/confirm')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php artisan test tests/Feature/Booking/CoworkingSessionEndTest.php`
Expected: FAIL, 404 on `/api/v1/member/coworking-sessions/current/end-request`.

- [ ] **Step 3: Migrations**

`2026_09_28_100200_create_session_end_requests_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member pressing "end session" in the app; reception confirms it
 * (docs/decisions/coworking-self-service-sessions.md). `requested_at` is
 * what the session is billed to by default, so the member never pays for
 * reception's delay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_end_requests', function (Blueprint $table) {
            $table->id();
            $table->morphs('sessionable');
            $table->foreignId('user_id')->constrained('users');
            $table->dateTime('requested_at');
            $table->string('status', 20)->default('pending');
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_end_requests');
    }
};
```

`2026_09_28_100300_alias_session_models_in_activity_log.php`: copy `database/migrations/2026_09_27_100005_alias_form_models_in_activity_log.php` verbatim, changing only the docblock's first sentence and the constant:

```php
    private const ALIASES = [
        'booking' => 'App\\Domain\\Booking\\Models\\Booking',
        'walkin_session' => 'App\\Domain\\Booking\\Models\\WalkinSession',
    ];
```

- [ ] **Step 4: Morph aliases.** In `MorphMapServiceProvider::boot()`:
  - Add `'booking' => Booking::class,` and `'walkin_session' => WalkinSession::class,`, with their imports.
  - Extend the class docblock's first paragraph with: "…and the coworking-session records that end requests and resource consumptions point at (`session_end_requests.sessionable_type`, `resource_consumptions.sessionable_type`)."

- [ ] **Step 5: Enum, model, trait**

```php
<?php

namespace App\Domain\Booking\Enums;

enum SessionEndRequestStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    /** The session closed another way — at the desk or by auto-close — first. */
    case Superseded = 'superseded';
}
```

```php
<?php

namespace App\Domain\Booking\Models;

use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SessionEndRequest extends Model
{
    protected $fillable = ['user_id', 'requested_at', 'status'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'status' => SessionEndRequestStatus::class,
            'resolved_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    /** A Booking or a WalkinSession. */
    public function sessionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

```php
<?php

namespace App\Domain\Booking\Concerns;

use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Models\SessionEndRequest;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/** What a coworking session — Booking or WalkinSession — carries besides its time. */
trait HasSessionActivity
{
    public function endRequests(): MorphMany
    {
        return $this->morphMany(SessionEndRequest::class, 'sessionable');
    }

    public function pendingEndRequest(): MorphOne
    {
        return $this->morphOne(SessionEndRequest::class, 'sessionable')
            ->where('status', SessionEndRequestStatus::Pending);
    }
}
```

Add `use HasSessionActivity;` (with the import) to both `WalkinSession` and `Booking`.

Register the new status column in the guard. In `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`:
- Add the imports `SessionEndRequestStatus` and `SessionEndRequest`.
- Add to `EXPECTED_CASTS`:

```php
        SessionEndRequest::class => [
            'status' => SessionEndRequestStatus::class,
        ],
```

- [ ] **Step 6: Service** `app/Domain/Booking/Services/SessionEndService.php`

```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\SessionEndRequest;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The member asks to end; reception confirms
 * (docs/decisions/coworking-self-service-sessions.md). Billing itself is
 * untouched: confirmation calls the same SessionClosureService::closeOut()
 * reception's own checkout uses.
 */
class SessionEndService
{
    public function __construct(
        private readonly CoworkingSessionService $sessions,
        private readonly SessionClosureService $closures,
    ) {}

    public function request(User $member): SessionEndRequest
    {
        return DB::transaction(function () use ($member) {
            // One pending request per session, even on a double tap.
            User::query()->whereKey($member->id)->lockForUpdate()->first();

            $session = $this->sessions->openSessionFor($member)
                ?? throw new ReceptionActionException('api.coworking.no_open_session');

            return $session->pendingEndRequest()->first()
                ?? $session->endRequests()->create([
                    'user_id' => $member->id,
                    'requested_at' => now(),
                    'status' => SessionEndRequestStatus::Pending,
                ]);
        });
    }

    public function cancel(User $member): void
    {
        $session = $this->sessions->openSessionFor($member)
            ?? throw new ReceptionActionException('api.coworking.no_open_session');

        $pending = $session->pendingEndRequest()->first()
            ?? throw new ReceptionActionException('api.coworking.end_request_not_pending', 409);

        $pending->forceFill([
            'status' => SessionEndRequestStatus::Cancelled,
            'resolved_at' => now(),
        ])->save();
    }

    /**
     * $checkedOutAt defaults to the moment the member pressed. Marked
     * confirmed before closing, so the closure's "supersede whatever is still
     * pending" leaves this row alone; both happen in one transaction, so a
     * closure that refuses (past closing, before check-in) undoes the mark too.
     */
    public function confirm(SessionEndRequest $endRequest, User $operator, ?CarbonInterface $checkedOutAt = null): SessionEndRequest
    {
        return DB::transaction(function () use ($endRequest, $operator, $checkedOutAt) {
            $locked = SessionEndRequest::query()->whereKey($endRequest->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SessionEndRequestStatus::Pending) {
                throw new ReceptionActionException('api.coworking.end_request_not_pending', 409);
            }

            $time = $checkedOutAt ?? $locked->requested_at;

            $locked->forceFill([
                'status' => SessionEndRequestStatus::Confirmed,
                'resolved_by_user_id' => $operator->id,
                'resolved_at' => now(),
                'checked_out_at' => $time->copy()->setTimezone('UTC'),
            ])->save();

            $this->closures->closeOut($locked->sessionable, $time);

            return $locked;
        });
    }
}
```

- [ ] **Step 7: Closure supersedes pending requests.** In `SessionClosureService::finalizeClosure()`:
  - Add the imports `App\Domain\Booking\Enums\SessionEndRequestStatus`.
  - Add, right after `$session->forceFill($attributes)->save();`:

```php
        // An end request still waiting on this session is moot once it is
        // closed — at the desk, by auto-close, or by a confirmation that
        // already marked its own request before calling here.
        $session->pendingEndRequest()->update([
            'status' => SessionEndRequestStatus::Superseded->value,
            'resolved_at' => now(),
            'checked_out_at' => $attributes['checked_out_at'],
        ]);
```

- [ ] **Step 8: Auto-close at `requested_at`.** In `CloseOverdueReceptionSessions::closeIfOverdue()`, replace the final `autoClose` call:

```php
        // The member pressed "end" and reception never confirmed: bill to
        // that moment, not to closing time.
        $pending = $session->pendingEndRequest()->first();

        $closures->autoClose($session, $pending?->requested_at ?? $now->copy()->setTimeFromTimeString($closingTime));
```

- [ ] **Step 9: Resources, request, controllers**

`app/Http/Resources/SessionEndRequestResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionEndRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
        ];
    }
}
```

In `CoworkingSessionResource::toArray()`, add (with the import):

```php
            'end_request' => $session->pendingEndRequest === null
                ? null
                : new SessionEndRequestResource($session->pendingEndRequest),
```

`app/Http/Requests/Admin/Reception/ConfirmSessionEndRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin\Reception;

use App\Http\Requests\Concerns\InterpretsLocalTimes;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmSessionEndRequest extends FormRequest
{
    use InterpretsLocalTimes;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->interpretLocalTimes(['checked_out_at']);
    }

    /**
     * Omitted means "when the member pressed end" — the default.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'checked_out_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }
}
```

`app/Http/Controllers/Api/V1/Admin/Reception/SessionEndRequestController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\SessionEndRequest;
use App\Domain\Booking\Services\SessionEndService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\ConfirmSessionEndRequest;
use App\Services\Notification\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class SessionEndRequestController extends Controller
{
    use LogsSensitiveActions;

    public function confirm(
        ConfirmSessionEndRequest $request,
        SessionEndRequest $sessionEndRequest,
        SessionEndService $ends,
        NotificationService $notifications,
    ): JsonResponse {
        $override = $request->validated('checked_out_at');

        try {
            $ends->confirm($sessionEndRequest, $request->user(), $override === null ? null : Carbon::parse($override, 'UTC'));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $session = $sessionEndRequest->sessionable;

        $this->logSensitiveAction('session_end_confirmed', $session, ['end_request_id' => $sessionEndRequest->id]);

        $notifications->sendToUser(
            $session->user,
            'coworking.session_ended',
            ['space' => $session->space->translate('name')],
            ['session_type' => $session->coworkingType(), 'session_id' => (string) $session->id],
        );

        return response()->json(['message' => __('api.coworking.session_ended')]);
    }
}
```

In `CoworkingSessionController`, add (with imports `SessionEndService` and `SessionEndRequestResource`):

```php
    public function requestEnd(Request $request, SessionEndService $ends): JsonResponse
    {
        try {
            $endRequest = $ends->request($request->user());
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        if ($endRequest->wasRecentlyCreated) {
            $this->logSensitiveAction('session_end_requested', $endRequest);
        }

        return response()->json(['data' => new SessionEndRequestResource($endRequest)], $endRequest->wasRecentlyCreated ? 201 : 200);
    }

    public function cancelEnd(Request $request, SessionEndService $ends): JsonResponse
    {
        try {
            $ends->cancel($request->user());
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.coworking.end_request_cancelled')]);
    }
```

- [ ] **Step 10: Routes.**

In `member.php`, under the Task 8 routes:

```php
Route::post('coworking-sessions/current/end-request', [CoworkingSessionController::class, 'requestEnd']);
Route::delete('coworking-sessions/current/end-request', [CoworkingSessionController::class, 'cancelEnd']);
```

In `admin.php`, in the reception block after `reception/sessions/active` (admin|operations tier, like the rest of reception), with the import:

```php
// A member's "end session" press, confirmed here
// (docs/decisions/coworking-self-service-sessions.md).
Route::post('reception/session-end-requests/{sessionEndRequest}/confirm', [SessionEndRequestController::class, 'confirm']);
```

- [ ] **Step 11: Run the new test, closure and command suites, morph-map and local-time guards**

Run: `php artisan test tests/Feature/Booking/CoworkingSessionEndTest.php tests/Feature/Booking/SessionClosureServiceTest.php tests/Feature/Booking/CloseOverdueReceptionSessionsCommandTest.php tests/Feature/Booking/CoworkingSessionStartTest.php tests/Guards`
Expected: PASS, with the same guard results as the baseline.

- [ ] **Step 12: Commit**

```bash
./vendor/bin/pint --dirty
git add database/migrations/2026_09_28_100200_create_session_end_requests_table.php database/migrations/2026_09_28_100300_alias_session_models_in_activity_log.php app routes tests/Feature/Booking/CoworkingSessionEndTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php
git commit -m "feat(coworking): member requests the end, reception confirms it

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Linking resource consumption to the session

**Files:**
- Create: `database/migrations/2026_09_28_100400_add_sessionable_to_resource_consumptions_table.php`
- Modify:
  - `app/Domain/Membership/Models/ResourceConsumption.php`
  - `app/Domain/Booking/Concerns/HasSessionActivity.php`
  - `app/Http/Controllers/Api/V1/Admin/Reception/ResourceConsumptionController.php`
  - `app/Domain/Membership/Services/PlanHourCoverageService.php` (`consume`)
- Test: `tests/Feature/Booking/ResourceConsumptionSessionLinkTest.php`

**Interfaces — Produces:**
- `ResourceConsumption::sessionable(): MorphTo`
- `HasSessionActivity::resourceConsumptions(): MorphMany`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\ResourceConsumption;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Services\PlanHourCoverageService;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class ResourceConsumptionSessionLinkTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function printingId(): int
    {
        return ResourceType::where('slug', 'printing')->value('id');
    }

    private function planWithPrinting(): Plan
    {
        $plan = $this->planWithHours(40);
        $plan->resourceGrants()->create(['resource_type_id' => $this->printingId(), 'quantity' => 100, 'order' => 1]);

        return $plan;
    }

    private function recordPrinting(array $owner): ResourceConsumption
    {
        $this->actingAsOperations();
        $id = $this->postJson('/api/v1/admin/reception/resource-consumptions', $owner + [
            'resource_type_id' => $this->printingId(),
            'quantity' => 20,
        ])->assertCreated()->json('data.id');

        return ResourceConsumption::findOrFail($id);
    }

    public function test_printing_during_an_open_coworking_session_lands_on_that_session(): void
    {
        $member = User::factory()->create();
        $this->subscribe($member, $this->planWithPrinting());
        $session = WalkinSession::factory()->create(['space_id' => $this->coworkingSpace()->id, 'user_id' => $member->id]);

        $consumption = $this->recordPrinting(['user_id' => $member->id]);

        $this->assertTrue($consumption->sessionable->is($session));
        $this->assertSame(1, $session->resourceConsumptions()->count());
    }

    public function test_printing_with_no_open_session_is_not_linked(): void
    {
        $member = User::factory()->create();
        $this->subscribe($member, $this->planWithPrinting());

        $this->assertNull($this->recordPrinting(['user_id' => $member->id])->sessionable_id);
    }

    public function test_company_consumption_is_never_linked(): void
    {
        $company = Company::factory()->create();
        Membership::create([
            'plan_id' => $this->planWithPrinting()->id,
            'owner_type' => OwnerType::Company,
            'owner_id' => $company->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addDays(20),
        ]);

        $this->assertNull($this->recordPrinting(['company_id' => $company->id])->sessionable_id);
    }

    public function test_the_plan_hours_a_walk_in_used_are_linked_to_it(): void
    {
        $member = User::factory()->create();
        $membership = $this->subscribe($member, $this->planWithHours(40));
        $session = WalkinSession::factory()->create([
            'space_id' => $this->coworkingSpace()->id,
            'user_id' => $member->id,
            'membership_id' => $membership->id,
            'checked_in_at' => Carbon::parse('2026-08-17 08:00', 'Asia/Damascus')->utc(),
        ]);

        app(SessionClosureService::class)->closeOut($session, Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc());

        $hours = ResourceConsumption::where('membership_id', $membership->id)->sole();
        $this->assertTrue($hours->sessionable->is($session));
        $this->assertSame(PlanHourCoverageService::RESOURCE_TYPE_SLUG, $hours->grant->resourceType->slug);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php artisan test tests/Feature/Booking/ResourceConsumptionSessionLinkTest.php`
Expected: FAIL (no such column `sessionable_id`).

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The coworking session a consumption happened during, when there was one
 * (docs/decisions/coworking-self-service-sessions.md) — what the session
 * report lists. Nullable: consumption outside any session, and company
 * consumption, stay unlinked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_consumptions', function (Blueprint $table) {
            $table->nullableMorphs('sessionable');
        });
    }

    public function down(): void
    {
        Schema::table('resource_consumptions', function (Blueprint $table) {
            $table->dropMorphs('sessionable');
        });
    }
};
```

- [ ] **Step 4: Relations.**

In `ResourceConsumption`, add (with `use Illuminate\Database\Eloquent\Relations\MorphTo;`):

```php
    /** The coworking session this was used during, if any — a Booking or a WalkinSession. */
    public function sessionable(): MorphTo
    {
        return $this->morphTo();
    }
```

In `HasSessionActivity`, add (with the `ResourceConsumption` import):

```php
    public function resourceConsumptions(): MorphMany
    {
        return $this->morphMany(ResourceConsumption::class, 'sessionable');
    }
```

- [ ] **Step 5: Link at the two write sites.**

In `ResourceConsumptionController::store()`:
- Add the parameter `CoworkingSessionService $sessions` (with the import).
- Add, after the `try { $consumption = $quota->consume(...) } catch ...` block:

```php
        // Printing or internet used during an open coworking session belongs
        // on that session's report (docs/decisions/coworking-self-service-sessions.md).
        if ($ownerType === OwnerType::User) {
            $open = $sessions->openSessionFor(User::findOrFail($ownerId));

            if ($open !== null) {
                $consumption->sessionable()->associate($open)->save();
            }
        }
```

In `PlanHourCoverageService::consume()`, capture the returned row and link it:

```php
        $consumption = $this->quotas->consume(
            $grant,
            $membership,
            round($seconds / 3600, 2),
            $session->user,
            "Walk-in session #{$session->id}",
        );

        // A structured link to the session it paid for; the note was the only
        // link before.
        $consumption->sessionable()->associate($session)->save();

        return $seconds;
```

- [ ] **Step 6: Run the new test and the consumption / coverage suites**

Run: `php artisan test tests/Feature/Booking/ResourceConsumptionSessionLinkTest.php tests/Feature/Booking/ResourceConsumptionControllerTest.php tests/Feature/Booking/WalkInPlanCoverageTest.php tests/Feature/Membership/ResourceQuotaServiceTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint --dirty
git add database/migrations/2026_09_28_100400_add_sessionable_to_resource_consumptions_table.php app tests/Feature/Booking/ResourceConsumptionSessionLinkTest.php
git commit -m "feat(coworking): link resource consumption to the session it happened in

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: The session report

**Files:**
- Create: `app/Domain/Booking/Services/SessionReportBuilder.php`
- Modify:
  - `CoworkingSessionService.php` (add `find`)
  - `CoworkingSessionController.php` (add `report`)
  - `ReceptionSessionsController.php` (add `report`)
  - `routes/api/v1/member.php`, `routes/api/v1/admin.php`
- Test: `tests/Feature/Booking/SessionReportTest.php`

**Interfaces:**
- Consumes: `AmountCalculator::forRange(Space, CarbonInterface, CarbonInterface): int`, `PlanHourCoverageService::hourGrantFor`, `ResourceQuotaService::remainingFor`, `resourceConsumptions()`, `coworkingType()`, `startSource()`
- Produces:
  - `CoworkingSessionService::find(string $type, int $id): Booking|WalkinSession|null`
  - `SessionReportBuilder::build(Booking|WalkinSession $session): array{session, lines, plan, totals}`
  - Line shape: `{kind, label, quantity, unit, source: 'plan'|'charge'|null, points, amount, recorded_at}`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Services\ResourceQuotaService;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class SessionReportTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 13:00:00', 'Asia/Damascus')->utc());
        $this->space = $this->coworkingSpace(hourlyRatePoints: 100);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse("2026-08-17 {$time}", 'Asia/Damascus')->utc();
    }

    private function walkIn(User $member, ?Membership $membership, string $in, ?string $out = null): WalkinSession
    {
        $session = WalkinSession::factory()->create([
            'space_id' => $this->space->id,
            'user_id' => $member->id,
            'membership_id' => $membership?->id,
            'checked_in_at' => $this->at($in),
        ]);

        if ($out !== null) {
            app(SessionClosureService::class)->closeOut($session, $this->at($out));
        }

        return $session->fresh();
    }

    private function report(string $type, int $id): array
    {
        return $this->withHeader('lang', 'en')->getJson("/api/v1/member/coworking-sessions/{$type}/{$id}/report")->assertOk()->json('data');
    }

    public function test_a_session_the_plan_covered_in_full_is_one_plan_line_and_nothing_due(): void
    {
        $member = $this->actingAsMember();
        $membership = $this->subscribe($member, $this->planWithHours(40));
        $session = $this->walkIn($member, $membership, '09:00', '12:00');

        $report = $this->report('walkin', $session->id);

        $this->assertCount(1, $report['lines']);
        $this->assertSame(['time', 'plan', 3.0], [$report['lines'][0]['kind'], $report['lines'][0]['source'], $report['lines'][0]['quantity']]);
        $this->assertSame(3.0, $report['plan']['hours_used']);
        $this->assertSame(37.0, $report['plan']['hours_remaining']);
        $this->assertSame('0.00', $report['totals']['amount_owed']);
        $this->assertSame('paid', $report['totals']['payment_state']);
        $this->assertSame(10800, $report['session']['duration_seconds']);
    }

    public function test_a_partly_covered_session_splits_plan_time_from_charged_time(): void
    {
        $member = $this->actingAsMember();
        $session = $this->walkIn($member, $this->subscribe($member, $this->planWithHours(2)), '09:00', '12:00');

        $lines = $this->report('walkin', $session->id)['lines'];

        $this->assertSame(['plan', 2.0, null], [$lines[0]['source'], $lines[0]['quantity'], $lines[0]['amount']]);
        $this->assertSame(['charge', 1.0, 100, '10.00'], [$lines[1]['source'], $lines[1]['quantity'], $lines[1]['points'], $lines[1]['amount']]);
    }

    public function test_printing_during_the_session_is_its_own_line(): void
    {
        $member = $this->actingAsMember();
        $plan = $this->planWithHours(40);
        $printing = ResourceType::where('slug', 'printing')->firstOrFail();
        $grant = $plan->resourceGrants()->create(['resource_type_id' => $printing->id, 'quantity' => 100, 'order' => 1]);
        $membership = $this->subscribe($member, $plan);
        $session = $this->walkIn($member, $membership, '09:00');
        app(ResourceQuotaService::class)->consume($grant, $membership, 20, $member)->sessionable()->associate($session)->save();
        app(SessionClosureService::class)->closeOut($session, $this->at('12:00'));

        $lines = collect($this->report('walkin', $session->id)['lines']);

        $printed = $lines->firstWhere('kind', 'resource_consumption');
        $this->assertSame(20.0, $printed['quantity']);
        $this->assertSame('plan', $printed['source']);
        $this->assertNotNull($printed['label']);
        $this->assertSame(1, $lines->where('kind', 'time')->count()); // the hour row is the time line, not repeated
    }

    public function test_an_open_session_reports_time_so_far_and_no_totals(): void
    {
        $member = $this->actingAsMember();
        $session = $this->walkIn($member, null, '12:00');

        $report = $this->report('walkin', $session->id);

        $this->assertSame([null, 1.0], [$report['lines'][0]['source'], $report['lines'][0]['quantity']]);
        $this->assertNull($report['totals']);
    }

    public function test_a_booking_reports_its_stored_charge(): void
    {
        $member = $this->actingAsMember();
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => $this->space->id,
            'checked_in_at' => $this->at('09:00'),
        ]);
        app(SessionClosureService::class)->closeOut($booking, $this->at('11:00'));

        $lines = $this->report('booking', $booking->id)['lines'];

        $this->assertCount(1, $lines);
        $this->assertSame(['charge', 2.0, (string) $booking->fresh()->amount_owed], [$lines[0]['source'], $lines[0]['quantity'], $lines[0]['amount']]);
    }

    public function test_a_member_cannot_read_another_members_report(): void
    {
        $session = $this->walkIn(User::factory()->create(), null, '09:00', '10:00');
        $this->actingAsMember();

        $this->getJson("/api/v1/member/coworking-sessions/walkin/{$session->id}/report")->assertNotFound();
    }

    public function test_reception_reads_any_report(): void
    {
        $session = $this->walkIn(User::factory()->create(), null, '09:00', '10:00');
        $this->actingAsOperations();

        $this->getJson("/api/v1/admin/reception/sessions/walkin/{$session->id}/report")
            ->assertOk()
            ->assertJsonPath('data.session.id', $session->id);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php artisan test tests/Feature/Booking/SessionReportTest.php`
Expected: FAIL, 404 on the report routes.

- [ ] **Step 3: Builder** `app/Domain/Booking/Services/SessionReportBuilder.php`

```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Membership\Models\ResourceConsumption;
use App\Domain\Membership\Services\PlanHourCoverageService;
use App\Domain\Membership\Services\ResourceQuotaService;

/**
 * The end-of-session report (docs/decisions/coworking-self-service-sessions.md):
 * time, then every transaction linked to the session. `lines` is a generic
 * list on purpose — café orders arrive later as kind "order", with no
 * redesign. Reads only; every figure comes from what closure stored.
 */
class SessionReportBuilder
{
    public function __construct(
        private readonly AmountCalculator $amounts,
        private readonly PlanHourCoverageService $planHours,
        private readonly ResourceQuotaService $quotas,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Booking|WalkinSession $session): array
    {
        $session->loadMissing('space', 'resourceConsumptions.grant.resourceType');

        $closed = $session->checked_out_at !== null;
        $end = $session->checked_out_at ?? now();
        $duration = max(0, $end->getTimestamp() - $session->checked_in_at->getTimestamp());

        return [
            'session' => [
                'type' => $session->coworkingType(),
                'id' => $session->id,
                'space' => ['id' => $session->space->id, 'name' => $session->space->translate('name')],
                'started_via' => $session->startSource()->value,
                'checked_in_at' => $session->checked_in_at->toIso8601String(),
                'checked_out_at' => $session->checked_out_at?->toIso8601String(),
                'duration_seconds' => $duration,
                'termination_source' => $session->termination_source?->value,
            ],
            'lines' => [...$this->timeLines($session, $duration, $closed), ...$this->consumptionLines($session)],
            'plan' => $this->planSection($session),
            'totals' => $closed ? [
                'amount_owed' => (string) $session->amount_owed,
                'currency' => $session->currency,
                'payment_state' => $session->payment_state?->value,
                'payment_method' => $session->payment_method?->value,
                'paid_at' => $session->paid_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function timeLines(Booking|WalkinSession $session, int $duration, bool $closed): array
    {
        if (! $closed) {
            return [$this->timeLine('api.coworking.report.time_so_far', $duration, null)];
        }

        // A booking's price was fixed at creation (plan discount included);
        // it is shown exactly as stored.
        if ($session instanceof Booking) {
            return [$this->timeLine('api.coworking.report.time_charged', $duration, 'charge', null, (string) $session->amount_owed)];
        }

        $covered = $session->plan_covered_seconds;
        $billable = $duration - $covered;
        $lines = [];

        if ($covered > 0) {
            $lines[] = $this->timeLine('api.coworking.report.time_from_plan', $covered, 'plan');
        }

        if ($billable > 0) {
            // Same range SessionClosureService priced, so the points match the bill.
            $points = $this->amounts->forRange($session->space, $session->checked_in_at, $session->checked_out_at->copy()->subSeconds($covered));
            $lines[] = $this->timeLine('api.coworking.report.time_charged', $billable, 'charge', $points, (string) $session->amount_owed);
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function timeLine(string $labelKey, int $seconds, ?string $source, ?int $points = null, ?string $amount = null): array
    {
        return [
            'kind' => 'time',
            'label' => __($labelKey),
            'quantity' => round($seconds / 3600, 2),
            'unit' => __('api.coworking.report.unit_hour'),
            'source' => $source,
            'points' => $points,
            'amount' => $amount,
            'recorded_at' => null,
        ];
    }

    /**
     * Everything linked to the session except the plan-hours row, which the
     * time lines already represent.
     *
     * @return list<array<string, mixed>>
     */
    private function consumptionLines(Booking|WalkinSession $session): array
    {
        return $session->resourceConsumptions
            ->reject(fn (ResourceConsumption $c) => $c->grant?->resourceType?->slug === PlanHourCoverageService::RESOURCE_TYPE_SLUG)
            ->map(fn (ResourceConsumption $c) => [
                'kind' => 'resource_consumption',
                'label' => $c->grant?->resourceType?->translate('name'),
                'quantity' => (float) $c->quantity,
                'unit' => $c->grant?->resourceType?->translate('unit_label'),
                'source' => 'plan',
                'points' => null,
                'amount' => null,
                'recorded_at' => $c->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function planSection(Booking|WalkinSession $session): ?array
    {
        if (! $session instanceof WalkinSession || $session->membership === null) {
            return null;
        }

        $grant = $this->planHours->hourGrantFor($session->membership);

        if ($grant === null) {
            return null;
        }

        return [
            'name' => $session->membership->plan->translate('name'),
            'hours_used' => round($session->plan_covered_seconds / 3600, 2),
            'hours_remaining' => $this->quotas->remainingFor($session->membership, $grant->resourceType),
        ];
    }
}
```

- [ ] **Step 4: Lookup and endpoints.**

In `CoworkingSessionService`, add:

```php
    public function find(string $type, int $id): Booking|WalkinSession|null
    {
        return match ($type) {
            'walkin' => WalkinSession::find($id),
            'booking' => Booking::find($id),
            default => null,
        };
    }
```

In `CoworkingSessionController`, add (with the `SessionReportBuilder` import):

```php
    public function report(Request $request, string $type, int $id, CoworkingSessionService $sessions, SessionReportBuilder $reports): JsonResponse
    {
        $session = $sessions->find($type, $id);

        abort_if($session === null || $session->user_id !== $request->user()->id, 404);

        return response()->json(['data' => $reports->build($session)]);
    }
```

In `ReceptionSessionsController`, add (with imports `CoworkingSessionService`, `SessionReportBuilder`, `JsonResponse`):

```php
    public function report(string $type, int $id, CoworkingSessionService $sessions, SessionReportBuilder $reports): JsonResponse
    {
        $session = $sessions->find($type, $id);

        abort_if($session === null, 404);

        return response()->json(['data' => $reports->build($session)]);
    }
```

Routes:
- `member.php`, after the end-request routes:

```php
Route::get('coworking-sessions/{type}/{id}/report', [CoworkingSessionController::class, 'report'])
    ->whereIn('type', ['booking', 'walkin'])
    ->whereNumber('id');
```

- `admin.php`, next to `reception/sessions/active`:

```php
Route::get('reception/sessions/{type}/{id}/report', [ReceptionSessionsController::class, 'report'])
    ->whereIn('type', ['booking', 'walkin'])
    ->whereNumber('id');
```

- [ ] **Step 5: Run and confirm it passes**

Run: `php artisan test tests/Feature/Booking/SessionReportTest.php tests/Feature/Booking/ReceptionSessionsControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add app routes tests/Feature/Booking/SessionReportTest.php
git commit -m "feat(coworking): session report with time and linked transactions

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: What reception sees in the active list

**Files:**
- Modify:
  - `app/Http/Controllers/Api/V1/Admin/Reception/ReceptionSessionsController.php` (`active` eager loads)
  - `app/Http/Resources/ActiveReceptionSessionResource.php`
- Test: `tests/Feature/Booking/ReceptionActiveSessionsDetailTest.php`

**Interfaces — Consumes:** `startSource()`, `coverage_snapshot`, `pendingEndRequest`, `SessionCoverageResource`, `SessionEndRequestResource`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class ReceptionActiveSessionsDetailTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(string $type, int $id): array
    {
        return collect($this->getJson('/api/v1/admin/reception/sessions/active')->assertOk()->json('data'))
            ->first(fn (array $row) => $row['type'] === $type && $row['id'] === $id);
    }

    public function test_a_scan_started_session_shows_who_their_plan_and_their_end_request(): void
    {
        $space = $this->coworkingSpace();
        [$kiosk] = $this->pairedKiosk($space);
        $member = User::factory()->create(['name' => 'Lina Haddad']);
        $this->subscribe($member, $this->planWithHours(40));
        $session = app(WalkInCapacityService::class)->start($space, $member, SessionStartSource::Scan, $kiosk)->session;
        $session->endRequests()->create(['user_id' => $member->id, 'requested_at' => now(), 'status' => SessionEndRequestStatus::Pending]);
        $this->actingAsOperations();

        $row = $this->row('walkin', $session->id);

        $this->assertSame('scan', $row['started_via']);
        $this->assertSame('Lina Haddad', $row['member']['name']);
        $this->assertSame($member->phone, $row['member']['phone']);
        $this->assertSame('covered', $row['plan']['status']);
        $this->assertSame(40.0, $row['plan']['hours_remaining']);
        $this->assertFalse($row['accepted_uncovered']);
        $this->assertSame('pending', $row['end_request']['status']);
    }

    public function test_a_desk_checked_in_booking_reads_as_reception_with_no_plan_block(): void
    {
        $booking = Booking::factory()->checkedIn()->create(['space_id' => $this->coworkingSpace()->id]);
        $this->actingAsOperations();

        $row = $this->row('booking', $booking->id);

        $this->assertSame('reception', $row['started_via']);
        $this->assertNull($row['plan']);
        $this->assertNull($row['end_request']);
        $this->assertSame($booking->user_id, $row['member']['id']);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `php artisan test tests/Feature/Booking/ReceptionActiveSessionsDetailTest.php`
Expected: FAIL, undefined array key `started_via`.

- [ ] **Step 3: Eager loads.** In `ReceptionSessionsController::active()`, change both `->with('space.building.branch', 'user')` calls to:

```php
            ->with('space.building.branch', 'user.personalProfile', 'user.companies', 'pendingEndRequest')
```

- [ ] **Step 4: Resource.** In `ActiveReceptionSessionResource::toArray()`:
  - Keep every existing key; the dashboard already reads `user_id` / `user_name`.
  - Append the new keys (with imports `WalkinSession`, `SessionCoverageResource`, `SessionEndRequestResource`):

```php
            'started_via' => $this->resource->startSource()->value,
            // What reception checks against the person in front of them.
            'member' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'phone' => $this->user?->phone,
                'avatar_url' => $this->user?->personalProfile?->avatar_url,
                'company' => $this->user?->companies->first()?->legal_name,
            ],
            'plan' => $this->resource instanceof WalkinSession && $this->coverage_snapshot !== null
                ? new SessionCoverageResource($this->coverage_snapshot)
                : null,
            'accepted_uncovered' => $this->resource instanceof WalkinSession && $this->accepted_uncovered,
            'end_request' => $this->pendingEndRequest === null
                ? null
                : new SessionEndRequestResource($this->pendingEndRequest),
```

- [ ] **Step 5: Run the new test and the existing sessions test**

Run: `php artisan test tests/Feature/Booking/ReceptionActiveSessionsDetailTest.php tests/Feature/Booking/ReceptionSessionsControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Http/Controllers/Api/V1/Admin/Reception/ReceptionSessionsController.php app/Http/Resources/ActiveReceptionSessionResource.php tests/Feature/Booking/ReceptionActiveSessionsDetailTest.php
git commit -m "feat(coworking): show reception who scanned in, their plan and end requests

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Documentation and full verification

**Files:**
- Create: `docs/decisions/coworking-self-service-sessions.md`
- Modify:
  - `docs/decisions/README.md`
  - `docs/decisions/kiosk-display.md`
  - `docs/decisions/plan-access-windows.md`
  - `CLAUDE.md`

- [ ] **Step 1: Decision doc.** Write `docs/decisions/coworking-self-service-sessions.md` in the house style of `docs/decisions/kiosk-display.md`, i.e. a `**Status:** resolved 2026-09-28. **Owner:** Maryam Asha.` header, then these sections, each stating what the code now does, with file references:
  1. **What this adds:** scan to start; confirm-before-paid; request-to-end with reception confirmation; the session report. `co_space` only.
  2. **Relationship to `kiosk-display.md`:** the rotating code is a documented exception to "scanning never starts a paid session", scoped to `co_space`. The static arrival QR is unchanged.
  3. **Kiosk pairing and codes:**
     - The pairing flow (code format, 10 min, single use, SHA-256 at rest, throttle); secret delivered once; every pairing rotates; revocation separate from `is_active`.
     - The kiosk-app contract (inputs, TOTP SHA1/30 s/8 digits, `{qr_prefix}:{kiosk_id}:{code}`, secure storage, NTP clock).
     - Why the codebase's own `Totp` rather than Google2FA (the caller-supplied clock).
     - The accepted residual risks: a code photographed and sent within ~90 s; weaker storage on a web kiosk.
  4. **Starting:**
     - The order of checks (code/ticket → space available → business hours → open session → today's booking → coverage → walk-in).
     - Booking mode, and why a pending booking has no walk-in fallback.
     - The coverage reasons table.
     - The 120 s scan ticket.
     - That `coverage_snapshot` is stored for every walk-in.
  5. **Ending:** defaults to `requested_at`; override bounds; the statuses; direct checkout and auto-close supersede; auto-close bills to `requested_at`.
  6. **Report:** the line shape; the plan-hours row excluded from consumption lines; open sessions have no totals; bookings are shown as stored.
  7. **Explicitly not done here:**
     - Café orders (stage two).
     - Paid printing (stage two).
     - Live dashboard push (polling).
     - Mid-session coverage push.
     - Booking checkout billing (the separate task).
     - Permission coverage: `KioskPairingController` and `SessionEndRequestController` join the documented uncovered-controllers list in `rbac-permission-pilot.md`.
     - The Postman update, deferred.
  8. **Guard:** no `tests/Guards/` entry (additive capability); list the feature tests from Tasks 1–12.

- [ ] **Step 2: Cross-references.**
  - `docs/decisions/README.md`: add `- [coworking-self-service-sessions.md](coworking-self-service-sessions.md) — scan-to-start coworking sessions with a TOTP kiosk, confirm-before-paid, member-requested end confirmed by reception, and the session report`.
  - `docs/decisions/kiosk-display.md`:
    - Under "Relationship to the R1 QR exclusion", append: "**Exception (2026-09-28):** a *rotating* TOTP code on the kiosk does start a coworking session directly, for `co_space` only — see [coworking-self-service-sessions.md](coworking-self-service-sessions.md). The static arrival QR described here is unchanged."
    - Under "Single branch", append: "Kiosks are now rows (`kiosks.space_id`), so the scan path resolves its branch from the kiosk's space; the arrival flow still uses the single-branch assumption."
  - `docs/decisions/plan-access-windows.md`: add a section `### Also evaluated at scan time` stating that `SessionCoverageEvaluator` reads the same window and hour grant before a coworking session starts, stores the result as `walkin_sessions.coverage_snapshot`, and never changes what closure absorbs.
  - `CLAUDE.md`: in the route-composition list, add a bullet after `mobile.php`: "`routes/api/v1/kiosk.php` — unauthenticated endpoints the kiosk app calls; today only `POST kiosk/pair` (single-use pairing code → TOTP secret, docs/decisions/coworking-self-service-sessions.md)." Also change "it `require`s five split files" to "six".

- [ ] **Step 3: Full suite against the baseline**

```bash
php artisan test > ../coworking-after.txt 2>&1; tail -5 ../coworking-after.txt
diff <(grep -E "^\s+(FAIL|⨯)" ../coworking-baseline.txt | sort) <(grep -E "^\s+(FAIL|⨯)" ../coworking-after.txt | sort)
```

Expected: the diff shows no newly failing tests; the only differences are new passing tests. Any new failure is fixed before continuing.

- [ ] **Step 4: Style**

Run: `./vendor/bin/pint --test`
Expected: no files listed (the per-task `--dirty` runs already fixed them).

- [ ] **Step 5: Route sanity**

Run: `php artisan route:list --path=coworking && php artisan route:list --path=kiosk && php artisan route:list --path=session-end-requests`
Expected, 9 routes total:
- Member: `POST coworking-sessions`, `GET …/current`, `POST|DELETE …/current/end-request`, `GET …/{type}/{id}/report`.
- Kiosk: `POST kiosk/pair`.
- Admin: `kiosks` resource + `pairing-code` + `secret`, `reception/session-end-requests/{…}/confirm`, `reception/sessions/{type}/{id}/report`.

- [ ] **Step 6: Commit**

```bash
git add docs/decisions/coworking-self-service-sessions.md docs/decisions/README.md docs/decisions/kiosk-display.md docs/decisions/plan-access-windows.md CLAUDE.md
git commit -m "docs(coworking): decision record and cross-references

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Verification (end to end)

1. **Automated:** Task 13 Step 3. The full suite shows no failures beyond the Task 0 baseline, and every new test file passes.
2. **Manual smoke test against the local server** (`composer run dev` in the worktree):
   1. Create a kiosk on the seeded Hot Desk space (`POST /api/v1/admin/kiosks`) as the seeded admin, via the dashboard session.
   2. Issue a pairing code, then `POST /api/v1/kiosk/pair` and keep the secret.
   3. Compute a code with `php artisan tinker --execute="echo app(App\Support\Security\Totp::class)->codeAt('<secret>', now()->timestamp);"`.
   4. As a member token, `POST /api/v1/member/coworking-sessions` with `add-cowork:v1:{id}:{code}`. Without a plan you get 422 plus a ticket; accepting it gives 201.
   5. `GET /api/v1/admin/reception/sessions/active` shows `started_via: scan` and the member block.
   6. `POST …/current/end-request`, then confirm it as operations. `GET …/walkin/{id}/report` shows one charge line.
3. **Follow-ups outside this plan:**
   - The Postman collection (after the Paymera edits land).
   - The kiosk app implementing the contract.
   - The booking-checkout billing task already queued.

## Execution

Recommended: **Subagent-driven.** 14 tasks chain through exact interfaces (Kiosk → verifier → coverage → start → end → report), and a shipped mistake here bills members wrongly, so a fresh reviewer per task is worth the cost. The alternative is Native execution in this session with one whole-branch review at the end.
