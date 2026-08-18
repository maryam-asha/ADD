# Booking Creation, Granularity, Approval, Extension (Phase 4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a member actually create a booking — with slot granularity, buffer, a per-space approval flag and its `pending`/`rejected` states, and an extension mechanism usable by the member or by reception on their behalf. This is the piece Reception Operations (previous phase) deliberately deferred; none of that phase's endpoints (check-in, check-out, cancel, settle) change.

**Architecture:** Three new services in the existing `App\Domain\Booking` namespace — `BookingCreationService`, `BookingApprovalService`, `BookingExtensionService` — reusing `WalletService`, `BusinessHoursService`, `SettingService`, and `AmountCalculator` as-is. Two new nullable override columns + one boolean flag on `spaces` (same pattern as the existing `cancellation_window_minutes`), and three new columns on `bookings` for the approval audit trail. A new member-facing `BookingController` (`store`, `extend`) and three new methods on the existing `BookingReceptionController` (`approve`, `reject`, `extend`). Every shared-state read-check-write is wrapped in `DB::transaction()` with `lockForUpdate()`, re-checking the locked row — the fix the previous phase needed after review, applied here from the start.

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit, SQLite in-memory (tests), Carbon, bcmath.

**Spec:** [docs/superpowers/specs/2026-08-18-booking-creation-approval-extension-design.md](../specs/2026-08-18-booking-creation-approval-extension-design.md) — read it alongside this plan; this plan argues from it and doesn't repeat its rationale.

## Global Constraints

- PHP `^8.2`, Laravel Framework `^12.0`.
- **Never use `->enum()` in a migration.** Every enum-shaped column is a `string` column cast to a PHP 8.2 backed enum on the model — guarded by `tests/Guards/NoNewMysqlEnumColumnsTest.php` (pure grep) and `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` (model side — no new entry needed this phase: `BookingStatus`'s two new cases extend an already-registered cast, and the three new `spaces` columns/`Space::requires_approval` are plain `boolean`/`int`, not enum-backed).
- Models/enums/services/exceptions stay under the existing `App\Domain\Booking\{Models,Enums,Services,Exceptions}` — extend, don't fork a parallel structure.
- Eloquent casts are declared via the `casts(): array` method, never the legacy `protected $casts` property.
- Factories are flat under `database/factories/{Name}Factory.php`.
- **Update-style endpoints return `{"message": "..."}`, never the resource** — `approve`, `reject`, `extend` (member and reception) all follow this. `store()` (booking creation) is the one exception per this repo's own convention and returns the created booking via a new `App\Http\Resources\BookingResource`, since the client needs the new booking's id/status/payment outcome back.
- **Admin feature tests**: `use RefreshDatabase;`, `$this->seed(RoleSeeder::class);` in `setUp()`, authenticate via `Laravel\Sanctum\Sanctum::actingAs($user, ['*'])` after `$user->assignRole(...)` — each test class defines its own private `actingAsOperations()`/`actingAsMember()` helper (no shared base-class helper exists in this codebase).
- `routes/api/v1/admin.php`: every route already sits behind `auth:sanctum` + `abilities:dashboard` + `role:admin|operations`. `routes/api/v1/member.php`: every route already sits behind `auth:sanctum` + `role:member`. Neither file needs a narrower `role:` sub-group for anything in this plan.
- Migration filenames: `database/migrations/YYYY_MM_DD_HHMMSS_verb_description.php`. Most recent existing migration is `2026_08_17_090003_change_contact_links_value_to_text.php` — this plan's two migrations use the `2026_08_18_*` prefix to sort after it.
- **Money**: `DECIMAL(10,2)` exclusively, every arithmetic operation via `bcmath`, never a float. Currency is copied from `Space.pricing_currency` at the point an amount is computed.
- `App\Domain\Foundation\Services\BusinessHoursService` (already built, **do not modify**): `isWithinBusinessHours(CarbonInterface $instant, Branch $branch): bool` and `periodsFor(CarbonInterface $date, Branch $branch): array` (each period `['open_time' => 'H:i', 'close_time' => 'H:i']`; empty array = closed that day; both boundaries inclusive; resolves through the `app.timezone` Setting internally).
- `App\Domain\Settings\Services\SettingService` (already built, **do not modify**): `get(string $key, mixed $default = null): mixed`. `booking.slot_granularity_minutes` (30), `booking.buffer_minutes` (0), `booking.min_duration_minutes` (60), `booking.overrun_grace_minutes` (10) are **already seeded** by `SettingSeeder` — nothing to add there.
- `App\Domain\Membership\Services\WalletService` (already built, **do not change its method signatures**): `walletFor(OwnerType, int): Wallet`, `spendOptions(User $user, WalletTransactionCategory $category): array` (returns a `list<array{wallet_id, owner_type, owner_id, owner_label, category, category_balance, general_balance, usable_balance}>`, one entry per wallet with a positive usable balance — 0/1/2+ entries are exactly the three payment-routing branches this plan needs), `debit(Wallet, User $spendingUser, WalletTransactionCategory, string $amount, ?string $description): array` (resolves the category pool first, falls back to general automatically, throws `InsufficientBalanceException` only if neither covers it).
- **Booking payments use `WalletTransactionCategory::SpaceSpecific`** — the category the wallet-categorization decision doc earmarks for space/room usage (already used this way by `MembershipController` for a plan's included hours). Never `General` directly; `debit()`'s own fallback already reaches general when the space-specific pool can't cover it.
- **Wallet-side operations (extension charges) always target the member's own personal wallet** (`OwnerType::User`), even if the original booking payment came from a company wallet — matching `BookingCancellationService::cancel()`'s existing refund behavior, which makes the same simplification today. Nothing on `bookings` records which specific wallet paid; see the decision doc (Task 11) for why this isn't being fixed here.
- `App\Concerns\LogsSensitiveActions` trait: `use` it on every controller method that changes state, call `$this->logSensitiveAction(string $action, Model $subject, array $properties = [])`.
- **`ReceptionActionException`** (`app/Domain/Booking/Exceptions/ReceptionActionException.php`) is shared across every service in this domain — Task 9 adds an optional `array $params = []` constructor argument to it (default `[]`, backward compatible) and updates every existing catch site to call `__($e->messageKey, $e->params)` instead of `__($e->messageKey)`.
- `docs/decisions/*.md` format: `# Title`, `**Status:** resolved <date>. **Owner:** Maryam Asha.`, then `## Decision`, `## Why`, `## What this changed in code`, `## Guard` sections.
- **SQLite in-memory test DB**: a genuine two-connection concurrency test isn't reproducible (each connection gets its own separate in-memory database). Every "concurrency" test in this plan instead reproduces the shape from `tests/Feature/Booking/CloseOverdueReceptionSessionsCommandTest.php::test_autoclose_does_not_overwrite_a_session_closed_concurrently_since_it_was_loaded`: two independently-fetched model instances of the same row, one mutates first via a completed call, the second (stale) instance drives the method under test and must observe the committed state, not its own earlier snapshot.
- `Space::factory()->room()` already sets `hourly_rate`/`pricing_currency`; tests in this plan additionally pass `slot_granularity_minutes`/`buffer_minutes`/`requires_approval`/`capacity` as explicit `create([...])` overrides — no new per-attribute factory states needed except `Space::factory()->requiresApproval()` (Task 1) and `Booking::factory()->pending()`/`->rejected()` (Task 1), mirroring the existing `checkedIn()`/`cancelled()` states.
- 2026-08-17 is a Monday — every test in this plan that needs an open business-hours window uses `BusinessHour::factory()->create(['day_of_week' => DayOfWeek::Monday, 'open_time' => '08:00', 'close_time' => '20:00'])` against a Monday `Carbon::setTestNow(...)`, matching every existing Booking test file's convention.
- This is the **first** placeholder-interpolated lang key in this codebase (`api.booking.extension_conflict` carries `:latest_end_at`) — every other existing key is a static string. This is a deliberate, minimal, first-of-its-kind exception because the phase brief explicitly requires the latest possible end time to be stated in the error text, which cannot be done with a static string.

---

### Task 1: Schema — `spaces` overrides, `bookings` approval columns, `BookingStatus` enum

**Files:**
- Create: `database/migrations/2026_08_18_100000_add_granularity_buffer_and_approval_to_spaces_table.php`
- Create: `database/migrations/2026_08_18_100100_add_approval_columns_to_bookings_table.php`
- Modify: `app/Domain/Booking/Enums/BookingStatus.php`
- Modify: `app/Domain/Booking/Models/Booking.php`
- Modify: `app/Domain/Foundation/Models/Space.php`
- Modify: `database/factories/BookingFactory.php`
- Modify: `database/factories/SpaceFactory.php`
- Modify: `tests/Unit/Domain/Booking/EnumsTest.php`
- Modify: `tests/Feature/Booking/BookingCancellationServiceTest.php`
- Test: `tests/Unit/Domain/Booking/BookingApprovalColumnsTest.php`

**Interfaces:**
- Produces: `Space.slot_granularity_minutes`/`buffer_minutes` (nullable int overrides), `Space.requires_approval` (bool, default false). `Booking.rejection_reason` (nullable string), `Booking.approved_by`/`approved_at` (nullable). `BookingStatus::Pending`/`Rejected`. `Space::factory()->requiresApproval()`, `Booking::factory()->pending()`/`->rejected()`. Every later task's service reads these columns/cases.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingApprovalColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_space_can_carry_granularity_and_buffer_overrides(): void
    {
        $space = Space::factory()->room()->create([
            'slot_granularity_minutes' => 15,
            'buffer_minutes' => 10,
        ]);

        $space->refresh();
        $this->assertSame(15, $space->slot_granularity_minutes);
        $this->assertSame(10, $space->buffer_minutes);
    }

    public function test_a_space_without_overrides_has_null_granularity_and_buffer(): void
    {
        $space = Space::factory()->room()->create();

        $this->assertNull($space->fresh()->slot_granularity_minutes);
        $this->assertNull($space->fresh()->buffer_minutes);
    }

    public function test_a_space_does_not_require_approval_by_default(): void
    {
        $space = Space::factory()->room()->create();

        $this->assertFalse($space->fresh()->requires_approval);
    }

    public function test_a_space_can_be_flagged_to_require_approval(): void
    {
        $space = Space::factory()->room()->requiresApproval()->create();

        $this->assertTrue($space->fresh()->requires_approval);
    }

    public function test_a_booking_can_carry_an_approval_decision(): void
    {
        $operator = User::factory()->create();
        $booking = Booking::factory()->rejected()->create(['approved_by' => $operator->id, 'approved_at' => now()]);

        $booking->refresh();
        $this->assertSame(BookingStatus::Rejected, $booking->status);
        $this->assertNotNull($booking->rejection_reason);
        $this->assertSame($operator->id, $booking->approved_by);
        $this->assertNotNull($booking->approved_at);
    }

    public function test_a_pending_booking_has_no_approval_decision_yet(): void
    {
        $booking = Booking::factory()->pending()->create();

        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertNull($booking->rejection_reason);
        $this->assertNull($booking->approved_by);
        $this->assertNull($booking->approved_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Booking/BookingApprovalColumnsTest.php`
Expected: FAIL — `Unknown column 'slot_granularity_minutes'` (and `Class ... requiresApproval` / `pending` not found once the factory calls are hit)

- [ ] **Step 3: Write the `spaces` migration**

`database/migrations/2026_08_18_100000_add_granularity_buffer_and_approval_to_spaces_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-space overrides, same pattern as the existing
     * cancellation_window_minutes column — null-coalesced at the consuming
     * call site against the already-seeded booking.slot_granularity_minutes
     * (30) / booking.buffer_minutes (0) settings. requires_approval has no
     * global fallback: a per-space toggle, not a value worth centralizing.
     */
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->unsignedInteger('slot_granularity_minutes')->nullable();
            $table->unsignedInteger('buffer_minutes')->nullable();
            $table->boolean('requires_approval')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn(['slot_granularity_minutes', 'buffer_minutes', 'requires_approval']);
        });
    }
};
```

- [ ] **Step 4: Write the `bookings` migration**

`database/migrations/2026_08_18_100100_add_approval_columns_to_bookings_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approval workflow columns. rejection_reason is nullable at the schema
     * level but enforced non-empty in BookingApprovalService whenever
     * status is set to rejected — schema permissiveness, service-layer
     * enforcement, same split as every other business rule in this domain.
     * approved_by/approved_at mirror the existing paid_by/paid_at
     * denormalization pattern and are set on approval AND rejection alike
     * (same audit trail either way).
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('rejection_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->dateTime('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
```

- [ ] **Step 5: Extend `BookingStatus`**

Edit `app/Domain/Booking/Enums/BookingStatus.php`:
```php
<?php

namespace App\Domain\Booking\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Pending = 'pending';
    case Rejected = 'rejected';
}
```

- [ ] **Step 6: Update the existing enum-cases test**

Edit `tests/Unit/Domain/Booking/EnumsTest.php` — `BookingStatus` now has four cases:
```php
    public function test_booking_status_cases(): void
    {
        $this->assertSame(['confirmed', 'cancelled', 'pending', 'rejected'], array_column(BookingStatus::cases(), 'value'));
    }
