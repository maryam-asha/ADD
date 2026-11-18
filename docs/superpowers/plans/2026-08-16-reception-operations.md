# Reception Operations (Phase 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the reception-facing operational layer — check-in, check-out, automatic session closure, payment settlement, booking cancellation, and manual wallet top-up — as a minimum schema slice supporting reception operations only. Full booking rules (slot granularity, extension, approval) are explicitly deferred to a later phase.

**Architecture:** New `App\Domain\Booking` namespace with two tables (`bookings`, `walkin_sessions`) sharing an identical payment/termination shape, three domain services (`WalkInCapacityService`, `SessionClosureService`, `BookingCancellationService`) handling the business rules, and three command-shaped admin controllers (`BookingReceptionController`, `WalkInSessionController`, `WalletTopUpController`) under the existing `operations|admin` route group. Reuses `WalletService`, `BusinessHoursService`, and `LogsSensitiveActions` as-is. A scheduled artisan command closes overdue sessions.

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit, SQLite in-memory (tests), Carbon, bcmath.

**Spec:** [docs/superpowers/specs/2026-08-16-reception-operations-design.md](../specs/2026-08-16-reception-operations-design.md) — read it alongside this plan; this plan argues from it and doesn't repeat its rationale.

## Global Constraints

- PHP `^8.2`, Laravel Framework `^12.0`.
- **Never use `->enum()` in a migration.** Every enum-shaped column is a `string` column cast to a PHP 8.2 backed enum on the model — guarded by `tests/Guards/NoNewMysqlEnumColumnsTest.php` (pure grep, no registration needed) and `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` (model side — add an entry to its `EXPECTED_CASTS` map for every new enum-shaped column, in the task that creates the model).
- **Every backed enum in this codebase is string-backed.** All five new enums (`BookingStatus`, `PaymentState`, `PaymentSource`, `TerminationSource`, `Finance\PaymentMethod`) follow this.
- Models under `App\Domain\<Domain>\Models`, enums under `Enums`, services under `Services`, exceptions under `Exceptions` — created only where actually needed.
- Eloquent casts are declared via the `casts(): array` method, never the legacy `protected $casts` property.
- Factories are flat under `database/factories/{Name}Factory.php` (namespace `Database\Factories`), with an explicit `protected $model = X::class;`.
- **Update-style endpoints return `{"message": "..."}`, never the resource.** `store()`-style endpoints (walk-in start, wallet top-up) return the created data, since the client needs the new id back — same split CLAUDE.md documents for every other domain. Keys live in `lang/en/api.php` / `lang/ar/api.php` under a new top-level `'reception'` group, mirroring the existing flat-group structure (see Task 10).
- **Admin feature tests**: `use RefreshDatabase;`, `$this->seed(RoleSeeder::class);` in `setUp()`, authenticate via `Laravel\Sanctum\Sanctum::actingAs($user, ['*'])` after `$user->assignRole('admin')` or `'operations'` — each test class defines its own private `actingAsAdmin()`/`actingAsOperations()` helpers (no shared base-class helper exists in this codebase; don't add one here either).
- `routes/api/v1/admin.php`: **every route in this file already sits behind `auth:sanctum` + `abilities:dashboard` + `role:admin|operations`**, applied once by the group wrapping this file in `routes/api.php` — do not add a redundant role-middleware string inside `admin.php` itself. New routes are plain top-level `Route::post(...)` calls; there is no destroy/admin-only carve-out needed for reception (both roles do all of it).
- Migration filenames: `database/migrations/YYYY_MM_DD_HHMMSS_verb_description.php`, one flat directory. Most recent existing migration is `2026_08_15_100100_create_business_hour_exceptions_table.php` — this plan's four migrations use the `2026_08_16_*` prefix to sort after it.
- **Money**: `DECIMAL(10,2)` exclusively, every arithmetic operation via `bcmath` (`bcmul`/`bcadd`/`bcsub`/`bccomp`), never a float. Currency is copied from `Space.pricing_currency` at the point an amount is computed — never forced to USD, no `usd_equivalent`-style column anywhere in this plan.
- `App\Domain\Foundation\Services\BusinessHoursService` (already built, do not modify): `isWithinBusinessHours(CarbonInterface $instant, Branch $branch): bool` and `periodsFor(CarbonInterface $date, Branch $branch): array` (each period `['open_time' => 'H:i', 'close_time' => 'H:i']`, empty array = closed that day). Never throws. Both boundaries inclusive. Resolves through the `app.timezone` Setting (default `Asia/Damascus`) internally — callers pass a plain instant, not something pre-converted.
- `App\Domain\Membership\Services\WalletService` (already built, **do not change its method signatures**): `walletFor(OwnerType, int): Wallet`, `creditGeneral(Wallet, string $amount, WalletTransactionSource, ?string $description): WalletTransaction`, `debitGeneral(Wallet, string $amount, ?string $description): WalletTransaction` (throws `InsufficientBalanceException`).
- `App\Concerns\LogsSensitiveActions` trait: `use` it on every new controller, call `$this->logSensitiveAction(string $action, Model $subject, array $properties = [])` on every state-changing action.
- `docs/decisions/*.md` format: `# Title`, `**Status:** resolved <date>. **Owner:** Maryam Asha.`, then `## Decision`, `## Why`, `## What this changed in code`, `## Guard` sections.
- **SQLite in-memory test DB** (`phpunit.xml`, `DB_DATABASE=:memory:`): a genuine two-connection concurrency test isn't reproducible (each connection gets its own separate in-memory database). Task 5's "concurrency" test instead proves serialized-order correctness — the property that actually matters once `lockForUpdate()` serializes two real concurrent requests on MySQL: the second arrival, evaluated after the first's commit, never sees stale capacity.
- Both `Booking` and `WalkinSession` share an identical payment/termination column set by design (no formal interface needed — PHP union types `Booking|WalkinSession` in service method signatures are sufficient; adding a shared contract interface would be an unused abstraction at this scale).
- **`lang/en/api.php` and `lang/ar/api.php` currently carry unrelated, unstaged local edits** (from a separate, unrelated in-progress task — not part of this plan). Before Task 10's commit step, run `git diff lang/en/api.php lang/ar/api.php` and stage only the new `'reception' => [...]` hunk (e.g. `git add -p`), not the whole file, so the two unrelated changes land in separate commits.

---

### Task 1: Booking domain enums, `Finance\PaymentMethod`, `WalletTransactionSource::Refund`

**Files:**
- Create: `app/Domain/Booking/Enums/BookingStatus.php`
- Create: `app/Domain/Booking/Enums/PaymentState.php`
- Create: `app/Domain/Booking/Enums/PaymentSource.php`
- Create: `app/Domain/Booking/Enums/TerminationSource.php`
- Create: `app/Domain/Finance/Enums/PaymentMethod.php`
- Modify: `app/Domain/Membership/Enums/WalletTransactionSource.php`
- Test: `tests/Unit/Domain/Booking/EnumsTest.php`

**Interfaces:**
- Produces: `BookingStatus::Confirmed|Cancelled`, `PaymentState::Paid|Unpaid`, `PaymentSource::Wallet|Cash`, `TerminationSource::Reception|Auto`, `PaymentMethod::Cash|Sham|Mtn|Syriatel`, `WalletTransactionSource::Refund` — every later task in this plan consumes these exact case names.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Membership\Enums\WalletTransactionSource;
use Tests\TestCase;

class EnumsTest extends TestCase
{
    public function test_booking_status_cases(): void
    {
        $this->assertSame(['confirmed', 'cancelled'], array_column(BookingStatus::cases(), 'value'));
    }

    public function test_payment_state_cases(): void
    {
        $this->assertSame(['paid', 'unpaid'], array_column(PaymentState::cases(), 'value'));
    }

    public function test_payment_source_cases(): void
    {
        $this->assertSame(['wallet', 'cash'], array_column(PaymentSource::cases(), 'value'));
    }

    public function test_termination_source_cases(): void
    {
        $this->assertSame(['reception', 'auto'], array_column(TerminationSource::cases(), 'value'));
    }

    public function test_payment_method_cases(): void
    {
        $this->assertSame(['cash', 'sham', 'mtn', 'syriatel'], array_column(PaymentMethod::cases(), 'value'));
    }

    public function test_wallet_transaction_source_gained_a_refund_case(): void
    {
        $this->assertSame('refund', WalletTransactionSource::Refund->value);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Booking/EnumsTest.php`
Expected: FAIL — `Class "App\Domain\Booking\Enums\BookingStatus" not found`

- [ ] **Step 3: Create the four Booking domain enums**

`app/Domain/Booking/Enums/BookingStatus.php`:
```php
<?php

namespace App\Domain\Booking\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
```

`app/Domain/Booking/Enums/PaymentState.php`:
```php
<?php

namespace App\Domain\Booking\Enums;

enum PaymentState: string
{
    case Paid = 'paid';
    case Unpaid = 'unpaid';
}
```

`app/Domain/Booking/Enums/PaymentSource.php`:
```php
<?php

namespace App\Domain\Booking\Enums;

/**
 * The coarse routing recorded on a booking/walk-in: whether it was (or will
 * be) paid from the member's wallet, or is destined for a cash-equivalent
 * settlement. The specific cash channel (cash|sham|mtn|syriatel) is a
 * separate, finer-grained value recorded only at settlement time — see
 * App\Domain\Finance\Enums\PaymentMethod.
 */
enum PaymentSource: string
{
    case Wallet = 'wallet';
    case Cash = 'cash';
}
```

`app/Domain/Booking/Enums/TerminationSource.php`:
```php
<?php

namespace App\Domain\Booking\Enums;

enum TerminationSource: string
{
    case Reception = 'reception';
    case Auto = 'auto';
}
```

- [ ] **Step 4: Create `Finance\PaymentMethod`**

`app/Domain/Finance/Enums/PaymentMethod.php`:
```php
<?php

namespace App\Domain\Finance\Enums;

/**
 * Placed in Finance rather than Booking because the backend build plan
 * (docs/architecture/2026-08-08-backend-build-plan.md §A.1) already
 * earmarks this domain for payment methods (Phase 4) — this is a minimal
 * sliver of that domain pulled forward, not the full payment_methods/
 * transactions/Money system, which is not built here.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Sham = 'sham';
    case Mtn = 'mtn';
    case Syriatel = 'syriatel';
}
```

- [ ] **Step 5: Add `Refund` to `WalletTransactionSource`**

Edit `app/Domain/Membership/Enums/WalletTransactionSource.php` — add one case:

```php
<?php

namespace App\Domain\Membership\Enums;

/**
 * Documentation/reporting only (docs/decisions/wallet-points-categorization.md).
 * No code may branch on this enum's value to decide spend behavior — the
 * debit-resolution algorithm reads only `category`, `expires_at`, and
 * `wallet_transaction_allowed_users`, never `source`.
 */
enum WalletTransactionSource: string
{
    case TopUp = 'top_up';
    case SubscriptionGrant = 'subscription_grant';
    case Gift = 'gift';
    case CompanyAdminAllocation = 'company_admin_allocation';
    case Refund = 'refund';
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Booking/EnumsTest.php`
Expected: PASS (6 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Booking/Enums app/Domain/Finance/Enums/PaymentMethod.php app/Domain/Membership/Enums/WalletTransactionSource.php tests/Unit/Domain/Booking/EnumsTest.php
git commit -m "feat: add Booking domain enums, Finance PaymentMethod, wallet Refund source"
```

---

### Task 2: `bookings` table, `Booking` model, factory

**Files:**
- Create: `database/migrations/2026_08_16_090000_create_bookings_table.php`
- Create: `app/Domain/Booking/Models/Booking.php`
- Create: `database/factories/BookingFactory.php`
- Modify: `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
- Test: `tests/Unit/Domain/Booking/BookingModelTest.php`

**Interfaces:**
- Consumes: `BookingStatus`, `PaymentState`, `PaymentSource`, `TerminationSource` (Task 1), `App\Domain\Finance\Enums\PaymentMethod` (Task 1), `App\Domain\Foundation\Models\Space`, `App\Domain\Identity\Models\User`.
- Produces: `Booking` model with columns `space_id, user_id, start_at, end_at, status, payment_state, payment_source, checked_in_at, checked_out_at, termination_source, amount_owed, currency, payment_method, paid_by, paid_at, cancelled_at`; relations `space(): BelongsTo`, `user(): BelongsTo`. Every later task's `Booking|WalkinSession` union-typed service method consumes this exact column set.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_booking_can_be_created_with_defaults(): void
    {
        $space = Space::factory()->room()->create();
        $member = User::factory()->create();

        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
        ]);

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertNull($booking->payment_source);
        $this->assertNull($booking->checked_in_at);
        $this->assertTrue($booking->space->is($space));
        $this->assertTrue($booking->user->is($member));
    }

    public function test_a_booking_can_carry_a_full_settlement(): void
    {
        $operator = User::factory()->create();

        $booking = Booking::factory()->create([
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Cash,
            'payment_method' => PaymentMethod::Sham,
            'paid_by' => $operator->id,
            'paid_at' => now(),
            'amount_owed' => '12.50',
            'currency' => 'USD',
        ]);

        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(PaymentSource::Cash, $booking->payment_source);
        $this->assertSame(PaymentMethod::Sham, $booking->payment_method);
        $this->assertSame('12.50', (string) $booking->amount_owed);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Booking/BookingModelTest.php`
Expected: FAIL — `Class "App\Domain\Booking\Models\Booking" not found`

- [ ] **Step 3: Write the migration**

`database/migrations/2026_08_16_090000_create_bookings_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimum schema for reception operations
     * (docs/superpowers/specs/2026-08-16-reception-operations-design.md) —
     * not the full Phase 5 booking system (no space_capacity_slots,
     * affected_bookings, extension/approval). payment_state defaults to
     * unpaid and payment_source is nullable: payment is a state, never a
     * precondition for creation (2026-08-15 decision session, decisions
     * #1-3) — a booking-creation flow (out of scope this phase) may mark
     * it paid immediately via wallet, or leave it unpaid for reception to
     * settle later.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces');
            $table->foreignId('user_id')->constrained('users');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('status', 20)->default('confirmed');
            $table->string('payment_state', 20)->default('unpaid');
            $table->string('payment_source', 20)->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->string('termination_source', 20)->nullable();
            $table->decimal('amount_owed', 10, 2)->nullable();
            $table->string('currency')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['space_id', 'checked_in_at', 'checked_out_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
```

- [ ] **Step 4: Write the model**

`app/Domain/Booking/Models/Booking.php`:
```php
<?php

namespace App\Domain\Booking\Models;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    use HasFactory;

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
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Write the factory**

`database/factories/BookingFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addDay()->setTime(10, 0);

        return [
            'space_id' => Space::factory()->room(),
            'user_id' => User::factory(),
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
            'status' => BookingStatus::Confirmed,
            'payment_state' => PaymentState::Unpaid,
        ];
    }

    public function checkedIn(): static
    {
        return $this->state(fn () => ['checked_in_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
```

- [ ] **Step 6: Register the enum casts in the guard test**

Edit `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` — add the `Booking` import and an `EXPECTED_CASTS` entry:

```php
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentMethod;
```

(added alongside the existing `use` block, alphabetically among the `App\Domain\*` imports), and inside `EXPECTED_CASTS`:

```php
        Booking::class => [
            'status' => BookingStatus::class,
            'payment_state' => PaymentState::class,
            'payment_source' => PaymentSource::class,
            'termination_source' => TerminationSource::class,
            'payment_method' => PaymentMethod::class,
        ],
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Booking/BookingModelTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
Expected: PASS (2 + 1 tests)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_16_090000_create_bookings_table.php app/Domain/Booking/Models/Booking.php database/factories/BookingFactory.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php tests/Unit/Domain/Booking/BookingModelTest.php
git commit -m "feat: add bookings table, Booking model and factory"
```

---

### Task 3: `walkin_sessions` table, `WalkinSession` model, factory

**Files:**
- Create: `database/migrations/2026_08_16_090100_create_walkin_sessions_table.php`
- Create: `app/Domain/Booking/Models/WalkinSession.php`
- Create: `database/factories/WalkinSessionFactory.php`
- Modify: `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
- Test: `tests/Unit/Domain/Booking/WalkinSessionModelTest.php`

**Interfaces:**
- Consumes: same enums as Task 2, minus `BookingStatus`.
- Produces: `WalkinSession` model — identical shape to `Booking` minus `start_at`/`end_at`/`status`/`cancelled_at`. Same `space()`/`user()` relations.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalkinSessionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_walkin_session_can_be_created_with_defaults(): void
    {
        $space = Space::factory()->room()->create();
        $member = User::factory()->create();

        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
        ]);

        $this->assertSame(PaymentState::Unpaid, $session->payment_state);
        $this->assertNotNull($session->checked_in_at);
        $this->assertNull($session->checked_out_at);
        $this->assertTrue($session->space->is($space));
        $this->assertTrue($session->user->is($member));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Booking/WalkinSessionModelTest.php`
Expected: FAIL — `Class "App\Domain\Booking\Models\WalkinSession" not found`

- [ ] **Step 3: Write the migration**

`database/migrations/2026_08_16_090100_create_walkin_sessions_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same payment/termination shape as `bookings` (see that migration's
     * docblock), minus a planned window: a walk-in has no start_at/end_at
     * and, per PRD decision #5, no cancellation path — postpaid, settled
     * only after checkout.
     */
    public function up(): void
    {
        Schema::create('walkin_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces');
            $table->foreignId('user_id')->constrained('users');
            $table->dateTime('checked_in_at');
            $table->dateTime('checked_out_at')->nullable();
            $table->string('payment_state', 20)->default('unpaid');
            $table->string('payment_source', 20)->nullable();
            $table->string('termination_source', 20)->nullable();
            $table->decimal('amount_owed', 10, 2)->nullable();
            $table->string('currency')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->index(['space_id', 'checked_out_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walkin_sessions');
    }
};
```

- [ ] **Step 4: Write the model**

`app/Domain/Booking/Models/WalkinSession.php`:
```php
<?php

namespace App\Domain\Booking\Models;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalkinSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'space_id',
        'user_id',
        'checked_in_at',
        'checked_out_at',
        'payment_state',
        'payment_source',
        'termination_source',
        'amount_owed',
        'currency',
        'payment_method',
        'paid_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'payment_state' => PaymentState::class,
            'payment_source' => PaymentSource::class,
            'termination_source' => TerminationSource::class,
            'amount_owed' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Write the factory**

`database/factories/WalkinSessionFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalkinSession>
 */
class WalkinSessionFactory extends Factory
{
    protected $model = WalkinSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'space_id' => Space::factory()->room(),
            'user_id' => User::factory(),
            'checked_in_at' => now(),
            'payment_state' => PaymentState::Unpaid,
        ];
    }
}
```

- [ ] **Step 6: Register the enum casts in the guard test**

Edit `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` — add `use App\Domain\Booking\Models\WalkinSession;` and, inside `EXPECTED_CASTS`, right after the `Booking::class` entry from Task 2:

```php
        WalkinSession::class => [
            'payment_state' => PaymentState::class,
            'payment_source' => PaymentSource::class,
            'termination_source' => TerminationSource::class,
            'payment_method' => PaymentMethod::class,
        ],
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Booking/WalkinSessionModelTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
Expected: PASS (1 + 1 tests)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_16_090100_create_walkin_sessions_table.php app/Domain/Booking/Models/WalkinSession.php database/factories/WalkinSessionFactory.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php tests/Unit/Domain/Booking/WalkinSessionModelTest.php
git commit -m "feat: add walkin_sessions table, WalkinSession model and factory"
```

---

### Task 4: Additive columns — `spaces.cancellation_window_minutes`, `wallet_transactions.{performed_by_user_id,payment_method}`

**Files:**
- Create: `database/migrations/2026_08_16_090200_add_cancellation_window_minutes_to_spaces_table.php`
- Create: `database/migrations/2026_08_16_090300_add_reception_columns_to_wallet_transactions_table.php`
- Modify: `app/Domain/Foundation/Models/Space.php`
- Modify: `app/Domain/Membership/Models/WalletTransaction.php`
- Modify: `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
- Test: `tests/Unit/Domain/Booking/ReceptionAdditiveColumnsTest.php`

**Interfaces:**
- Produces: `Space.cancellation_window_minutes` (nullable int, per-space override — falls back to the already-seeded global `booking.cancellation_window_minutes` setting, decision #4). `WalletTransaction.performed_by_user_id` (nullable FK users), `WalletTransaction.payment_method` (nullable, `App\Domain\Finance\Enums\PaymentMethod`), `WalletTransaction::performedBy(): BelongsTo`. Task 9 (cancellation) reads the first; Task 13 (wallet top-up) writes the other two.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceptionAdditiveColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_space_can_carry_a_cancellation_window_override(): void
    {
        $space = Space::factory()->room()->create(['cancellation_window_minutes' => 120]);

        $this->assertSame(120, $space->fresh()->cancellation_window_minutes);
    }

    public function test_a_space_without_an_override_has_a_null_cancellation_window(): void
    {
        $space = Space::factory()->room()->create();

        $this->assertNull($space->fresh()->cancellation_window_minutes);
    }

    public function test_a_wallet_transaction_can_record_the_operator_and_payment_method(): void
    {
        $wallet = Wallet::factory()->create();
        $operator = User::factory()->create();
        $transaction = (new WalletService)->creditGeneral($wallet, '10.00', WalletTransactionSource::TopUp);

        $transaction->forceFill([
            'performed_by_user_id' => $operator->id,
            'payment_method' => PaymentMethod::Cash,
        ])->save();

        $transaction->refresh();
        $this->assertTrue($transaction->performedBy->is($operator));
        $this->assertSame(PaymentMethod::Cash, $transaction->payment_method);
    }

    public function test_a_wallet_transaction_without_an_operator_has_null_performed_by(): void
    {
        $wallet = Wallet::factory()->create();
        $transaction = (new WalletService)->creditGeneral($wallet, '10.00', WalletTransactionSource::TopUp);

        $this->assertNull($transaction->fresh()->performed_by_user_id);
        $this->assertNull($transaction->fresh()->payment_method);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Booking/ReceptionAdditiveColumnsTest.php`
Expected: FAIL — `Unknown column 'cancellation_window_minutes'`

- [ ] **Step 3: Write the `spaces` migration**

`database/migrations/2026_08_16_090200_add_cancellation_window_minutes_to_spaces_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-space override of the global booking.cancellation_window_minutes
     * setting (decision #4) — a plain column, not a scoped Setting row,
     * per App\Domain\Settings\Enums\SettingScope's own docblock: a
     * per-space override belongs on that domain's own model, not a new
     * SettingScope case.
     */
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->unsignedInteger('cancellation_window_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn('cancellation_window_minutes');
        });
    }
};
```

- [ ] **Step 4: Write the `wallet_transactions` migration**

`database/migrations/2026_08_16_090300_add_reception_columns_to_wallet_transactions_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reception's manual wallet top-up needs to record which operator keyed
     * it and which physical/electronic channel the money came in through.
     * Both nullable — null for every existing row and for any future
     * non-reception-initiated transaction.
     */
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users');
            $table->string('payment_method', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('performed_by_user_id');
            $table->dropColumn('payment_method');
        });
    }
};
```

- [ ] **Step 5: Add the column to `Space`'s fillable**

Edit `app/Domain/Foundation/Models/Space.php` — add one entry to `$fillable`:

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
        'status',
        'status_reason',
        'status_from',
        'status_until',
    ];
```

- [ ] **Step 6: Add the columns/relation/cast to `WalletTransaction`**

Edit `app/Domain/Membership/Models/WalletTransaction.php`:

```php
<?php

namespace App\Domain\Membership\Models;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * docs/decisions/wallet-points-categorization.md. `amount` is signed:
 * positive is a credit/grant, negative is a debit. No rows in
 * `wallet_transaction_allowed_users` means unrestricted (any member of the
 * owning wallet is eligible); rows present restrict eligibility to those
 * users. `performed_by_user_id`/`payment_method` are reception-only
 * metadata (docs/superpowers/specs/2026-08-16-reception-operations-design.md)
 * — null for every transaction created outside a manual reception action.
 */
class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'amount',
        'description',
        'category',
        'restricted_space_id',
        'source',
        'expires_at',
        'performed_by_user_id',
        'payment_method',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'category' => WalletTransactionCategory::class,
            'source' => WalletTransactionSource::class,
            'expires_at' => 'datetime',
            'payment_method' => PaymentMethod::class,
        ];
    }

    /**
     * The categorization doc's own required validator: `restricted_space_id`
     * may only be set alongside `category = space_specific`. The reverse
     * (space_specific with a null restricted_space_id) is explicitly
     * allowed and not checked here.
     */
    protected static function booted(): void
    {
        static::saving(function (self $transaction) {
            if ($transaction->restricted_space_id !== null
                && $transaction->category !== WalletTransactionCategory::SpaceSpecific) {
                throw new \InvalidArgumentException(
                    'restricted_space_id may only be set when category is space_specific.'
                );
            }
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function allowedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wallet_transaction_allowed_users');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    public function isRestricted(): bool
    {
        return $this->allowedUsers()->exists();
    }

    public function isEligibleFor(User $user): bool
    {
        return ! $this->isRestricted() || $this->allowedUsers()->where('users.id', $user->id)->exists();
    }
}
```

- [ ] **Step 7: Register the `payment_method` cast in the guard test**

Edit `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` — extend the existing `WalletTransaction::class` entry (do not duplicate the key):

```php
        WalletTransaction::class => [
            'category' => WalletTransactionCategory::class,
            'source' => WalletTransactionSource::class,
            'payment_method' => PaymentMethod::class,
        ],
```

(`PaymentMethod` is already imported from Task 2's edit to this same file.)

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Booking/ReceptionAdditiveColumnsTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
Expected: PASS (4 + 1 tests)

- [ ] **Step 9: Run the full suite once to catch any regression in existing Wallet/Space tests**

Run: `php artisan test tests/Feature/Membership tests/Feature/Foundation`
Expected: PASS, no regressions

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_08_16_090200_add_cancellation_window_minutes_to_spaces_table.php database/migrations/2026_08_16_090300_add_reception_columns_to_wallet_transactions_table.php app/Domain/Foundation/Models/Space.php app/Domain/Membership/Models/WalletTransaction.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php tests/Unit/Domain/Booking/ReceptionAdditiveColumnsTest.php
git commit -m "feat: add cancellation-window and reception operator/payment-method columns"
```

---

### Task 5: `ReceptionActionException`, `WalkInCapacityService`

**Files:**
- Create: `app/Domain/Booking/Exceptions/ReceptionActionException.php`
- Create: `app/Domain/Booking/Services/WalkInCapacityService.php`
- Test: `tests/Feature/Booking/WalkInCapacityServiceTest.php`

**Interfaces:**
- Consumes: `App\Domain\Foundation\Services\BusinessHoursService::isWithinBusinessHours()`, `App\Domain\Foundation\Models\Space`, `App\Domain\Booking\Models\{Booking,WalkinSession}`.
- Produces: `ReceptionActionException(string $messageKey, int $status = 422)` — every later service/controller in this plan throws/catches this one exception type. `WalkInCapacityService::start(Space $space, User $member): WalkinSession`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalkInCapacityServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalkInCapacityService $capacity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capacity = app(WalkInCapacityService::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function openSpace(?int $capacity): Space
    {
        $space = Space::factory()->room()->create(['capacity' => $capacity]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_a_walk_in_starts_successfully_when_capacity_is_available(): void
    {
        $space = $this->openSpace(2);
        $member = User::factory()->create();

        $session = $this->capacity->start($space, $member);

        $this->assertInstanceOf(WalkinSession::class, $session);
        $this->assertTrue($session->checked_in_at->equalTo(now()));
        $this->assertTrue($session->space->is($space));
        $this->assertTrue($session->user->is($member));
    }

    public function test_a_null_capacity_space_is_treated_as_unlimited(): void
    {
        $space = $this->openSpace(null);

        $this->capacity->start($space, User::factory()->create());
        $second = $this->capacity->start($space, User::factory()->create());

        $this->assertInstanceOf(WalkinSession::class, $second);
    }

    public function test_a_second_walk_in_is_rejected_once_the_last_unit_of_capacity_is_taken(): void
    {
        $space = $this->openSpace(1);
        $first = User::factory()->create();
        $second = User::factory()->create();

        // True multi-connection concurrency isn't reproducible against this
        // suite's in-memory SQLite (each connection would get its own
        // separate database — see this plan's Global Constraints). This
        // proves the property that actually matters once `lockForUpdate()`
        // serializes two real concurrent requests on MySQL: the second
        // arrival, evaluated only after the first's commit, never sees
        // stale capacity and is correctly rejected.
        $this->capacity->start($space, $first);

        try {
            $this->capacity->start($space, $second);
            $this->fail('Expected a ReceptionActionException for no capacity.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.no_capacity', $e->messageKey);
            $this->assertSame(422, $e->status);
        }

        $this->assertSame(1, WalkinSession::where('space_id', $space->id)->count());
    }

    public function test_an_existing_checked_in_booking_counts_toward_capacity(): void
    {
        $space = $this->openSpace(1);
        Booking::factory()->checkedIn()->create(['space_id' => $space->id]);

        $this->expectException(ReceptionActionException::class);
        $this->capacity->start($space, User::factory()->create());
    }

    public function test_a_checked_out_booking_does_not_count_toward_capacity(): void
    {
        $space = $this->openSpace(1);
        Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_out_at' => now()->subMinutes(10),
        ]);

        $session = $this->capacity->start($space, User::factory()->create());

        $this->assertInstanceOf(WalkinSession::class, $session);
    }

    public function test_starting_a_walk_in_outside_business_hours_fails(): void
    {
        $space = $this->openSpace(2);
        Carbon::setTestNow(Carbon::parse('2026-08-17 23:00:00', 'Asia/Damascus'));

        try {
            $this->capacity->start($space, User::factory()->create());
            $this->fail('Expected a ReceptionActionException for outside business hours.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.outside_business_hours', $e->messageKey);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/WalkInCapacityServiceTest.php`
Expected: FAIL — `Class "App\Domain\Booking\Services\WalkInCapacityService" not found`

- [ ] **Step 3: Write `ReceptionActionException`**

`app/Domain/Booking/Exceptions/ReceptionActionException.php`:
```php
<?php

namespace App\Domain\Booking\Exceptions;

use RuntimeException;

/**
 * One exception type for every reception-operation precondition failure
 * across booking check-in/check-out/cancel/settlement and walk-in
 * start/check-out/settlement. Carries the translation key and HTTP status
 * the controller maps directly to a JSON error response — a proliferation
 * of one-off exception subclasses would buy nothing over this for the
 * ~15 distinct failure modes this plan needs.
 */
class ReceptionActionException extends RuntimeException
{
    public function __construct(public readonly string $messageKey, public readonly int $status = 422)
    {
        parent::__construct($messageKey);
    }
}
```

- [ ] **Step 4: Write `WalkInCapacityService`**

`app/Domain/Booking/Services/WalkInCapacityService.php`:
```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

class WalkInCapacityService
{
    public function __construct(private readonly BusinessHoursService $businessHours) {}

    /**
     * "Space has available capacity right now" is a physical-presence count
     * (currently checked-in-and-not-checked-out bookings + walk-ins),
     * compared against Space.capacity — not a reservation/slot-overlap
     * count (that's space_capacity_slots, out of scope this phase). A
     * confirmed booking that hasn't checked in yet does not count against
     * a walk-in's capacity check — flagged in the decision doc as an
     * assumption to revisit when the full capacity-slot system lands.
     * `capacity === null` is treated as unlimited: a space with no
     * configured capacity shouldn't block every walk-in.
     */
    public function start(Space $space, User $member): WalkinSession
    {
        return DB::transaction(function () use ($space, $member) {
            $locked = Space::query()->whereKey($space->id)->lockForUpdate()->firstOrFail();

            $branch = $locked->building->branch;

            if (! $this->businessHours->isWithinBusinessHours(now(), $branch)) {
                throw new ReceptionActionException('api.reception.outside_business_hours');
            }

            if ($locked->capacity !== null) {
                $occupied = Booking::query()
                    ->where('space_id', $locked->id)
                    ->whereNotNull('checked_in_at')
                    ->whereNull('checked_out_at')
                    ->count();

                $occupied += WalkinSession::query()
                    ->where('space_id', $locked->id)
                    ->whereNull('checked_out_at')
                    ->count();

                if ($occupied >= $locked->capacity) {
                    throw new ReceptionActionException('api.reception.no_capacity');
                }
            }

            return WalkinSession::create([
                'space_id' => $locked->id,
                'user_id' => $member->id,
                'checked_in_at' => now(),
                'payment_state' => PaymentState::Unpaid,
            ]);
        });
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/WalkInCapacityServiceTest.php`
Expected: PASS (6 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Booking/Exceptions/ReceptionActionException.php app/Domain/Booking/Services/WalkInCapacityService.php tests/Feature/Booking/WalkInCapacityServiceTest.php
git commit -m "feat: add ReceptionActionException and WalkInCapacityService"
```

---

### Task 6: `AmountCalculator`, `SessionClosureService::closeOut()`

**Files:**
- Create: `app/Domain/Booking/Services/AmountCalculator.php`
- Create: `app/Domain/Booking/Services/SessionClosureService.php`
- Test: `tests/Feature/Booking/SessionClosureServiceTest.php`

**Interfaces:**
- Consumes: `BusinessHoursService::periodsFor()`, `App\Domain\Settings\Services\SettingService::get()`.
- Produces: `AmountCalculator::forRange(Space $space, CarbonInterface $start, CarbonInterface $end): array{0: string, 1: ?string}` (reused by Task 9's cancellation refund). `SessionClosureService::closeOut(Booking|WalkinSession $session, CarbonInterface $enteredTime): void` and `SessionClosureService::closingTimeFor(Branch $branch, CarbonInterface $instant): ?string` (reused by Task 7's auto-closure command). Tasks 7/8 add `autoClose()`/`settlePayment()` to this same service/file.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionClosureServiceTest extends TestCase
{
    use RefreshDatabase;

    private SessionClosureService $closures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->closures = app(SessionClosureService::class);
    }

    private function openSpace(string $hourlyRate = '10.00'): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate' => $hourlyRate, 'pricing_currency' => 'USD']);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_checkout_computes_amount_from_actual_elapsed_duration(): void
    {
        $space = $this->openSpace('10.00');
        $checkedInAt = Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus');
        $session = WalkinSession::factory()->create(['space_id' => $space->id, 'checked_in_at' => $checkedInAt]);

        $this->closures->closeOut($session, Carbon::parse('2026-08-17 11:30:00', 'Asia/Damascus'));

        $session->refresh();
        $this->assertNotNull($session->checked_out_at);
        $this->assertSame('reception', $session->termination_source->value);
        $this->assertSame('25.00', (string) $session->amount_owed);
        $this->assertSame('USD', $session->currency);
    }

    public function test_checkout_works_identically_for_a_booking(): void
    {
        $space = $this->openSpace('10.00');
        $checkedInAt = Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus');
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => $checkedInAt,
        ]);

        $this->closures->closeOut($booking, Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));

        $this->assertSame('10.00', (string) $booking->fresh()->amount_owed);
    }

    public function test_checkout_time_exactly_at_closing_succeeds(): void
    {
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);

        $this->closures->closeOut($session, Carbon::parse('2026-08-17 20:00:00', 'Asia/Damascus'));

        $this->assertNotNull($session->fresh()->checked_out_at);
    }

    public function test_checkout_one_minute_past_closing_fails(): void
    {
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);

        try {
            $this->closures->closeOut($session, Carbon::parse('2026-08-17 20:01:00', 'Asia/Damascus'));
            $this->fail('Expected a ReceptionActionException for past closing.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.checkout_past_closing', $e->messageKey);
        }

        $this->assertNull($session->fresh()->checked_out_at);
    }

    public function test_checkout_before_check_in_fails(): void
    {
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);

        try {
            $this->closures->closeOut($session, Carbon::parse('2026-08-17 08:59:00', 'Asia/Damascus'));
            $this->fail('Expected a ReceptionActionException for checkout before check-in.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.checkout_before_checkin', $e->messageKey);
        }
    }

    public function test_checking_out_an_already_checked_out_session_fails(): void
    {
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
        ]);

        try {
            $this->closures->closeOut($session, Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus'));
            $this->fail('Expected a ReceptionActionException for already checked out.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.already_checked_out', $e->messageKey);
            $this->assertSame(409, $e->status);
        }
    }

    public function test_checking_out_a_session_never_checked_in_fails(): void
    {
        $space = $this->openSpace();
        $booking = Booking::factory()->create(['space_id' => $space->id]);

        try {
            $this->closures->closeOut($booking, Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus'));
            $this->fail('Expected a ReceptionActionException for not checked in.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.not_checked_in', $e->messageKey);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/SessionClosureServiceTest.php`
Expected: FAIL — `Class "App\Domain\Booking\Services\SessionClosureService" not found`

- [ ] **Step 3: Write `AmountCalculator`**

`app/Domain/Booking/Services/AmountCalculator.php`:
```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Foundation\Models\Space;
use Carbon\CarbonInterface;

/**
 * Shared by SessionClosureService (actual checked-in-to-checked-out
 * duration) and BookingCancellationService (planned start_at-to-end_at
 * duration, for a wallet refund when a booking is cancelled before check-in
 * ever happens). bcmath throughout — DECIMAL(10,2) exclusively, never a
 * float (decision #15).
 */
class AmountCalculator
{
    /**
     * @return array{0: string, 1: ?string} [amount, currency]
     */
    public function forRange(Space $space, CarbonInterface $start, CarbonInterface $end): array
    {
        $seconds = $start->diffInSeconds($end);
        $hours = sprintf('%.6f', $seconds / 3600);
        $rate = (string) ($space->hourly_rate ?? '0.00');

        return [bcmul($hours, $rate, 2), $space->pricing_currency];
    }
}
```

- [ ] **Step 4: Write `SessionClosureService` (checkout half only — `autoClose`/`settlePayment` land in Tasks 7-8)**

`app/Domain/Booking/Services/SessionClosureService.php`:
```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class SessionClosureService
{
    public function __construct(
        private readonly BusinessHoursService $businessHours,
        private readonly SettingService $settings,
        private readonly AmountCalculator $amounts,
    ) {}

    /**
     * Reception enters a specific checkout time (decision #10). Computed
     * from the ACTUAL checked-in-to-checked-out duration, not the booking's
     * originally planned window — early departure or overrun is billed
     * correctly for both entity types uniformly.
     */
    public function closeOut(Booking|WalkinSession $session, CarbonInterface $enteredTime): void
    {
        $this->assertOpenForClosure($session);

        if ($enteredTime->lt($session->checked_in_at)) {
            throw new ReceptionActionException('api.reception.checkout_before_checkin');
        }

        $branch = $session->space->building->branch;
        $closingTime = $this->closingTimeFor($branch, $enteredTime);

        if ($closingTime === null || $this->localTimeOfDay($enteredTime) > $closingTime) {
            throw new ReceptionActionException('api.reception.checkout_past_closing');
        }

        $this->finalizeClosure($session, $enteredTime, TerminationSource::Reception);
    }

    /**
     * Last period's close_time (H:i) for the branch's local calendar date
     * matching $instant, or null if closed that day. Public: Task 7's
     * auto-closure command needs this to decide which open sessions are
     * actually overdue.
     */
    public function closingTimeFor(Branch $branch, CarbonInterface $instant): ?string
    {
        $localInstant = $instant->copy()->setTimezone($this->settings->get('app.timezone', 'Asia/Damascus'));
        $periods = $this->businessHours->periodsFor($localInstant, $branch);

        if ($periods === []) {
            return null;
        }

        return Collection::make($periods)->pluck('close_time')->sort()->last();
    }

    private function assertOpenForClosure(Booking|WalkinSession $session): void
    {
        if ($session->checked_in_at === null) {
            throw new ReceptionActionException('api.reception.not_checked_in');
        }

        if ($session->checked_out_at !== null) {
            throw new ReceptionActionException('api.reception.already_checked_out', 409);
        }
    }

    private function finalizeClosure(Booking|WalkinSession $session, CarbonInterface $checkedOutAt, TerminationSource $source): void
    {
        [$amount, $currency] = $this->amounts->forRange($session->space, $session->checked_in_at, $checkedOutAt);

        $session->forceFill([
            'checked_out_at' => $checkedOutAt,
            'termination_source' => $source,
            'amount_owed' => $amount,
            'currency' => $currency,
        ])->save();
    }

    private function localTimeOfDay(CarbonInterface $instant): string
    {
        return $instant->copy()->setTimezone($this->settings->get('app.timezone', 'Asia/Damascus'))->format('H:i');
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/SessionClosureServiceTest.php`
Expected: PASS (7 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Booking/Services/AmountCalculator.php app/Domain/Booking/Services/SessionClosureService.php tests/Feature/Booking/SessionClosureServiceTest.php
git commit -m "feat: add AmountCalculator and SessionClosureService checkout logic"
```

---

### Task 7: `SessionClosureService::autoClose()`, `reception:close-overdue-sessions` command

**Files:**
- Modify: `app/Domain/Booking/Services/SessionClosureService.php`
- Create: `app/Console/Commands/CloseOverdueReceptionSessions.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Booking/CloseOverdueReceptionSessionsCommandTest.php`

**Interfaces:**
- Consumes: `SessionClosureService::closingTimeFor()` (Task 6), `finalizeClosure()` (private, same class).
- Produces: `SessionClosureService::autoClose(Booking|WalkinSession $session, CarbonInterface $closingInstant): void`. Artisan command `reception:close-overdue-sessions`, scheduled every 5 minutes.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseOverdueReceptionSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function openSpace(): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_a_session_still_open_past_closing_is_auto_closed(): void
    {
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 20:30:00', 'Asia/Damascus'));

        $this->artisan('reception:close-overdue-sessions')->assertExitCode(0);

        $session->refresh();
        $this->assertNotNull($session->checked_out_at);
        $this->assertSame(TerminationSource::Auto, $session->termination_source);
        $this->assertSame('20:00', $session->checked_out_at->copy()->setTimezone('Asia/Damascus')->format('H:i'));
    }

    public function test_a_confirmed_booking_still_open_past_closing_is_auto_closed(): void
    {
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 20:30:00', 'Asia/Damascus'));

        $this->artisan('reception:close-overdue-sessions')->assertExitCode(0);

        $this->assertSame(TerminationSource::Auto, $booking->fresh()->termination_source);
    }

    public function test_a_session_not_yet_past_closing_is_left_open(): void
    {
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 15:00:00', 'Asia/Damascus'));

        $this->artisan('reception:close-overdue-sessions')->assertExitCode(0);

        $this->assertNull($session->fresh()->checked_out_at);
    }

    public function test_a_session_auto_closed_cannot_later_be_manually_closed(): void
    {
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 20:30:00', 'Asia/Damascus'));
        $this->artisan('reception:close-overdue-sessions');

        $closures = app(\App\Domain\Booking\Services\SessionClosureService::class);

        $this->expectException(\App\Domain\Booking\Exceptions\ReceptionActionException::class);
        $closures->closeOut($session->fresh(), now());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/CloseOverdueReceptionSessionsCommandTest.php`
Expected: FAIL — `Command "reception:close-overdue-sessions" is not defined`

- [ ] **Step 3: Add `autoClose()` to `SessionClosureService`**

Edit `app/Domain/Booking/Services/SessionClosureService.php` — add this public method right after `closeOut()`:

```php
    /**
     * Identical effect to closeOut(), termination_source = auto, no
     * operator. Called by the scheduled command for any session still
     * checked in past its branch's closing time.
     */
    public function autoClose(Booking|WalkinSession $session, CarbonInterface $closingInstant): void
    {
        $this->finalizeClosure($session, $closingInstant, TerminationSource::Auto);
    }
```

- [ ] **Step 4: Write the artisan command**

`app/Console/Commands/CloseOverdueReceptionSessions.php`:
```php
<?php

namespace App\Console\Commands;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * System-driven counterpart to reception's manual checkout: any booking or
 * walk-in still checked in past its branch's closing time gets closed with
 * termination_source = auto, amount computed to closing time, exactly like
 * a manual checkout entered at that instant.
 */
class CloseOverdueReceptionSessions extends Command
{
    protected $signature = 'reception:close-overdue-sessions';

    protected $description = "Auto-close any booking/walk-in session still checked in past its branch's closing time.";

    public function handle(SessionClosureService $closures, SettingService $settings): int
    {
        $timezone = $settings->get('app.timezone', 'Asia/Damascus');
        $now = Carbon::now($timezone);

        WalkinSession::query()
            ->whereNotNull('checked_in_at')
            ->whereNull('checked_out_at')
            ->with('space.building.branch')
            ->chunkById(100, function ($sessions) use ($closures, $now) {
                foreach ($sessions as $session) {
                    $this->closeIfOverdue($closures, $session, $now);
                }
            });

        Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->whereNotNull('checked_in_at')
            ->whereNull('checked_out_at')
            ->with('space.building.branch')
            ->chunkById(100, function ($bookings) use ($closures, $now) {
                foreach ($bookings as $booking) {
                    $this->closeIfOverdue($closures, $booking, $now);
                }
            });

        return self::SUCCESS;
    }

    private function closeIfOverdue(SessionClosureService $closures, Booking|WalkinSession $session, Carbon $now): void
    {
        $branch = $session->space->building->branch;
        $closingTime = $closures->closingTimeFor($branch, $now);

        if ($closingTime === null || $now->format('H:i') <= $closingTime) {
            return;
        }

        $closures->autoClose($session, $now->copy()->setTimeFromTimeString($closingTime));
    }
}
```

- [ ] **Step 5: Schedule the command**

Edit `routes/console.php` — append after the existing `Schedule::command('model:prune')->hourly();` line:

```php
/*
 * Reception operations (docs/superpowers/specs/2026-08-16-reception-operations-design.md)
 * — a booking or walk-in session still checked in past its branch's
 * closing time must be closed automatically (termination_source = auto),
 * not left open indefinitely until reception happens to notice.
 */
Schedule::command('reception:close-overdue-sessions')->everyFiveMinutes();
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/CloseOverdueReceptionSessionsCommandTest.php`
Expected: PASS (4 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Booking/Services/SessionClosureService.php app/Console/Commands/CloseOverdueReceptionSessions.php routes/console.php tests/Feature/Booking/CloseOverdueReceptionSessionsCommandTest.php
git commit -m "feat: add auto-closure of overdue reception sessions"
```

---

### Task 8: `SessionClosureService::settlePayment()`

**Files:**
- Modify: `app/Domain/Booking/Services/SessionClosureService.php`
- Test: `tests/Feature/Booking/SessionClosureServiceTest.php`

**Interfaces:**
- Consumes: `App\Domain\Finance\Enums\PaymentMethod` (Task 1), `App\Domain\Identity\Models\User`.
- Produces: `SessionClosureService::settlePayment(Booking|WalkinSession $session, PaymentMethod $method, User $operator): void`. Task 11/12's controllers call this directly.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Booking/SessionClosureServiceTest.php` (add the import `use App\Domain\Booking\Enums\PaymentState;`, `use App\Domain\Finance\Enums\PaymentMethod;`, `use App\Domain\Identity\Models\User;` alongside the existing ones, then add these methods to the class):

```php
    public function test_settling_payment_marks_paid_and_records_operator(): void
    {
        $space = $this->openSpace();
        $operator = \App\Domain\Identity\Models\User::factory()->create();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
            'amount_owed' => '10.00',
        ]);

        $this->closures->settlePayment($session, \App\Domain\Finance\Enums\PaymentMethod::Sham, $operator);

        $session->refresh();
        $this->assertSame(\App\Domain\Booking\Enums\PaymentState::Paid, $session->payment_state);
        $this->assertSame(\App\Domain\Finance\Enums\PaymentMethod::Sham, $session->payment_method);
        $this->assertTrue($session->paid_by === $operator->id);
        $this->assertNotNull($session->paid_at);
    }

    public function test_settling_an_already_paid_session_fails(): void
    {
        $space = $this->openSpace();
        $operator = \App\Domain\Identity\Models\User::factory()->create();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
            'amount_owed' => '10.00',
            'payment_state' => \App\Domain\Booking\Enums\PaymentState::Paid,
        ]);

        try {
            $this->closures->settlePayment($session, \App\Domain\Finance\Enums\PaymentMethod::Cash, $operator);
            $this->fail('Expected a ReceptionActionException for already paid.');
        } catch (\App\Domain\Booking\Exceptions\ReceptionActionException $e) {
            $this->assertSame('api.reception.already_paid', $e->messageKey);
            $this->assertSame(409, $e->status);
        }
    }

    public function test_settling_payment_before_checkout_fails(): void
    {
        $space = $this->openSpace();
        $operator = \App\Domain\Identity\Models\User::factory()->create();
        $session = WalkinSession::factory()->create(['space_id' => $space->id]);

        try {
            $this->closures->settlePayment($session, \App\Domain\Finance\Enums\PaymentMethod::Cash, $operator);
            $this->fail('Expected a ReceptionActionException for not yet checked out.');
        } catch (\App\Domain\Booking\Exceptions\ReceptionActionException $e) {
            $this->assertSame('api.reception.not_yet_checked_out', $e->messageKey);
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/SessionClosureServiceTest.php`
Expected: FAIL — `Call to undefined method App\Domain\Booking\Services\SessionClosureService::settlePayment()`

- [ ] **Step 3: Add `settlePayment()` to `SessionClosureService`**

Edit `app/Domain/Booking/Services/SessionClosureService.php` — add the imports `use App\Domain\Booking\Enums\PaymentState;`, `use App\Domain\Finance\Enums\PaymentMethod;`, `use App\Domain\Identity\Models\User;`, and this public method after `autoClose()`:

```php
    /**
     * Reception collected cash/sham/mtn/syriatel and confirms it here — no
     * wallet is touched (that only happens for a wallet-sourced booking
     * payment, out of scope this phase, and for the separate wallet
     * top-up endpoint). "Write the same wallet-transaction-style audit
     * trail used elsewhere" is the caller's job via LogsSensitiveActions,
     * not this service's.
     */
    public function settlePayment(Booking|WalkinSession $session, PaymentMethod $method, User $operator): void
    {
        if ($session->checked_out_at === null || $session->amount_owed === null) {
            throw new ReceptionActionException('api.reception.not_yet_checked_out');
        }

        if ($session->payment_state === PaymentState::Paid) {
            throw new ReceptionActionException('api.reception.already_paid', 409);
        }

        $session->forceFill([
            'payment_state' => PaymentState::Paid,
            'payment_method' => $method,
            'paid_by' => $operator->id,
            'paid_at' => now(),
        ])->save();
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/SessionClosureServiceTest.php`
Expected: PASS (10 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Booking/Services/SessionClosureService.php tests/Feature/Booking/SessionClosureServiceTest.php
git commit -m "feat: add SessionClosureService::settlePayment"
```

---

### Task 9: `BookingCancellationService`

**Files:**
- Create: `app/Domain/Booking/Services/BookingCancellationService.php`
- Test: `tests/Feature/Booking/BookingCancellationServiceTest.php`

**Interfaces:**
- Consumes: `AmountCalculator::forRange()` (Task 6), `WalletService::walletFor()`/`creditGeneral()` (already exists), `WalletTransactionSource::Refund` (Task 1), `Space.cancellation_window_minutes` + the global `booking.cancellation_window_minutes` setting (Task 4 / already seeded).
- Produces: `BookingCancellationService::cancel(Booking $booking): void`. Task 11's controller calls this directly.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingCancellationServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingCancellationService $cancellations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cancellations = app(BookingCancellationService::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cancelling_within_the_global_window_succeeds(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
        ]);

        $this->cancellations->cancel($booking);

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertNotNull($booking->cancelled_at);
    }

    public function test_cancelling_a_wallet_paid_booking_refunds_the_planned_amount(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
        $member = \App\Domain\Identity\Models\User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(4),
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
        ]);

        $this->cancellations->cancel($booking);

        $refund = $wallet->transactions()
            ->where('source', WalletTransactionSource::Refund)
            ->where('category', WalletTransactionCategory::General)
            ->first();

        $this->assertNotNull($refund);
        $this->assertSame('20.00', (string) $refund->amount);
    }

    public function test_cancelling_a_cash_paid_booking_does_not_touch_any_wallet(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate' => '10.00']);
        $member = \App\Domain\Identity\Models\User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Cash,
        ]);

        $this->cancellations->cancel($booking);

        $this->assertSame(0, $wallet->transactions()->count());
    }

    public function test_cancelling_past_the_window_fails(): void
    {
        $space = Space::factory()->room()->create();
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => now()->addMinutes(30),
            'end_at' => now()->addHours(1),
        ]);

        try {
            $this->cancellations->cancel($booking);
            $this->fail('Expected a ReceptionActionException for past the cancellation window.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.cancellation_window_passed', $e->messageKey);
        }

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_a_per_space_window_override_takes_precedence_over_the_global_default(): void
    {
        // Global default is 60 minutes (SettingSeeder); this space overrides to 15.
        $space = Space::factory()->room()->create(['cancellation_window_minutes' => 15]);
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => now()->addMinutes(30),
            'end_at' => now()->addHours(1),
        ]);

        $this->cancellations->cancel($booking);

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cancelling_an_already_checked_in_booking_fails(): void
    {
        $space = Space::factory()->room()->create();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
        ]);

        try {
            $this->cancellations->cancel($booking);
            $this->fail('Expected a ReceptionActionException for already checked in.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.already_checked_in', $e->messageKey);
            $this->assertSame(409, $e->status);
        }
    }

    public function test_cancelling_an_already_cancelled_booking_fails(): void
    {
        $space = Space::factory()->room()->create();
        $booking = Booking::factory()->cancelled()->create(['space_id' => $space->id]);

        try {
            $this->cancellations->cancel($booking);
            $this->fail('Expected a ReceptionActionException for already cancelled.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.already_cancelled', $e->messageKey);
            $this->assertSame(409, $e->status);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/BookingCancellationServiceTest.php`
Expected: FAIL — `Class "App\Domain\Booking\Services\BookingCancellationService" not found`

- [ ] **Step 3: Write `BookingCancellationService`**

`app/Domain/Booking/Services/BookingCancellationService.php`:
```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Settings\Services\SettingService;

class BookingCancellationService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly SettingService $settings,
        private readonly AmountCalculator $amounts,
    ) {}

    /**
     * A cancelled-but-never-checked-in booking was never counted toward
     * WalkInCapacityService's occupancy sum (that only counts
     * checked_in_at IS NOT NULL AND checked_out_at IS NULL rows) — setting
     * status = Cancelled doesn't change any capacity query result; "capacity
     * released" is true by construction, nothing further to do for it.
     *
     * The refund amount (when payment_source = wallet) uses the booking's
     * PLANNED window (start_at to end_at), not amount_owed — amount_owed is
     * only ever set at checkout (SessionClosureService), and cancellation
     * can only happen before check-in, so it's always null here. A booking
     * marked paid via wallet before this phase's out-of-scope creation flow
     * would have been charged for the planned window; that's what gets
     * refunded.
     */
    public function cancel(Booking $booking): void
    {
        if ($booking->status === BookingStatus::Cancelled) {
            throw new ReceptionActionException('api.reception.already_cancelled', 409);
        }

        if ($booking->checked_in_at !== null) {
            throw new ReceptionActionException('api.reception.already_checked_in', 409);
        }

        $windowMinutes = $booking->space->cancellation_window_minutes
            ?? $this->settings->get('booking.cancellation_window_minutes', 60);

        if (now()->gt($booking->start_at->copy()->subMinutes($windowMinutes))) {
            throw new ReceptionActionException('api.reception.cancellation_window_passed');
        }

        $booking->forceFill([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        if ($booking->payment_source === PaymentSource::Wallet && $booking->payment_state === PaymentState::Paid) {
            [$refundAmount] = $this->amounts->forRange($booking->space, $booking->start_at, $booking->end_at);

            $wallet = $this->wallets->walletFor(OwnerType::User, $booking->user_id);
            $this->wallets->creditGeneral($wallet, $refundAmount, WalletTransactionSource::Refund, 'Booking cancellation refund');
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/BookingCancellationServiceTest.php`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Booking/Services/BookingCancellationService.php tests/Feature/Booking/BookingCancellationServiceTest.php
git commit -m "feat: add BookingCancellationService"
```

---

### Task 10: Reception lang keys

**Files:**
- Modify: `lang/en/api.php`
- Modify: `lang/ar/api.php`
- Test: `tests/Unit/Domain/Booking/ReceptionLangKeysTest.php`

**Interfaces:**
- Produces: `api.reception.*` translation keys — every `ReceptionActionException` message key thrown across Tasks 5-9, plus the four success messages Tasks 11-13's controllers return, must exist in both files under this exact key list.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Booking;

use Tests\TestCase;

class ReceptionLangKeysTest extends TestCase
{
    /** @return list<string> */
    public static function keys(): array
    {
        return [
            'checked_in',
            'checked_out',
            'cancelled',
            'payment_settled',
            'already_checked_in',
            'already_checked_out',
            'already_cancelled',
            'already_paid',
            'outside_business_hours',
            'no_capacity',
            'not_checked_in',
            'checkout_before_checkin',
            'checkout_past_closing',
            'not_yet_checked_out',
            'cancellation_window_passed',
        ];
    }

    public function test_every_reception_key_exists_in_english(): void
    {
        foreach (self::keys() as $key) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Lang::has("api.reception.{$key}", 'en'),
                "Missing lang/en/api.php reception.{$key}"
            );
        }
    }

    public function test_every_reception_key_exists_in_arabic(): void
    {
        foreach (self::keys() as $key) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Lang::has("api.reception.{$key}", 'ar'),
                "Missing lang/ar/api.php reception.{$key}"
            );
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Booking/ReceptionLangKeysTest.php`
Expected: FAIL — assertion fails on the first missing key

- [ ] **Step 3: Add the `reception` group to `lang/en/api.php`**

Edit `lang/en/api.php` — add a new top-level key right after the existing `'admin' => [...]` group, before the closing `];`:

```php
    'reception' => [
        'checked_in' => 'Checked in.',
        'checked_out' => 'Checked out.',
        'cancelled' => 'Booking cancelled.',
        'payment_settled' => 'Payment settled.',
        'already_checked_in' => 'This booking has already been checked in.',
        'already_checked_out' => 'This session has already been checked out.',
        'already_cancelled' => 'This booking has already been cancelled.',
        'already_paid' => 'This booking or session has already been paid.',
        'outside_business_hours' => 'This action is not available outside business hours.',
        'no_capacity' => 'This space has no available capacity right now.',
        'not_checked_in' => 'This session has not been checked in yet.',
        'checkout_before_checkin' => 'The checkout time cannot be before the check-in time.',
        'checkout_past_closing' => "The checkout time cannot be after the branch's closing time.",
        'not_yet_checked_out' => 'This booking or session must be checked out before payment can be settled.',
        'cancellation_window_passed' => 'This booking is past its cancellation window.',
    ],
```

- [ ] **Step 4: Add the matching `reception` group to `lang/ar/api.php`**

Edit `lang/ar/api.php` — same position, same keys:

```php
    'reception' => [
        'checked_in' => 'تم تسجيل الدخول.',
        'checked_out' => 'تم تسجيل الخروج.',
        'cancelled' => 'تم إلغاء الحجز.',
        'payment_settled' => 'تم تسوية الدفع.',
        'already_checked_in' => 'تم تسجيل دخول هذا الحجز مسبقاً.',
        'already_checked_out' => 'تم تسجيل خروج هذه الجلسة مسبقاً.',
        'already_cancelled' => 'تم إلغاء هذا الحجز مسبقاً.',
        'already_paid' => 'تمت تسوية دفع هذا الحجز أو الجلسة مسبقاً.',
        'outside_business_hours' => 'هذا الإجراء غير متاح خارج ساعات العمل.',
        'no_capacity' => 'لا توجد سعة متاحة لهذه المساحة حالياً.',
        'not_checked_in' => 'لم يتم تسجيل دخول هذه الجلسة بعد.',
        'checkout_before_checkin' => 'لا يمكن أن يكون وقت تسجيل الخروج قبل وقت تسجيل الدخول.',
        'checkout_past_closing' => 'لا يمكن أن يكون وقت تسجيل الخروج بعد وقت إغلاق الفرع.',
        'not_yet_checked_out' => 'يجب تسجيل خروج هذا الحجز أو الجلسة قبل تسوية الدفع.',
        'cancellation_window_passed' => 'تجاوز هذا الحجز مهلة الإلغاء المسموحة.',
    ],
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Booking/ReceptionLangKeysTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add lang/en/api.php lang/ar/api.php tests/Unit/Domain/Booking/ReceptionLangKeysTest.php
git commit -m "feat: add reception lang keys"
```

---

### Task 11: `BookingReceptionController`, Form Requests, routes

**Files:**
- Create: `app/Http/Requests/Admin/Reception/CheckOutSessionRequest.php`
- Create: `app/Http/Requests/Admin/Reception/SettlePaymentRequest.php`
- Create: `app/Http/Controllers/Api/V1/Admin/Reception/BookingReceptionController.php`
- Modify: `routes/api/v1/admin.php`
- Test: `tests/Feature/Booking/BookingReceptionControllerTest.php`

**Interfaces:**
- Consumes: `SessionClosureService` (Tasks 6-8), `BookingCancellationService` (Task 9), `BusinessHoursService` (already built), `ReceptionActionException` (Task 5).
- Produces: `POST reception/bookings/{booking}/check-in|check-out|cancel|settle-payment`. `CheckOutSessionRequest`/`SettlePaymentRequest` are reused as-is by Task 12's `WalkInSessionController`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class BookingReceptionControllerTest extends TestCase
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

    private function openSpace(): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_operations_can_check_in_a_confirmed_booking(): void
    {
        $operator = $this->actingAsOperations();
        $booking = Booking::factory()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-in");

        $response->assertOk()->assertExactJson(['message' => 'Checked in.']);
        $this->assertNotNull($booking->fresh()->checked_in_at);
        $activity = Activity::where('description', 'booking_checked_in')->latest('id')->first();
        $this->assertSame($operator->id, $activity->causer_id);
    }

    public function test_check_in_fails_if_already_checked_in(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->checkedIn()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-in");

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking has already been checked in.']);
    }

    public function test_check_in_fails_outside_business_hours(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->create(['space_id' => $this->openSpace()->id]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 23:00:00', 'Asia/Damascus'));

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-in");

        $response->assertStatus(422)->assertExactJson(['message' => 'This action is not available outside business hours.']);
    }

    public function test_check_in_fails_if_booking_is_cancelled(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->cancelled()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-in");

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking has already been cancelled.']);
    }

    public function test_check_in_on_a_nonexistent_booking_is_404(): void
    {
        $this->actingAsOperations();

        $this->postJson('/api/v1/admin/reception/bookings/99999/check-in')->assertNotFound();
    }

    public function test_operations_can_check_out_a_checked_in_booking(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-out", [
            'checked_out_at' => Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus')->toIso8601String(),
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Checked out.']);
        $this->assertSame('20.00', (string) $booking->fresh()->amount_owed);
    }

    public function test_check_out_fails_if_entered_time_is_before_check_in(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-out", [
            'checked_out_at' => Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->toIso8601String(),
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => 'The checkout time cannot be before the check-in time.']);
    }

    public function test_check_out_fails_if_entered_time_is_past_closing(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-out", [
            'checked_out_at' => Carbon::parse('2026-08-17 20:01:00', 'Asia/Damascus')->toIso8601String(),
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => "The checkout time cannot be after the branch's closing time."]);
    }

    public function test_check_out_fails_if_already_checked_out(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
        ]);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-out", [
            'checked_out_at' => Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus')->toIso8601String(),
        ]);

        $response->assertStatus(409)->assertExactJson(['message' => 'This session has already been checked out.']);
    }

    public function test_operations_can_settle_payment_after_checkout(): void
    {
        $operator = $this->actingAsOperations();
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
            'amount_owed' => '10.00',
        ]);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/settle-payment", [
            'payment_method' => 'sham',
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Payment settled.']);
        $booking->refresh();
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame($operator->id, $booking->paid_by);
    }

    public function test_settle_payment_fails_if_already_paid(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_out_at' => now(),
            'amount_owed' => '10.00',
            'payment_state' => PaymentState::Paid,
        ]);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/settle-payment", [
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking or session has already been paid.']);
    }

    public function test_settle_payment_fails_if_not_yet_checked_out(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->checkedIn()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/settle-payment", [
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => 'This booking or session must be checked out before payment can be settled.']);
    }

    public function test_settle_payment_rejects_an_invalid_payment_method(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $this->openSpace()->id,
            'checked_out_at' => now(),
            'amount_owed' => '10.00',
        ]);

        $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/settle-payment", ['payment_method' => 'visa'])
            ->assertStatus(422);
    }

    public function test_operations_can_cancel_a_confirmed_booking_within_the_window(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->create([
            'space_id' => $this->openSpace()->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
        ]);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/cancel");

        $response->assertOk()->assertExactJson(['message' => 'Booking cancelled.']);
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cancel_fails_past_the_window(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->create([
            'space_id' => $this->openSpace()->id,
            'start_at' => now()->addMinutes(30),
            'end_at' => now()->addHours(1),
        ]);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/cancel");

        $response->assertStatus(422)->assertExactJson(['message' => 'This booking is past its cancellation window.']);
    }

    public function test_cancel_fails_if_already_checked_in(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $this->openSpace()->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
        ]);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/cancel");

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking has already been checked in.']);
    }

    public function test_cancel_fails_if_already_cancelled(): void
    {
        $this->actingAsOperations();
        $booking = Booking::factory()->cancelled()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/cancel");

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking has already been cancelled.']);
    }

    public function test_a_member_cannot_access_reception_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $booking = Booking::factory()->create(['space_id' => $this->openSpace()->id]);

        $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-in")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/BookingReceptionControllerTest.php`
