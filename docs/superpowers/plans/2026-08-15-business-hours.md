# Business Hours Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the business-hours *capability* — a per-branch weekly schedule, date-specific exceptions, and a resolution service answering "is this instant within business hours, and what are the day's open/close periods" — as the foundation Sprint 3's booking validation and session auto-closure will consume. This phase builds the capability only; it does not build bookings, sessions, or touch TTLock/access.

**Architecture:** Two new tables under the existing `Foundation` domain (`business_hours`: recurring weekly schedule per branch; `business_hour_exceptions`: date-specific overrides, including "closed entirely"), a `BusinessHoursService` (new `Foundation/Services/`, mirroring how `Finance`/`Membership` already have one) that resolves exception-then-weekly-then-closed, and standard `AdminResourceController`-based CRUD for both tables. A single global `app.timezone` setting (via the existing `SettingService`) is what every open/close comparison resolves through — not a per-branch column. `tests/Guards/NoAccessHoursWindowTest.php`'s docblock is amended (not weakened) to state explicitly that it guards only the physical/door-access layer (PRD decision #11), which this phase does not touch — booking-hours is a distinct, newly-sanctioned concept enforced by an entirely different mechanism and vocabulary.

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit, SQLite in-memory (tests), Carbon for instant/timezone handling.

**Spec:** The "ADD Core — Phase 2: Business Hours" prompt from the 2026-08-15 decision session (decisions #6, #7, #8, #9), building on Phase 1 (`settings`, merged to `master` at `acb6a5c`).

## Global Constraints

- PHP `^8.2`, Laravel Framework `^12.0` — no other version floors apply.
- **Never use `->enum()` in a migration.** Every enum-shaped column is a `string` column cast to a PHP 8.2 backed enum on the model — guarded by `tests/Guards/NoNewMysqlEnumColumnsTest.php` (migration side) and `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` (model side — add an entry to its `EXPECTED_CASTS` map for every new enum-shaped column).
- **Every backed enum in this codebase is string-backed** (17 existing examples, zero int-backed). `DayOfWeek` follows this — string-backed, not int-backed — for consistency, even though Carbon's native `dayOfWeek` accessor is an int.
- **Time-of-day values are plain `string` columns in `H:i` format, not native `TIME` columns** — matches the existing precedent in `App\Domain\Settings\Models\Setting`'s `SettingValueType::Time` handling (validated via `date_format:H:i`, regex `^([01]\d|2[0-3]):[0-5]\d$`). No table in this codebase uses a native `TIME` column type; this phase doesn't introduce the first one.
- Models live under `App\Domain\<Domain>\Models`, enums under `App\Domain\<Domain>\Enums`, services under `App\Domain\<Domain>\Services`. This phase adds a `Services/` subdirectory to `Foundation` for the first time (mirroring `Finance`/`Membership`, which already have one) — `Foundation` previously had none.
- Eloquent casts are declared via the `casts(): array` method, never the legacy `protected $casts` array property.
- Factories are flat under `database/factories/{Name}Factory.php` (namespace `Database\Factories`, not domain-nested), with an explicit `protected $model = X::class;`.
- `AdminResourceController::hasOrderColumn()` **defaults to `true`** — every new subclass without an `order`/`sort_order` column (both new tables here) must override it to return `false`, or `index()` throws a SQL error trying to `ORDER BY order`.
- `AdminResourceController::show(int $id)` / `destroy(int $id)` take a raw `int`, not route-model binding — but concrete controllers' own `store()`/`update()` DO use implicit route-model binding (e.g. `update(UpdateBuildingRequest $request, Building $building)`), and destroy routes still name the parameter to match (Laravel passes it through positionally regardless of the scalar parameter's own name — this is the existing, working pattern for every other spatial-hierarchy resource).
- Every PATCH/PUT admin endpoint responds with `response()->json(['message' => __('api.<domain>.<key>')])`, never the updated resource. Keys live in `lang/en/api.php` / `lang/ar/api.php` under the existing `'admin'` group.
- `routes/api/v1/admin.php`: every route already sits behind `auth:sanctum` + `role:admin|operations` (applied once upstream). Non-destroy actions go in the main body near the other Spatial Hierarchy resources; `destroy` routes go inside the existing `Route::middleware('role:admin')->group(...)` block at the bottom of the file, alongside the other Spatial Hierarchy destroys.
- **Hyphenated multi-word `apiResource` names need an explicit `->parameters([...])` mapping** — e.g. `Route::apiResource('business-hours', BusinessHourController::class)->parameters(['business-hours' => 'businessHour'])` — since Laravel's auto-derived snake_case placeholder won't implicit-bind a camelCase controller argument (established precedent: `community-members`, `seats-desks`, `device-capabilities`).
- **`tests/Guards/NoAccessHoursWindowTest.php` bans the literal, case-insensitive substrings `allowed_hours`, `ACCESS_HOURS_START`, `ACCESS_HOURS_END`, `isWithinAllowedHours` anywhere in `app/`, `config/`, `database/`, `routes/`.** None of this phase's chosen names collide (`BusinessHoursService`, `isWithinBusinessHours`, `open_time`/`close_time`, `business_hours`/`business_hour_exceptions`) — but every task must avoid introducing those exact banned substrings, including in comments/docblocks.
- **`branches.timezone` already exists as a plain string column (Phase 1, unrelated, unused)** — this phase does NOT read, write, or otherwise touch it. The new `app.timezone` **Setting** key (via `SettingService`, a completely separate mechanism) is what every open/close comparison in this phase resolves through. This is flagged explicitly in the decision doc so a future reader doesn't conflate the two.
- `SettingService` (already built, Phase 1): `get(string $key, mixed $default = null, SettingScope $scopeType = SettingScope::Global, int $scopeId = 0): mixed` and `setDefault(string $key, mixed $value, SettingValueType $type, ...): Setting` (non-clobbering — use this in the seeder, never `set()`).
- Admin feature tests: `use RefreshDatabase;`, seed roles via `$this->seed(RoleSeeder::class);` in `setUp()`, authenticate via `Sanctum::actingAs($admin, ['*'])` after `$admin->assignRole('admin')` (or `'operations'`).
- `docs/decisions/*.md` format: `# Title`, a `**Status:** resolved <date>. **Owner:** Maryam Asha.` line, then `## Decision`, `## Why`, `## What this changed in code`, `## Guard` sections.
- Migration filenames: `database/migrations/YYYY_MM_DD_HHMMSS_verb_description.php`, one flat directory. Most recent existing migration is `2026_08_15_090000_create_settings_table.php` — this phase's two new migrations must sort after it.
- **Boundary convention (decide once, apply everywhere):** both `open_time` and `close_time` are **inclusive** boundaries — an instant exactly at either edge counts as within business hours. This mirrors the booking-side rule from the wider decision session ("reject a booking that starts before opening or ends after closing" — starting exactly at opening or ending exactly at closing is not "before"/"after"), keeping the single-instant check and the future range check symmetric.

---

### Task 1: `DayOfWeek` enum

**Files:**
- Create: `app/Domain/Foundation/Enums/DayOfWeek.php`

**Interfaces:**
- Produces: `DayOfWeek` (string-backed, cases `Sunday`..`Saturday`, values `'sunday'`..`'saturday'`), static `DayOfWeek::fromCarbon(\Carbon\CarbonInterface $date): self` — consumed by Task 2 (model cast) and Task 5 (resolution service).

- [ ] **Step 1: Create the enum**

```php
<?php

namespace App\Domain\Foundation\Enums;

use Carbon\CarbonInterface;

/**
 * String-backed, not int-backed, to match every other enum in this
 * codebase (see build plan §A.4) — even though Carbon's own `dayOfWeek`
 * accessor is an int. `fromCarbon()` is the one place that translation
 * happens, so nothing else needs to know Carbon's numbering.
 */
enum DayOfWeek: string
{
    case Sunday = 'sunday';
    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';

    public static function fromCarbon(CarbonInterface $date): self
    {
        return self::from(strtolower($date->format('l')));
    }
}
```

- [ ] **Step 2: Verify it loads**

Run: `php artisan tinker --execute="echo App\Domain\Foundation\Enums\DayOfWeek::fromCarbon(\Carbon\Carbon::parse('2026-08-16'))->value;"`
Expected: prints `sunday` (2026-08-16 is a Sunday)

- [ ] **Step 3: Commit**