```

- [ ] **Step 7: Add the new columns to `Booking`'s fillable and casts**

Edit `app/Domain/Booking/Models/Booking.php`:
```php
    protected $fillable = [
        'space_id',
        'user_id',
        'start_at',
        'end_at',
        'status',
        'payment_state',
        'payment_source',
        'checked_in_at',
        'checked_out_at',
        'termination_source',
        'amount_owed',
        'currency',
        'payment_method',
        'paid_by',
        'paid_at',
        'cancelled_at',
        'rejection_reason',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'status' => BookingStatus::class,
            'payment_state' => PaymentState::class,
            'payment_source' => PaymentSource::class,
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'termination_source' => TerminationSource::class,
            'amount_owed' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
```

- [ ] **Step 8: Add the new columns to `Space`'s fillable and casts**

Edit `app/Domain/Foundation/Models/Space.php`:
```php
    protected $fillable = [
        'building_id',
        'zone_id',
        'space_type',
        'allocation_model',
        'is_lockable',
        'capacity',
        'hourly_rate',
        'pricing_currency',
        'cancellation_window_minutes',
        'slot_granularity_minutes',
        'buffer_minutes',
        'requires_approval',
        'status',
        'status_reason',
        'status_from',
        'status_until',
    ];

    protected function casts(): array
    {
        return [
            'space_type' => SpaceType::class,
            'allocation_model' => AllocationModel::class,
            'is_lockable' => 'boolean',
            'hourly_rate' => 'decimal:2',
            'requires_approval' => 'boolean',
            'status' => OperationalStatus::class,
            'status_from' => 'datetime',
            'status_until' => 'datetime',
        ];
    }
```

- [ ] **Step 9: Add factory states**

Edit `database/factories/SpaceFactory.php` — add one state method (anywhere after `maintenance()`):
```php
    public function requiresApproval(): static
    {
        return $this->state(['requires_approval' => true]);
    }
```

Edit `database/factories/BookingFactory.php` — add two state methods (after the existing `cancelled()`):
```php
    public function pending(): static
    {
        return $this->state(fn () => ['status' => BookingStatus::Pending]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::Rejected,
            'rejection_reason' => 'Not a good fit for this request.',
        ]);
    }
```

- [ ] **Step 10: Run the new test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Booking/BookingApprovalColumnsTest.php tests/Unit/Domain/Booking/EnumsTest.php`
Expected: PASS (6 + 6 tests)

- [ ] **Step 11: Prove `BookingCancellationService` already handles `pending` correctly — no service code change**

`BookingCancellationService::cancel()` only rejects an already-`Cancelled` booking or one with `checked_in_at` already set; it never checks for `status === Confirmed`, so a `pending`, not-yet-checked-in booking already cancels correctly through the unmodified method. Add one test proving it — edit `tests/Feature/Booking/BookingCancellationServiceTest.php`, appending:

```php
    public function test_cancelling_a_pending_booking_succeeds(): void
    {
        $space = Space::factory()->room()->create();
        $booking = Booking::factory()->pending()->create([
            'space_id' => $space->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
        ]);

        $this->cancellations->cancel($booking);

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }
```

- [ ] **Step 12: Run the full Booking test suite to catch any regression**

Run: `php artisan test tests/Feature/Booking tests/Unit/Domain/Booking`
Expected: PASS, no regressions

- [ ] **Step 13: Commit**

```bash
git add database/migrations/2026_08_18_100000_add_granularity_buffer_and_approval_to_spaces_table.php database/migrations/2026_08_18_100100_add_approval_columns_to_bookings_table.php app/Domain/Booking/Enums/BookingStatus.php app/Domain/Booking/Models/Booking.php app/Domain/Foundation/Models/Space.php database/factories/BookingFactory.php database/factories/SpaceFactory.php tests/Unit/Domain/Booking/EnumsTest.php tests/Feature/Booking/BookingCancellationServiceTest.php tests/Unit/Domain/Booking/BookingApprovalColumnsTest.php
git commit -m "feat: add spaces granularity/buffer/approval columns and bookings approval columns"
```

---

### Task 2: `booking` lang group

**Files:**
- Modify: `lang/en/api.php`
- Modify: `lang/ar/api.php`
- Test: `tests/Unit/Domain/Booking/BookingLangKeysTest.php`

**Interfaces:**
- Produces: `api.booking.*` — every later service throws `ReceptionActionException` with one of these keys (or an existing `api.reception.*` key where the message is identical — see Task 3).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Booking;

use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

class BookingLangKeysTest extends TestCase
{
    /** @return list<string> */
    public static function keys(): array
    {
        return [
            'invalid_start_time',
            'duration_too_short',
            'duration_invalid_granularity',
            'slot_unavailable',
            'buffer_conflict',
            'wallet_choice_required',
            'not_pending',
            'rejection_reason_required',
            'approved',
            'rejected',
            'invalid_extension_duration',
            'extension_conflict',
            'extended',
        ];
    }

    public function test_every_booking_key_exists_in_english(): void
    {
        foreach (self::keys() as $key) {
            $this->assertTrue(Lang::has("api.booking.{$key}", 'en'), "Missing lang/en/api.php booking.{$key}");
        }
    }