Expected: FAIL — 404s / route not defined

- [ ] **Step 3: Write the Form Requests**

`app/Http/Requests/Admin/Reception/CheckOutSessionRequest.php`:
```php
<?php

namespace App\Http\Requests\Admin\Reception;

use Illuminate\Foundation\Http\FormRequest;

class CheckOutSessionRequest extends FormRequest
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
            'checked_out_at' => ['required', 'date'],
        ];
    }
}
```

`app/Http/Requests/Admin/Reception/SettlePaymentRequest.php`:
```php
<?php

namespace App\Http\Requests\Admin\Reception;

use App\Domain\Finance\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettlePaymentRequest extends FormRequest
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
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
        ];
    }
}
```

- [ ] **Step 4: Write `BookingReceptionController`**

`app/Http/Controllers/Api/V1/Admin/Reception/BookingReceptionController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\CheckOutSessionRequest;
use App\Http\Requests\Admin\Reception\SettlePaymentRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class BookingReceptionController extends Controller
{
    use LogsSensitiveActions;

    public function checkIn(Booking $booking, BusinessHoursService $businessHours): JsonResponse
    {
        if ($booking->status === BookingStatus::Cancelled) {
            return response()->json(['message' => __('api.reception.already_cancelled')], 409);
        }

        if ($booking->checked_in_at !== null) {
            return response()->json(['message' => __('api.reception.already_checked_in')], 409);
        }

        $branch = $booking->space->building->branch;

        if (! $businessHours->isWithinBusinessHours(now(), $branch)) {
            return response()->json(['message' => __('api.reception.outside_business_hours')], 422);
        }

        $booking->forceFill(['checked_in_at' => now()])->save();

        $this->logSensitiveAction('booking_checked_in', $booking);

        return response()->json(['message' => __('api.reception.checked_in')]);
    }

    public function checkOut(CheckOutSessionRequest $request, Booking $booking, SessionClosureService $closures): JsonResponse
    {
        try {
            $closures->closeOut($booking, Carbon::parse($request->validated('checked_out_at')));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $this->logSensitiveAction('booking_checked_out', $booking, ['amount_owed' => (string) $booking->amount_owed]);

        return response()->json(['message' => __('api.reception.checked_out')]);
    }

    public function cancel(Booking $booking, BookingCancellationService $cancellations): JsonResponse
    {
        try {
            $cancellations->cancel($booking);
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $this->logSensitiveAction('booking_cancelled', $booking);

        return response()->json(['message' => __('api.reception.cancelled')]);
    }

    public function settlePayment(SettlePaymentRequest $request, Booking $booking, SessionClosureService $closures): JsonResponse
    {
        try {
            $closures->settlePayment($booking, PaymentMethod::from($request->validated('payment_method')), $request->user());
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $this->logSensitiveAction('payment_settled', $booking, ['payment_method' => $booking->payment_method->value]);

        return response()->json(['message' => __('api.reception.payment_settled')]);
    }
}
```