```bash
git add app/Domain/Foundation/Enums/DayOfWeek.php
git commit -m "feat: add DayOfWeek enum for business hours"
```

---

### Task 2: `business_hours` table, `BusinessHour` model, factory

**Files:**
- Create: `database/migrations/2026_08_15_100000_create_business_hours_table.php`
- Create: `app/Domain/Foundation/Models/BusinessHour.php`
- Create: `database/factories/BusinessHourFactory.php`
- Modify: `app/Domain/Foundation/Models/Branch.php` (add `businessHours()` relationship)
- Test: `tests/Unit/Domain/Foundation/BusinessHourTest.php`

**Interfaces:**
- Consumes: `DayOfWeek` (Task 1).
- Produces: `BusinessHour` (fillable `branch_id`, `day_of_week`, `open_time`, `close_time`; cast `day_of_week` → `DayOfWeek`; `branch(): BelongsTo`) — consumed by Task 4 (validation rule queries), Task 5 (resolution service), Task 7 (admin CRUD).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Foundation;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessHourTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_casts_day_of_week_to_the_enum(): void
    {
        $branch = Branch::factory()->create();

        $businessHour = BusinessHour::create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        $this->assertSame(DayOfWeek::Monday, $businessHour->fresh()->day_of_week);
    }

    public function test_it_belongs_to_a_branch(): void
    {
        $branch = Branch::factory()->create();
        $businessHour = BusinessHour::factory()->create(['branch_id' => $branch->id]);

        $this->assertTrue($businessHour->branch->is($branch));
    }

    public function test_branch_has_many_business_hours(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->count(2)->create(['branch_id' => $branch->id]);

        $this->assertCount(2, $branch->fresh()->businessHours);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Foundation/BusinessHourTest.php`
Expected: FAIL — `Class "App\Domain\Foundation\Models\BusinessHour" not found`

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('day_of_week');
            // H:i strings, not a native TIME column — matches Setting's
            // existing time-value precedent (App\Domain\Settings\Models\Setting).
            $table->string('open_time', 5);
            $table->string('close_time', 5);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_hours');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Domain\Foundation\Models;

use App\Domain\Foundation\Enums\DayOfWeek;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per recurring period. Multiple rows for the same
 * (branch, day_of_week) are how a two-period day (e.g. a midday closure)
 * is expressed — there is no separate "periods" child table. Absence of
 * any row for a given (branch, day_of_week) means closed that day; see
 * App\Domain\Foundation\Services\BusinessHoursService for the resolution
 * that depends on this.
 */
class BusinessHour extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'day_of_week',
        'open_time',
        'close_time',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessHour>
 */
class BusinessHourFactory extends Factory
{
    protected $model = BusinessHour::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'day_of_week' => $this->faker->randomElement(DayOfWeek::cases()),
            'open_time' => '08:00',
            'close_time' => '17:00',
        ];
    }
}
```

- [ ] **Step 6: Add the `businessHours()` relationship to `Branch`**

In `app/Domain/Foundation/Models/Branch.php`, add the import `use App\Domain\Foundation\Models\BusinessHour;` is unnecessary (same namespace); add this method next to the existing `buildings()`/`devices()` methods:

```php
    public function businessHours(): HasMany
    {
        return $this->hasMany(BusinessHour::class);
    }
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Foundation/BusinessHourTest.php`
Expected: PASS (3 tests)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_15_100000_create_business_hours_table.php app/Domain/Foundation/Models/BusinessHour.php database/factories/BusinessHourFactory.php app/Domain/Foundation/Models/Branch.php tests/Unit/Domain/Foundation/BusinessHourTest.php
git commit -m "feat: add business_hours table and BusinessHour model"
```

---

### Task 3: `business_hour_exceptions` table, `BusinessHourException` model, factory

**Files:**
- Create: `database/migrations/2026_08_15_100100_create_business_hour_exceptions_table.php`
- Create: `app/Domain/Foundation/Models/BusinessHourException.php`
- Create: `database/factories/BusinessHourExceptionFactory.php`
- Modify: `app/Domain/Foundation/Models/Branch.php` (add `businessHourExceptions()` relationship — Task 2 already modified this file to add `businessHours()`; add alongside it, don't disturb it)
- Test: `tests/Unit/Domain/Foundation/BusinessHourExceptionTest.php`

**Interfaces:**
- Produces: `BusinessHourException` (fillable `branch_id`, `date`, `is_closed`, `open_time`, `close_time`, `reason`; casts `date` → `date`, `is_closed` → `boolean`; `branch(): BelongsTo`) — consumed by Task 4, Task 5, Task 8.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Foundation;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHourException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessHourExceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_casts_date_and_is_closed(): void
    {
        $branch = Branch::factory()->create();

        $exception = BusinessHourException::create([
            'branch_id' => $branch->id,
            'date' => '2026-12-25',
            'is_closed' => true,
            'reason' => 'Holiday',
        ]);

        $fresh = $exception->fresh();
        $this->assertTrue($fresh->date->isSameDay('2026-12-25'));
        $this->assertTrue($fresh->is_closed);
    }

    public function test_it_belongs_to_a_branch(): void
    {
        $branch = Branch::factory()->create();
        $exception = BusinessHourException::factory()->create(['branch_id' => $branch->id]);

        $this->assertTrue($exception->branch->is($branch));
    }

    public function test_branch_has_many_business_hour_exceptions(): void
    {
        $branch = Branch::factory()->create();
        BusinessHourException::factory()->count(2)->create(['branch_id' => $branch->id]);

        $this->assertCount(2, $branch->fresh()->businessHourExceptions);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Foundation/BusinessHourExceptionTest.php`
Expected: FAIL — `Class "App\Domain\Foundation\Models\BusinessHourException" not found`

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_hour_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->date('date');
            // Absence of rows for a date means "no exception, fall back to
            // the weekly schedule" — that's different from "closed", which
            // needs its own explicit flag (unlike business_hours, where
            // absence of rows for a weekday unambiguously means closed).
            $table->boolean('is_closed')->default(false);
            $table->string('open_time', 5)->nullable();
            $table->string('close_time', 5)->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_hour_exceptions');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Domain\Foundation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A date-specific override for one branch. `is_closed = true` means the
 * branch is closed all day regardless of the weekly schedule — it is the
 * ONLY row for that (branch, date) when true. `is_closed = false` rows
 * carry `open_time`/`close_time` and fully replace the weekly schedule for
 * that date (they do not merge with it); multiple such rows express a
 * two-period exception day the same way business_hours does for a weekday.
 */
class BusinessHourException extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'date',
        'is_closed',
        'open_time',
        'close_time',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_closed' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHourException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessHourException>
 */
class BusinessHourExceptionFactory extends Factory
{
    protected $model = BusinessHourException::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'date' => $this->faker->unique()->date(),
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
            'reason' => null,
        ];
    }

    public function closedEntirely(): static
    {
        return $this->state(fn () => [
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
        ]);
    }
}
```

- [ ] **Step 6: Add the `businessHourExceptions()` relationship to `Branch`**

In `app/Domain/Foundation/Models/Branch.php`, add this method next to the `businessHours()` method Task 2 added:

```php
    public function businessHourExceptions(): HasMany
    {
        return $this->hasMany(BusinessHourException::class);
    }
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Foundation/BusinessHourExceptionTest.php`
Expected: PASS (3 tests)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_15_100100_create_business_hour_exceptions_table.php app/Domain/Foundation/Models/BusinessHourException.php database/factories/BusinessHourExceptionFactory.php app/Domain/Foundation/Models/Branch.php tests/Unit/Domain/Foundation/BusinessHourExceptionTest.php
git commit -m "feat: add business_hour_exceptions table and BusinessHourException model"
```

---

### Task 4: `NoOverlappingPeriod` validation rule

**Files:**
- Create: `app/Rules/NoOverlappingPeriod.php`
- Test: `tests/Unit/Rules/NoOverlappingPeriodTest.php`

