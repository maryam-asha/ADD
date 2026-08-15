# Settings Key/Value Store Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a cached, typed key/value `settings` store — the first piece of the 2026-08-15 decision-session build, and the enabler five later subsystems (business hours, booking, guests, profile completion, module toggles) depend on.

**Architecture:** A new `App\Domain\Settings` domain holds one `Setting` model (one row per key per scope, `scope_type`/`scope_id` defaulting to a `global`/`0` sentinel rather than `null` so the uniqueness constraint actually holds), two enums (`SettingValueType` for the five supported value shapes, `SettingScope` for where a setting applies), and a `SettingService` that is the only read/write path — reads are `Cache::rememberForever()`'d and `set()` always forgets its own cache key first, so there is no TTL to go stale. An admin controller exposes read/update over the seeded global keys; nothing in this plan builds per-entity scoped rows (those are deferred to whichever later domain needs them — see Task 2's docblock).

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit, SQLite in-memory (tests), `database` cache store (dev/prod) / `array` (tests, per `phpunit.xml`).

**Spec:** The "ADD Core — Implementation Prompt" from the 2026-08-15 decision session, §2 (`settings — key/value with scope`) and its 8 required keys. This plan implements §2 only; the other sections (business hours, booking, guests, groups, profile, contact links, module toggles) are separate plans that will read from the `SettingService` this plan builds.

## Global Constraints

- PHP `^8.2`, Laravel Framework `^12.0` (`composer.json`) — no other version floors apply.
- **Never use `->enum()` in a migration.** Every enum-shaped column is a `string` column cast to a PHP 8.2 backed enum on the model — guarded by `tests/Guards/NoNewMysqlEnumColumnsTest.php` (migration side) and `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` (model side, a static `EXPECTED_CASTS` map you must add entries to for any new enum-shaped column).
- Models live under `App\Domain\<Domain>\Models`, never `App\Models\*`. Enums under `App\Domain\<Domain>\Enums`. Services under `App\Domain\<Domain>\Services`.
- Eloquent casts are declared via the `casts(): array` **method**, not the legacy `protected $casts` array property — every model in this codebase uses the method form.
- Factories are flat under `database/factories/{Name}Factory.php` (namespace `Database\Factories`, not domain-nested), with an explicit `protected $model = X::class;` since the model's own namespace is nested.
- Every PATCH/PUT admin/member endpoint responds with `response()->json(['message' => __('api.<domain>.<key>')])`, never the updated resource. Keys live in `lang/en/api.php` and `lang/ar/api.php`, grouped by domain — add a new key rather than inlining a string.
- `routes/api/v1/admin.php`: every route already sits behind `auth:sanctum` + `role:admin|operations` (applied once in `routes/api.php`). A narrower `Route::middleware('role:admin')->group(fn () => ...)` block near the bottom of the file holds admin-only actions.
- Sensitive/mutating admin actions call `$this->logSensitiveAction(string $action, Model $subject, array $properties = [])` (the `App\Concerns\LogsSensitiveActions` trait), which writes to spatie/activitylog's `activity_log` table.
- Migration filenames: `database/migrations/YYYY_MM_DD_HHMMSS_verb_description.php`, one flat directory. The most recent existing migration is timestamped `2026_08_13_085123` — anything new in this plan must sort after it.
- Cache: default store is `database` (`config('cache.default')` → `env('CACHE_STORE', 'database')`); tests force `CACHE_STORE=array` (`phpunit.xml`). No Redis is installed — do not assume it.
- Admin feature tests: `use RefreshDatabase;`, seed roles via `$this->seed(RoleSeeder::class);` in `setUp()`, authenticate via `Sanctum::actingAs($admin, ['*'])` after `$admin->assignRole('admin')` (or `'operations'`).
- `docs/decisions/*.md` format: `# Title`, a `**Status:** resolved <date>. **Owner:** Maryam Asha.` line, then `## Decision`, `## Why`, `## What this changed in code`, `## Guard` sections.

---

### Task 1: Settings enums

**Files:**
- Create: `app/Domain/Settings/Enums/SettingValueType.php`
- Create: `app/Domain/Settings/Enums/SettingScope.php`

**Interfaces:**
- Produces: `SettingValueType` (string-backed cases `Int`, `Bool`, `String`, `Time`, `Json`) and `SettingScope` (string-backed case `Global = 'global'`) — consumed by every later task in this plan.