- [ ] **Step 5: Register the routes**

Edit `routes/api/v1/admin.php` — add the import and a new block right before the trailing `Route::middleware('role:admin')->group(function () {` block:

```php
use App\Http\Controllers\Api\V1\Admin\Reception\BookingReceptionController;
```

```php
// Reception Operations (docs/superpowers/specs/2026-08-16-reception-operations-design.md)
// — check-in, check-out, cancellation and payment settlement for bookings.
// No narrower role gate than the file's own admin|operations group: both do
// all of it.
Route::post('reception/bookings/{booking}/check-in', [BookingReceptionController::class, 'checkIn']);
Route::post('reception/bookings/{booking}/check-out', [BookingReceptionController::class, 'checkOut']);
Route::post('reception/bookings/{booking}/cancel', [BookingReceptionController::class, 'cancel']);
Route::post('reception/bookings/{booking}/settle-payment', [BookingReceptionController::class, 'settlePayment']);
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/BookingReceptionControllerTest.php`
Expected: PASS (18 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Admin/Reception app/Http/Controllers/Api/V1/Admin/Reception/BookingReceptionController.php routes/api/v1/admin.php tests/Feature/Booking/BookingReceptionControllerTest.php
git commit -m "feat: add BookingReceptionController and reception routes"
```

---

### Task 12: `WalkInSessionController`, `StoreWalkInSessionRequest`, routes

**Files:**
- Create: `app/Http/Requests/Admin/Reception/StoreWalkInSessionRequest.php`
- Create: `app/Http/Controllers/Api/V1/Admin/Reception/WalkInSessionController.php`
- Modify: `routes/api/v1/admin.php`
- Test: `tests/Feature/Booking/WalkInSessionControllerTest.php`

**Interfaces:**
- Consumes: `WalkInCapacityService` (Task 5), `SessionClosureService` (Tasks 6-8), `CheckOutSessionRequest`/`SettlePaymentRequest` (Task 11, reused as-is).
- Produces: `POST reception/walk-ins`, `POST reception/walk-ins/{walkinSession}/check-out`, `POST reception/walk-ins/{walkinSession}/settle-payment`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalkInSessionControllerTest extends TestCase
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

    private function openSpace(?int $capacity = 2): Space
    {
        $space = Space::factory()->room()->create(['capacity' => $capacity, 'hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_operations_can_start_a_walk_in_session(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $member = User::factory()->create();

        $response = $this->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $space->id,
            'user_id' => $member->id,
        ]);

        $response->assertCreated();
        $this->assertSame(1, WalkinSession::where('space_id', $space->id)->count());
    }

    public function test_starting_a_walk_in_fails_when_the_space_is_at_capacity(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace(1);
        WalkinSession::factory()->create(['space_id' => $space->id]);

        $response = $this->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $space->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => 'This space has no available capacity right now.']);
    }

    public function test_starting_a_walk_in_fails_outside_business_hours(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        Carbon::setTestNow(Carbon::parse('2026-08-17 23:00:00', 'Asia/Damascus'));

        $response = $this->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $space->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => 'This action is not available outside business hours.']);
    }

    public function test_starting_a_walk_in_requires_a_valid_space_and_user(): void
    {
        $this->actingAsOperations();

        $this->postJson('/api/v1/admin/reception/walk-ins', ['space_id' => 99999, 'user_id' => 99999])
            ->assertStatus(422);
    }

    public function test_operations_can_check_out_a_walk_in_session(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);

        $response = $this->postJson("/api/v1/admin/reception/walk-ins/{$session->id}/check-out", [
            'checked_out_at' => Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus')->toIso8601String(),
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Checked out.']);
        $this->assertSame('20.00', (string) $session->fresh()->amount_owed);
    }

    public function test_check_out_fails_if_the_walk_in_session_was_never_checked_in(): void
    {
        // Not reachable via factory (checked_in_at is required at creation),
        // but exercised directly against the service in
        // SessionClosureServiceTest; this controller test instead covers
        // the already-checked-out failure mode, which IS reachable via the
        // HTTP surface.
        $this->actingAsOperations();
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
        ]);

        $response = $this->postJson("/api/v1/admin/reception/walk-ins/{$session->id}/check-out", [
            'checked_out_at' => Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus')->toIso8601String(),
        ]);

        $response->assertStatus(409)->assertExactJson(['message' => 'This session has already been checked out.']);
    }

    public function test_operations_can_settle_payment_for_a_walk_in_session(): void
    {
        $operator = $this->actingAsOperations();
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_out_at' => now(),
            'amount_owed' => '10.00',
        ]);

        $response = $this->postJson("/api/v1/admin/reception/walk-ins/{$session->id}/settle-payment", [
            'payment_method' => 'mtn',
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Payment settled.']);
        $session->refresh();
        $this->assertSame(PaymentState::Paid, $session->payment_state);
        $this->assertSame($operator->id, $session->paid_by);
    }

    public function test_settle_payment_fails_if_not_yet_checked_out(): void
    {
        $this->actingAsOperations();
        $session = WalkinSession::factory()->create(['space_id' => $this->openSpace()->id]);

        $response = $this->postJson("/api/v1/admin/reception/walk-ins/{$session->id}/settle-payment", [
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => 'This booking or session must be checked out before payment can be settled.']);
    }

    public function test_a_member_cannot_start_a_walk_in(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $this->openSpace()->id,
            'user_id' => $member->id,
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/WalkInSessionControllerTest.php`
Expected: FAIL — route not defined

- [ ] **Step 3: Write `StoreWalkInSessionRequest`**

`app/Http/Requests/Admin/Reception/StoreWalkInSessionRequest.php`:
```php
<?php

namespace App\Http\Requests\Admin\Reception;

use Illuminate\Foundation\Http\FormRequest;

class StoreWalkInSessionRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
```

- [ ] **Step 4: Write `WalkInSessionController`**

`app/Http/Controllers/Api/V1/Admin/Reception/WalkInSessionController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\CheckOutSessionRequest;
use App\Http\Requests\Admin\Reception\SettlePaymentRequest;
use App\Http\Requests\Admin\Reception\StoreWalkInSessionRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class WalkInSessionController extends Controller
{
    use LogsSensitiveActions;

    public function store(StoreWalkInSessionRequest $request, WalkInCapacityService $capacity): JsonResponse
    {
        $space = Space::findOrFail($request->validated('space_id'));
        $member = User::findOrFail($request->validated('user_id'));

        try {
            $session = $capacity->start($space, $member);
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $this->logSensitiveAction('walkin_session_started', $session);

        return response()->json([
            'data' => [
                'id' => $session->id,
                'space_id' => $session->space_id,
                'user_id' => $session->user_id,
                'checked_in_at' => $session->checked_in_at->toIso8601String(),
            ],
        ], 201);
    }

    public function checkOut(CheckOutSessionRequest $request, WalkinSession $walkinSession, SessionClosureService $closures): JsonResponse
    {
        try {
            $closures->closeOut($walkinSession, Carbon::parse($request->validated('checked_out_at')));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $this->logSensitiveAction('walkin_session_checked_out', $walkinSession, ['amount_owed' => (string) $walkinSession->amount_owed]);

        return response()->json(['message' => __('api.reception.checked_out')]);
    }

    public function settlePayment(SettlePaymentRequest $request, WalkinSession $walkinSession, SessionClosureService $closures): JsonResponse
    {
        try {
            $closures->settlePayment($walkinSession, PaymentMethod::from($request->validated('payment_method')), $request->user());
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $this->logSensitiveAction('payment_settled', $walkinSession, ['payment_method' => $walkinSession->payment_method->value]);

        return response()->json(['message' => __('api.reception.payment_settled')]);
    }
}
```

- [ ] **Step 5: Register the routes**

Edit `routes/api/v1/admin.php` — add the import alongside the one from Task 11, and append after the `BookingReceptionController` routes:

```php
use App\Http\Controllers\Api\V1\Admin\Reception\WalkInSessionController;
```

```php
Route::post('reception/walk-ins', [WalkInSessionController::class, 'store']);
Route::post('reception/walk-ins/{walkinSession}/check-out', [WalkInSessionController::class, 'checkOut']);
Route::post('reception/walk-ins/{walkinSession}/settle-payment', [WalkInSessionController::class, 'settlePayment']);
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/WalkInSessionControllerTest.php`
Expected: PASS (9 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Admin/Reception/StoreWalkInSessionRequest.php app/Http/Controllers/Api/V1/Admin/Reception/WalkInSessionController.php routes/api/v1/admin.php tests/Feature/Booking/WalkInSessionControllerTest.php
git commit -m "feat: add WalkInSessionController and walk-in routes"
```

---

### Task 13: `WalletTopUpController`, `StoreWalletTopUpRequest`, routes

**Files:**
- Create: `app/Http/Requests/Admin/Reception/StoreWalletTopUpRequest.php`
- Create: `app/Http/Controllers/Api/V1/Admin/Reception/WalletTopUpController.php`
- Modify: `routes/api/v1/admin.php`
- Test: `tests/Feature/Booking/WalletTopUpControllerTest.php`

**Interfaces:**
- Consumes: `WalletService::walletFor()`/`creditGeneral()` (already exists), `WalletTransactionSource::TopUp` (already exists), `App\Domain\Finance\Enums\PaymentMethod` (Task 1), `wallet_transactions.performed_by_user_id`/`payment_method` (Task 4).
- Produces: `POST reception/wallet-top-ups`. This is this plan's last endpoint — S2-BE-06's missing manual-top-up piece.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class WalletTopUpControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    public function test_operations_can_top_up_a_members_wallet(): void
    {
        $operator = $this->actingAsOperations();
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $response = $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '25.00',
            'payment_method' => 'cash',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.amount', '25.00');

        $transaction = $wallet->fresh()->transactions()->latest('id')->first();
        $this->assertSame($operator->id, $transaction->performed_by_user_id);
        $this->assertSame(PaymentMethod::Cash, $transaction->payment_method);

        $activity = Activity::where('description', 'wallet_top_up')->latest('id')->first();
        $this->assertSame($operator->id, $activity->causer_id);
    }

    public function test_operations_can_top_up_a_companys_wallet(): void
    {
        $this->actingAsOperations();
        $company = Company::factory()->create();
        Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $response = $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'company_id' => $company->id,
            'amount' => '50.00',
            'payment_method' => 'syriatel',
        ]);

        $response->assertCreated();
    }

    public function test_top_up_requires_exactly_one_target(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create();
        $company = Company::factory()->create();

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'amount' => '10.00',
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'company_id' => $company->id,
            'amount' => '10.00',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_top_up_rejects_an_invalid_payment_method(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create();
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '10.00',
            'payment_method' => 'visa',
        ])->assertStatus(422);
    }

    public function test_top_up_rejects_a_non_positive_amount(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create();
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '0',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_a_member_cannot_top_up_a_wallet(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '10.00',
            'payment_method' => 'cash',
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/WalletTopUpControllerTest.php`
Expected: FAIL — route not defined

- [ ] **Step 3: Write `StoreWalletTopUpRequest`**

`app/Http/Requests/Admin/Reception/StoreWalletTopUpRequest.php`:
```php
<?php

namespace App\Http\Requests\Admin\Reception;

use App\Domain\Finance\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWalletTopUpRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'user_id' => ['required_without:company_id', 'prohibits:company_id', 'integer', 'exists:users,id'],
            'company_id' => ['required_without:user_id', 'integer', 'exists:companies,id'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 4: Write `WalletTopUpController`**

`app/Http/Controllers/Api/V1/Admin/Reception/WalletTopUpController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\StoreWalletTopUpRequest;
use Illuminate\Http\JsonResponse;

/**
 * S2-BE-06: WalletService::creditGeneral() already exists and is tested —
 * this is the missing reception/admin-facing endpoint over it. No parallel
 * top-up mechanism; reuses the existing wallet transaction categorisation
 * fields exactly as they are.
 */
class WalletTopUpController extends Controller
{
    use LogsSensitiveActions;

    public function store(StoreWalletTopUpRequest $request, WalletService $wallets): JsonResponse
    {
        [$ownerType, $ownerId] = $request->validated('company_id')
            ? [OwnerType::Company, (int) $request->validated('company_id')]
            : [OwnerType::User, (int) $request->validated('user_id')];

        $wallet = $wallets->walletFor($ownerType, $ownerId);

        $transaction = $wallets->creditGeneral(
            $wallet,
            $request->validated('amount'),
            WalletTransactionSource::TopUp,
            $request->validated('description')
        );

        $transaction->forceFill([
            'performed_by_user_id' => $request->user()->id,
            'payment_method' => PaymentMethod::from($request->validated('payment_method')),
        ])->save();

        $this->logSensitiveAction('wallet_top_up', $transaction, [
            'owner_type' => $ownerType->value,
            'owner_id' => $ownerId,
            'amount' => $request->validated('amount'),
            'payment_method' => $request->validated('payment_method'),
        ]);

        return response()->json([
            'data' => [
                'id' => $transaction->id,
                'amount' => (string) $transaction->amount,
                'source' => $transaction->source->value,
                'payment_method' => $transaction->payment_method->value,
                'performed_by_user_id' => $transaction->performed_by_user_id,
            ],
        ], 201);
    }
}
```

- [ ] **Step 5: Register the route**

Edit `routes/api/v1/admin.php` — add the import alongside the other two reception controllers, and append after the `WalkInSessionController` routes:

```php
use App\Http\Controllers\Api\V1\Admin\Reception\WalletTopUpController;
```

```php
Route::post('reception/wallet-top-ups', [WalletTopUpController::class, 'store']);
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Booking/WalletTopUpControllerTest.php`
Expected: PASS (6 tests)

- [ ] **Step 7: Run the full suite**

Run:
```bash
composer test
./vendor/bin/pint --test
```
Expected: both clean — this is the last code task in the plan.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Admin/Reception/StoreWalletTopUpRequest.php app/Http/Controllers/Api/V1/Admin/Reception/WalletTopUpController.php routes/api/v1/admin.php tests/Feature/Booking/WalletTopUpControllerTest.php
git commit -m "feat: add WalletTopUpController (S2-BE-06)"
```

---

### Task 14: Decision doc, `docs/decisions/README.md` update

**Files:**
- Create: `docs/decisions/reception-operations-scope.md`
- Modify: `docs/decisions/README.md`

**Interfaces:**
- Consumes: nothing code-level — records the scope cuts and assumptions from Tasks 1-13 for a future reader, per the source spec's own §5 requirement ("a decision doc noting the deliberate scope cut... flag explicitly if anything built here will need revisiting").

- [ ] **Step 1: Write the decision doc**

`docs/decisions/reception-operations-scope.md`:
```markdown
# Reception Operations: scope cut, defaults chosen without a reply, and PRD #5's status

**Status:** resolved 2026-08-16. **Owner:** Maryam Asha.
**Type:** design doc for a new capability, alongside a scope note on an
existing locked decision.

## What this phase adds

A new `App\Domain\Booking` namespace: `bookings` and `walkin_sessions`
tables, three services (`WalkInCapacityService`, `SessionClosureService`,
`BookingCancellationService`), three admin controllers under
`Api\V1\Admin\Reception\`, and a scheduled auto-closure command — the
reception-facing check-in/check-out/settlement/cancellation/wallet-top-up
layer. Full details: [the design spec](../superpowers/specs/2026-08-16-reception-operations-design.md).

## This is a slice of the backend build plan's Phase 5, not the whole thing

`docs/architecture/2026-08-08-backend-build-plan.md` calls this domain's
full scope "Phase 5 — Booking, walk-in sessions, capacity, affected
bookings," with tables `bookings · walkin_sessions · space_capacity_slots ·
affected_bookings`. This phase builds only `bookings` and `walkin_sessions`,
and only reception's own actions on them — no slot-granularity capacity
table, no `affected_bookings`, no extension/approval flow, no booking
creation endpoint. A `PaymentMethod` enum is also pulled forward from the
still-unbuilt "Phase 4 — Finance primitives" (payment methods only, not the
full `payment_methods`/`transactions`/`Money`/exchange-rate-snapshot
system). Anyone extending this domain toward the full Phase 5/4 scope should
read this doc first.

## Decision

- **No booking-creation endpoint this phase.** Nothing in the operations
  list creates a booking — check-in and cancel both act on one that already
  exists. This was asked as a clarifying question and went unanswered; the
  recommended default was taken. Bookings are creatable only via
  factory/tinker for this phase's tests; the real member-facing creation
  flow (with granularity/approval rules) is the next phase's job. If that
  next phase instead wants reception itself to originate bookings (e.g. a
  phone booking), this needs revisiting.
- **Denormalized operator columns, not audit-log-only.** `paid_by` on
  `bookings`/`walkin_sessions`, `performed_by_user_id` on
  `wallet_transactions` (additive migration on an already-shipped table).
  Also asked and unanswered; taken because the source spec's wording
  ("record the operator... and write an audit log entry") reads as two
  distinct requirements, and because every other reception timestamp
  (`checked_in_at`, `checked_out_at`) is already a queryable column rather
  than audit-log-only.
- **Capacity counts physical presence, not reservation.** "Space has
  available capacity right now" (walk-in start) counts currently
  checked-in-and-not-checked-out bookings + walk-ins against `Space.capacity`
  — a confirmed booking that hasn't checked in yet does not count against
  a walk-in's capacity check. A future capacity-slot system (Phase 5 proper)
  might instead reserve capacity from booking creation, not check-in; this
  will need reconciling when that lands.
- **No reconciliation between a pre-checkout payment and the actual
  checkout-computed amount.** If a booking is already `paid` (e.g. by
  wallet, via the out-of-scope creation flow) and the actual checked-in-to-
  checked-out duration differs from what was originally paid for,
  `amount_owed`/`currency` are recorded for reporting only — no partial
  refund or extra charge is raised. Reconciling this is exactly the kind of
  extension/granularity logic explicitly deferred to the next phase.
- **A booking cancellation refund uses the planned window
  (`start_at`-`end_at`), not `amount_owed`.** `amount_owed` is only ever set
  at checkout, and cancellation can only happen before check-in — so it's
  always null at the point a refund is computed. The refund amount is
  whatever the (out-of-scope) creation flow would have charged for the full
  planned duration.
- **`PaymentMethod` lives in `App\Domain\Finance`,** not `Booking`, even
  though nothing else in Finance is built yet — the backend build plan
  already earmarks that domain for payment methods, so this avoids
  relocating the enum when Phase 4 eventually lands.

## PRD decision #5's status

Decision #5 ("Booking = prepaid + cancellable; session without booking =
postpaid + not cancellable") is genuinely refined here, not violated: this
phase's `payment_state`/`payment_source` model allows a booking to be
created unpaid and settled later by reception rather than strictly
requiring payment at creation — the 2026-08-15 decision session's own
framing ("payment is a state, never a precondition for creation") is the
authority for this refinement. Cancellability still applies only to
bookings; walk-in sessions expose no cancellation path at all, matching the
PRD's decision unchanged.

## Why

See "Decision" above — each bullet states its own reasoning inline.

## What this changed in code

- `App\Domain\Booking\{Enums,Models,Services,Exceptions}\*`
- `App\Domain\Finance\Enums\PaymentMethod`
- `App\Domain\Membership\Enums\WalletTransactionSource::Refund`
- `database/migrations/2026_08_16_*` (four migrations: `bookings`,
  `walkin_sessions`, `spaces.cancellation_window_minutes`,
  `wallet_transactions.{performed_by_user_id,payment_method}`)
- `App\Http\Controllers\Api\V1\Admin\Reception\*`, their Form Requests
- `routes/api/v1/admin.php` (`reception/*` routes)
- `app/Console/Commands/CloseOverdueReceptionSessions.php`,
  `routes/console.php`
- `lang/{en,ar}/api.php` (`reception` group)
- `postman/ADD-OS.postman_collection.json` (`Reception Operations` folder)

## Guard

No dedicated `tests/Guards/` entry — like `business-hours.md`, this is new
additive capability rather than a schema-shape invariant. Every enforcement
rule (precondition, failure mode, boundary, the auto-close/manual-close
cross-check) is covered by `tests/Feature/Booking/*` and
`tests/Unit/Domain/Booking/*` instead — see this plan's tasks for the exact
list. `docs/decisions/README.md`'s PRD §7.1 table row for decision #5 is
updated to point here rather than leaving it silently stale at "— (Phase 5)".
```

- [ ] **Step 2: Update `docs/decisions/README.md`**

Edit `docs/decisions/README.md` — add one bullet to the "Design docs" list, right after the `business-hours.md` entry:

```markdown
- [reception-operations-scope.md](reception-operations-scope.md) — reception's check-in/check-out/settlement/cancellation/wallet-top-up layer; a deliberate slice of the build plan's Phase 5 (+ a PaymentMethod sliver of Phase 4), not the full thing
```

Then update the PRD §7.1 decision-map table row for decision #5:

Old:
```markdown
| 5 | Booking = prepaid + cancellable; session without booking = postpaid + not cancellable | — (Phase 5) | 5 |
```

New:
```markdown
| 5 | Booking = prepaid + cancellable; session without booking = postpaid + not cancellable | `tests/Feature/Booking/*` (reception-ops slice, [reception-operations-scope.md](reception-operations-scope.md)); no dedicated `tests/Guards/` entry yet — full capacity-slot/extension scope still pending | 3 (slice), 5 (full) |
```

- [ ] **Step 3: Verify the new doc is correctly linked**

Run: `grep -rn "reception-operations-scope" docs/decisions/README.md`
Expected: two matches (the Design docs bullet and the table row)

- [ ] **Step 4: Commit**

```bash
git add docs/decisions/reception-operations-scope.md docs/decisions/README.md
git commit -m "docs: record Reception Operations scope decisions"
```

---

### Task 15: Postman collection audit and Reception Operations folder

**Files:**
- Modify: `postman/ADD-OS.postman_collection.json`

**Interfaces:**
- Consumes: all routes from Tasks 11-13, plus every existing route in `routes/api/v1/*.php`.
- Produces: the audit table required in the PR description (source spec §4.3: "Report this audit as a short table in the PR description before making changes"), and a new `Reception Operations` folder under `Admin (Dashboard)` with realistic bodies and error examples.

- [ ] **Step 1: Generate the actual route list**

Run: `php artisan route:list --path=api/v1 --json > /tmp/routes.json` (or an OS-appropriate temp path) and open it alongside `postman/ADD-OS.postman_collection.json`. For every route in the JSON output, check whether a Postman request with a matching method+path exists anywhere in the collection (search by the path segments, not exact string match — Postman stores `{{base_url}}/api/v1/...` with `{{variable}}` placeholders for ids). Build the audit table with three categories:

- **Stale** — a Postman request whose method+path has no matching route in the JSON output.
- **Gap** — a route in the JSON output with no matching Postman request.
- **Outdated** — a Postman request that exists for a real route, but whose body/params don't match that route's current Form Request `rules()` (e.g. a field the request no longer accepts, or a required field missing from the example body).

This table is a required part of the PR description, not just an internal note — do not skip writing it out even if it comes back mostly clean.

- [ ] **Step 2: Fix every stale/gap/outdated finding from Step 1**

Remove stale entries, add missing gap entries (following this collection's existing per-folder structure and auth-inheritance conventions), and fix outdated bodies — each as its own small JSON edit to `postman/ADD-OS.postman_collection.json`.

- [ ] **Step 3: Add the "Reception Operations" folder**

Find the `"Business Hours"` folder inside the `"Admin (Dashboard)"` item's `item` array. Insert a new sibling folder immediately after it and before `"Spatial Hierarchy"`:

```json
{
  "name": "Reception Operations",
  "description": "Check-in/check-out, payment settlement, cancellation and manual wallet top-up (docs/decisions/reception-operations-scope.md). Bookings/walk-ins are created via factory/tinker in this phase — there is no creation endpoint yet, so set {{booking_id}}/{{walkin_session_id}} manually before running these.",
  "item": [
    {
      "name": "Check-in & Check-out",
      "item": [
        {
          "name": "Check In Booking",
          "request": {
            "method": "POST",
            "header": [
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/bookings/{{booking_id}}/check-in",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "bookings", "{{booking_id}}", "check-in"]
            }
          }
        },
        {
          "name": "Check In Booking — Error: Outside Business Hours",
          "request": {
            "method": "POST",
            "header": [
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/bookings/{{booking_id}}/check-in",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "bookings", "{{booking_id}}", "check-in"]
            },
            "description": "422 `{\"message\": \"This action is not available outside business hours.\"}` when the branch is currently closed."
          }
        },
        {
          "name": "Start Walk-in Session",
          "event": [
            {
              "listen": "test",
              "script": {
                "type": "text/javascript",
                "exec": [
                  "if (pm.response.code === 201) {",
                  "    pm.collectionVariables.set('walkin_session_id', pm.response.json().data.id);",
                  "}"
                ]
              }
            }
          ],
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"space_id\": {{space_id}},\n  \"user_id\": {{user_id}}\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/walk-ins",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "walk-ins"]
            }
          }
        },
        {
          "name": "Start Walk-in Session — Error: No Capacity",
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"space_id\": {{space_id}},\n  \"user_id\": {{user_id}}\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/walk-ins",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "walk-ins"]
            },
            "description": "422 `{\"message\": \"This space has no available capacity right now.\"}` when the space is already at Space.capacity."
          }
        },
        {
          "name": "Check Out Booking",
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"checked_out_at\": \"2026-08-17T11:00:00+03:00\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/bookings/{{booking_id}}/check-out",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "bookings", "{{booking_id}}", "check-out"]
            }
          }
        },
        {
          "name": "Check Out Booking — Error: Past Closing Time",
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"checked_out_at\": \"2026-08-17T23:59:00+03:00\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/bookings/{{booking_id}}/check-out",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "bookings", "{{booking_id}}", "check-out"]
            },
            "description": "422 `{\"message\": \"The checkout time cannot be after the branch's closing time.\"}`"
          }
        },
        {
          "name": "Check Out Walk-in Session",
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"checked_out_at\": \"2026-08-17T11:00:00+03:00\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/walk-ins/{{walkin_session_id}}/check-out",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "walk-ins", "{{walkin_session_id}}", "check-out"]
            }
          }
        },
        {
          "name": "Cancel Booking",
          "request": {
            "method": "POST",
            "header": [
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/bookings/{{booking_id}}/cancel",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "bookings", "{{booking_id}}", "cancel"]
            }
          }
        },
        {
          "name": "Cancel Booking — Error: Past Cancellation Window",
          "request": {
            "method": "POST",
            "header": [
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/bookings/{{booking_id}}/cancel",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "bookings", "{{booking_id}}", "cancel"]
            },
            "description": "422 `{\"message\": \"This booking is past its cancellation window.\"}`"
          }
        }
      ]
    },
    {
      "name": "Payment Settlement",
      "item": [
        {
          "name": "Settle Booking Payment",
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"payment_method\": \"sham\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/bookings/{{booking_id}}/settle-payment",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "bookings", "{{booking_id}}", "settle-payment"]
            },
            "description": "payment_method is one of cash|sham|mtn|syriatel."
          }
        },
        {
          "name": "Settle Booking Payment — Error: Already Paid",
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"payment_method\": \"cash\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/bookings/{{booking_id}}/settle-payment",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "bookings", "{{booking_id}}", "settle-payment"]
            },
            "description": "409 `{\"message\": \"This booking or session has already been paid.\"}`"
          }
        },
        {
          "name": "Settle Walk-in Payment",
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"payment_method\": \"mtn\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/walk-ins/{{walkin_session_id}}/settle-payment",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "walk-ins", "{{walkin_session_id}}", "settle-payment"]
            }
          }
        },
        {
          "name": "Settle Walk-in Payment — Error: Not Yet Checked Out",
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"payment_method\": \"cash\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/walk-ins/{{walkin_session_id}}/settle-payment",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "walk-ins", "{{walkin_session_id}}", "settle-payment"]
            },
            "description": "422 `{\"message\": \"This booking or session must be checked out before payment can be settled.\"}`"
          }
        }
      ]
    },
    {
      "name": "Wallet Top-up",
      "item": [
        {
          "name": "Top Up Member Wallet",
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"user_id\": {{user_id}},\n  \"amount\": \"25.00\",\n  \"payment_method\": \"cash\",\n  \"description\": \"Front-desk cash top-up\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/wallet-top-ups",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "wallet-top-ups"]
            }
          }
        },
        {
          "name": "Top Up Company Wallet",
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"company_id\": {{company_id}},\n  \"amount\": \"50.00\",\n  \"payment_method\": \"syriatel\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/wallet-top-ups",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "wallet-top-ups"]
            }
          }
        },
        {
          "name": "Top Up Wallet — Error: No Target Specified",
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"amount\": \"25.00\",\n  \"payment_method\": \"cash\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/reception/wallet-top-ups",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "reception", "wallet-top-ups"]
            },
            "description": "422 — exactly one of user_id/company_id is required."
          }
        }
      ]
    }
  ]
}
```

- [ ] **Step 4: Validate the JSON is well-formed**

Run: `node -e "JSON.parse(require('fs').readFileSync('postman/ADD-OS.postman_collection.json', 'utf8')); console.log('valid')"`
Expected: prints `valid`

- [ ] **Step 5: Commit**

```bash
git add postman/ADD-OS.postman_collection.json
git commit -m "docs: audit and add Reception Operations to Postman collection"
```

Include the Step 1 audit table verbatim in the pull request description — this is a required deliverable, not optional.

---

## Post-plan check

Run the full suite once more before opening the PR:

```bash
composer test
./vendor/bin/pint --test
```

Both should be clean. At this point: `App\Domain\Booking\{Booking,WalkinSession}` exist with full reception lifecycle support; `WalkInCapacityService`, `SessionClosureService`, `BookingCancellationService` cover every precondition/failure mode from the source spec's §2 and §3; `reception:close-overdue-sessions` runs every 5 minutes; the three reception controllers are live under `/api/v1/admin/reception/*`; `docs/decisions/reception-operations-scope.md` records the scope cuts; the Postman collection is audited and extended. Booking creation itself, `space_capacity_slots`, `affected_bookings`, extension/approval, and the full Finance `payment_methods`/`transactions`/`Money` system remain for a later phase, per this plan's decision doc.