**Interfaces:**
- Produces: `NoOverlappingPeriod` (implements `Illuminate\Contracts\Validation\ValidationRule`; constructor `(iterable $existingPeriods, string $openTime)`; validates the attribute it's attached to — the new period's `close_time` — against every existing period's `[open_time, close_time]`, inclusive) — consumed by Task 7 and Task 8's Form Requests.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Rules;

use App\Rules\NoOverlappingPeriod;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class NoOverlappingPeriodTest extends TestCase
{
    public function test_it_passes_when_no_existing_periods_overlap(): void
    {
        $rule = new NoOverlappingPeriod(
            existingPeriods: [['open_time' => '08:00', 'close_time' => '12:00']],
            openTime: '13:00',
        );

        $validator = Validator::make(['close_time' => '17:00'], ['close_time' => [$rule]]);

        $this->assertTrue($validator->passes());
    }

    public function test_it_fails_when_the_new_period_overlaps_an_existing_one(): void
    {
        $rule = new NoOverlappingPeriod(
            existingPeriods: [['open_time' => '08:00', 'close_time' => '12:00']],
            openTime: '11:00',
        );

        $validator = Validator::make(['close_time' => '15:00'], ['close_time' => [$rule]]);

        $this->assertFalse($validator->passes());
    }

    public function test_it_fails_when_the_new_period_exactly_matches_an_existing_one(): void
    {
        $rule = new NoOverlappingPeriod(
            existingPeriods: [['open_time' => '08:00', 'close_time' => '12:00']],
            openTime: '08:00',
        );

        $validator = Validator::make(['close_time' => '12:00'], ['close_time' => [$rule]]);

        $this->assertFalse($validator->passes());
    }

    public function test_it_passes_when_the_new_period_is_adjacent_but_not_overlapping(): void
    {
        // Touching at a single boundary point does not count as overlap —
        // both edges are inclusive, so 08:00-12:00 and 12:00-16:00 would
        // share the instant 12:00, which is a real overlap under an
        // inclusive-inclusive convention. Use a genuinely non-touching pair.
        $rule = new NoOverlappingPeriod(
            existingPeriods: [['open_time' => '08:00', 'close_time' => '11:59']],
            openTime: '12:00',
        );

        $validator = Validator::make(['close_time' => '16:00'], ['close_time' => [$rule]]);

        $this->assertTrue($validator->passes());
    }

    public function test_it_passes_with_no_existing_periods(): void
    {
        $rule = new NoOverlappingPeriod(existingPeriods: [], openTime: '08:00');

        $validator = Validator::make(['close_time' => '17:00'], ['close_time' => [$rule]]);

        $this->assertTrue($validator->passes());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Rules/NoOverlappingPeriodTest.php`
Expected: FAIL — `Class "App\Rules\NoOverlappingPeriod" not found`

- [ ] **Step 3: Write the rule**

```php
<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fails if [openTime, closeTime] (both inclusive) overlaps any of the
 * given existing periods. `closeTime` is the value being validated (this
 * rule is attached to the `close_time` field); `openTime` is the sibling
 * field's value, passed in via the constructor since ValidationRule only
 * sees the one attribute it's attached to. Two closed intervals
 * [a1,a2] and [b1,b2] overlap iff a1 <= b2 AND b1 <= a2 — with H:i
 * zero-padded strings, string comparison is equivalent to numeric
 * comparison for same-day times.
 *
 * The "which siblings" query (same branch+weekday, or same branch+date,
 * excluding the record being updated) is the caller's job — this rule
 * only does the interval-overlap math.
 */
class NoOverlappingPeriod implements ValidationRule
{
    /**
     * @param iterable<array{open_time: string, close_time: string}> $existingPeriods
     */
    public function __construct(
        private readonly iterable $existingPeriods,
        private readonly string $openTime,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach ($this->existingPeriods as $period) {
            if ($this->openTime <= $period['close_time'] && $period['open_time'] <= $value) {
                $fail('This time period overlaps an existing period.');

                return;
            }
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Rules/NoOverlappingPeriodTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Rules/NoOverlappingPeriod.php tests/Unit/Rules/NoOverlappingPeriodTest.php
git commit -m "feat: add NoOverlappingPeriod validation rule"
```

---

### Task 5: `BusinessHoursService` — the resolution logic

**Files:**
- Create: `app/Domain/Foundation/Services/BusinessHoursService.php`
- Test: `tests/Unit/Domain/Foundation/BusinessHoursServiceTest.php`

**Interfaces:**
- Consumes: `BusinessHour`, `BusinessHourException`, `DayOfWeek` (Tasks 1-3), `App\Domain\Settings\Services\SettingService::get()` (Phase 1, already exists).
- Produces: `BusinessHoursService::isWithinBusinessHours(\Carbon\CarbonInterface $instant, \App\Domain\Foundation\Models\Branch $branch): bool` and `BusinessHoursService::periodsFor(\Carbon\CarbonInterface $date, \App\Domain\Foundation\Models\Branch $branch): array` (returns `array<int, array{open_time: string, close_time: string}>`, sorted by `open_time`) — this is the consumer-facing API Sprint 3's booking validation and session auto-closure will use; **no caller is implemented in this plan.**

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Foundation;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\BusinessHourException;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessHoursServiceTest extends TestCase
{
    use RefreshDatabase;

    private BusinessHoursService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BusinessHoursService(new SettingService);
    }

    public function test_a_weekday_with_no_schedule_rows_resolves_to_closed(): void
    {
        $branch = Branch::factory()->create();
        // Monday has no rows at all.
        $monday = Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'); // a Monday

        $this->assertFalse($this->service->isWithinBusinessHours($monday, $branch));
        $this->assertSame([], $this->service->periodsFor($monday, $branch));
    }

    public function test_an_instant_within_the_weekly_schedule_is_within_hours(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        $instant = Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus');

        $this->assertTrue($this->service->isWithinBusinessHours($instant, $branch));
    }

    public function test_instant_exactly_at_open_time_is_within_hours(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        $instant = Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus');

        $this->assertTrue($this->service->isWithinBusinessHours($instant, $branch));
    }

    public function test_instant_exactly_at_close_time_is_within_hours(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        $instant = Carbon::parse('2026-08-17 17:00:00', 'Asia/Damascus');

        $this->assertTrue($this->service->isWithinBusinessHours($instant, $branch));
    }

    public function test_instant_one_minute_after_close_time_is_not_within_hours(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        $instant = Carbon::parse('2026-08-17 17:01:00', 'Asia/Damascus');

        $this->assertFalse($this->service->isWithinBusinessHours($instant, $branch));
    }

    public function test_two_period_day_treats_the_midday_gap_as_closed(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '12:00',
        ]);
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '15:00',
            'close_time' => '20:00',
        ]);

        $morning = Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus');
        $gap = Carbon::parse('2026-08-17 13:00:00', 'Asia/Damascus');
        $evening = Carbon::parse('2026-08-17 16:00:00', 'Asia/Damascus');

        $this->assertTrue($this->service->isWithinBusinessHours($morning, $branch));
        $this->assertFalse($this->service->isWithinBusinessHours($gap, $branch));
        $this->assertTrue($this->service->isWithinBusinessHours($evening, $branch));
        $this->assertCount(2, $this->service->periodsFor($morning, $branch));
    }

    public function test_exception_overrides_the_weekday_schedule_for_that_date(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);
        // 2026-08-17 is a Monday — shortened hours just for this date.
        BusinessHourException::factory()->create([
            'branch_id' => $branch->id,
            'date' => '2026-08-17',
            'is_closed' => false,
            'open_time' => '10:00',
            'close_time' => '14:00',
        ]);

        $withinExceptionOnly = Carbon::parse('2026-08-17 15:00:00', 'Asia/Damascus');
        $withinBoth = Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus');

        // 15:00 is within the normal Monday schedule but NOT the exception.
        $this->assertFalse($this->service->isWithinBusinessHours($withinExceptionOnly, $branch));
        $this->assertTrue($this->service->isWithinBusinessHours($withinBoth, $branch));
    }

    public function test_closed_entirely_exception_blocks_a_day_that_is_normally_open(): void
    {
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);
        BusinessHourException::factory()->closedEntirely()->create([
            'branch_id' => $branch->id,
            'date' => '2026-08-17',
            'reason' => 'Emergency closure',
        ]);

        $instant = Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus');

        $this->assertFalse($this->service->isWithinBusinessHours($instant, $branch));
        $this->assertSame([], $this->service->periodsFor($instant, $branch));
    }

    public function test_one_branchs_hours_do_not_leak_into_another_branchs_resolution(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branchA->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);
        // Branch B has no schedule at all.

        $instant = Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus');

        $this->assertTrue($this->service->isWithinBusinessHours($instant, $branchA));
        $this->assertFalse($this->service->isWithinBusinessHours($instant, $branchB));
    }

    public function test_resolution_is_correct_for_an_instant_near_a_day_boundary_in_the_configured_timezone(): void
    {
        $branch = Branch::factory()->create();
        // Sunday is open all day; Saturday has no schedule (closed).
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Sunday,
            'open_time' => '00:00',
            'close_time' => '23:59',
        ]);

        $settings = new SettingService;
        $settings->setDefault('app.timezone', 'Asia/Damascus', SettingValueType::String);

        // 2026-08-16 21:30:00 UTC is a Saturday in UTC, but Asia/Damascus is
        // UTC+3, so locally it is already 2026-08-17 00:30:00 — a Sunday.
        $instant = Carbon::parse('2026-08-16 21:30:00', 'UTC');

        $this->assertTrue($this->service->isWithinBusinessHours($instant, $branch));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Foundation/BusinessHoursServiceTest.php`
Expected: FAIL — `Class "App\Domain\Foundation\Services\BusinessHoursService" not found`

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\BusinessHourException;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonInterface;

/**
 * Answers "is this instant within business hours for this branch" and
 * "what are the open/close periods for this branch on this date." This is
 * the ONLY place resolution order is decided: an exception for the date,
 * if any, fully replaces the weekly schedule (it does not merge with it);
 * otherwise the weekday's schedule rows apply; no rows at either level
 * means closed. Both `open_time` and `close_time` are inclusive boundaries
 * (docs/decisions/business-hours.md) — an instant exactly at either edge
 * counts as within business hours.
 *
 * All comparisons resolve through a single global `app.timezone` Setting,
 * not a per-branch column — `branches.timezone` is a separate, unrelated,
 * pre-existing column this service does not read.
 */
class BusinessHoursService
{
    public function __construct(private readonly SettingService $settings) {}

    public function isWithinBusinessHours(CarbonInterface $instant, Branch $branch): bool
    {
        $local = $this->toLocal($instant);
        $time = $local->format('H:i');

        foreach ($this->periodsFor($local, $branch) as $period) {
            if ($time >= $period['open_time'] && $time <= $period['close_time']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{open_time: string, close_time: string}>
     */
    public function periodsFor(CarbonInterface $date, Branch $branch): array
    {
        $local = $this->toLocal($date);

        $exceptions = BusinessHourException::query()
            ->where('branch_id', $branch->id)
            ->whereDate('date', $local->toDateString())
            ->get();

        if ($exceptions->isNotEmpty()) {
            if ($exceptions->first()->is_closed) {
                return [];
            }

            return $exceptions
                ->sortBy('open_time')
                ->values()
                ->map(fn (BusinessHourException $exception) => [
                    'open_time' => $exception->open_time,
                    'close_time' => $exception->close_time,
                ])
                ->all();
        }

        $weekday = DayOfWeek::fromCarbon($local);

        return BusinessHour::query()
            ->where('branch_id', $branch->id)
            ->where('day_of_week', $weekday)
            ->orderBy('open_time')
            ->get(['open_time', 'close_time'])
            ->map(fn (BusinessHour $businessHour) => [
                'open_time' => $businessHour->open_time,
                'close_time' => $businessHour->close_time,
            ])
            ->all();
    }

    private function toLocal(CarbonInterface $instant): CarbonInterface
    {
        return $instant->clone()->setTimezone(
            $this->settings->get('app.timezone', 'Asia/Damascus')
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Foundation/BusinessHoursServiceTest.php`
Expected: PASS (10 tests)

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: all green, no regressions

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Foundation/Services/BusinessHoursService.php tests/Unit/Domain/Foundation/BusinessHoursServiceTest.php
git commit -m "feat: add BusinessHoursService resolution logic"
```

---

### Task 6: Amend `NoAccessHoursWindowTest`, record the PRD #11 partial reversal

**Files:**
- Modify: `tests/Guards/NoAccessHoursWindowTest.php`
- Create: `docs/decisions/business-hours-prd-11-partial-reversal.md`
- Modify: `docs/decisions/README.md`

**Interfaces:**
- None — this task is documentation and a docblock/clarifying-comment change. It does not touch any of the guard's three assertions.

- [ ] **Step 1: Amend the guard's docblock**

In `tests/Guards/NoAccessHoursWindowTest.php`, replace the existing class docblock with:

```php
/**
 * PRD decision #11: access is 24/7; the previous 08:00-23:00 window is
 * "ملغاة نهائياً من كل موضع" (abolished finally, everywhere). This was a real
 * implemented feature (config/access.php + AccessHoursPolicy), not a stub —
 * so the risk is a partial revert or a copy-pasted reintroduction in a
 * later booking/check-in flow. The guard fails on the file paths and on
 * the specific config key, not just "the class doesn't exist", so it also
 * catches a re-implementation under a different name.
 *
 * Scope, clarified 2026-08-15 (docs/decisions/business-hours-prd-11-partial-reversal.md):
 * this guard covers the PHYSICAL/DOOR-ACCESS layer only. Decision #11 never
 * governed booking or scheduling — a distinct, separately-approved
 * business-hours concept for BOOKING now exists
 * (App\Domain\Foundation\Services\BusinessHoursService, `business_hours` /
 * `business_hour_exceptions` tables). That is not a violation of this
 * guard or of decision #11: physical door access remains unrestricted and
 * TTLock grants remain `Period` with no time window, exactly as before.
 * The three assertions below are unchanged — none of them are weakened,
 * narrowed, or skipped; this comment only makes the scope explicit now
 * that something exists to contrast against.
 */
```

- [ ] **Step 2: Verify the guard still passes unchanged**

Run: `php artisan test tests/Guards/NoAccessHoursWindowTest.php`
Expected: PASS (3 tests) — proves the new Business Hours code (from Tasks 1-5) does not collide with the guard's banned substrings.

- [ ] **Step 3: Write the decision doc**

```markdown
# Business hours (booking) is not a reversal of PRD #11 (physical access)

**Status:** resolved 2026-08-15. **Owner:** Maryam Asha.
**Type:** clarification of guard scope, alongside a genuinely new,
separately-approved capability — not a reversal, partial or otherwise, of
a locked decision.

## What PRD decision #11 actually locked

Decision #11 states physical access is 24/7, with no time-of-day
restriction. The abolished implementation it replaced
(`config/access.php` + `App\Services\Access\AccessHoursPolicy`) gated the
BUILDING'S DOOR — whether a person's credential would open a lock during
a given window. `tests/Guards/NoAccessHoursWindowTest.php` was written to
make sure nothing re-imposes that specific restriction, anywhere, under
any name.

## What this phase adds

A business-hours concept that gates BOOKING and SCHEDULING — whether a
member can reserve a space for a given time. This is a different question
about a different system: no `Device`, lock, or TTLock code is touched by
this phase (Access/TTLock is Phase 6, not yet built), and physical entry
continues to require nothing beyond a valid access grant, unrestricted by
time — decision #9 keeps TTLock grants `Period`-typed specifically because
the main door has no lock at all, so time-restricting a room's own code
would have no enforcement effect on when someone enters the building.

## Decision

**This is not a reversal of decision #11.** Decision #11 was never about
booking. Business hours restricting *when a booking may be made* and
"access is 24/7" restricting *when a door may open* are orthogonal
questions; enforcing the first does not relax the second. The guard test's
docblock is amended to state this scope explicitly (see the guard file
itself) — its three assertions (no `config/access.php`, no
`AccessHoursPolicy` class, no reintroduction of `allowed_hours` /
`ACCESS_HOURS_(START|END)` / `isWithinAllowedHours` anywhere in `app`,
`config`, `database`, `routes`) are unchanged, unweakened, and still pass
against this phase's code — verified directly, not assumed.

## Why

The original mega-prompt for this decision session described this as a
"partial reversal" of decision #11, and asked for a decision record
saying so. On inspection, decision #11's own PRD language ("access is
24/7") and this guard's own docblock ("PRD decision #11: **access** is
24/7") are unambiguously about physical/door access, not booking hours —
so nothing in decision #11 is actually being reversed, partially or
otherwise. Recording it as a reversal would misstate what changed and
could mislead a future reader into thinking decision #11 itself was
renegotiated. Recording the actual relationship (orthogonal, guard scope
clarified) here instead avoids that.

## What this changed in code

- `tests/Guards/NoAccessHoursWindowTest.php`'s class docblock gained a
  "Scope, clarified 2026-08-15" paragraph. No assertion was added,
  removed, or altered.
- Nothing else — Business Hours' own tables, model, and service are
  recorded in [business-hours.md](business-hours.md), not here.

## Guard

[`NoAccessHoursWindowTest`](../../tests/Guards/NoAccessHoursWindowTest.php)
— unchanged assertions, confirmed still green against this phase's code.
```

- [ ] **Step 4: Add the doc to the decision register**

In `docs/decisions/README.md`, add this line to the **Conflict resolutions** section (the bulleted list near the top, after the existing `preferred-language-mutable.md` / most recent entry — add as the new last item in that list):

```markdown
- [business-hours-prd-11-partial-reversal.md](business-hours-prd-11-partial-reversal.md) — clarifies that booking-hours (Phase 2) does not reverse PRD #11 (physical access remains 24/7); the guard's docblock scope is stated explicitly, not weakened
```

- [ ] **Step 5: Commit**

```bash
git add tests/Guards/NoAccessHoursWindowTest.php docs/decisions/business-hours-prd-11-partial-reversal.md docs/decisions/README.md
git commit -m "docs: clarify NoAccessHoursWindowTest guards access, not booking, hours"
```

---

### Task 7: Admin CRUD for `BusinessHour` (weekly schedule)

**Files:**
- Create: `app/Http/Resources/BusinessHourResource.php`
- Create: `app/Http/Requests/Admin/StoreBusinessHourRequest.php`
- Create: `app/Http/Requests/Admin/UpdateBusinessHourRequest.php`
- Create: `app/Http/Controllers/Api/V1/Admin/BusinessHourController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`
- Modify: `lang/ar/api.php`
- Test: `tests/Feature/Admin/BusinessHourControllerTest.php`

**Interfaces:**
- Consumes: `BusinessHour`, `DayOfWeek` (Tasks 1-2), `NoOverlappingPeriod` (Task 4).
- Produces: `GET|POST /api/v1/admin/business-hours`, `GET|PUT|PATCH /api/v1/admin/business-hours/{businessHour}` (both roles), `DELETE /api/v1/admin/business-hours/{businessHour}` (admin only).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Admin;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessHourControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_an_admin_can_create_a_business_hour(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/business-hours', [
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('business_hours', [
            'branch_id' => $branch->id,
            'day_of_week' => 'monday',
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);
    }

    public function test_close_time_must_be_strictly_after_open_time(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/business-hours', [
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'open_time' => '17:00',
            'close_time' => '17:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_overlapping_periods_on_the_same_weekday_are_rejected(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '12:00',
        ]);

        $response = $this->postJson('/api/v1/admin/business-hours', [
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'open_time' => '11:00',
            'close_time' => '15:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_non_overlapping_periods_on_the_same_weekday_are_accepted(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '12:00',
        ]);

        $response = $this->postJson('/api/v1/admin/business-hours', [
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'open_time' => '15:00',
            'close_time' => '20:00',
        ]);

        $response->assertCreated();
    }

    public function test_updating_a_business_hour_excludes_itself_from_the_overlap_check(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        $businessHour = BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '12:00',
        ]);

        $response = $this->putJson("/api/v1/admin/business-hours/{$businessHour->id}", [
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);

        $response->assertOk();
        $response->assertJson(['message' => __('api.admin.business_hour_updated')]);
        $this->assertDatabaseHas('business_hours', ['id' => $businessHour->id, 'open_time' => '09:00', 'close_time' => '13:00']);
    }

    public function test_index_can_be_filtered_by_branch_id(): void
    {
        $this->actingAsAdmin();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        BusinessHour::factory()->create(['branch_id' => $branchA->id]);
        BusinessHour::factory()->create(['branch_id' => $branchB->id]);

        $response = $this->getJson("/api/v1/admin/business-hours?branch_id={$branchA->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_an_operations_user_can_list_but_not_delete(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
        $businessHour = BusinessHour::factory()->create();

        $this->getJson('/api/v1/admin/business-hours')->assertOk();
        $this->deleteJson("/api/v1/admin/business-hours/{$businessHour->id}")->assertForbidden();
    }

    public function test_an_admin_can_delete_a_business_hour(): void
    {
        $this->actingAsAdmin();
        $businessHour = BusinessHour::factory()->create();

        $this->deleteJson("/api/v1/admin/business-hours/{$businessHour->id}")->assertNoContent();
        $this->assertDatabaseMissing('business_hours', ['id' => $businessHour->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Admin/BusinessHourControllerTest.php`
Expected: FAIL — 404s (routes don't exist yet)

- [ ] **Step 3: Write the resource**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessHourResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'day_of_week' => $this->day_of_week->value,
            'open_time' => $this->open_time,
            'close_time' => $this->close_time,
        ];
    }
}
```

- [ ] **Step 4: Write the Store Form Request**

```php
<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Rules\NoOverlappingPeriod;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessHourRequest extends FormRequest
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
        $existingPeriods = BusinessHour::query()
            ->where('branch_id', $this->input('branch_id'))
            ->where('day_of_week', $this->input('day_of_week'))
            ->when(
                $this->route('businessHour'),
                fn ($query, $businessHour) => $query->whereKeyNot($businessHour->id)
            )
            ->get(['open_time', 'close_time'])
            ->map(fn (BusinessHour $businessHour) => [
                'open_time' => $businessHour->open_time,
                'close_time' => $businessHour->close_time,
            ])
            ->all();

        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'day_of_week' => ['required', Rule::enum(DayOfWeek::class)],
            'open_time' => ['required', 'date_format:H:i'],
            'close_time' => [
                'required',
                'date_format:H:i',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->filled('open_time') && $value <= $this->input('open_time')) {
                        $fail('The close time must be strictly after the open time.');
                    }
                },
                new NoOverlappingPeriod($existingPeriods, (string) $this->input('open_time')),
            ],
        ];
    }
}
```

- [ ] **Step 5: Write the Update Form Request**

```php
<?php

namespace App\Http\Requests\Admin;

class UpdateBusinessHourRequest extends StoreBusinessHourRequest {}
```

- [ ] **Step 6: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\BusinessHour;
use App\Http\Requests\Admin\StoreBusinessHourRequest;
use App\Http\Requests\Admin\UpdateBusinessHourRequest;
use App\Http\Resources\BusinessHourResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessHourController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return BusinessHour::class;
    }

    protected function resourceClass(): string
    {
        return BusinessHourResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('branch_id'),
            fn (Builder $q) => $q->where('branch_id', $request->query('branch_id'))
        );
    }

    public function store(StoreBusinessHourRequest $request): BusinessHourResource
    {
        return new BusinessHourResource(BusinessHour::create($request->validated()));
    }

    public function update(UpdateBusinessHourRequest $request, BusinessHour $businessHour): JsonResponse
    {
        $businessHour->update($request->validated());

        return response()->json(['message' => __('api.admin.business_hour_updated')]);
    }
}
```

- [ ] **Step 7: Register the routes**

In `routes/api/v1/admin.php`, add the import:

```php
use App\Http\Controllers\Api\V1\Admin\BusinessHourController;
```

Add right after the Spatial Hierarchy block (after the `device-capabilities` `apiResource` line, before the `founders`/`partners`/`plans` lines):

```php
// Business hours — per-branch recurring weekly schedule
// (docs/decisions/business-hours.md). Multi-word resource name — same
// reason as community-members above.
Route::apiResource('business-hours', BusinessHourController::class)
    ->parameters(['business-hours' => 'businessHour'])
    ->except('destroy');
```

Add inside the existing `Route::middleware('role:admin')->group(function () { ... })` block, alongside the other Spatial Hierarchy destroys:

```php
    Route::delete('business-hours/{businessHour}', [BusinessHourController::class, 'destroy']);
```

- [ ] **Step 8: Add the language keys**

In `lang/en/api.php`, inside the `'admin' => [...]` array, add:

```php
        'business_hour_updated' => 'Business hour updated.',
```

In `lang/ar/api.php`, inside the `'admin' => [...]` array, add:

```php
        'business_hour_updated' => 'تم تحديث ساعات العمل.',
```

- [ ] **Step 9: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Admin/BusinessHourControllerTest.php`
Expected: PASS (8 tests)

- [ ] **Step 10: Run the full suite**

Run: `php artisan test`
Expected: all green, no regressions

- [ ] **Step 11: Commit**

```bash
git add app/Http/Resources/BusinessHourResource.php app/Http/Requests/Admin/StoreBusinessHourRequest.php app/Http/Requests/Admin/UpdateBusinessHourRequest.php app/Http/Controllers/Api/V1/Admin/BusinessHourController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Admin/BusinessHourControllerTest.php
git commit -m "feat: add admin CRUD for business_hours"
```

---

### Task 8: Admin CRUD for `BusinessHourException`

**Files:**
- Create: `app/Http/Resources/BusinessHourExceptionResource.php`
- Create: `app/Http/Requests/Admin/StoreBusinessHourExceptionRequest.php`
- Create: `app/Http/Requests/Admin/UpdateBusinessHourExceptionRequest.php`
- Create: `app/Http/Controllers/Api/V1/Admin/BusinessHourExceptionController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`
- Modify: `lang/ar/api.php`
- Test: `tests/Feature/Admin/BusinessHourExceptionControllerTest.php`

**Interfaces:**
- Consumes: `BusinessHourException` (Task 3), `NoOverlappingPeriod` (Task 4).
- Produces: `GET|POST /api/v1/admin/business-hour-exceptions`, `GET|PUT|PATCH /api/v1/admin/business-hour-exceptions/{businessHourException}` (both roles), `DELETE .../{businessHourException}` (admin only).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Admin;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHourException;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessHourExceptionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_an_admin_can_create_a_closed_entirely_exception(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-12-25',
            'is_closed' => true,
            'reason' => 'Holiday',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('business_hour_exceptions', [
            'branch_id' => $branch->id,
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
        ]);
    }

    public function test_an_admin_can_create_a_shortened_hours_exception(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
            'reason' => 'Ramadan hours',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('business_hour_exceptions', [
            'branch_id' => $branch->id,
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);
    }

    public function test_open_time_and_close_time_are_prohibited_when_closed_entirely(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-12-25',
            'is_closed' => true,
            'open_time' => '08:00',
            'close_time' => '12:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_open_time_and_close_time_are_required_when_not_closed_entirely(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
        ]);

        $response->assertStatus(422);
    }

    public function test_close_time_must_be_strictly_after_open_time(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '13:00',
            'close_time' => '13:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_add_a_period_when_the_date_already_has_a_closed_entirely_exception(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        BusinessHourException::factory()->closedEntirely()->create([
            'branch_id' => $branch->id,
            'date' => '2026-12-25',
        ]);

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-12-25',
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_mark_closed_entirely_when_the_date_already_has_period_rows(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        BusinessHourException::factory()->create([
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => true,
        ]);

        $response->assertStatus(422);
    }

    public function test_overlapping_periods_on_the_same_date_are_rejected(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        BusinessHourException::factory()->create([
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '12:00',
            'close_time' => '15:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_two_period_exception_day_is_accepted(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        BusinessHourException::factory()->create([
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '16:00',
            'close_time' => '20:00',
        ]);

        $response->assertCreated();
    }

    public function test_updating_an_exception_excludes_itself_from_the_conflict_checks(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        $exception = BusinessHourException::factory()->create([
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);

        $response = $this->putJson("/api/v1/admin/business-hour-exceptions/{$exception->id}", [
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '10:00',
            'close_time' => '14:00',
        ]);

        $response->assertOk();
        $response->assertJson(['message' => __('api.admin.business_hour_exception_updated')]);
    }

    public function test_an_operations_user_can_list_but_not_delete(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
        $exception = BusinessHourException::factory()->create();

        $this->getJson('/api/v1/admin/business-hour-exceptions')->assertOk();
        $this->deleteJson("/api/v1/admin/business-hour-exceptions/{$exception->id}")->assertForbidden();
    }

    public function test_an_admin_can_delete_an_exception(): void
    {
        $this->actingAsAdmin();
        $exception = BusinessHourException::factory()->create();

        $this->deleteJson("/api/v1/admin/business-hour-exceptions/{$exception->id}")->assertNoContent();
        $this->assertDatabaseMissing('business_hour_exceptions', ['id' => $exception->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Admin/BusinessHourExceptionControllerTest.php`
Expected: FAIL — 404s (routes don't exist yet)

- [ ] **Step 3: Write the resource**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessHourExceptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'date' => $this->date->toDateString(),
            'is_closed' => $this->is_closed,
            'open_time' => $this->open_time,
            'close_time' => $this->close_time,
            'reason' => $this->reason,
        ];
    }
}
```

- [ ] **Step 4: Write the Store Form Request**

```php
<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Models\BusinessHourException;
use App\Rules\NoOverlappingPeriod;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBusinessHourExceptionRequest extends FormRequest
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
        $isClosed = $this->boolean('is_closed');

        if ($isClosed) {
            $openTimeRules = ['prohibited'];
            $closeTimeRules = ['prohibited'];
        } else {
            $existingPeriods = BusinessHourException::query()
                ->where('branch_id', $this->input('branch_id'))
                ->whereDate('date', (string) $this->input('date'))
                ->where('is_closed', false)
                ->when(
                    $this->route('businessHourException'),
                    fn ($query, $exception) => $query->whereKeyNot($exception->id)
                )
                ->get(['open_time', 'close_time'])
                ->map(fn (BusinessHourException $exception) => [
                    'open_time' => $exception->open_time,
                    'close_time' => $exception->close_time,
                ])
                ->all();

            $openTimeRules = ['required', 'date_format:H:i'];
            $closeTimeRules = [
                'required',
                'date_format:H:i',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->filled('open_time') && $value <= $this->input('open_time')) {
                        $fail('The close time must be strictly after the open time.');
                    }
                },
                new NoOverlappingPeriod($existingPeriods, (string) $this->input('open_time')),
            ];
        }

        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'is_closed' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'open_time' => $openTimeRules,
            'close_time' => $closeTimeRules,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('branch_id') || ! $this->filled('date')) {
                return;
            }

            $siblings = BusinessHourException::query()
                ->where('branch_id', $this->input('branch_id'))
                ->whereDate('date', (string) $this->input('date'))
                ->when(
                    $this->route('businessHourException'),
                    fn ($query, $exception) => $query->whereKeyNot($exception->id)
                )
                ->get();

            $wantsClosed = $this->boolean('is_closed');

            if ($wantsClosed && $siblings->isNotEmpty()) {
                $validator->errors()->add(
                    'is_closed',
                    'This date already has period rows; remove them before marking it closed entirely.'
                );

                return;
            }

            if (! $wantsClosed && $siblings->contains('is_closed', true)) {
                $validator->errors()->add(
                    'is_closed',
                    'This date is already marked closed entirely; remove that exception before adding a period.'
                );
            }
        });
    }
}
```

- [ ] **Step 5: Write the Update Form Request**

```php
<?php

namespace App\Http\Requests\Admin;

class UpdateBusinessHourExceptionRequest extends StoreBusinessHourExceptionRequest {}
```

- [ ] **Step 6: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\BusinessHourException;
use App\Http\Requests\Admin\StoreBusinessHourExceptionRequest;
use App\Http\Requests\Admin\UpdateBusinessHourExceptionRequest;
use App\Http\Resources\BusinessHourExceptionResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessHourExceptionController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return BusinessHourException::class;
    }

    protected function resourceClass(): string
    {
        return BusinessHourExceptionResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('branch_id'),
            fn (Builder $q) => $q->where('branch_id', $request->query('branch_id'))
        );
    }

    public function store(StoreBusinessHourExceptionRequest $request): BusinessHourExceptionResource
    {
        return new BusinessHourExceptionResource(BusinessHourException::create($request->validated()));
    }

    public function update(UpdateBusinessHourExceptionRequest $request, BusinessHourException $businessHourException): JsonResponse
    {
        $businessHourException->update($request->validated());

        return response()->json(['message' => __('api.admin.business_hour_exception_updated')]);
    }
}
```

- [ ] **Step 7: Register the routes**

In `routes/api/v1/admin.php`, add the import:

```php
use App\Http\Controllers\Api\V1\Admin\BusinessHourExceptionController;
```

Add right after Task 7's `business-hours` route block:

```php
// Multi-word resource name — same reason as community-members above.
Route::apiResource('business-hour-exceptions', BusinessHourExceptionController::class)
    ->parameters(['business-hour-exceptions' => 'businessHourException'])
    ->except('destroy');
```

Add inside the existing `Route::middleware('role:admin')->group(function () { ... })` block, alongside `business-hours`' destroy:

```php
    Route::delete('business-hour-exceptions/{businessHourException}', [BusinessHourExceptionController::class, 'destroy']);
```

- [ ] **Step 8: Add the language keys**

In `lang/en/api.php`, inside the `'admin' => [...]` array, add:

```php
        'business_hour_exception_updated' => 'Business hour exception updated.',
```

In `lang/ar/api.php`, inside the `'admin' => [...]` array, add:

```php
        'business_hour_exception_updated' => 'تم تحديث استثناء ساعات العمل.',
```

- [ ] **Step 9: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Admin/BusinessHourExceptionControllerTest.php`
Expected: PASS (12 tests)

- [ ] **Step 10: Run the full suite**

Run: `php artisan test`
Expected: all green, no regressions

- [ ] **Step 11: Commit**

```bash
git add app/Http/Resources/BusinessHourExceptionResource.php app/Http/Requests/Admin/StoreBusinessHourExceptionRequest.php app/Http/Requests/Admin/UpdateBusinessHourExceptionRequest.php app/Http/Controllers/Api/V1/Admin/BusinessHourExceptionController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Admin/BusinessHourExceptionControllerTest.php
git commit -m "feat: add admin CRUD for business_hour_exceptions"
```

---

### Task 9: `app.timezone` in `SettingSeeder`

**Files:**
- Modify: `database/seeders/SettingSeeder.php`
- Modify: `tests/Feature/Settings/SettingSeederTest.php`

**Interfaces:**
- Consumes: `SettingService::setDefault()` (Phase 1, already exists), `SettingValueType::String` (Phase 1, already exists).

- [ ] **Step 1: Add the seed line**

In `database/seeders/SettingSeeder.php`, add this line inside `run()`, after the existing `module.cafe.is_enabled` line:

```php
        $settings->setDefault('app.timezone', 'Asia/Damascus', SettingValueType::String);
```

- [ ] **Step 2: Extend the existing seeder test**

In `tests/Feature/Settings/SettingSeederTest.php`, add this assertion to the existing "seeds every key" test method (alongside the other 8 `assertSame`/`assertTrue` calls):

```php
        $this->assertSame('Asia/Damascus', $settings->get('app.timezone'));
```

- [ ] **Step 3: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Settings/SettingSeederTest.php`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add database/seeders/SettingSeeder.php tests/Feature/Settings/SettingSeederTest.php
git commit -m "feat: seed app.timezone default for business hours resolution"
```

---

### Task 10: Decision doc — `business-hours.md`

**Files:**
- Create: `docs/decisions/business-hours.md`
- Modify: `docs/decisions/README.md`

- [ ] **Step 1: Write the decision doc**

```markdown
# Business hours: branch-level schedule, exceptions, and resolution

**Status:** resolved 2026-08-15. **Owner:** Maryam Asha.
**Type:** design doc for a new capability (2026-08-15 decision session,
Phase 2), building on `settings` (Phase 1, merged `acb6a5c`). Covers the
capability only — Sprint 3 (bookings, sessions, session auto-closure)
consumes this and is out of scope here. See
[business-hours-prd-11-partial-reversal.md](business-hours-prd-11-partial-reversal.md)
for why this is NOT a reversal of PRD decision #11 (physical access).

## What this adds

Two tables under the existing `Foundation` domain:
- `business_hours` — a recurring weekly schedule per branch. Multiple rows
  for the same (branch, weekday) express a two-period day (e.g. a midday
  closure). Zero rows for a weekday means closed that day.
- `business_hour_exceptions` — date-specific overrides per branch, fully
  replacing (not merging with) the weekly schedule for that date. Supports
  both "closed entirely" (`is_closed = true`, the sole row for that date)
  and "different hours" (`is_closed = false` rows, same two-period
  support as the weekly schedule).

`App\Domain\Foundation\Services\BusinessHoursService` resolves both into
two consumer-facing methods: `isWithinBusinessHours(instant, branch): bool`
and `periodsFor(date, branch): array`. Admin CRUD exists for both tables.

## Decision

- **Scoped to branch, not space.** Business hours are a property of the
  branch (the physical building's operating schedule), not of individual
  bookable spaces — a per-space quiet-hours or availability concept, if
  ever needed, is a distinct, unbuilt feature.
- **No midnight-crossing shifts in v1.** `close_time` must be strictly
  greater than `open_time` (same-day comparison only), enforced by
  validation with a clear error, not left ambiguous. A branch open past
  midnight would need a different data model (e.g. a shift that spans two
  calendar dates) — deliberately out of scope for this phase.
- **Two periods per day are supported**, on both the weekly schedule and
  exceptions, via multiple rows for the same (branch, weekday) or
  (branch, date) rather than a single row with a "second period" pair of
  columns — this scales to any number of periods without a schema change,
  though the UI/API only needs to support two today.
- **`business_hour_exceptions` needs an explicit `is_closed` flag**,
  unlike `business_hours`. For the weekly schedule, "zero rows for this
  weekday" unambiguously means closed — there's nothing to fall back to.
  For exceptions, "zero rows for this date" means "no exception, use the
  weekly schedule" — a genuinely different meaning from "closed" — so
  "closed" needs its own explicit signal rather than reusing "absence of
  rows."
- **Resolution order:** an exception for the date, if any, is authoritative
  and fully replaces the weekly schedule (it does not merge with it — a
  single-period exception on a normally-two-period day means exactly one
  period that day, not the exception period plus a leftover weekly one).
  No exception → the weekday's schedule rows. No rows at either level →
  closed.
- **Boundary convention: both `open_time` and `close_time` are inclusive.**
  An instant exactly at either edge counts as within business hours. This
  mirrors the wider decision session's booking rule (a booking may start
  exactly at opening and end exactly at closing — neither is "before" nor
  "after"), so the single-instant check built here and the future
  start/end range check Sprint 3 builds share one convention rather than
  needing separate open/closed rules for each.
- **Single global timezone**, not per-branch. `app.timezone` (a `Setting`,
  default `Asia/Damascus`) is what every open/close comparison resolves
  through for every branch. `branches.timezone` is a pre-existing (Phase
  1), unrelated, unused plain string column — this phase does not read,
  write, or otherwise touch it; a future phase may reconcile or remove it,
  but that's out of this phase's scope.
- **Time-of-day values are plain `H:i` strings**, not a native `TIME`
  column — matching the precedent already established by
  `Setting`'s `SettingValueType::Time` handling, and avoiding introducing
  the first `TIME`-typed column in this codebase for a value that's
  simplest to validate and compare as a zero-padded string.
- **`DayOfWeek` is a string-backed enum** (`'sunday'`..`'saturday'`), not
  int-backed, matching every other enum in this codebase (17 existing
  examples, all string-backed) even though Carbon's own `dayOfWeek`
  accessor is numeric — the translation happens once, in
  `DayOfWeek::fromCarbon()`.

## Why

See "Decision" above — each bullet states its own reasoning inline.

## What this changed in code

- `App\Domain\Foundation\Enums\DayOfWeek`.
- `App\Domain\Foundation\Models\{BusinessHour,BusinessHourException}`,
  migrations, factories; `Branch::businessHours()` /
  `Branch::businessHourExceptions()` relationships.
- `App\Domain\Foundation\Services\BusinessHoursService`.
- `App\Rules\NoOverlappingPeriod`.
- `App\Http\Controllers\Api\V1\Admin\{BusinessHourController,BusinessHourExceptionController}`,
  their Form Requests and Resources.
- Routes: `GET|POST /api/v1/admin/business-hours`,
  `GET|PUT|PATCH|DELETE /api/v1/admin/business-hours/{businessHour}`, and
  the same shape for `business-hour-exceptions`.
- `app.timezone` added to `SettingSeeder`.

## Guard

No dedicated guard test — this is new, additive capability with no PRD
decision locking its specific shape (unlike PRD #11, which
[business-hours-prd-11-partial-reversal.md](business-hours-prd-11-partial-reversal.md)
addresses separately). `tests/Unit/Domain/Foundation/BusinessHoursServiceTest.php`
covers every enforcement rule (resolution order, closed-entirely,
two-period gaps, boundary inclusivity, branch isolation, timezone
correctness near a day boundary) by construction — reintroducing a bug in
any of these would fail that suite, not a schema-shape guard.
```

- [ ] **Step 2: Add the doc to the decision register**

In `docs/decisions/README.md`, add this line to the **Design docs** section (after the existing `settings-key-value-store.md` line):

```markdown
- [business-hours.md](business-hours.md) — per-branch weekly schedule + date exceptions + resolution service; the capability Sprint 3's booking validation will consume
```

- [ ] **Step 3: Commit**

```bash
git add docs/decisions/business-hours.md docs/decisions/README.md
git commit -m "docs: record the business hours design decision"
```

---

### Task 11: Update the Postman collection

**Files:**
- Modify: `postman/ADD-OS.postman_collection.json`

**Interfaces:**
- Consumes: the routes from Tasks 7-8.

- [ ] **Step 1: Add a "Business Hours" folder under "Admin (Dashboard)"**

Open `postman/ADD-OS.postman_collection.json`. Find the `"Settings"` folder inside the `"Admin (Dashboard)"` item's `item` array (added in the previous phase, sits between `"Roles"` and `"Spatial Hierarchy"`). Insert a new sibling folder immediately after `"Settings"` and before `"Spatial Hierarchy"`:

```json
{
  "name": "Business Hours",
  "description": "Per-branch weekly schedule and date exceptions (docs/decisions/business-hours.md). List/Get are available to admin and operations; Create/Update/Delete are admin-only where the route requires it.",
  "item": [
    {
      "name": "Weekly Schedule",
      "item": [
        {
          "name": "List Business Hours",
          "request": {
            "method": "GET",
            "header": [
              { "key": "lang", "value": "{{lang}}", "type": "text" },
              { "key": "currency", "value": "{{currency}}", "type": "text" }
            ],
            "url": {
              "raw": "{{base_url}}/api/v1/admin/business-hours",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "business-hours"],
              "query": [
                { "key": "branch_id", "value": "{{branch_id}}", "disabled": true }
              ]
            },
            "description": "Optional ?branch_id= filter."
          }
        },
        {
          "name": "Create Business Hour",
          "event": [
            {
              "listen": "test",
              "script": {
                "type": "text/javascript",
                "exec": [
                  "if (pm.response.code === 201 || pm.response.code === 200) {",
                  "    pm.collectionVariables.set('business_hour_id', pm.response.json().data.id);",
                  "}"
                ]
              }
            }
          ],
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" },
              { "key": "currency", "value": "{{currency}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"branch_id\": {{branch_id}},\n  \"day_of_week\": \"monday\",\n  \"open_time\": \"08:00\",\n  \"close_time\": \"17:00\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/business-hours",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "business-hours"]
            }
          }
        },
        {
          "name": "Get Business Hour",
          "request": {
            "method": "GET",
            "header": [
              { "key": "lang", "value": "{{lang}}", "type": "text" },
              { "key": "currency", "value": "{{currency}}", "type": "text" }
            ],
            "url": {
              "raw": "{{base_url}}/api/v1/admin/business-hours/{{business_hour_id}}",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "business-hours", "{{business_hour_id}}"]
            }
          }
        },
        {
          "name": "Update Business Hour",
          "request": {
            "method": "PUT",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" },
              { "key": "currency", "value": "{{currency}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"branch_id\": {{branch_id}},\n  \"day_of_week\": \"monday\",\n  \"open_time\": \"09:00\",\n  \"close_time\": \"18:00\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/business-hours/{{business_hour_id}}",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "business-hours", "{{business_hour_id}}"]
            },
            "description": "Returns `{\"message\": \"...\"}` on success, not the updated resource. day_of_week must be one of sunday..saturday; close_time must be strictly after open_time and must not overlap an existing period for the same branch+weekday."
          }
        },
        {
          "name": "Delete Business Hour",
          "request": {
            "method": "DELETE",
            "header": [
              { "key": "lang", "value": "{{lang}}", "type": "text" },
              { "key": "currency", "value": "{{currency}}", "type": "text" }
            ],
            "url": {
              "raw": "{{base_url}}/api/v1/admin/business-hours/{{business_hour_id}}",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "business-hours", "{{business_hour_id}}"]
            },
            "description": "Admin-only (not operations)."
          }
        }
      ]
    },
    {
      "name": "Exceptions",
      "item": [
        {
          "name": "List Exceptions",
          "request": {
            "method": "GET",
            "header": [
              { "key": "lang", "value": "{{lang}}", "type": "text" },
              { "key": "currency", "value": "{{currency}}", "type": "text" }
            ],
            "url": {
              "raw": "{{base_url}}/api/v1/admin/business-hour-exceptions",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "business-hour-exceptions"],
              "query": [
                { "key": "branch_id", "value": "{{branch_id}}", "disabled": true }
              ]
            }
          }
        },
        {
          "name": "Create Exception (closed entirely)",
          "event": [
            {
              "listen": "test",
              "script": {
                "type": "text/javascript",
                "exec": [
                  "if (pm.response.code === 201 || pm.response.code === 200) {",
                  "    pm.collectionVariables.set('business_hour_exception_id', pm.response.json().data.id);",
                  "}"
                ]
              }
            }
          ],
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" },
              { "key": "currency", "value": "{{currency}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"branch_id\": {{branch_id}},\n  \"date\": \"2026-12-25\",\n  \"is_closed\": true,\n  \"reason\": \"Holiday\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/business-hour-exceptions",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "business-hour-exceptions"]
            },
            "description": "is_closed=true means open_time/close_time must be omitted."
          }
        },
        {
          "name": "Create Exception (shortened hours)",
          "request": {
            "method": "POST",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" },
              { "key": "currency", "value": "{{currency}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"branch_id\": {{branch_id}},\n  \"date\": \"2026-04-10\",\n  \"is_closed\": false,\n  \"open_time\": \"09:00\",\n  \"close_time\": \"13:00\",\n  \"reason\": \"Ramadan hours\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/business-hour-exceptions",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "business-hour-exceptions"]
            },
            "description": "is_closed=false requires open_time/close_time; close_time must be strictly after open_time and must not overlap an existing period for the same branch+date."
          }
        },
        {
          "name": "Get Exception",
          "request": {
            "method": "GET",
            "header": [
              { "key": "lang", "value": "{{lang}}", "type": "text" },
              { "key": "currency", "value": "{{currency}}", "type": "text" }
            ],
            "url": {
              "raw": "{{base_url}}/api/v1/admin/business-hour-exceptions/{{business_hour_exception_id}}",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "business-hour-exceptions", "{{business_hour_exception_id}}"]
            }
          }
        },
        {
          "name": "Update Exception",
          "request": {
            "method": "PUT",
            "header": [
              { "key": "Content-Type", "value": "application/json" },
              { "key": "lang", "value": "{{lang}}", "type": "text" },
              { "key": "currency", "value": "{{currency}}", "type": "text" }
            ],
            "body": {
              "mode": "raw",
              "raw": "{\n  \"branch_id\": {{branch_id}},\n  \"date\": \"2026-12-25\",\n  \"is_closed\": true,\n  \"reason\": \"Holiday (updated)\"\n}",
              "options": { "raw": { "language": "json" } }
            },
            "url": {
              "raw": "{{base_url}}/api/v1/admin/business-hour-exceptions/{{business_hour_exception_id}}",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "business-hour-exceptions", "{{business_hour_exception_id}}"]
            },
            "description": "Returns `{\"message\": \"...\"}` on success, not the updated resource."
          }
        },
        {
          "name": "Delete Exception",
          "request": {
            "method": "DELETE",
            "header": [
              { "key": "lang", "value": "{{lang}}", "type": "text" },
              { "key": "currency", "value": "{{currency}}", "type": "text" }
            ],
            "url": {
              "raw": "{{base_url}}/api/v1/admin/business-hour-exceptions/{{business_hour_exception_id}}",
              "host": ["{{base_url}}"],
              "path": ["api", "v1", "admin", "business-hour-exceptions", "{{business_hour_exception_id}}"]
            },
            "description": "Admin-only (not operations)."
          }
        }
      ]
    }
  ]
}
```

- [ ] **Step 2: Validate the JSON is well-formed**

Run: `node -e "JSON.parse(require('fs').readFileSync('postman/ADD-OS.postman_collection.json', 'utf8')); console.log('valid')"`
Expected: prints `valid`

- [ ] **Step 3: Commit**

```bash
git add postman/ADD-OS.postman_collection.json
git commit -m "docs: add business hours endpoints to Postman collection"
```

---

## Post-plan check

Run the full suite once more before moving to the next subsystem plan:

```bash
composer test
./vendor/bin/pint --test
```

Both should be clean. `App\Domain\Foundation\Services\BusinessHoursService::isWithinBusinessHours()` and `::periodsFor()` are now the two methods Sprint 3's booking validation and session auto-closure will call — no caller exists yet, by design.