- [ ] **Step 1: Create the value-type enum**

```php
<?php

namespace App\Domain\Settings\Enums;

/**
 * How a setting's `value` column (always stored as text) round-trips to a
 * PHP type. Matches Setting::resolvedValue() / Setting::encodeValue().
 */
enum SettingValueType: string
{
    case Int = 'int';
    case Bool = 'bool';
    case String = 'string';
    case Time = 'time';
    case Json = 'json';
}
```

- [ ] **Step 2: Create the scope enum**

```php
<?php

namespace App\Domain\Settings\Enums;

/**
 * Whether a setting row applies platform-wide or to one entity. Only
 * `Global` exists today — every key this session seeds (booking defaults,
 * profile threshold, guest timeout, module toggle) is unscoped. Per-space
 * overrides (e.g. a space's own cancellation window) are plain columns on
 * that domain's own model, not scoped `Setting` rows — a new case is added
 * here only when a domain actually needs a scoped override row instead of
 * its own column.
 */
enum SettingScope: string
{
    case Global = 'global';
}
```

- [ ] **Step 3: Verify the enums load**

Run: `php artisan tinker --execute="echo App\Domain\Settings\Enums\SettingValueType::Int->value . ' ' . App\Domain\Settings\Enums\SettingScope::Global->value;"`
Expected: prints `int global`

- [ ] **Step 4: Commit**

```bash
git add app/Domain/Settings/Enums/SettingValueType.php app/Domain/Settings/Enums/SettingScope.php
git commit -m "feat: add settings domain enums"
```

---

### Task 2: `settings` table, `Setting` model, factory

**Files:**
- Create: `database/migrations/2026_08_15_090000_create_settings_table.php`
- Create: `app/Domain/Settings/Models/Setting.php`
- Create: `database/factories/SettingFactory.php`
- Test: `tests/Unit/Domain/Settings/SettingTest.php`

**Interfaces:**
- Consumes: `SettingValueType`, `SettingScope` (Task 1).
- Produces: `Setting` (fillable `key`, `scope_type`, `scope_id`, `type`, `value`; casts `scope_type` → `SettingScope`, `type` → `SettingValueType`), `Setting::encodeValue(SettingValueType $type, mixed $value): string` (static), `Setting::resolvedValue(): int|bool|string|array|null` (instance) — consumed by `SettingService` (Task 4) and the admin controller (Task 6).

- [ ] **Step 1: Write the failing unit test for encode/decode**

```php
<?php

namespace Tests\Unit\Domain\Settings;

use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Models\Setting;
use InvalidArgumentException;
use JsonException;
use Tests\TestCase;

class SettingTest extends TestCase
{
    public function test_int_round_trips(): void
    {
        $encoded = Setting::encodeValue(SettingValueType::Int, 42);
        $this->assertSame('42', $encoded);

        $setting = new Setting(['type' => SettingValueType::Int, 'value' => $encoded]);
        $this->assertSame(42, $setting->resolvedValue());
    }

    public function test_bool_round_trips(): void
    {
        $this->assertSame('1', Setting::encodeValue(SettingValueType::Bool, true));
        $this->assertSame('0', Setting::encodeValue(SettingValueType::Bool, false));

        $true = new Setting(['type' => SettingValueType::Bool, 'value' => '1']);
        $false = new Setting(['type' => SettingValueType::Bool, 'value' => '0']);

        $this->assertTrue($true->resolvedValue());
        $this->assertFalse($false->resolvedValue());
    }

    public function test_string_round_trips(): void
    {
        $encoded = Setting::encodeValue(SettingValueType::String, 'cash');
        $setting = new Setting(['type' => SettingValueType::String, 'value' => $encoded]);

        $this->assertSame('cash', $setting->resolvedValue());
    }

    public function test_time_round_trips(): void
    {
        $encoded = Setting::encodeValue(SettingValueType::Time, '08:30');
        $setting = new Setting(['type' => SettingValueType::Time, 'value' => $encoded]);

        $this->assertSame('08:30', $setting->resolvedValue());
    }

    public function test_time_rejects_a_malformed_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Setting::encodeValue(SettingValueType::Time, '25:99');
    }

    public function test_json_round_trips(): void
    {
        $encoded = Setting::encodeValue(SettingValueType::Json, ['a' => 1, 'b' => [2, 3]]);
        $setting = new Setting(['type' => SettingValueType::Json, 'value' => $encoded]);

        $this->assertSame(['a' => 1, 'b' => [2, 3]], $setting->resolvedValue());
    }

    public function test_json_rejects_a_non_encodable_value(): void
    {
        $this->expectException(JsonException::class);

        Setting::encodeValue(SettingValueType::Json, NAN);
    }

    public function test_resolved_value_is_null_when_value_is_null(): void
    {
        $setting = new Setting(['type' => SettingValueType::Int, 'value' => null]);

        $this->assertNull($setting->resolvedValue());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Settings/SettingTest.php`