    public function test_every_booking_key_exists_in_arabic(): void
    {
        foreach (self::keys() as $key) {
            $this->assertTrue(Lang::has("api.booking.{$key}", 'ar'), "Missing lang/ar/api.php booking.{$key}");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Booking/BookingLangKeysTest.php`
Expected: FAIL — missing `api.booking.invalid_start_time` (and the rest)

- [ ] **Step 3: Add the `booking` group to `lang/en/api.php`**

Edit `lang/en/api.php` — add a new top-level group after the existing `'reception' => [...]` block, before the final `];`:
```php
    'booking' => [
        'invalid_start_time' => "The start time does not match this space's slot granularity.",
        'duration_too_short' => 'The booking duration is shorter than the minimum allowed.',
        'duration_invalid_granularity' => "The booking duration must be a multiple of this space's slot granularity above the minimum.",
        'slot_unavailable' => 'This space is not available for the requested time.',
        'buffer_conflict' => 'This booking is too close to another booking on this space.',
        'wallet_choice_required' => 'More than one wallet can cover this booking. Please choose one.',
        'not_pending' => 'This booking is not awaiting approval.',
        'rejection_reason_required' => 'A rejection reason is required.',
        'approved' => 'Booking approved.',
        'rejected' => 'Booking rejected.',
        'invalid_extension_duration' => 'The extension duration does not meet the minimum duration or granularity requirements.',
        'extension_conflict' => 'This booking cannot be extended past :latest_end_at.',
        'extended' => 'Booking extended.',
    ],
```

- [ ] **Step 4: Add the `booking` group to `lang/ar/api.php`**

Edit `lang/ar/api.php` — same position:
```php
    'booking' => [
        'invalid_start_time' => 'وقت البدء لا يتوافق مع فترة الحجز المحددة لهذه المساحة.',
        'duration_too_short' => 'مدة الحجز أقل من الحد الأدنى المسموح به.',
        'duration_invalid_granularity' => 'يجب أن تكون مدة الحجز من مضاعفات فترة الحجز المحددة لهذه المساحة فوق الحد الأدنى.',
        'slot_unavailable' => 'هذه المساحة غير متاحة في الوقت المطلوب.',
        'buffer_conflict' => 'هذا الحجز قريب جداً من حجز آخر على هذه المساحة.',
        'wallet_choice_required' => 'يمكن لأكثر من محفظة تغطية هذا الحجز. الرجاء اختيار واحدة.',
        'not_pending' => 'هذا الحجز ليس في انتظار الموافقة.',
        'rejection_reason_required' => 'سبب الرفض مطلوب.',
        'approved' => 'تمت الموافقة على الحجز.',
        'rejected' => 'تم رفض الحجز.',
        'invalid_extension_duration' => 'مدة التمديد لا تستوفي الحد الأدنى للمدة أو متطلبات فترة الحجز.',
        'extension_conflict' => 'لا يمكن تمديد هذا الحجز إلى ما بعد :latest_end_at.',
        'extended' => 'تم تمديد الحجز.',
    ],
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Booking/BookingLangKeysTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add lang/en/api.php lang/ar/api.php tests/Unit/Domain/Booking/BookingLangKeysTest.php
git commit -m "feat: add booking lang group for creation/approval/extension messages"
```

---

### Task 3: `BookingCreationService` — timing, overlap, buffer, occupancy (always confirmed + unpaid)

**Files:**
- Create: `app/Domain/Booking/Services/BookingCreationService.php`
- Test: `tests/Feature/Booking/BookingCreationServiceTest.php`

**Interfaces:**
- Consumes: `BusinessHoursService::periodsFor()`, `SettingService::get()`, `AmountCalculator::forRange()`, `Space`, `Booking`, `ReceptionActionException`.
- Produces: `BookingCreationService::create(Space $space, User $member, CarbonInterface $startAt, CarbonInterface $endAt, ?OwnerType $walletOwnerType = null, ?int $walletOwnerId = null): Booking`. This exact signature is called by Task 4 (adds payment), Task 5 (adds approval branching), Task 6 (HTTP layer), and Task 8's capacity-release test.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Booking/BookingCreationServiceTest.php`:
```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingCreationService $creations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creations = app(BookingCreationService::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function openSpace(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'hourly_rate' => '10.00',
            'pricing_currency' => 'USD',
        ], $attributes));

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function slot(int $hour, int $durationMinutes = 60): array
    {
        $start = Carbon::parse('2026-08-17', 'Asia/Damascus')->setTime($hour, 0);

        return [$start, $start->copy()->addMinutes($durationMinutes)];
    }

    public function test_a_member_can_create_a_confirmed_unpaid_booking(): void
    {
        $space = $this->openSpace();
        $member = User::factory()->create();
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, $member, $start, $end);

        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertTrue($booking->start_at->equalTo($start));
        $this->assertTrue($booking->end_at->equalTo($end));
    }

    public function test_creation_fails_outside_business_hours(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(21);

        try {
            $this->creations->create($space, User::factory()->create(), $start, $end);
            $this->fail('Expected a ReceptionActionException for outside business hours.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.outside_business_hours', $e->messageKey);
        }
    }

    public function test_creation_fails_when_the_window_spans_a_closed_gap_between_two_periods(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
        BusinessHour::factory()->create(['branch_id' => $space->building->branch_id, 'day_of_week' => DayOfWeek::Monday, 'open_time' => '08:00', 'close_time' => '12:00']);
        BusinessHour::factory()->create(['branch_id' => $space->building->branch_id, 'day_of_week' => DayOfWeek::Monday, 'open_time' => '13:00', 'close_time' => '20:00']);
        $start = Carbon::parse('2026-08-17 11:30:00', 'Asia/Damascus');

        try {
            $this->creations->create($space, User::factory()->create(), $start, $start->copy()->addHour());
            $this->fail('Expected a ReceptionActionException — the window crosses the 12:00-13:00 closed gap.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.outside_business_hours', $e->messageKey);
        }
    }

    public function test_creation_fails_when_the_start_time_does_not_match_granularity(): void
    {
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);
        $start = Carbon::parse('2026-08-17 10:15:00', 'Asia/Damascus');

        try {
            $this->creations->create($space, User::factory()->create(), $start, $start->copy()->addMinutes(60));
            $this->fail('Expected a ReceptionActionException for invalid start time.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.invalid_start_time', $e->messageKey);
        }
    }

    public function test_creation_fails_when_duration_is_below_the_minimum(): void
    {
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);
        [$start] = $this->slot(10);

        try {
            $this->creations->create($space, User::factory()->create(), $start, $start->copy()->addMinutes(45));
            $this->fail('Expected a ReceptionActionException for duration too short.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.duration_too_short', $e->messageKey);
        }
    }

    public function test_creation_fails_when_duration_does_not_match_granularity_above_the_minimum(): void
    {
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);
        [$start] = $this->slot(10);

        try {
            $this->creations->create($space, User::factory()->create(), $start, $start->copy()->addMinutes(70));
            $this->fail('Expected a ReceptionActionException for invalid duration granularity.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.duration_invalid_granularity', $e->messageKey);
        }
    }

    public function test_valid_durations_at_30_minute_granularity_are_accepted(): void
    {
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);

        foreach ([60, 90, 120] as $minutes) {
            [$start] = $this->slot(10);

            $booking = $this->creations->create($space, User::factory()->create(), $start, $start->copy()->addMinutes($minutes));

            $this->assertSame($minutes, (int) $start->diffInMinutes($booking->end_at));
        }
    }

    public function test_creation_fails_when_the_slot_overlaps_a_confirmed_booking(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(10);
        Booking::factory()->create(['space_id' => $space->id, 'start_at' => $start, 'end_at' => $end]);

        try {
            $this->creations->create($space, User::factory()->create(), $start->copy()->addMinutes(30), $end->copy()->addMinutes(30));
            $this->fail('Expected a ReceptionActionException for slot unavailable.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.slot_unavailable', $e->messageKey);
        }
    }

    public function test_creation_fails_when_within_the_buffer_of_an_adjacent_booking(): void
    {
        $space = $this->openSpace(['buffer_minutes' => 15]);
        [$start, $end] = $this->slot(10);
        Booking::factory()->create(['space_id' => $space->id, 'start_at' => $start, 'end_at' => $end]);
        $newStart = $end->copy()->addMinutes(10);

        try {
            $this->creations->create($space, User::factory()->create(), $newStart, $newStart->copy()->addHour());
            $this->fail('Expected a ReceptionActionException for buffer conflict.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.buffer_conflict', $e->messageKey);
        }
    }

    public function test_creation_succeeds_exactly_at_the_buffer_boundary(): void
    {
        $space = $this->openSpace(['buffer_minutes' => 15]);
        [$start, $end] = $this->slot(10);
        Booking::factory()->create(['space_id' => $space->id, 'start_at' => $start, 'end_at' => $end]);
        $newStart = $end->copy()->addMinutes(15);

        $booking = $this->creations->create($space, User::factory()->create(), $newStart, $newStart->copy()->addHour());

        $this->assertInstanceOf(Booking::class, $booking);
    }

    public function test_creation_succeeds_when_a_buffer_is_configured_but_no_adjacent_booking_exists(): void
    {
        $space = $this->openSpace(['buffer_minutes' => 15]);
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, User::factory()->create(), $start, $end);

        $this->assertInstanceOf(Booking::class, $booking);
    }

    public function test_creation_fails_when_current_occupancy_is_already_at_capacity(): void
    {
        $space = $this->openSpace(['capacity' => 1]);
        Booking::factory()->checkedIn()->create(['space_id' => $space->id]);
        [$start, $end] = $this->slot(14);

        try {
            $this->creations->create($space, User::factory()->create(), $start, $end);
            $this->fail('Expected a ReceptionActionException for no capacity.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.no_capacity', $e->messageKey);
        }
    }

    public function test_creation_succeeds_when_capacity_is_unlimited(): void
    {
        $space = $this->openSpace(['capacity' => null]);
        Booking::factory()->checkedIn()->create(['space_id' => $space->id]);
        [$start, $end] = $this->slot(14);

        $booking = $this->creations->create($space, User::factory()->create(), $start, $end);

        $this->assertInstanceOf(Booking::class, $booking);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/BookingCreationServiceTest.php`
Expected: FAIL — `Class "App\Domain\Booking\Services\BookingCreationService" not found`

- [ ] **Step 3: Write `BookingCreationService`**

`app/Domain/Booking/Services/BookingCreationService.php`:
```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Member-facing booking creation — the piece Reception Operations
 * deliberately deferred. Validation order matches the phase brief exactly:
 * business hours, then granularity, then duration (none of these depend on
 * shared state, so none need a lock); then, inside one locked transaction
 * on the Space row, overlap + live occupancy, then buffer; then payment;
 * then the requires_approval branch. Task 4 adds payment routing and Task 5
 * adds the requires_approval branch to this same create() method.
 */
class BookingCreationService
{
    public function __construct(
        private readonly BusinessHoursService $businessHours,
        private readonly SettingService $settings,
        private readonly AmountCalculator $amounts,
    ) {}

    public function create(
        Space $space,
        User $member,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        ?OwnerType $walletOwnerType = null,
        ?int $walletOwnerId = null,
    ): Booking {
        $this->assertWithinBusinessHours($space, $startAt, $endAt);

        $granularity = $space->slot_granularity_minutes ?? $this->settings->get('booking.slot_granularity_minutes', 30);
        $this->assertValidGranularity($startAt, $granularity);

        $minDuration = (int) $this->settings->get('booking.min_duration_minutes', 60);
        $this->assertValidDuration($startAt, $endAt, $minDuration, $granularity);

        return DB::transaction(function () use ($space, $member, $startAt, $endAt) {
            $locked = Space::query()->whereKey($space->id)->lockForUpdate()->firstOrFail();

            $this->assertSlotAvailable($locked, $startAt, $endAt);
            $this->assertOccupancyLeavesRoom($locked);
            $this->assertBufferRespected($locked, $startAt, $endAt);

            $status = BookingStatus::Confirmed;

            return Booking::create([
                'space_id' => $locked->id,
                'user_id' => $member->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => $status,
                'payment_state' => PaymentState::Unpaid,
                'payment_source' => null,
            ]);
        });
    }

    private function assertWithinBusinessHours(Space $space, CarbonInterface $start, CarbonInterface $end): void
    {
        $branch = $space->building->branch;
        $startTime = $this->localTimeOfDay($start);
        $endTime = $this->localTimeOfDay($end);

        foreach ($this->businessHours->periodsFor($start, $branch) as $period) {
            if ($startTime >= $period['open_time'] && $endTime <= $period['close_time']) {
                return;
            }
        }

        throw new ReceptionActionException('api.reception.outside_business_hours');
    }

    private function assertValidGranularity(CarbonInterface $start, int $granularity): void
    {
        $local = $start->copy()->setTimezone($this->settings->get('app.timezone', 'Asia/Damascus'));
        $minutesSinceMidnight = $local->hour * 60 + $local->minute;

        if ($local->second !== 0 || $minutesSinceMidnight % $granularity !== 0) {
            throw new ReceptionActionException('api.booking.invalid_start_time');
        }
    }

    private function assertValidDuration(CarbonInterface $start, CarbonInterface $end, int $minDuration, int $granularity): void
    {
        $duration = (int) $start->diffInMinutes($end);

        if ($duration < $minDuration) {
            throw new ReceptionActionException('api.booking.duration_too_short');
        }

        if (($duration - $minDuration) % $granularity !== 0) {
            throw new ReceptionActionException('api.booking.duration_invalid_granularity');
        }
    }

    private function assertSlotAvailable(Space $space, CarbonInterface $start, CarbonInterface $end): void
    {
        $overlapping = Booking::query()
            ->where('space_id', $space->id)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->exists();

        if ($overlapping) {
            throw new ReceptionActionException('api.booking.slot_unavailable');
        }
    }

    /**
     * Present-moment physical-presence count, reusing
     * WalkInCapacityService::start()'s exact counting logic — deliberately
     * not re-solved as a reservation-against-the-future-window check (that's
     * space_capacity_slots, out of scope this phase; see the decision doc).
     */
    private function assertOccupancyLeavesRoom(Space $space): void
    {
        if ($space->capacity === null) {
            return;
        }

        $occupied = Booking::query()
            ->where('space_id', $space->id)
            ->whereNotNull('checked_in_at')
            ->whereNull('checked_out_at')
            ->count();

        $occupied += WalkinSession::query()
            ->where('space_id', $space->id)
            ->whereNull('checked_out_at')
            ->count();

        if ($occupied >= $space->capacity) {
            throw new ReceptionActionException('api.reception.no_capacity');
        }
    }

    /**
     * A gap exactly equal to buffer_minutes passes — inclusive boundary,
     * matching BusinessHoursService's own convention for a sibling concept.
     */
    private function assertBufferRespected(Space $space, CarbonInterface $start, CarbonInterface $end): void
    {
        $buffer = $space->buffer_minutes ?? $this->settings->get('booking.buffer_minutes', 0);

        if ($buffer <= 0) {
            return;
        }

        $violation = Booking::query()
            ->where('space_id', $space->id)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
            ->where(function ($query) use ($start, $end, $buffer) {
                $query->where(function ($q) use ($start, $buffer) {
                    $q->where('end_at', '<=', $start)->where('end_at', '>', $start->copy()->subMinutes($buffer));
                })->orWhere(function ($q) use ($end, $buffer) {
                    $q->where('start_at', '>=', $end)->where('start_at', '<', $end->copy()->addMinutes($buffer));
                });
            })
            ->exists();

        if ($violation) {
            throw new ReceptionActionException('api.booking.buffer_conflict');
        }
    }

    private function localTimeOfDay(CarbonInterface $instant): string
    {
        return $instant->copy()->setTimezone($this->settings->get('app.timezone', 'Asia/Damascus'))->format('H:i');
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/BookingCreationServiceTest.php`
Expected: PASS (13 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Booking/Services/BookingCreationService.php tests/Feature/Booking/BookingCreationServiceTest.php
git commit -m "feat: add BookingCreationService (timing, overlap, buffer, occupancy)"
```

---

### Task 4: `BookingCreationService` — wallet payment routing

**Files:**
- Modify: `app/Domain/Booking/Services/BookingCreationService.php`
- Create: `app/Domain/Booking/Exceptions/WalletChoiceRequiredException.php`
- Modify: `tests/Feature/Booking/BookingCreationServiceTest.php`

**Interfaces:**
- Consumes: `WalletService::spendOptions()`, `WalletService::walletFor()`, `WalletService::debit()`, `WalletTransactionCategory::SpaceSpecific`.
- Produces: `WalletChoiceRequiredException(array $options)` — Task 6's controller catches this and returns the options list. `create()`'s signature is unchanged; its `?OwnerType $walletOwnerType, ?int $walletOwnerId` parameters (already declared in Task 3, unused until now) become live.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Booking/BookingCreationServiceTest.php` — add these `use` statements at the top:
```php
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
```

and these test methods:
```php
    public function test_creation_debits_the_single_available_wallet_and_marks_paid(): void
    {
        $space = $this->openSpace();
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        (new WalletService)->creditGeneral($wallet, '50.00', WalletTransactionSource::TopUp);
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, $member, $start, $end);

        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(PaymentSource::Wallet, $booking->payment_source);
        $this->assertSame(1, $wallet->transactions()->where('amount', '-10.00')->count());
    }

    public function test_creation_stays_unpaid_when_no_balance_covers_the_cost(): void
    {
        $space = $this->openSpace();
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, User::factory()->create(), $start, $end);

        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertNull($booking->payment_source);
    }

    public function test_creation_requires_an_explicit_wallet_choice_when_multiple_balances_apply(): void
    {
        $space = $this->openSpace();
        $member = User::factory()->create();
        $personalWallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        (new WalletService)->creditGeneral($personalWallet, '50.00', WalletTransactionSource::TopUp);
        $company = Company::factory()->create();
        $member->companies()->attach($company->id);
        $companyWallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        (new WalletService)->creditGeneral($companyWallet, '50.00', WalletTransactionSource::TopUp);
        [$start, $end] = $this->slot(10);

        try {
            $this->creations->create($space, $member, $start, $end);
            $this->fail('Expected a WalletChoiceRequiredException.');
        } catch (WalletChoiceRequiredException $e) {
            $this->assertCount(2, $e->options);
        }

        $this->assertSame(0, Booking::where('user_id', $member->id)->count());
    }

    public function test_creation_debits_the_explicitly_chosen_company_wallet_when_provided(): void
    {
        $space = $this->openSpace();
        $member = User::factory()->create();
        $company = Company::factory()->create();
        $member->companies()->attach($company->id);
        $companyWallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        (new WalletService)->creditGeneral($companyWallet, '50.00', WalletTransactionSource::TopUp);
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, $member, $start, $end, OwnerType::Company, $company->id);

        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(1, $companyWallet->transactions()->where('amount', '-10.00')->count());
    }

    public function test_creation_stays_unpaid_when_the_computed_amount_is_zero(): void
    {
        $space = $this->openSpace(['hourly_rate' => '0.00']);
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        (new WalletService)->creditGeneral($wallet, '50.00', WalletTransactionSource::TopUp);
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, $member, $start, $end);

        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertSame(0, $wallet->transactions()->count());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/BookingCreationServiceTest.php`
Expected: FAIL — `Class "App\Domain\Booking\Exceptions\WalletChoiceRequiredException" not found`, and the paid/unpaid assertions fail since payment routing doesn't exist yet

- [ ] **Step 3: Write `WalletChoiceRequiredException`**

`app/Domain/Booking/Exceptions/WalletChoiceRequiredException.php`:
```php
<?php

namespace App\Domain\Booking\Exceptions;

use RuntimeException;

/**
 * Thrown by BookingCreationService when the spending member has more than
 * one wallet that could cover the cost (their own balance and at least one
 * company's) — the client must resubmit with an explicit
 * wallet_owner_type/wallet_owner_id chosen from $options, the same shape
 * WalletController::options() already returns.
 */
class WalletChoiceRequiredException extends RuntimeException
{
    /**
     * @param list<array{wallet_id: int, owner_type: string, owner_id: int, owner_label: string, category: string, category_balance: string, general_balance: string, usable_balance: string}> $options
     */
    public function __construct(public readonly array $options)
    {
        parent::__construct('Multiple wallets can cover this booking; an explicit choice is required.');
    }
}
```

- [ ] **Step 4: Wire payment routing into `BookingCreationService`**

Edit `app/Domain/Booking/Services/BookingCreationService.php` — add imports:
```php
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Services\WalletService;
```

add `WalletService $wallets` to the constructor:
```php
    public function __construct(
        private readonly BusinessHoursService $businessHours,
        private readonly SettingService $settings,
        private readonly AmountCalculator $amounts,
        private readonly WalletService $wallets,
    ) {}
```

replace the transaction body's booking-creation block with:
```php
        return DB::transaction(function () use ($space, $member, $startAt, $endAt, $walletOwnerType, $walletOwnerId) {
            $locked = Space::query()->whereKey($space->id)->lockForUpdate()->firstOrFail();

            $this->assertSlotAvailable($locked, $startAt, $endAt);
            $this->assertOccupancyLeavesRoom($locked);
            $this->assertBufferRespected($locked, $startAt, $endAt);

            [$amount] = $this->amounts->forRange($locked, $startAt, $endAt);
            [$paymentState, $paymentSource] = $this->routePayment($locked, $member, $amount, $walletOwnerType, $walletOwnerId);

            return Booking::create([
                'space_id' => $locked->id,
                'user_id' => $member->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => BookingStatus::Confirmed,
                'payment_state' => $paymentState,
                'payment_source' => $paymentSource,
            ]);
        });
    }

    /**
     * @return array{0: PaymentState, 1: ?PaymentSource}
     */
    private function routePayment(Space $space, User $member, string $amount, ?OwnerType $walletOwnerType, ?int $walletOwnerId): array
    {
        if (bccomp($amount, '0.00', 2) <= 0) {
            return [PaymentState::Unpaid, null];
        }

        if ($walletOwnerType !== null && $walletOwnerId !== null) {
            $wallet = $this->wallets->walletFor($walletOwnerType, $walletOwnerId);
            $this->wallets->debit($wallet, $member, WalletTransactionCategory::SpaceSpecific, $amount, "Booking for space #{$space->id}");

            return [PaymentState::Paid, PaymentSource::Wallet];
        }

        $options = $this->wallets->spendOptions($member, WalletTransactionCategory::SpaceSpecific);

        if (count($options) > 1) {
            throw new WalletChoiceRequiredException($options);
        }

        if (count($options) === 1) {
            $wallet = $this->wallets->walletFor(OwnerType::from($options[0]['owner_type']), $options[0]['owner_id']);
            $this->wallets->debit($wallet, $member, WalletTransactionCategory::SpaceSpecific, $amount, "Booking for space #{$space->id}");

            return [PaymentState::Paid, PaymentSource::Wallet];
        }

        return [PaymentState::Unpaid, null];
    }
```

(This replaces the `create()` method's transaction body from Task 3 and adds a new private `routePayment()` method right after it — the rest of the file, including `assertWithinBusinessHours`/`assertValidGranularity`/`assertValidDuration`/`assertSlotAvailable`/`assertOccupancyLeavesRoom`/`assertBufferRespected`/`localTimeOfDay`, is unchanged from Task 3.)

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/BookingCreationServiceTest.php`
Expected: PASS (18 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Booking/Services/BookingCreationService.php app/Domain/Booking/Exceptions/WalletChoiceRequiredException.php tests/Feature/Booking/BookingCreationServiceTest.php
git commit -m "feat: add wallet payment routing to BookingCreationService"
```

---

### Task 5: `BookingCreationService` — `requires_approval` branch and notification

**Files:**
- Modify: `app/Domain/Booking/Services/BookingCreationService.php`
- Modify: `tests/Feature/Booking/BookingCreationServiceTest.php`

**Interfaces:**
- Produces: a booking created against a `requires_approval = true` space is `Pending` and writes one `NotificationLog` row per `operations`/`admin` user with `template_key = 'booking.pending_approval'`. This is the last change to `create()`'s core logic — Task 6 only adds the HTTP layer on top.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Booking/BookingCreationServiceTest.php` — add these `use` statements:
```php
use App\Domain\Identity\Models\NotificationLog;
use Database\Seeders\RoleSeeder;
```

and add `$this->seed(RoleSeeder::class);` as the first line of `setUp()` (needed for `assignRole('operations')` below), and these test methods:
```php
    public function test_creation_creates_a_pending_booking_when_the_space_requires_approval(): void
    {
        $space = $this->openSpace(['requires_approval' => true]);
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, User::factory()->create(), $start, $end);

        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame(
            1,
            NotificationLog::where('user_id', $operator->id)->where('template_key', 'booking.pending_approval')->count()
        );
    }

    public function test_a_pending_booking_blocks_a_second_request_for_the_same_slot(): void
    {
        $space = $this->openSpace(['requires_approval' => true]);
        [$start, $end] = $this->slot(10);
        $this->creations->create($space, User::factory()->create(), $start, $end);

        try {
            $this->creations->create($space, User::factory()->create(), $start, $end);
            $this->fail('Expected a ReceptionActionException for slot unavailable.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.slot_unavailable', $e->messageKey);
        }
    }

    public function test_creation_stays_confirmed_when_the_space_does_not_require_approval(): void
    {
        $space = $this->openSpace(['requires_approval' => false]);
        [$start, $end] = $this->slot(10);

        $booking = $this->creations->create($space, User::factory()->create(), $start, $end);

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(0, NotificationLog::count());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/BookingCreationServiceTest.php`
Expected: FAIL — every booking is created `Confirmed` regardless of `requires_approval`

- [ ] **Step 3: Add the approval branch**

Edit `app/Domain/Booking/Services/BookingCreationService.php` — add imports:
```php
use App\Domain\Identity\Models\NotificationLog;
```

change the `Booking::create([...])` call inside the transaction from:
```php
            return Booking::create([
                'space_id' => $locked->id,
                'user_id' => $member->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => BookingStatus::Confirmed,
                'payment_state' => $paymentState,
                'payment_source' => $paymentSource,
            ]);
        });
    }
```
to:
```php
            $status = $locked->requires_approval ? BookingStatus::Pending : BookingStatus::Confirmed;

            $booking = Booking::create([
                'space_id' => $locked->id,
                'user_id' => $member->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => $status,
                'payment_state' => $paymentState,
                'payment_source' => $paymentSource,
            ]);

            if ($status === BookingStatus::Pending) {
                $this->notifyOperationsOfPendingBooking($booking);
            }

            return $booking;
        });
    }

    /**
     * NotificationLog rows only — no real send channel exists anywhere in
     * this app yet (see the decision doc). channel is written as 'push'
     * (the closest fit among the table's three legacy values for an
     * eventual in-app/dashboard notification); status 'sent' means
     * "generated by this system," not "confirmed delivered."
     */
    private function notifyOperationsOfPendingBooking(Booking $booking): void
    {
        User::role(['operations', 'admin'])->get()->each(function (User $recipient) use ($booking) {
            NotificationLog::create([
                'user_id' => $recipient->id,
                'channel' => 'push',
                'template_key' => 'booking.pending_approval',
                'status' => 'sent',
            ]);
        });
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/BookingCreationServiceTest.php`
Expected: PASS (21 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Booking/Services/BookingCreationService.php tests/Feature/Booking/BookingCreationServiceTest.php
git commit -m "feat: add requires_approval branch and pending-booking notification"
```

---

### Task 6: Member booking creation endpoint

**Files:**
- Create: `app/Http/Requests/Member/Booking/StoreBookingRequest.php`
- Create: `app/Http/Resources/BookingResource.php`
- Create: `app/Http/Controllers/Api/V1/Member/BookingController.php`
- Modify: `routes/api/v1/member.php`
- Test: `tests/Feature/Booking/BookingControllerTest.php`

**Interfaces:**
- Consumes: `BookingCreationService::create()` (Task 5), `ReceptionActionException`, `WalletChoiceRequiredException` (Task 4).
- Produces: `POST /api/v1/member/bookings` → 201 `{"data": {...}}` or 422 (validation / `ReceptionActionException` / wallet choice). Task 10 adds `extend()` to this same controller.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Booking/BookingControllerTest.php`:
```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsMember(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function openSpace(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'hourly_rate' => '10.00',
            'pricing_currency' => 'USD',
        ], $attributes));

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_a_member_can_create_a_booking(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
        ]);

        $response->assertCreated();
        $this->assertSame(1, Booking::where('space_id', $space->id)->count());
        $this->assertSame(BookingStatus::Confirmed, Booking::first()->status);
    }

    public function test_booking_creation_rejects_a_start_time_off_the_granularity(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:15:00+03:00',
            'end_at' => '2026-08-17T11:15:00+03:00',
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => "The start time does not match this space's slot granularity."]);
    }

    public function test_booking_creation_returns_wallet_options_when_choice_is_ambiguous(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();
        $personalWallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        (new WalletService)->creditGeneral($personalWallet, '50.00', WalletTransactionSource::TopUp);
        $company = Company::factory()->create();
        $member->companies()->attach($company->id);
        $companyWallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        (new WalletService)->creditGeneral($companyWallet, '50.00', WalletTransactionSource::TopUp);

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
        ]);

        $response->assertStatus(422);
        $this->assertCount(2, $response->json('wallet_options'));
        $this->assertSame(0, Booking::count());
    }

    public function test_an_operator_cannot_create_a_booking_via_the_member_route(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/BookingControllerTest.php`
Expected: FAIL — 404 (route doesn't exist yet)

- [ ] **Step 3: Write `StoreBookingRequest`**

`app/Http/Requests/Member/Booking/StoreBookingRequest.php`:
```php
<?php

namespace App\Http\Requests\Member\Booking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
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
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'wallet_owner_type' => ['nullable', 'string', Rule::in(['user', 'company'])],
            'wallet_owner_id' => ['nullable', 'integer', 'required_with:wallet_owner_type'],
        ];
    }
}
```

- [ ] **Step 4: Write `BookingResource`**

`app/Http/Resources/BookingResource.php`:
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'user_id' => $this->user_id,
            'start_at' => $this->start_at->toIso8601String(),
            'end_at' => $this->end_at->toIso8601String(),
            'status' => $this->status->value,
            'payment_state' => $this->payment_state->value,
            'payment_source' => $this->payment_source?->value,
        ];
    }
}
```

- [ ] **Step 5: Write `BookingController::store()`**

`app/Http/Controllers/Api/V1/Member/BookingController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Enums\OwnerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\Booking\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class BookingController extends Controller
{
    public function store(StoreBookingRequest $request, BookingCreationService $creations): JsonResponse
    {
        $space = Space::findOrFail($request->validated('space_id'));

        $walletOwnerType = $request->validated('wallet_owner_type')
            ? OwnerType::from($request->validated('wallet_owner_type'))
            : null;

        try {
            $booking = $creations->create(
                $space,
                $request->user(),
                Carbon::parse($request->validated('start_at')),
                Carbon::parse($request->validated('end_at')),
                $walletOwnerType,
                $request->validated('wallet_owner_id'),
            );
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        } catch (WalletChoiceRequiredException $e) {
            return response()->json([
                'message' => __('api.booking.wallet_choice_required'),
                'wallet_options' => $e->options,
            ], 422);
        }

        return response()->json(['data' => new BookingResource($booking)], 201);
    }
}
```

Note: `ReceptionActionException` has no `$params` property yet — it is added in Task 9. This call site uses the plain `__($e->messageKey)` for now; Task 9's Step 4 updates it (and every other existing catch site) to `__($e->messageKey, $e->params)`.

- [ ] **Step 6: Register the route**

Edit `routes/api/v1/member.php` — add the import:
```php
use App\Http\Controllers\Api\V1\Member\BookingController;
```

and, after the `wallet/options` line:
```php
// docs/decisions/booking-creation-approval-extension.md — member-facing
// booking creation with slot granularity/buffer/approval rules.
Route::post('bookings', [BookingController::class, 'store']);
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/BookingControllerTest.php`
Expected: PASS (4 tests)

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Member/Booking/StoreBookingRequest.php app/Http/Resources/BookingResource.php app/Http/Controllers/Api/V1/Member/BookingController.php routes/api/v1/member.php tests/Feature/Booking/BookingControllerTest.php
git commit -m "feat: add member booking creation endpoint"
```

---

### Task 7: `BookingApprovalService`

**Files:**
- Create: `app/Domain/Booking/Services/BookingApprovalService.php`
- Test: `tests/Feature/Booking/BookingApprovalServiceTest.php`

**Interfaces:**
- Produces: `BookingApprovalService::approve(Booking $booking, User $operator): void`, `::reject(Booking $booking, User $operator, string $reason): void`. Task 8 wires both to HTTP.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Booking/BookingApprovalServiceTest.php`:
```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingApprovalService;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingApprovalService $approvals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvals = app(BookingApprovalService::class);
    }

    public function test_approving_a_pending_booking_confirms_it_and_notifies_the_member(): void
    {
        $operator = User::factory()->create();
        $booking = Booking::factory()->pending()->create();

        $this->approvals->approve($booking, $operator);

        $booking->refresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame($operator->id, $booking->approved_by);
        $this->assertNotNull($booking->approved_at);
        $this->assertSame(
            1,
            NotificationLog::where('user_id', $booking->user_id)->where('template_key', 'booking.approved')->count()
        );
    }

    public function test_rejecting_a_pending_booking_requires_a_reason(): void
    {
        $operator = User::factory()->create();
        $booking = Booking::factory()->pending()->create();

        try {
            $this->approvals->reject($booking, $operator, '');
            $this->fail('Expected a ReceptionActionException for a missing rejection reason.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.rejection_reason_required', $e->messageKey);
        }

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_rejecting_a_pending_booking_with_a_reason_rejects_it_and_notifies_the_member(): void
    {
        $operator = User::factory()->create();
        $booking = Booking::factory()->pending()->create();

        $this->approvals->reject($booking, $operator, 'Space closed for maintenance that day.');

        $booking->refresh();
        $this->assertSame(BookingStatus::Rejected, $booking->status);
        $this->assertSame('Space closed for maintenance that day.', $booking->rejection_reason);
        $this->assertSame($operator->id, $booking->approved_by);
        $this->assertNotNull($booking->approved_at);
        $this->assertSame(
            1,
            NotificationLog::where('user_id', $booking->user_id)->where('template_key', 'booking.rejected')->count()
        );
    }

    public function test_approving_an_already_decided_booking_fails(): void
    {
        $operator = User::factory()->create();
        $booking = Booking::factory()->create();

        try {
            $this->approvals->approve($booking, $operator);
            $this->fail('Expected a ReceptionActionException for not pending.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.not_pending', $e->messageKey);
            $this->assertSame(409, $e->status);
        }
    }

    public function test_rejecting_an_already_cancelled_booking_fails(): void
    {
        $operator = User::factory()->create();
        $booking = Booking::factory()->cancelled()->create();

        try {
            $this->approvals->reject($booking, $operator, 'Too late.');
            $this->fail('Expected a ReceptionActionException for not pending.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.not_pending', $e->messageKey);
        }
    }

    /**
     * Reproduces true concurrency the way
     * CloseOverdueReceptionSessionsCommandTest does: two independently
     * fetched instances of the same row, one decides first, the second
     * (stale) instance must observe the committed state and reject.
     */
    public function test_a_stale_approval_attempt_after_a_concurrent_rejection_is_rejected(): void
    {
        $operator = User::factory()->create();
        $booking = Booking::factory()->pending()->create();

        $staleCopy = Booking::find($booking->id);

        $this->approvals->reject(Booking::find($booking->id), $operator, 'Already handled.');

        try {
            $this->approvals->approve($staleCopy, $operator);
            $this->fail('Expected a ReceptionActionException — approve() must not overwrite a concurrently-completed rejection.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.not_pending', $e->messageKey);
        }

        $this->assertSame(BookingStatus::Rejected, $booking->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/BookingApprovalServiceTest.php`
Expected: FAIL — `Class "App\Domain\Booking\Services\BookingApprovalService" not found`

- [ ] **Step 3: Write `BookingApprovalService`**

`app/Domain/Booking/Services/BookingApprovalService.php`:
```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Both actions lock a fresh copy of the Booking row before deciding — the
 * same pattern BookingCancellationService::cancel() already uses — and act
 * only on that locked copy, never the caller's in-memory $booking.
 */
class BookingApprovalService
{
    public function approve(Booking $booking, User $operator): void
    {
        DB::transaction(function () use ($booking, $operator) {
            $locked = $this->lockPending($booking);

            $locked->forceFill([
                'status' => BookingStatus::Confirmed,
                'approved_by' => $operator->id,
                'approved_at' => now(),
            ])->save();

            $this->notify($locked->user_id, 'booking.approved');
        });
    }

    public function reject(Booking $booking, User $operator, string $reason): void
    {
        if (trim($reason) === '') {
            throw new ReceptionActionException('api.booking.rejection_reason_required');
        }

        DB::transaction(function () use ($booking, $operator, $reason) {
            $locked = $this->lockPending($booking);

            $locked->forceFill([
                'status' => BookingStatus::Rejected,
                'rejection_reason' => $reason,
                'approved_by' => $operator->id,
                'approved_at' => now(),
            ])->save();

            $this->notify($locked->user_id, 'booking.rejected');
        });
    }

    private function lockPending(Booking $booking): Booking
    {
        $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== BookingStatus::Pending) {
            throw new ReceptionActionException('api.booking.not_pending', 409);
        }

        return $locked;
    }

    private function notify(int $userId, string $templateKey): void
    {
        NotificationLog::create([
            'user_id' => $userId,
            'channel' => 'push',
            'template_key' => $templateKey,
            'status' => 'sent',
        ]);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/BookingApprovalServiceTest.php`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Booking/Services/BookingApprovalService.php tests/Feature/Booking/BookingApprovalServiceTest.php
git commit -m "feat: add BookingApprovalService"
```

---

### Task 8: Reception approve/reject endpoints

**Files:**
- Create: `app/Http/Requests/Admin/Reception/RejectBookingRequest.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/Reception/BookingReceptionController.php`
- Modify: `routes/api/v1/admin.php`
- Test: `tests/Feature/Booking/BookingApprovalControllerTest.php`

**Interfaces:**
- Consumes: `BookingApprovalService` (Task 7), `BookingCreationService` + `BookingCancellationService` (for the capacity-release test).
- Produces: `POST admin/reception/bookings/{booking}/approve`, `POST admin/reception/bookings/{booking}/reject`. Task 10 adds `extend` to this same controller/route block/test file.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Booking/BookingApprovalControllerTest.php`:
```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingApprovalControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    private function openSpace(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'hourly_rate' => '10.00',
            'pricing_currency' => 'USD',
        ], $attributes));

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_operations_can_approve_a_pending_booking(): void
    {
        $operator = $this->actingAsOperations();
        $booking = Booking::factory()->pending()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/approve");

        $response->assertOk()->assertExactJson(['message' => 'Booking approved.']);
        $booking->refresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame($operator->id, $booking->approved_by);
    }

    public function test_operations_can_reject_a_pending_booking_with_a_reason(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->pending()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/reject", [
            'rejection_reason' => 'Space unavailable that day.',
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Booking rejected.']);
        $this->assertSame(BookingStatus::Rejected, $booking->fresh()->status);
    }

    public function test_rejecting_without_a_reason_fails_validation(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->pending()->create(['space_id' => $this->openSpace()->id]);

        $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/reject", [])
            ->assertStatus(422);

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_approving_an_already_confirmed_booking_fails(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/approve");

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking is not awaiting approval.']);
    }

    public function test_a_member_cannot_approve_a_booking(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $booking = Booking::factory()->pending()->create(['space_id' => $this->openSpace()->id]);

        $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/approve")->assertForbidden();
    }

    public function test_a_member_cancelling_their_own_pending_booking_releases_the_slot(): void
    {
        $space = $this->openSpace(['requires_approval' => true]);
        $member = User::factory()->create();
        $start = Carbon::parse('2026-08-17 14:00:00', 'Asia/Damascus');
        $end = Carbon::parse('2026-08-17 15:00:00', 'Asia/Damascus');

        $creations = app(BookingCreationService::class);
        $firstBooking = $creations->create($space, $member, $start, $end);
        $this->assertSame(BookingStatus::Pending, $firstBooking->status);

        app(BookingCancellationService::class)->cancel($firstBooking);
        $this->assertSame(BookingStatus::Cancelled, $firstBooking->fresh()->status);

        $secondBooking = $creations->create($space, User::factory()->create(), $start, $end);
        $this->assertSame(BookingStatus::Pending, $secondBooking->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/BookingApprovalControllerTest.php`
Expected: FAIL — 404 (routes don't exist yet)

- [ ] **Step 3: Write `RejectBookingRequest`**

`app/Http/Requests/Admin/Reception/RejectBookingRequest.php`:
```php
<?php

namespace App\Http\Requests\Admin\Reception;

use Illuminate\Foundation\Http\FormRequest;

class RejectBookingRequest extends FormRequest
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
            'rejection_reason' => ['required', 'string', 'min:1'],
        ];
    }
}
```

- [ ] **Step 4: Add `approve()`/`reject()` to `BookingReceptionController`**

Edit `app/Http/Controllers/Api/V1/Admin/Reception/BookingReceptionController.php` — add imports:
```php
use App\Domain\Booking\Services\BookingApprovalService;
use App\Http\Requests\Admin\Reception\RejectBookingRequest;
use Illuminate\Http\Request;
```

and append two methods (after `settlePayment()`):
```php
    public function approve(Request $request, Booking $booking, BookingApprovalService $approvals): JsonResponse
    {
        try {
            $approvals->approve($booking, $request->user());
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $this->logSensitiveAction('booking_approved', $booking);

        return response()->json(['message' => __('api.booking.approved')]);
    }

    public function reject(RejectBookingRequest $request, Booking $booking, BookingApprovalService $approvals): JsonResponse
    {
        try {
            $approvals->reject($booking, $request->user(), $request->validated('rejection_reason'));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $this->logSensitiveAction('booking_rejected', $booking, ['rejection_reason' => $booking->rejection_reason]);

        return response()->json(['message' => __('api.booking.rejected')]);
    }
```

Note: `$e->params` does not exist on `ReceptionActionException` until Task 9. Use `__($e->messageKey)` (no second argument) here for now; Task 9's Step 4 updates every catch site in this file, including these two, in one pass.

- [ ] **Step 5: Register the routes**

Edit `routes/api/v1/admin.php` — after the existing `reception/bookings/{booking}/settle-payment` line:
```php
Route::post('reception/bookings/{booking}/approve', [BookingReceptionController::class, 'approve']);
Route::post('reception/bookings/{booking}/reject', [BookingReceptionController::class, 'reject']);
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/BookingApprovalControllerTest.php`
Expected: PASS (6 tests)

- [ ] **Step 7: Run the full Booking test suite to catch any regression**

Run: `php artisan test tests/Feature/Booking tests/Unit/Domain/Booking`
Expected: PASS, no regressions

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Admin/Reception/RejectBookingRequest.php app/Http/Controllers/Api/V1/Admin/Reception/BookingReceptionController.php routes/api/v1/admin.php tests/Feature/Booking/BookingApprovalControllerTest.php
git commit -m "feat: add reception booking approve/reject endpoints"
```

---

### Task 9: `ReceptionActionException` params + `BookingExtensionService`

**Files:**
- Modify: `app/Domain/Booking/Exceptions/ReceptionActionException.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/Reception/BookingReceptionController.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/Reception/WalkInSessionController.php`
- Modify: `app/Http/Controllers/Api/V1/Member/BookingController.php`
- Create: `app/Domain/Booking/Services/BookingExtensionService.php`
- Test: `tests/Feature/Booking/BookingExtensionServiceTest.php`

**Interfaces:**
- Produces: `ReceptionActionException(string $messageKey, int $status = 422, array $params = [])`. `BookingExtensionService::extend(Booking $booking, int $additionalMinutes): void`. Task 10 wires this to HTTP (both member and reception).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Booking/BookingExtensionServiceTest.php`:
```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingExtensionServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingExtensionService $extensions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extensions = app(BookingExtensionService::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function checkedInBooking(array $attributes = []): Booking
    {
        $space = Space::factory()->room()->create([
            'hourly_rate' => '10.00',
            'pricing_currency' => 'USD',
            'slot_granularity_minutes' => 30,
        ]);

        return Booking::factory()->checkedIn()->create(array_merge([
            'space_id' => $space->id,
            'start_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus'),
            'checked_in_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
        ], $attributes));
    }

    public function test_extending_a_checked_in_booking_with_no_conflict_succeeds(): void
    {
        $booking = $this->checkedInBooking();

        $this->extensions->extend($booking, 60);

        $this->assertTrue($booking->fresh()->end_at->equalTo(Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus')));
    }

    public function test_extension_fails_if_the_booking_is_not_checked_in(): void
    {
        $booking = Booking::factory()->create();

        try {
            $this->extensions->extend($booking, 60);
            $this->fail('Expected a ReceptionActionException for not checked in.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.not_checked_in', $e->messageKey);
        }
    }

    public function test_extension_fails_if_already_checked_out(): void
    {
        $booking = $this->checkedInBooking(['checked_out_at' => now()]);

        try {
            $this->extensions->extend($booking, 60);
            $this->fail('Expected a ReceptionActionException for not checked in.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.not_checked_in', $e->messageKey);
        }
    }

    public function test_extension_fails_when_the_duration_is_below_the_minimum(): void
    {
        $booking = $this->checkedInBooking();

        try {
            $this->extensions->extend($booking, 45);
            $this->fail('Expected a ReceptionActionException for invalid extension duration.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.invalid_extension_duration', $e->messageKey);
        }
    }

    public function test_extension_fails_when_a_conflicting_booking_follows(): void
    {
        $booking = $this->checkedInBooking();
        Booking::factory()->create([
            'space_id' => $booking->space_id,
            'start_at' => Carbon::parse('2026-08-17 11:30:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-17 12:30:00', 'Asia/Damascus'),
        ]);

        try {
            $this->extensions->extend($booking, 60);
            $this->fail('Expected a ReceptionActionException for extension conflict.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.extension_conflict', $e->messageKey);
            $this->assertSame('2026-08-17T11:30:00+03:00', $e->params['latest_end_at']);
        }

        $this->assertTrue($booking->fresh()->end_at->equalTo(Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus')));
    }

    public function test_extension_debits_the_wallet_when_the_booking_was_paid_by_wallet(): void
    {
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        (new WalletService)->creditGeneral($wallet, '50.00', WalletTransactionSource::TopUp);
        $booking = $this->checkedInBooking([
            'user_id' => $member->id,
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
        ]);

        $this->extensions->extend($booking, 60);

        $this->assertSame(1, $wallet->transactions()->where('amount', '-10.00')->count());
        $this->assertSame(PaymentState::Paid, $booking->fresh()->payment_state);
    }

    public function test_extension_leaves_an_unpaid_booking_unpaid(): void
    {
        $booking = $this->checkedInBooking();

        $this->extensions->extend($booking, 60);

        $this->assertSame(PaymentState::Unpaid, $booking->fresh()->payment_state);
    }

    /**
     * The lock-and-recheck lesson, applied to extension: the first request
     * safely extends into the free 11:00-12:00 gap. The second, evaluated
     * after the first's commit, must see the now-current end_at (12:00) —
     * not its own stale in-memory end_at (11:00), against which the same
     * +60-minute request would have wrongly looked free of the booking that
     * starts at 12:00.
     */
    public function test_only_one_of_two_concurrent_extension_requests_for_the_same_following_slot_succeeds(): void
    {
        $booking = $this->checkedInBooking();
        Booking::factory()->create([
            'space_id' => $booking->space_id,
            'start_at' => Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-17 13:00:00', 'Asia/Damascus'),
        ]);

        $first = Booking::find($booking->id);
        $second = Booking::find($booking->id);

        $this->extensions->extend($first, 60);

        try {
            $this->extensions->extend($second, 60);
            $this->fail('Expected a ReceptionActionException for extension conflict.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.extension_conflict', $e->messageKey);
        }

        $this->assertTrue($booking->fresh()->end_at->equalTo(Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus')));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/BookingExtensionServiceTest.php`
Expected: FAIL — `Class "App\Domain\Booking\Services\BookingExtensionService" not found`

- [ ] **Step 3: Add `$params` to `ReceptionActionException`**

Edit `app/Domain/Booking/Exceptions/ReceptionActionException.php`:
```php
<?php

namespace App\Domain\Booking\Exceptions;

use RuntimeException;

/**
 * One exception type for every reception/booking precondition failure in
 * this domain. Carries the translation key, HTTP status, and (optionally)
 * Laravel-style `:placeholder` params the controller passes straight
 * through to __(). $params defaults to [] — every pre-existing call site
 * that doesn't need it is unaffected.
 */
class ReceptionActionException extends RuntimeException
{
    public function __construct(
        public readonly string $messageKey,
        public readonly int $status = 422,
        public readonly array $params = [],
    ) {
        parent::__construct($messageKey);
    }
}
```

- [ ] **Step 4: Update every existing catch site to pass `$e->params`**

Edit `app/Http/Controllers/Api/V1/Admin/Reception/BookingReceptionController.php` — there are 5 occurrences of `__($e->messageKey)` (in `checkOut`, `cancel`, `settlePayment`, and the two added in Task 8, `approve`/`reject`); replace every one with `__($e->messageKey, $e->params)`.

Edit `app/Http/Controllers/Api/V1/Admin/Reception/WalkInSessionController.php` — there are 3 occurrences of `__($e->messageKey)` (in `store`, `checkOut`, `settlePayment`); replace every one with `__($e->messageKey, $e->params)`.

Edit `app/Http/Controllers/Api/V1/Member/BookingController.php` — the one occurrence in `store()`'s catch block: replace `__($e->messageKey)` with `__($e->messageKey, $e->params)`.

- [ ] **Step 5: Write `BookingExtensionService`**

`app/Domain/Booking/Services/BookingExtensionService.php`:
```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Support\Facades\DB;

/**
 * Used by both the member's own extend request and reception acting on the
 * member's behalf — one service, two thin routes (see the design doc).
 * Locks the Space row (same pattern as BookingCreationService) so this
 * serializes correctly against both a concurrent new booking and a
 * concurrent second extension attempt on the same booking.
 */
class BookingExtensionService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly AmountCalculator $amounts,
        private readonly WalletService $wallets,
    ) {}

    public function extend(Booking $booking, int $additionalMinutes): void
    {
        DB::transaction(function () use ($booking, $additionalMinutes) {
            $lockedBooking = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedBooking->checked_in_at === null || $lockedBooking->checked_out_at !== null) {
                throw new ReceptionActionException('api.reception.not_checked_in');
            }

            $space = Space::query()->whereKey($lockedBooking->space_id)->lockForUpdate()->firstOrFail();

            $granularity = $space->slot_granularity_minutes ?? $this->settings->get('booking.slot_granularity_minutes', 30);
            $minDuration = (int) $this->settings->get('booking.min_duration_minutes', 60);

            if ($additionalMinutes < $minDuration || ($additionalMinutes - $minDuration) % $granularity !== 0) {
                throw new ReceptionActionException('api.booking.invalid_extension_duration');
            }

            $newEndAt = $lockedBooking->end_at->copy()->addMinutes($additionalMinutes);

            $conflict = Booking::query()
                ->where('space_id', $lockedBooking->space_id)
                ->whereKeyNot($lockedBooking->getKey())
                ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
                ->where('start_at', '<', $newEndAt)
                ->where('end_at', '>', $lockedBooking->end_at)
                ->orderBy('start_at')
                ->first();

            if ($conflict !== null) {
                throw new ReceptionActionException('api.booking.extension_conflict', 422, [
                    'latest_end_at' => $conflict->start_at->toIso8601String(),
                ]);
            }

            [$oldAmount] = $this->amounts->forRange($space, $lockedBooking->start_at, $lockedBooking->end_at);
            [$newAmount] = $this->amounts->forRange($space, $lockedBooking->start_at, $newEndAt);
            $difference = bcsub($newAmount, $oldAmount, 2);

            $paymentState = $lockedBooking->payment_state;

            if ($lockedBooking->payment_state === PaymentState::Paid && $lockedBooking->payment_source === PaymentSource::Wallet) {
                $wallet = $this->wallets->walletFor(OwnerType::User, $lockedBooking->user_id);
                $this->wallets->debit($wallet, $lockedBooking->user, WalletTransactionCategory::SpaceSpecific, $difference, "Booking #{$lockedBooking->id} extension");
            } else {
                $paymentState = PaymentState::Unpaid;
            }

            $lockedBooking->forceFill([
                'end_at' => $newEndAt,
                'payment_state' => $paymentState,
            ])->save();
        });
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/BookingExtensionServiceTest.php`
Expected: PASS (8 tests)

- [ ] **Step 7: Run the full Booking test suite to catch any regression from the `ReceptionActionException` change**

Run: `php artisan test tests/Feature/Booking tests/Unit/Domain/Booking`
Expected: PASS, no regressions

- [ ] **Step 8: Commit**

```bash
git add app/Domain/Booking/Exceptions/ReceptionActionException.php app/Http/Controllers/Api/V1/Admin/Reception/BookingReceptionController.php app/Http/Controllers/Api/V1/Admin/Reception/WalkInSessionController.php app/Http/Controllers/Api/V1/Member/BookingController.php app/Domain/Booking/Services/BookingExtensionService.php tests/Feature/Booking/BookingExtensionServiceTest.php
git commit -m "feat: add BookingExtensionService and ReceptionActionException params"
```

---

### Task 10: Extension endpoints (member + reception)

**Files:**
- Create: `app/Http/Requests/Member/Booking/ExtendBookingRequest.php`
- Create: `app/Http/Requests/Admin/Reception/ExtendBookingRequest.php`
- Modify: `app/Http/Controllers/Api/V1/Member/BookingController.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/Reception/BookingReceptionController.php`
- Modify: `routes/api/v1/member.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `tests/Feature/Booking/BookingControllerTest.php`
- Modify: `tests/Feature/Booking/BookingApprovalControllerTest.php`

**Interfaces:**
- Consumes: `BookingExtensionService::extend()` (Task 9).
- Produces: `POST member/bookings/{booking}/extend`, `POST admin/reception/bookings/{booking}/extend`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Booking/BookingControllerTest.php`:
```php
    public function test_a_member_can_extend_their_own_checked_in_booking(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus'),
            'checked_in_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/member/bookings/{$booking->id}/extend", [
            'additional_minutes' => 60,
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Booking extended.']);
        $this->assertTrue($booking->fresh()->end_at->equalTo(Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus')));
    }

    public function test_a_member_cannot_extend_another_members_booking(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create(['space_id' => $space->id]);

        $this->withHeader('lang', 'en')->postJson("/api/v1/member/bookings/{$booking->id}/extend", [
            'additional_minutes' => 60,
        ])->assertForbidden();
    }
```

Append to `tests/Feature/Booking/BookingApprovalControllerTest.php`:
```php
    public function test_reception_can_extend_a_checked_in_booking_on_the_members_behalf(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'start_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus'),
            'checked_in_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/extend", [
            'additional_minutes' => 60,
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Booking extended.']);
        $this->assertTrue($booking->fresh()->end_at->equalTo(Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus')));
    }

    public function test_extending_past_a_conflicting_booking_states_the_latest_possible_end_time(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'start_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus'),
            'checked_in_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
        ]);
        Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => Carbon::parse('2026-08-17 11:30:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-17 12:30:00', 'Asia/Damascus'),
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/extend", [
            'additional_minutes' => 60,
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => 'This booking cannot be extended past 2026-08-17T11:30:00+03:00.']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Booking/BookingControllerTest.php tests/Feature/Booking/BookingApprovalControllerTest.php`
Expected: FAIL — 404 (routes don't exist yet)

- [ ] **Step 3: Write both `ExtendBookingRequest` classes**

`app/Http/Requests/Member/Booking/ExtendBookingRequest.php`:
```php
<?php

namespace App\Http\Requests\Member\Booking;

use Illuminate\Foundation\Http\FormRequest;

class ExtendBookingRequest extends FormRequest
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
            'additional_minutes' => ['required', 'integer', 'min:1'],
        ];
    }
}
```

`app/Http/Requests/Admin/Reception/ExtendBookingRequest.php` — identical rules, separate namespace by convention:
```php
<?php

namespace App\Http\Requests\Admin\Reception;

use Illuminate\Foundation\Http\FormRequest;

class ExtendBookingRequest extends FormRequest
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
            'additional_minutes' => ['required', 'integer', 'min:1'],
        ];
    }
}
```

- [ ] **Step 4: Add `BookingController::extend()`**

Edit `app/Http/Controllers/Api/V1/Member/BookingController.php` — add imports:
```php
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Http\Requests\Member\Booking\ExtendBookingRequest;
```

and append:
```php
    public function extend(ExtendBookingRequest $request, Booking $booking, BookingExtensionService $extensions): JsonResponse
    {
        if (! $booking->user->is($request->user())) {
            abort(403);
        }

        try {
            $extensions->extend($booking, $request->validated('additional_minutes'));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.booking.extended')]);
    }
```

- [ ] **Step 5: Add `BookingReceptionController::extend()`**

Edit `app/Http/Controllers/Api/V1/Admin/Reception/BookingReceptionController.php` — add imports:
```php
use App\Domain\Booking\Services\BookingExtensionService;
use App\Http\Requests\Admin\Reception\ExtendBookingRequest;
```

and append:
```php
    public function extend(ExtendBookingRequest $request, Booking $booking, BookingExtensionService $extensions): JsonResponse
    {
        try {
            $extensions->extend($booking, $request->validated('additional_minutes'));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('booking_extended', $booking, ['additional_minutes' => $request->validated('additional_minutes')]);

        return response()->json(['message' => __('api.booking.extended')]);
    }
```

- [ ] **Step 6: Register both routes**

Edit `routes/api/v1/member.php` — after the `bookings` creation line:
```php
Route::post('bookings/{booking}/extend', [BookingController::class, 'extend']);
```

Edit `routes/api/v1/admin.php` — after the `reject` line added in Task 8:
```php
Route::post('reception/bookings/{booking}/extend', [BookingReceptionController::class, 'extend']);
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Booking/BookingControllerTest.php tests/Feature/Booking/BookingApprovalControllerTest.php`
Expected: PASS (6 + 8 tests)

- [ ] **Step 8: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions anywhere in the suite

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/Member/Booking/ExtendBookingRequest.php app/Http/Requests/Admin/Reception/ExtendBookingRequest.php app/Http/Controllers/Api/V1/Member/BookingController.php app/Http/Controllers/Api/V1/Admin/Reception/BookingReceptionController.php routes/api/v1/member.php routes/api/v1/admin.php tests/Feature/Booking/BookingControllerTest.php tests/Feature/Booking/BookingApprovalControllerTest.php
git commit -m "feat: add member and reception booking extension endpoints"
```

---

### Task 11: Decision doc

**Files:**
- Create: `docs/decisions/booking-creation-approval-extension.md`
- Modify: `docs/decisions/README.md`

- [ ] **Step 1: Write the decision doc**

`docs/decisions/booking-creation-approval-extension.md`:
```md
# Booking Creation, Approval, Extension: buffer convention, capacity interaction, and the concurrency lesson applied

**Status:** resolved 2026-08-18. **Owner:** Maryam Asha.
**Type:** design doc for a new capability, alongside a scope note on an existing locked decision.

## What this phase adds

Member-facing booking creation (`App\Domain\Booking\Services\BookingCreationService`), an approval workflow for spaces that opt into it (`BookingApprovalService`, `BookingStatus::Pending|Rejected`), and a booking extension mechanism (`BookingExtensionService`) usable by both the member and reception. Full details: [the design spec](../superpowers/specs/2026-08-18-booking-creation-approval-extension-design.md) and [the implementation plan](../superpowers/plans/2026-08-18-booking-creation-approval-extension.md).

## This closes the gap Reception Operations deliberately left open

`docs/decisions/reception-operations-scope.md` explicitly deferred booking creation, slot granularity, buffer, approval, and extension to "the next phase." This is that phase — it does not touch check-in, check-out, cancellation, or settlement, all of which continue to act on whatever this phase creates, unchanged.

## Decision

- **Buffer boundary is inclusive.** A gap exactly equal to a space's `buffer_minutes` is accepted, not rejected. Matches `BusinessHoursService::isWithinBusinessHours`'s existing inclusive-boundary convention rather than inventing a stricter rule for a sibling concept. Tested by `BookingCreationServiceTest::test_creation_succeeds_exactly_at_the_buffer_boundary`.
- **`pending` counts against capacity/overlap exactly like `confirmed`.** `BookingCreationService`'s overlap query is `whereIn('status', [Confirmed, Pending])` — a `pending` booking is itself the hold on the slot; no separate reservation/hold mechanism exists. A `rejected` (or `cancelled`) booking falls outside that same `whereIn`, so the very next overlap check sees the slot as free again with no extra release step. Tested by `BookingCreationServiceTest::test_a_pending_booking_blocks_a_second_request_for_the_same_slot` and `BookingApprovalControllerTest::test_a_member_cancelling_their_own_pending_booking_releases_the_slot`.
- **Live-occupancy capacity check is inherited as a present-moment check, not re-solved as a future-window one.** `WalkInCapacityService`'s counting query already documents this as "an assumption to revisit when the full capacity-slot system lands" — `space_capacity_slots` stays out of scope this phase, so `BookingCreationService` reuses the identical counting logic rather than building the reservation-based version that assumption anticipates. A booking request for tomorrow afternoon can still be blocked by right now's occupancy; this is a known, inherited limitation.
- **Extension debits (and, unchanged, cancellation refunds) always target the member's personal wallet**, even when the original booking was paid from a company wallet. `BookingCancellationService::cancel()` already made this simplification for refunds, from before wallet-sourced booking payment existed to expose it. Nothing on `bookings` records which specific wallet paid — only `payment_source` (`wallet`/`cash`). This phase inherits the same simplification rather than adding a `payment_owner_type`/`payment_owner_id` column pair, which is schema growth beyond §1 of the phase brief. Revisit if a booking ever needs to be traced back to the exact wallet that funded it.
- **Notifications are `NotificationLog` rows, not real delivery.** No notification-sending mechanism existed anywhere in this codebase before this phase — no `app/Notifications/`, no `NotificationService`, zero prior writers to `notification_logs`. `channel` is written as `push` — the closest fit among the table's three legacy MySQL-`ENUM` values (`sms`/`push`/`email`) for an eventual in-app/dashboard notification — and `status` as `sent`, meaning "generated by this system," not "confirmed delivered to a device." A future phase can wire a real channel without changing `BookingCreationService`/`BookingApprovalService`'s call sites.
- **`ReceptionActionException` gained an optional `array $params = []` constructor argument**, defaulting to a no-op for every pre-existing call site. This is the first exception in this domain whose message needs dynamic content — extension's "latest possible end time stated explicitly in the error" can't be a static string, and `api.booking.extension_conflict` is accordingly the first placeholder-interpolated lang key in this codebase. Every controller catch site now calls `__($e->messageKey, $e->params)`; behavior for every existing key (none of which use placeholders) is unchanged.

## The concurrency lesson, applied proactively

The previous phase shipped a race (`SessionClosureService::autoClose()` checking an in-memory session object instead of a freshly locked row) that needed a second fix after review. Every read-check-write sequence this phase adds locks the row it depends on and re-reads it fresh inside one `DB::transaction()`, from the first commit:

| Operation | Locked row | Re-checked against |
|---|---|---|
| `BookingCreationService::create()` | `Space` | overlap, buffer, live occupancy |
| `BookingApprovalService::approve()`/`reject()` | `Booking` | current `status` |
| `BookingExtensionService::extend()` | `Booking`, then `Space` | checked-in/out state, then conflicting booking in the following interval |

Each has a concurrency test using the same two-independently-fetched-instances shape as `CloseOverdueReceptionSessionsCommandTest::test_autoclose_does_not_overwrite_a_session_closed_concurrently_since_it_was_loaded`: `BookingApprovalServiceTest::test_a_stale_approval_attempt_after_a_concurrent_rejection_is_rejected` and `BookingExtensionServiceTest::test_only_one_of_two_concurrent_extension_requests_for_the_same_following_slot_succeeds`.

## Why

See "Decision" above — each bullet states its own reasoning inline.

## What this changed in code

- `App\Domain\Booking\{Enums,Models,Services,Exceptions}\*` (extended, not forked)
- `App\Http\Controllers\Api\V1\{Member\BookingController, Admin\Reception\BookingReceptionController}`
- `App\Http\Requests\{Member\Booking, Admin\Reception}\*`
- `App\Http\Resources\BookingResource`
- `database/migrations/2026_08_18_*` (two migrations: `spaces` granularity/buffer/approval columns, `bookings` approval columns)
- `routes/api/v1/{member,admin}.php`
- `lang/{en,ar}/api.php` (`booking` group)
- `postman/ADD-OS.postman_collection.json` (`Member (App) > Bookings`, `Admin (Dashboard) > Reception Operations > Booking Approvals`)

## Guard

No dedicated `tests/Guards/` entry — same call as `reception-operations-scope.md`: additive capability, not a schema-shape invariant. Every rule is covered by `tests/Feature/Booking/*` and `tests/Unit/Domain/Booking/*` instead. `docs/decisions/README.md`'s PRD §7.1 table row for decision #5 is updated to also point here.
```

- [ ] **Step 2: Update `docs/decisions/README.md`'s decision #5 row**

Find the row (search for `| 5 | Booking = prepaid`) and replace it:
```
| 5 | Booking = prepaid + cancellable; session without booking = postpaid + not cancellable | `tests/Feature/Booking/*` (reception-ops slice, [reception-operations-scope.md](reception-operations-scope.md); creation/approval/extension slice, [booking-creation-approval-extension.md](booking-creation-approval-extension.md)); no dedicated `tests/Guards/` entry — additive capability, not a schema-shape invariant | 3 (slice), 4 (slice), 5 (full) |
```

- [ ] **Step 3: Commit**

```bash
git add docs/decisions/booking-creation-approval-extension.md docs/decisions/README.md
git commit -m "docs(decisions): booking creation, approval, extension"
```

---

### Task 12: Postman

**Files:**
- Modify: `postman/ADD-OS.postman_collection.json`

- [ ] **Step 1: Audit**

Read `postman/ADD-OS.postman_collection.json`'s `Member (App)` and `Admin (Dashboard) > Reception Operations` folders against `routes/api/v1/{member,admin}.php` on the current branch. Confirm: no `Bookings` folder exists yet under `Member (App)`; the `Reception Operations` folder's `description` field currently reads "Bookings/walk-ins are created via factory/tinker in this phase -- there is no creation endpoint yet, so set `{{booking_id}}`/`{{walkin_session_id}}` manually before running these" — now stale, since a creation endpoint exists as of this phase.

- [ ] **Step 2: Update the stale `Reception Operations` folder description**

Find (around line 3627):
```json
                                      "description": "Check-in/check-out, payment settlement, cancellation and manual wallet top-up (docs/decisions/reception-operations-scope.md). Bookings/walk-ins are created via factory/tinker in this phase -- there is no creation endpoint yet, so set {{booking_id}}/{{walkin_session_id}} manually before running these.",
```
Replace with:
```json
                                      "description": "Check-in/check-out, payment settlement, cancellation, manual wallet top-up, and booking approval/extension (docs/decisions/reception-operations-scope.md, docs/decisions/booking-creation-approval-extension.md). Bookings are created via Member (App) > Bookings > Create Booking, which auto-captures {{booking_id}}; walk-ins are created via Start Walk-in Session below, which auto-captures {{walkin_session_id}}.",
```

- [ ] **Step 3: Add the `Booking Approvals` subfolder to `Reception Operations`**

Find the end of the `Wallet Top-up` subfolder (its closing `]` immediately followed by the `Reception Operations` folder's own closing `]`, around lines 3959-3961):
```json
                                              ]
                                          }
                                      ]
                                  },
```
Replace with (adds a comma and the new subfolder before `Reception Operations` closes):
```json
                                              ]
                                          },
                                          {
                                              "name": "Booking Approvals",
                                              "item": [
                                                  {
                                                      "name": "Approve Booking",
                                                      "request": {
                                                          "method": "POST",
                                                          "header": [
                                                              { "key": "lang", "value": "{{lang}}", "type": "text" }
                                                          ],
                                                          "url": {
                                                              "raw": "{{base_url}}/api/v1/admin/reception/bookings/{{booking_id}}/approve",
                                                              "host": ["{{base_url}}"],
                                                              "path": ["api", "v1", "admin", "reception", "bookings", "{{booking_id}}", "approve"]
                                                          },
                                                          "description": "docs/decisions/booking-creation-approval-extension.md. booking_id must reference a pending booking (created against a space with requires_approval = true)."
                                                      }
                                                  },
                                                  {
                                                      "name": "Reject Booking",
                                                      "request": {
                                                          "method": "POST",
                                                          "header": [
                                                              { "key": "Content-Type", "value": "application/json" },
                                                              { "key": "lang", "value": "{{lang}}", "type": "text" }
                                                          ],
                                                          "body": {
                                                              "mode": "raw",
                                                              "raw": "{\n  \"rejection_reason\": \"Space closed for maintenance that day.\"\n}",
                                                              "options": { "raw": { "language": "json" } }
                                                          },
                                                          "url": {
                                                              "raw": "{{base_url}}/api/v1/admin/reception/bookings/{{booking_id}}/reject",
                                                              "host": ["{{base_url}}"],
                                                              "path": ["api", "v1", "admin", "reception", "bookings", "{{booking_id}}", "reject"]
                                                          }
                                                      }
                                                  },
                                                  {
                                                      "name": "Reject Booking — Error: Reason Required",
                                                      "request": {
                                                          "method": "POST",
                                                          "header": [
                                                              { "key": "Content-Type", "value": "application/json" },
                                                              { "key": "lang", "value": "{{lang}}", "type": "text" }
                                                          ],
                                                          "body": {
                                                              "mode": "raw",
                                                              "raw": "{}",
                                                              "options": { "raw": { "language": "json" } }
                                                          },
                                                          "url": {
                                                              "raw": "{{base_url}}/api/v1/admin/reception/bookings/{{booking_id}}/reject",
                                                              "host": ["{{base_url}}"],
                                                              "path": ["api", "v1", "admin", "reception", "bookings", "{{booking_id}}", "reject"]
                                                          },
                                                          "description": "422 -- rejection_reason is required."
                                                      }
                                                  },
                                                  {
                                                      "name": "Approve/Reject — Error: Not Pending",
                                                      "request": {
                                                          "method": "POST",
                                                          "header": [
                                                              { "key": "lang", "value": "{{lang}}", "type": "text" }
                                                          ],
                                                          "url": {
                                                              "raw": "{{base_url}}/api/v1/admin/reception/bookings/{{booking_id}}/approve",
                                                              "host": ["{{base_url}}"],
                                                              "path": ["api", "v1", "admin", "reception", "bookings", "{{booking_id}}", "approve"]
                                                          },
                                                          "description": "409 `{\"message\": \"This booking is not awaiting approval.\"}` when the booking is already confirmed, rejected, or cancelled."
                                                      }
                                                  },
                                                  {
                                                      "name": "Extend Booking (Reception, on the member's behalf)",
                                                      "request": {
                                                          "method": "POST",
                                                          "header": [
                                                              { "key": "Content-Type", "value": "application/json" },
                                                              { "key": "lang", "value": "{{lang}}", "type": "text" }
                                                          ],
                                                          "body": {
                                                              "mode": "raw",
                                                              "raw": "{\n  \"additional_minutes\": 60\n}",
                                                              "options": { "raw": { "language": "json" } }
                                                          },
                                                          "url": {
                                                              "raw": "{{base_url}}/api/v1/admin/reception/bookings/{{booking_id}}/extend",
                                                              "host": ["{{base_url}}"],
                                                              "path": ["api", "v1", "admin", "reception", "bookings", "{{booking_id}}", "extend"]
                                                          }
                                                      }
                                                  },
                                                  {
                                                      "name": "Extend Booking (Reception) — Error: Conflict",
                                                      "request": {
                                                          "method": "POST",
                                                          "header": [
                                                              { "key": "Content-Type", "value": "application/json" },
                                                              { "key": "lang", "value": "{{lang}}", "type": "text" }
                                                          ],
                                                          "body": {
                                                              "mode": "raw",
                                                              "raw": "{\n  \"additional_minutes\": 180\n}",
                                                              "options": { "raw": { "language": "json" } }
                                                          },
                                                          "url": {
                                                              "raw": "{{base_url}}/api/v1/admin/reception/bookings/{{booking_id}}/extend",
                                                              "host": ["{{base_url}}"],
                                                              "path": ["api", "v1", "admin", "reception", "bookings", "{{booking_id}}", "extend"]
                                                          },
                                                          "description": "422 -- states the latest possible end time explicitly."
                                                      }
                                                  }
                                              ]
                                          }
                                      ]
                                  },
```

- [ ] **Step 4: Add the `Bookings` folder under `Member (App)`**

Find the end of the `Wallet` folder under `Member (App)` (its closing `]` immediately followed by its own closing `}`, around lines 753-755):
```json
                                          }
                                      ]
                                  },
                                  {
                                      "name": "Memberships",
```
Replace with (inserts a new sibling folder between `Wallet` and `Memberships`):
```json
                                          }
                                      ]
                                  },
                                  {
                                      "name": "Bookings",
                                      "item": [
                                          {
                                              "name": "Create Booking",
                                              "event": [
                                                  {
                                                      "listen": "test",
                                                      "script": {
                                                          "type": "text/javascript",
                                                          "exec": [
                                                              "if (pm.response.code === 201) {",
                                                              "    pm.collectionVariables.set('booking_id', pm.response.json().data.id);",
                                                              "}"
                                                          ]
                                                      }
                                                  }
                                              ],
                                              "request": {
                                                  "auth": {
                                                      "type": "bearer",
                                                      "bearer": [{ "key": "token", "value": "{{member_token}}" }]
                                                  },
                                                  "method": "POST",
                                                  "header": [
                                                      { "key": "Content-Type", "value": "application/json" },
                                                      { "key": "lang", "value": "{{lang}}", "type": "text" }
                                                  ],
                                                  "body": {
                                                      "mode": "raw",
                                                      "raw": "{\n  \"space_id\": {{space_id}},\n  \"start_at\": \"2026-08-24T10:00:00+03:00\",\n  \"end_at\": \"2026-08-24T11:00:00+03:00\"\n}",
                                                      "options": { "raw": { "language": "json" } }
                                                  },
                                                  "url": {
                                                      "raw": "{{base_url}}/api/v1/member/bookings",
                                                      "host": ["{{base_url}}"],
                                                      "path": ["api", "v1", "member", "bookings"]
                                                  },
                                                  "description": "docs/decisions/booking-creation-approval-extension.md. Creates a confirmed+unpaid booking when the space has no wallet balance and requires_approval = false. 201 returns the created booking and auto-captures booking_id."
                                              }
                                          },
                                          {
                                              "name": "Create Booking — Error: Invalid Start Time",
                                              "request": {
                                                  "auth": { "type": "bearer", "bearer": [{ "key": "token", "value": "{{member_token}}" }] },
                                                  "method": "POST",
                                                  "header": [
                                                      { "key": "Content-Type", "value": "application/json" },
                                                      { "key": "lang", "value": "{{lang}}", "type": "text" }
                                                  ],
                                                  "body": {
                                                      "mode": "raw",
                                                      "raw": "{\n  \"space_id\": {{space_id}},\n  \"start_at\": \"2026-08-24T10:15:00+03:00\",\n  \"end_at\": \"2026-08-24T11:15:00+03:00\"\n}",
                                                      "options": { "raw": { "language": "json" } }
                                                  },
                                                  "url": {
                                                      "raw": "{{base_url}}/api/v1/member/bookings",
                                                      "host": ["{{base_url}}"],
                                                      "path": ["api", "v1", "member", "bookings"]
                                                  },
                                                  "description": "422 `{\"message\": \"The start time does not match this space's slot granularity.\"}`"
                                              }
                                          },
                                          {
                                              "name": "Create Booking — Error: Slot Unavailable",
                                              "request": {
                                                  "auth": { "type": "bearer", "bearer": [{ "key": "token", "value": "{{member_token}}" }] },
                                                  "method": "POST",
                                                  "header": [
                                                      { "key": "Content-Type", "value": "application/json" },
                                                      { "key": "lang", "value": "{{lang}}", "type": "text" }
                                                  ],
                                                  "body": {
                                                      "mode": "raw",
                                                      "raw": "{\n  \"space_id\": {{space_id}},\n  \"start_at\": \"2026-08-24T10:00:00+03:00\",\n  \"end_at\": \"2026-08-24T11:00:00+03:00\"\n}",
                                                      "options": { "raw": { "language": "json" } }
                                                  },
                                                  "url": {
                                                      "raw": "{{base_url}}/api/v1/member/bookings",
                                                      "host": ["{{base_url}}"],
                                                      "path": ["api", "v1", "member", "bookings"]
                                                  },
                                                  "description": "422 `{\"message\": \"This space is not available for the requested time.\"}` when a confirmed or pending booking already covers this window."
                                              }
                                          },
                                          {
                                              "name": "Create Booking — Error: Multiple Wallets, Choice Required",
                                              "request": {
                                                  "auth": { "type": "bearer", "bearer": [{ "key": "token", "value": "{{member_token}}" }] },
                                                  "method": "POST",
                                                  "header": [
                                                      { "key": "Content-Type", "value": "application/json" },
                                                      { "key": "lang", "value": "{{lang}}", "type": "text" }
                                                  ],
                                                  "body": {
                                                      "mode": "raw",
                                                      "raw": "{\n  \"space_id\": {{space_id}},\n  \"start_at\": \"2026-08-24T10:00:00+03:00\",\n  \"end_at\": \"2026-08-24T11:00:00+03:00\"\n}",
                                                      "options": { "raw": { "language": "json" } }
                                                  },
                                                  "url": {
                                                      "raw": "{{base_url}}/api/v1/member/bookings",
                                                      "host": ["{{base_url}}"],
                                                      "path": ["api", "v1", "member", "bookings"]
                                                  },
                                                  "description": "422 `{\"message\": \"More than one wallet can cover this booking. Please choose one.\", \"wallet_options\": [...]}` when both a personal and a company balance apply -- resubmit with wallet_owner_type/wallet_owner_id from the list (see Wallet > Get Wallet Options)."
                                              }
                                          },
                                          {
                                              "name": "Extend Booking",
                                              "request": {
                                                  "auth": { "type": "bearer", "bearer": [{ "key": "token", "value": "{{member_token}}" }] },
                                                  "method": "POST",
                                                  "header": [
                                                      { "key": "Content-Type", "value": "application/json" },
                                                      { "key": "lang", "value": "{{lang}}", "type": "text" }
                                                  ],
                                                  "body": {
                                                      "mode": "raw",
                                                      "raw": "{\n  \"additional_minutes\": 60\n}",
                                                      "options": { "raw": { "language": "json" } }
                                                  },
                                                  "url": {
                                                      "raw": "{{base_url}}/api/v1/member/bookings/{{booking_id}}/extend",
                                                      "host": ["{{base_url}}"],
                                                      "path": ["api", "v1", "member", "bookings", "{{booking_id}}", "extend"]
                                                  },
                                                  "description": "Booking must already be checked in (checked_in_at set, checked_out_at null)."
                                              }
                                          },
                                          {
                                              "name": "Extend Booking — Error: Conflict",
                                              "request": {
                                                  "auth": { "type": "bearer", "bearer": [{ "key": "token", "value": "{{member_token}}" }] },
                                                  "method": "POST",
                                                  "header": [
                                                      { "key": "Content-Type", "value": "application/json" },
                                                      { "key": "lang", "value": "{{lang}}", "type": "text" }
                                                  ],
                                                  "body": {
                                                      "mode": "raw",
                                                      "raw": "{\n  \"additional_minutes\": 180\n}",
                                                      "options": { "raw": { "language": "json" } }
                                                  },
                                                  "url": {
                                                      "raw": "{{base_url}}/api/v1/member/bookings/{{booking_id}}/extend",
                                                      "host": ["{{base_url}}"],
                                                      "path": ["api", "v1", "member", "bookings", "{{booking_id}}", "extend"]
                                                  },
                                                  "description": "422 `{\"message\": \"This booking cannot be extended past <latest_end_at>.\"}` when a confirmed or pending booking follows on the same space."
                                              }
                                          }
                                      ]
                                  },
                                  {
                                      "name": "Memberships",
```

- [ ] **Step 5: Validate the JSON**

Run: `php -r "json_decode(file_get_contents('postman/ADD-OS.postman_collection.json'), false, 512, JSON_THROW_ON_ERROR); echo 'valid';"`
Expected: prints `valid` — confirms no trailing-comma or bracket-matching mistake was introduced.

- [ ] **Step 6: Commit**

```bash
git add postman/ADD-OS.postman_collection.json
git commit -m "docs(postman): add Bookings and Booking Approvals folders, update stale description"
```

---

## Final verification

- [ ] Run the entire suite once more: `php artisan test`. Expected: PASS, zero failures, zero regressions in any pre-existing Booking/Reception/Membership/Foundation test.
- [ ] Run `./vendor/bin/pint --test` and fix any style violation it reports.
- [ ] Run `php artisan route:list --path=bookings` and `php artisan route:list --path=reception/bookings` to visually confirm every new route from Tasks 6, 8, and 10 is registered exactly once, under the correct role group.