Expected: FAIL — `Class "App\Domain\Settings\Models\Setting" not found`

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
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            // Global/0 sentinel, not null — MySQL allows repeated NULLs in a
            // unique index, which would let two "global" rows for the same
            // key coexist silently. See App\Domain\Settings\Models\Setting.
            $table->string('scope_type')->default('global');
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['key', 'scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
```

- [ ] **Step 4: Write the `Setting` model**

```php
<?php

namespace App\Domain\Settings\Models;

use App\Domain\Settings\Enums\SettingScope;
use App\Domain\Settings\Enums\SettingValueType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * One row per key per scope. `scope_type`/`scope_id` default to Global/0
 * rather than null — MySQL treats NULL as distinct from itself in a unique
 * index, so two "global" rows for the same key could otherwise coexist
 * silently. A non-null sentinel keeps the (key, scope_type, scope_id)
 * unique index actually enforcing uniqueness.
 */
class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'scope_type',
        'scope_id',
        'type',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => SettingScope::class,
            'type' => SettingValueType::class,
        ];
    }

    public function resolvedValue(): int|bool|string|array|null
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            SettingValueType::Int => (int) $this->value,
            SettingValueType::Bool => $this->value === '1',
            SettingValueType::String => (string) $this->value,
            SettingValueType::Time => (string) $this->value,
            SettingValueType::Json => json_decode($this->value, true, 512, JSON_THROW_ON_ERROR),
        };
    }

    public static function encodeValue(SettingValueType $type, mixed $value): string
    {
        return match ($type) {
            SettingValueType::Int => (string) (int) $value,
            SettingValueType::Bool => $value ? '1' : '0',
            SettingValueType::String => (string) $value,
            SettingValueType::Time => self::encodeTime($value),
            SettingValueType::Json => json_encode($value, JSON_THROW_ON_ERROR),
        };
    }

    private static function encodeTime(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw new InvalidArgumentException('Setting time value must be an H:i string, got: '.json_encode($value));
        }

        return $value;
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Domain\Settings\Enums\SettingScope;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'test.'.$this->faker->unique()->word(),
            'scope_type' => SettingScope::Global,
            'scope_id' => 0,
            'type' => SettingValueType::Int,
            'value' => (string) $this->faker->numberBetween(1, 100),
        ];
    }
}
```

- [ ] **Step 6: Run migrations and the test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Settings/SettingTest.php`
Expected: PASS (8 tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_15_090000_create_settings_table.php app/Domain/Settings/Models/Setting.php database/factories/SettingFactory.php tests/Unit/Domain/Settings/SettingTest.php
git commit -m "feat: add settings table and Setting model with typed encode/decode"
```

---

### Task 3: Extend the enum-cast guard

**Files:**
- Modify: `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`

**Interfaces:**
- Consumes: `Setting` (Task 2), `SettingScope`, `SettingValueType` (Task 1).

- [ ] **Step 1: Add the imports**

In `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`, add alongside the existing `use App\Domain\...` lines (keep alphabetical within the block):

```php
use App\Domain\Settings\Enums\SettingScope;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Models\Setting;
```

- [ ] **Step 2: Add the `EXPECTED_CASTS` entry**

Add this entry to the `EXPECTED_CASTS` array, after the `WalletTransaction::class => [...]` entry:

```php
        Setting::class => [
            'scope_type' => SettingScope::class,
            'type' => SettingValueType::class,
        ],
```

- [ ] **Step 3: Run the guard test**

Run: `php artisan test tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php
git commit -m "test: extend enum-cast guard for the settings table"
```

---

### Task 4: `SettingService` — cached get/set

**Files:**
- Create: `app/Domain/Settings/Services/SettingService.php`
- Test: `tests/Unit/Domain/Settings/SettingServiceTest.php`

**Interfaces:**
- Consumes: `Setting`, `SettingValueType`, `SettingScope` (Tasks 1–2).
- Produces: `SettingService::get(string $key, mixed $default = null, SettingScope $scopeType = SettingScope::Global, int $scopeId = 0): mixed` and `SettingService::set(string $key, mixed $value, ?SettingValueType $type = null, SettingScope $scopeType = SettingScope::Global, int $scopeId = 0): Setting` — consumed by the seeder (Task 5), the admin controller (Task 6), and every later plan (business hours, booking, guests, profile, module toggles) that reads a global setting.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Settings;

use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SettingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_the_given_default_when_the_key_does_not_exist(): void
    {
        $service = new SettingService;

        $this->assertNull($service->get('test.missing_key'));
        $this->assertSame('fallback', $service->get('test.missing_key', 'fallback'));
    }

    public function test_set_creates_a_new_global_setting_and_get_reads_it_back(): void
    {
        $service = new SettingService;

        $service->set('test.new_key', 42, SettingValueType::Int);

        $this->assertSame(42, $service->get('test.new_key'));
        $this->assertDatabaseHas('settings', [
            'key' => 'test.new_key',
            'scope_type' => 'global',
            'scope_id' => 0,
            'type' => 'int',
            'value' => '42',
        ]);
    }

    public function test_set_throws_when_creating_a_new_key_without_a_type(): void
    {
        $service = new SettingService;

        $this->expectException(InvalidArgumentException::class);

        $service->set('test.brand_new_key', 42);
    }

    public function test_set_reuses_the_existing_type_when_not_given(): void
    {
        $service = new SettingService;
        $service->set('test.reuse_type', 10, SettingValueType::Int);

        $service->set('test.reuse_type', 20);

        $this->assertSame(20, $service->get('test.reuse_type'));
    }

    public function test_get_caches_the_resolved_value_until_set_invalidates_it(): void
    {
        $service = new SettingService;
        $service->set('test.cached_key', 5, SettingValueType::Int);

        $this->assertSame(5, $service->get('test.cached_key'));

        // Bypasses SettingService::set() on purpose — a direct DB write
        // must not appear until the cache is invalidated.
        Setting::query()->where('key', 'test.cached_key')->update(['value' => '999']);

        $this->assertSame(5, $service->get('test.cached_key'));

        $service->set('test.cached_key', 999, SettingValueType::Int);

        $this->assertSame(999, $service->get('test.cached_key'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Settings/SettingServiceTest.php`
Expected: FAIL — `Class "App\Domain\Settings\Services\SettingService" not found`

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Enums\SettingScope;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Models\Setting;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * The one read/write path for settings rows. Reads are cached forever
 * (settings change rarely, and only through set(), which always forgets
 * its own cache key first) rather than on a TTL — a TTL would mean a
 * booking request occasionally reads a stale cancellation window for no
 * reason, when exact invalidation is just as easy to get right.
 */
class SettingService
{
    public function get(
        string $key,
        mixed $default = null,
        SettingScope $scopeType = SettingScope::Global,
        int $scopeId = 0,
    ): mixed {
        return Cache::rememberForever(
            $this->cacheKey($key, $scopeType, $scopeId),
            fn () => $this->find($key, $scopeType, $scopeId)?->resolvedValue() ?? $default,
        );
    }

    public function set(
        string $key,
        mixed $value,
        ?SettingValueType $type = null,
        SettingScope $scopeType = SettingScope::Global,
        int $scopeId = 0,
    ): Setting {
        $setting = $this->find($key, $scopeType, $scopeId) ?? new Setting([
            'key' => $key,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ]);

        $type ??= $setting->type ?? throw new InvalidArgumentException(
            "Setting [{$key}] does not exist yet — a type is required to create it."
        );

        $setting->type = $type;
        $setting->value = Setting::encodeValue($type, $value);
        $setting->save();

        Cache::forget($this->cacheKey($key, $scopeType, $scopeId));

        return $setting;
    }

    private function find(string $key, SettingScope $scopeType, int $scopeId): ?Setting
    {
        return Setting::query()
            ->where('key', $key)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->first();
    }

    private function cacheKey(string $key, SettingScope $scopeType, int $scopeId): string
    {
        return "settings:{$scopeType->value}:{$scopeId}:{$key}";
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Settings/SettingServiceTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Settings/Services/SettingService.php tests/Unit/Domain/Settings/SettingServiceTest.php
git commit -m "feat: add SettingService with cache-aside get/set"
```

---

### Task 5: `SettingSeeder` and default values

**Files:**
- Create: `database/seeders/SettingSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Consumes: `SettingService::set()` (Task 4).

**Note on defaults:** the spec gives explicit defaults for only 3 of the 8 keys (`booking.min_duration_minutes` = 60, `booking.buffer_minutes` = 0, `guest.host_approval_timeout_seconds` = 120). The other 5 have no stated default — the values below are reasonable placeholders chosen for this plan and called out explicitly in the decision doc (Task 8) as assumptions for Maryam to confirm or override before this reaches production, not locked decisions:
- `booking.cancellation_window_minutes` → 60 (one hour before start)
- `booking.slot_granularity_minutes` → 30 (the middle of the allowed 15/30/60 set)
- `booking.overrun_grace_minutes` → 10
- `profile.completion_threshold` → 80 (percent)
- `module.cafe.is_enabled` → `true` (no stated reason to seed the module as hidden)

- [ ] **Step 1: Write the seeder**

```php
<?php

namespace Database\Seeders;

use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Database\Seeder;

/**
 * Default values for every global setting key introduced by the 2026-08-15
 * decision session (docs/decisions/settings-key-value-store.md). Seeded
 * ahead of the domains that read them (business hours, booking, guests,
 * profile completion, module toggles) — settings is the explicit enabler,
 * built first per that session's execution order.
 */
class SettingSeeder extends Seeder
{
    public function run(SettingService $settings): void
    {
        $settings->set('booking.cancellation_window_minutes', 60, SettingValueType::Int);
        $settings->set('booking.slot_granularity_minutes', 30, SettingValueType::Int);
        $settings->set('booking.min_duration_minutes', 60, SettingValueType::Int);
        $settings->set('booking.overrun_grace_minutes', 10, SettingValueType::Int);
        $settings->set('booking.buffer_minutes', 0, SettingValueType::Int);
        $settings->set('profile.completion_threshold', 80, SettingValueType::Int);
        $settings->set('guest.host_approval_timeout_seconds', 120, SettingValueType::Int);
        $settings->set('module.cafe.is_enabled', true, SettingValueType::Bool);
    }
}
```

- [ ] **Step 2: Register it in `DatabaseSeeder`**

In `database/seeders/DatabaseSeeder.php`, change:

```php
    public function run(): void
    {
        $this->call(RoleSeeder::class);
    }
```

to:

```php
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(SettingSeeder::class);
    }
```

- [ ] **Step 3: Write a feature test asserting the seed**

Create `tests/Feature/Admin/SettingSeederTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Domain\Settings\Services\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_every_key_required_by_the_2026_08_15_decision_session(): void
    {
        $this->seed(SettingSeeder::class);
        $settings = app(SettingService::class);

        $this->assertSame(60, $settings->get('booking.cancellation_window_minutes'));
        $this->assertSame(30, $settings->get('booking.slot_granularity_minutes'));
        $this->assertSame(60, $settings->get('booking.min_duration_minutes'));
        $this->assertSame(10, $settings->get('booking.overrun_grace_minutes'));
        $this->assertSame(0, $settings->get('booking.buffer_minutes'));
        $this->assertSame(80, $settings->get('profile.completion_threshold'));
        $this->assertSame(120, $settings->get('guest.host_approval_timeout_seconds'));
        $this->assertTrue($settings->get('module.cafe.is_enabled'));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Admin/SettingSeederTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/seeders/SettingSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/Admin/SettingSeederTest.php
git commit -m "feat: seed default values for every settings key"
```

---

### Task 6: Admin API — list and update settings

**Files:**
- Create: `app/Http/Resources/SettingResource.php`
- Create: `app/Http/Requests/Admin/UpdateSettingRequest.php`
- Create: `app/Http/Controllers/Api/V1/Admin/SettingController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`
- Modify: `lang/ar/api.php`
- Test: `tests/Feature/Admin/SettingControllerTest.php`

**Interfaces:**
- Consumes: `Setting`, `SettingScope`, `SettingValueType` (Tasks 1–2), `SettingService` (Task 4).
- Produces: `GET /api/v1/admin/settings` (both admin and operations), `PATCH /api/v1/admin/settings/{key}` (admin only) — no other task depends on these, they are the terminal deliverable of this plan.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SettingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(SettingService::class)->set('booking.buffer_minutes', 0, SettingValueType::Int);
        app(SettingService::class)->set('module.cafe.is_enabled', true, SettingValueType::Bool);
    }

    public function test_an_admin_can_list_settings(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/v1/admin/settings');

        $response->assertOk();
        $response->assertJsonFragment(['key' => 'booking.buffer_minutes', 'value' => 0]);
        $response->assertJsonFragment(['key' => 'module.cafe.is_enabled', 'value' => true]);
    }

    public function test_an_operations_user_can_list_settings(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->getJson('/api/v1/admin/settings')->assertOk();
    }

    public function test_an_admin_can_update_an_int_setting(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->patchJson('/api/v1/admin/settings/booking.buffer_minutes', ['value' => 15]);

        $response->assertOk();
        $response->assertJson(['message' => __('api.admin.setting_updated')]);
        $this->assertSame(15, app(SettingService::class)->get('booking.buffer_minutes'));
    }

    public function test_an_admin_can_update_a_bool_setting(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson('/api/v1/admin/settings/module.cafe.is_enabled', ['value' => false])->assertOk();

        $this->assertFalse(app(SettingService::class)->get('module.cafe.is_enabled'));
    }

    public function test_updating_a_setting_rejects_the_wrong_value_type(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->patchJson('/api/v1/admin/settings/booking.buffer_minutes', ['value' => 'not-a-number']);

        $response->assertStatus(422);
    }

    public function test_updating_an_unknown_key_returns_404(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson('/api/v1/admin/settings/no.such.key', ['value' => 1])->assertNotFound();
    }

    public function test_an_operations_user_cannot_update_a_setting(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->patchJson('/api/v1/admin/settings/booking.buffer_minutes', ['value' => 15])->assertForbidden();
    }

    public function test_updating_a_setting_writes_an_audit_log_entry(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson('/api/v1/admin/settings/booking.buffer_minutes', ['value' => 15])->assertOk();

        $activity = Activity::where('description', 'setting_updated')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame(0, $activity->properties['before']);
        $this->assertSame(15, $activity->properties['after']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Admin/SettingControllerTest.php`
Expected: FAIL — 404s (route doesn't exist yet)

- [ ] **Step 3: Write the resource**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'value' => $this->resolvedValue(),
            'updated_at' => $this->updated_at,
        ];
    }
}
```

- [ ] **Step 4: Write the form request**

```php
<?php

namespace App\Http\Requests\Admin;

use App\Domain\Settings\Enums\SettingScope;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    private ?Setting $resolvedSetting = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'value' => match ($this->targetSetting()->type) {
                SettingValueType::Int => ['required', 'integer'],
                SettingValueType::Bool => ['required', 'boolean'],
                SettingValueType::String => ['required', 'string'],
                SettingValueType::Time => ['required', 'date_format:H:i'],
                SettingValueType::Json => ['required', 'array'],
            },
        ];
    }

    public function targetSetting(): Setting
    {
        return $this->resolvedSetting ??= Setting::query()
            ->where('key', $this->route('key'))
            ->where('scope_type', SettingScope::Global)
            ->firstOrFail();
    }
}
```

- [ ] **Step 5: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Settings\Enums\SettingScope;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\Services\SettingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingRequest;
use App\Http\Resources\SettingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Deliberately not extending AdminResourceController: settings have no
 * 'order' column and no create/destroy — the key set is fixed by
 * SettingSeeder, not admin-authored — and "update" only ever changes
 * `value`. Same shape mismatch as UserController.
 */
class SettingController extends Controller
{
    use LogsSensitiveActions;

    public function index(): AnonymousResourceCollection
    {
        return SettingResource::collection(
            Setting::query()->where('scope_type', SettingScope::Global)->orderBy('key')->get()
        );
    }

    public function update(UpdateSettingRequest $request, string $key, SettingService $settings): JsonResponse
    {
        $setting = $request->targetSetting();
        $before = $setting->resolvedValue();
        $after = $request->validated('value');

        $settings->set($key, $after, $setting->type);

        $this->logSensitiveAction('setting_updated', $setting, [
            'before' => $before,
            'after' => $after,
        ]);

        return response()->json(['message' => __('api.admin.setting_updated')]);
    }
}
```

- [ ] **Step 6: Register the routes**

In `routes/api/v1/admin.php`, add the import:

```php
use App\Http\Controllers\Api\V1\Admin\SettingController;
```

Add near the Privacy Policy routes (both sit outside the `role:admin` group since reads apply to both roles):

```php
// Settings — global key/value config (docs/decisions/settings-key-value-store.md).
Route::get('settings', [SettingController::class, 'index']);
```

Add inside the existing `Route::middleware('role:admin')->group(function () { ... })` block at the bottom of the file (mutating a business-rule config value is admin-only, same reasoning as user/role management above it):

```php
    Route::patch('settings/{key}', [SettingController::class, 'update']);
```

- [ ] **Step 7: Add the language keys**

In `lang/en/api.php`, inside the `'admin' => [...]` array, add:

```php
        'setting_updated' => 'Setting updated.',
```

In `lang/ar/api.php`, inside the `'admin' => [...]` array, add:

```php
        'setting_updated' => 'تم تحديث الإعداد.',
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Admin/SettingControllerTest.php`
Expected: PASS (8 tests)

- [ ] **Step 9: Run the full suite**

Run: `php artisan test`
Expected: all green, no regressions

- [ ] **Step 10: Commit**

```bash
git add app/Http/Resources/SettingResource.php app/Http/Requests/Admin/UpdateSettingRequest.php app/Http/Controllers/Api/V1/Admin/SettingController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Admin/SettingControllerTest.php
git commit -m "feat: add admin settings list/update endpoints"
```

---

### Task 7: Update the Postman collection

**Files:**
- Modify: `postman/ADD-OS.postman_collection.json`

**Interfaces:**
- Consumes: the routes from Task 6 (`GET /api/v1/admin/settings`, `PATCH /api/v1/admin/settings/{key}`).

- [ ] **Step 1: Add a "Settings" folder under "Admin (Dashboard)"**

Open `postman/ADD-OS.postman_collection.json`. Find the `"Roles"` folder inside the `"Admin (Dashboard)"` item's `item` array (it directly precedes `"Spatial Hierarchy"`). Insert a new sibling folder immediately after `"Roles"` and before `"Spatial Hierarchy"`:

```json
{
  "name": "Settings",
  "description": "Global key/value config (docs/decisions/settings-key-value-store.md). List is available to admin and operations; update is admin-only.",
  "item": [
    {
      "name": "List Settings",
      "request": {
        "method": "GET",
        "header": [
          { "key": "lang", "value": "{{lang}}", "type": "text" },
          { "key": "currency", "value": "{{currency}}", "type": "text" }
        ],
        "url": {
          "raw": "{{base_url}}/api/v1/admin/settings",
          "host": ["{{base_url}}"],
          "path": ["api", "v1", "admin", "settings"]
        }
      }
    },
    {
      "name": "Update Setting",
      "request": {
        "method": "PATCH",
        "header": [
          { "key": "Content-Type", "value": "application/json" },
          { "key": "lang", "value": "{{lang}}", "type": "text" },
          { "key": "currency", "value": "{{currency}}", "type": "text" }
        ],
        "body": {
          "mode": "raw",
          "raw": "{\n  \"value\": 15\n}",
          "options": { "raw": { "language": "json" } }
        },
        "url": {
          "raw": "{{base_url}}/api/v1/admin/settings/booking.buffer_minutes",
          "host": ["{{base_url}}"],
          "path": ["api", "v1", "admin", "settings", "booking.buffer_minutes"]
        },
        "description": "`value`'s expected shape depends on the key's type (int/bool/string/time \"H:i\"/json array) — a mismatch is a 422. Returns `{\"message\": \"...\"}` on success, not the updated setting."
      }
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
git commit -m "docs: add settings endpoints to Postman collection"
```

---

### Task 8: Decision doc

**Files:**
- Create: `docs/decisions/settings-key-value-store.md`
- Modify: `docs/decisions/README.md`

- [ ] **Step 1: Write the decision doc**

```markdown
# Settings: a new domain for cached, typed runtime config

**Status:** resolved 2026-08-15. **Owner:** Maryam Asha.
**Type:** design doc, written ahead of the phase that implements it — the
2026-08-15 decision session names `settings` as the enabler five other
subsystems (business hours, booking, guests, profile completion, module
toggles) depend on, so it is built first.

## What this adds

A new `App\Domain\Settings` domain: one `settings` table (key, scope,
typed value), a `SettingService` that is the only read/write path, and an
admin endpoint to list/update the seeded global keys. Not anticipated in
the 2026-08-08 backend build plan's original 10-domain list — this session
adds an 11th.

## Decision

- **Domain placement:** `App\Domain\Settings`, not a non-domain
  `App\Services\*` class. Unlike Otp (a cross-domain *service* whose model
  lives in Identity), `Setting` the model and `SettingService` are tightly
  coupled — one wraps the other — so they belong together, mirroring how
  Membership holds `Wallet` + `WalletService` together.
- **Not added to `DomainLayerBoundaryTest`'s `FORBIDDEN` map.** Ecosystem
  and Experience must be able to read settings (e.g. `module.cafe.is_enabled`
  is read from the Experience-domain café code) — Settings is deliberately
  left as a domain everything may depend on, not a Core domain.
- **Scope column, not a scoped row per space this session.** The `settings`
  table supports `scope_type`/`scope_id` for a future per-entity override,
  but every key this session needs is global. Per-space booking overrides
  (`slot_granularity_minutes`, `cancellation_window_minutes`,
  `requires_approval`, `buffer_minutes`) are plain columns on `spaces`
  itself, built by the Booking plan — not scoped `Setting` rows.
- **`scope_type`/`scope_id` default to `'global'`/`0`, not `null`.** MySQL
  allows repeated `NULL`s in a unique index, so `(key, scope_type,
  scope_id)` with nullable scope columns would let two "global" rows for
  the same key coexist silently. A non-null sentinel keeps the unique
  index meaningful.
- **Cache-aside with no TTL.** `SettingService::get()` calls
  `Cache::rememberForever()`; `set()` always calls `Cache::forget()` on
  its own key first. A TTL would mean a booking request occasionally reads
  a stale value for no reason, when exact invalidation on write is no
  harder to build.
- **Seeded defaults for keys with no spec-given value are assumptions, not
  locked decisions** — flagged here for confirmation before this reaches
  production:
  - `booking.cancellation_window_minutes` → 60
  - `booking.slot_granularity_minutes` → 30
  - `booking.overrun_grace_minutes` → 10
  - `profile.completion_threshold` → 80
  - `module.cafe.is_enabled` → `true`

  The other three keys have spec-given defaults: `booking.min_duration_minutes`
  = 60, `booking.buffer_minutes` = 0, `guest.host_approval_timeout_seconds` = 120.

## Why

See "Decision" above — each bullet states its own reasoning inline; there
is no single overriding rationale to separate out.

## What this changed in code

- New migration `2026_08_15_090000_create_settings_table.php`.
- New `App\Domain\Settings\{Models\Setting,Enums\SettingScope,Enums\SettingValueType,Services\SettingService}`.
- `database/seeders/SettingSeeder.php`, called from `DatabaseSeeder::run()`.
- `App\Http\Controllers\Api\V1\Admin\SettingController` (`index`, `update`),
  `App\Http\Requests\Admin\UpdateSettingRequest`, `App\Http\Resources\SettingResource`.
- Routes: `GET /api/v1/admin/settings` (admin + operations), `PATCH
  /api/v1/admin/settings/{key}` (admin only).
- `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` extended with the
  `Setting::class` entry.

## Guard

[`EnumColumnsHaveBackedEnumCastsTest`](../../tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php)
covers the `scope_type`/`type` enum casts. No dedicated guard exists for
the scope-column design itself (e.g. "no scoped `Setting` row exists yet")
— there is nothing to regress until a later domain adds one, at which
point that domain's own plan is where a guard (if warranted) belongs.
```

- [ ] **Step 2: Add the doc to the decision register**

In `docs/decisions/README.md`, add this line to the **Design docs** section (after the existing `wallet-points-categorization.md` line):

```markdown
- [settings-key-value-store.md](settings-key-value-store.md) — new `Settings` domain: cached, typed key/value config, the enabler for business hours/booking/guests/profile/module-toggle work
```

- [ ] **Step 3: Commit**

```bash
git add docs/decisions/settings-key-value-store.md docs/decisions/README.md
git commit -m "docs: record the settings domain design decision"
```

---

## Post-plan check

Run the full suite once more before moving to the next subsystem plan (business hours):

```bash
composer test
./vendor/bin/pint --test
```

Both should be clean. `booking.*`, `profile.completion_threshold`, `guest.host_approval_timeout_seconds`, and `module.cafe.is_enabled` are now live, cached, admin-editable settings — every later plan in the 2026-08-15 session reads them through `App\Domain\Settings\Services\SettingService`, never by re-adding a `config/*.php` value or hardcoding a number.
