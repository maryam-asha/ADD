# ADD Point System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make ADD Point the unit of account for every price, balance, and charge in the product, with currency reduced to a display and cash-receipt concern.

**Architecture:** One admin-editable setting (`point.rate_usd`) converts points to USD; the existing `CurrencyConversionService` converts USD to any display currency. The database stores whole integer points; every currency figure is derived at read time. Points are credited with `floor()` and charged with `ceil()`, so rounding never favours the member.

**Tech Stack:** Laravel 12, PHP 8.2+, bcmath, MySQL (SQLite in tests), PHPUnit, Laravel Pint. Frontend: Vue 3 + TypeScript + Vite (ADDOS).

**Spec:** [docs/superpowers/specs/2026-09-12-add-point-system-design.md](../specs/2026-09-12-add-point-system-design.md) plus its three child specs ([A](../specs/2026-09-12-add-point-a-foundation-design.md), [B](../specs/2026-09-12-add-point-b-pricing-design.md), [C](../specs/2026-09-12-add-point-c-wallet-design.md)). Read the umbrella spec's five invariants before starting.

## Global Constraints

- **All existing data is disposable test data.** Confirmed 2026-09-12. Migrations **rewrite columns directly** — no backfill logic, no dry-run command, no ledger-migration Artisan command. Seeders are updated to produce point values. This deletes Spec C §2's entire "dangerous part" and Spec B's backfill/reversibility sections.
- **Points are integers.** No fractional point exists in a balance, price, or charge.
- **Rounding:** `floor()` on every credit; `ceil()` on every debit. Applied once, at the ledger boundary.
- **bcmath only** for any arithmetic involving the rate. Never `(float)`. PRD decision #15.
- **One conversion service.** Only `PointConversionService` may read `point.rate_usd`.
- **`rate_to_base` means units of base per 1 unit of currency** (SYP ≈ 0.0000680272). SYP→USD multiplies; USD→SYP divides. Reuse `CurrencyConversionService`; write no new FX math.
- **Default rate:** `point.rate_usd = '0.10'` (1 point = 10¢).
- **Test command:** `php artisan test`. Single file: `php artisan test tests/Path/File.php`.
- **Style:** `./vendor/bin/pint` before every commit.
- **Commit attribution:** end every commit message with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

## Execution Order

Backend first (Tasks 1–9), then frontend (Tasks 10–13). Task 1 must land before any other. Tasks 2–4 (pricing) must land before Tasks 5–8 (wallet), because the wallet's charge path consumes point-priced spaces.

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `app/Domain/Finance/Services/PointConversionService.php` | The only place points↔USD conversion happens |
| `database/migrations/*_convert_pricing_tables_to_points.php` | plans, spaces, plan_balances → point columns |
| `database/migrations/*_convert_wallet_transactions_to_points.php` | ledger amount → integer points + `point_rate_usd` |
| `tests/Unit/Finance/PointConversionServiceTest.php` | Conversion unit tests |
| `tests/Guards/PointConversionIsCentralizedTest.php` | Invariant 4 |
| `tests/Guards/PointRoundingFavoursTheHouseTest.php` | Invariant 2 |
| `tests/Guards/WalletBalanceIgnoresAuditColumnsTest.php` | Invariant 3 |
| `tests/Feature/Finance/PointPricingTest.php` | API shape |
| `ADDOS/src/add-os/modules/payments/composables/usePoints.ts` | Frontend point formatting |

**Modified:** `SettingSeeder`, `UpdateSettingRequest`, `Plan`, `PlanBalance`, `Space`, `WalletTransaction`, `WalletService`, `AmountCalculator`, 5 booking services, `WalletTopUpController`, `StoreWalletTopUpRequest`, `PlanResource`, `SpaceResource`, plan/space Form Requests, `PlanSeeder`, `SpaceSeeder`, factories, and the ADDOS payments/members modules.

---

## Task 1: Point conversion foundation

Implements Spec A in full. Nothing else can start until this lands.

**Files:**
- Create: `app/Domain/Finance/Services/PointConversionService.php`
- Create: `tests/Unit/Finance/PointConversionServiceTest.php`
- Create: `tests/Guards/PointConversionIsCentralizedTest.php`
- Create: `tests/Guards/PointRoundingFavoursTheHouseTest.php`
- Modify: `database/seeders/SettingSeeder.php`
- Modify: `app/Http/Requests/Admin/UpdateSettingRequest.php`

**Interfaces:**
- Consumes: `SettingService::get()`, `CurrencyConversionService::convert()`
- Produces:
  - `PointConversionService::rate(): string`
  - `PointConversionService::pointsToUsd(int $points): string`
  - `PointConversionService::usdToPoints(string $usd): int` — floor
  - `PointConversionService::usdToPointsCeil(string $usd): int` — ceil
  - `PointConversionService::pointsToCurrency(int $points, string $currencyCode): ?string`

- [ ] **Step 1: Seed the setting**

In `database/seeders/SettingSeeder.php`, add to `run()`:

```php
$settings->setDefault('point.rate_usd', '0.10', SettingValueType::String);
```

`String`, not `Int` — `SettingValueType::Int` casts via `(int) $value`, turning `0.10` into `0`.

- [ ] **Step 2: Write the failing unit test**

Create `tests/Unit/Finance/PointConversionServiceTest.php`:

```php
<?php

namespace Tests\Unit\Finance;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PointConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PointConversionService $points;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingService::class)->setDefault('point.rate_usd', '0.10', SettingValueType::String);
        $this->points = app(PointConversionService::class);
    }

    public function test_it_reads_the_rate_from_settings(): void
    {
        $this->assertSame('0.10', $this->points->rate());
    }

    public function test_it_converts_points_to_usd(): void
    {
        $this->assertSame('50.00', $this->points->pointsToUsd(500));
    }

    public function test_points_revalue_when_the_admin_changes_the_rate(): void
    {
        app(SettingService::class)->set('point.rate_usd', '0.12', SettingValueType::String);

        $this->assertSame('12.00', app(PointConversionService::class)->pointsToUsd(100));
    }

    public function test_credits_round_down(): void
    {
        $this->assertSame(340, $this->points->usdToPoints('34.09'));
    }

    public function test_debits_round_up(): void
    {
        $this->assertSame(8, $this->points->usdToPointsCeil('0.71'));
    }

    public function test_an_exact_debit_does_not_gain_a_point(): void
    {
        $this->assertSame(18, $this->points->usdToPointsCeil('1.80'));
    }

    public function test_negative_points_convert_for_display(): void
    {
        $this->assertSame('-5.00', $this->points->pointsToUsd(-50));
    }

    public function test_it_rejects_a_negative_credit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->points->usdToPoints('-5.00');
    }

    public function test_it_returns_null_when_no_exchange_rate_exists(): void
    {
        Currency::factory()->create(['code' => 'EUR', 'is_base' => false, 'is_default' => false]);

        $this->assertNull($this->points->pointsToCurrency(100, 'EUR'));
    }

    public function test_it_converts_points_to_a_foreign_currency(): void
    {
        ExchangeRate::factory()->create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
        ]);

        // 340 points -> $34.00 -> /0.0000680272 -> ~499,800 SYP
        $syp = $this->points->pointsToCurrency(340, 'SYP');

        $this->assertNotNull($syp);
        $this->assertEqualsWithDelta(499800, (float) $syp, 100);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test tests/Unit/Finance/PointConversionServiceTest.php`
Expected: FAIL — `Class "App\Domain\Finance\Services\PointConversionService" not found`.

- [ ] **Step 4: Implement the service**

Create `app/Domain/Finance/Services/PointConversionService.php`:

```php
<?php

namespace App\Domain\Finance\Services;

use App\Domain\Settings\Services\SettingService;
use InvalidArgumentException;

/**
 * The single place points convert to and from money
 * (docs/superpowers/specs/2026-09-12-add-point-system-design.md,
 * Invariant 4). Nothing else in the app may read `point.rate_usd` —
 * tests/Guards/PointConversionIsCentralizedTest.php enforces that.
 *
 * All arithmetic is bcmath on decimal strings: the rate is a decimal
 * and PRD decision #15 forbids float for monetary values. Credits floor
 * and debits ceil, so rounding never favours the member (Invariant 2).
 */
class PointConversionService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly CurrencyConversionService $currency,
    ) {}

    public function rate(): string
    {
        return (string) $this->settings->get('point.rate_usd', '0.10');
    }

    /**
     * Exact, unrounded USD value of a point count. Accepts negatives —
     * the ledger stores debits as negative integers and a refund needs
     * to render them.
     */
    public function pointsToUsd(int $points): string
    {
        return bcmul((string) $points, $this->rate(), 2);
    }

    /**
     * Credit direction — floors. bcdiv at scale 0 truncates toward zero,
     * which is floor for the non-negative input this accepts.
     */
    public function usdToPoints(string $usd): int
    {
        $this->rejectNegative($usd);

        return (int) bcdiv($usd, $this->rate(), 0);
    }

    /**
     * Debit direction — ceils. Deliberately not ceil((float) $usd / ...):
     * a float division at an exact boundary (e.g. 1.80 / 0.10) can land
     * just above the integer and wrongly charge an extra whole point.
     */
    public function usdToPointsCeil(string $usd): int
    {
        $this->rejectNegative($usd);

        $quotient = bcdiv($usd, $this->rate(), 4);
        $floor = bcdiv($quotient, '1', 0);

        return (int) (bccomp($quotient, $floor, 4) > 0 ? bcadd($floor, '1', 0) : $floor);
    }

    /**
     * Null when the target currency has no exchange rate, matching
     * CurrencyConversionService's existing contract so callers keep
     * their null handling.
     */
    public function pointsToCurrency(int $points, string $currencyCode): ?string
    {
        $usd = $this->pointsToUsd($points);
        $baseCurrency = $this->currency->baseCurrencyCode();

        if ($currencyCode === $baseCurrency) {
            return $usd;
        }

        $converted = $this->currency->convert((float) $usd, $baseCurrency, $currencyCode);

        return $converted !== null ? number_format($converted, 2, '.', '') : null;
    }

    private function rejectNegative(string $usd): void
    {
        if (bccomp($usd, '0', 4) < 0) {
            throw new InvalidArgumentException("Cannot convert a negative amount to points: {$usd}");
        }
    }
}
```

- [ ] **Step 5: Add the base-currency helper it depends on**

`pointsToCurrency()` calls `CurrencyConversionService::baseCurrencyCode()`, which does not exist yet. Add it to `app/Domain/Finance/Services/CurrencyConversionService.php` — the class already computes this inline in three places, so this is a cleanup as well as an addition:

```php
public function baseCurrencyCode(): string
{
    return Currency::query()->where('is_base', true)->value('code') ?? 'USD';
}
```

Then replace the three existing inline `Currency::query()->where('is_base', true)->value('code')` lookups inside `convert()` with `$this->baseCurrencyCode()`.

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Finance/PointConversionServiceTest.php`
Expected: PASS, 10 tests.

- [ ] **Step 7: Validate the setting so a bad rate cannot be saved**

In `app/Http/Requests/Admin/UpdateSettingRequest.php`, add a branch beside the existing `app.timezone` one, **before** the `match` on type:

```php
if ($this->targetSetting()->key === 'point.rate_usd') {
    return ['value' => ['required', 'numeric', 'gt:0', 'decimal:0,4']];
}
```

`gt:0` matters — a zero rate makes everything free and a negative one inverts the economy.

- [ ] **Step 8: Write the guard tests**

Create `tests/Guards/PointConversionIsCentralizedTest.php`:

```php
<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * Invariant 4 (docs/superpowers/specs/2026-09-12-add-point-system-design.md):
 * PointConversionService is the only place that reads the point rate.
 * Any other file doing so is duplicating rounding rules that must exist
 * exactly once.
 */
class PointConversionIsCentralizedTest extends TestCase
{
    use ScansSourceFiles;

    public function test_only_the_conversion_service_reads_the_point_rate(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(app_path()) as $path => $contents) {
            if (str_contains($path, 'PointConversionService.php')) {
                continue;
            }

            if (str_contains($contents, 'point.rate_usd')) {
                $violations[] = $path;
            }
        }

        $this->assertSame([], $violations, "Only PointConversionService may read point.rate_usd:\n".implode("\n", $violations));
    }
}
```

Create `tests/Guards/PointRoundingFavoursTheHouseTest.php`:

```php
<?php

namespace Tests\Guards;

use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invariant 2: credits floor, debits ceil. The exact-boundary case is
 * the one a float implementation silently gets wrong, so it is asserted
 * here rather than left to the unit tests.
 */
class PointRoundingFavoursTheHouseTest extends TestCase
{
    use RefreshDatabase;

    private PointConversionService $points;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingService::class)->setDefault('point.rate_usd', '0.10', SettingValueType::String);
        $this->points = app(PointConversionService::class);
    }

    public function test_a_credit_never_rounds_up(): void
    {
        $this->assertSame(340, $this->points->usdToPoints('34.09'));
        $this->assertSame(0, $this->points->usdToPoints('0.09'));
    }

    public function test_a_debit_never_rounds_down(): void
    {
        $this->assertSame(8, $this->points->usdToPointsCeil('0.71'));
        $this->assertSame(1, $this->points->usdToPointsCeil('0.01'));
    }

    public function test_an_exact_amount_is_never_inflated(): void
    {
        $this->assertSame(18, $this->points->usdToPointsCeil('1.80'));
        $this->assertSame(500, $this->points->usdToPointsCeil('50.00'));
    }
}
```

- [ ] **Step 9: Run the full suite**

Run: `php artisan test`
Expected: PASS. The centralization guard passes because only the service reads the key.

- [ ] **Step 10: Commit**

```bash
./vendor/bin/pint
git add app/Domain/Finance/Services/PointConversionService.php \
        app/Domain/Finance/Services/CurrencyConversionService.php \
        app/Http/Requests/Admin/UpdateSettingRequest.php \
        database/seeders/SettingSeeder.php \
        tests/Unit/Finance/PointConversionServiceTest.php \
        tests/Guards/PointConversionIsCentralizedTest.php \
        tests/Guards/PointRoundingFavoursTheHouseTest.php
git commit -m "feat(points): add point.rate_usd setting and PointConversionService

The single place points convert to and from money. Credits floor and
debits ceil so rounding never favours the member; all arithmetic is
bcmath because the rate is a decimal.

Spec: docs/superpowers/specs/2026-09-12-add-point-a-foundation-design.md

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 2: Convert pricing tables to points

**Files:**
- Create: `database/migrations/2026_09_12_100000_convert_pricing_tables_to_points.php`
- Modify: `app/Domain/Membership/Models/Plan.php`, `app/Domain/Membership/Models/PlanBalance.php`, `app/Domain/Foundation/Models/Space.php`

**Interfaces:**
- Consumes: nothing from Task 1 at runtime (the migration writes columns, not conversions)
- Produces: `plans.price_points`, `plans.overage_rate_points`, `spaces.hourly_rate_points`, `plan_balances.amount_points` — all `unsignedInteger`; `spaces.hourly_rate_points` and `plans.overage_rate_points` nullable

- [ ] **Step 1: Write the migration**

Because all data is disposable, this drops and adds columns directly — no backfill.

Create `database/migrations/2026_09_12_100000_convert_pricing_tables_to_points.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADD Point becomes the unit of account
 * (docs/superpowers/specs/2026-09-12-add-point-b-pricing-design.md).
 * Prices are whole point counts; currency is derived at read time, so
 * `pricing_currency` stops being a stored concept here.
 *
 * No backfill: every row in these tables is seeded test data as of
 * 2026-09-12, re-created by PlanSeeder/SpaceSeeder with point values.
 *
 * unsignedInteger, not decimal — a point is a cardinal quantity, and
 * PRD decision #15's guard (MoneyIsDecimalOnlyTest) forbids only
 * float/double, which this is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // The FK must go before the column it constrains, or MySQL
            // refuses the drop. SQLite (the test connection) tolerates
            // either order, so getting this wrong passes tests and fails
            // on deploy.
            $table->dropForeign(['pricing_currency']);
            $table->dropColumn(['price', 'pricing_currency', 'overage_rate']);
            $table->unsignedInteger('price_points')->default(0)->after('subtitle');
            $table->unsignedInteger('overage_rate_points')->nullable()->after('included_hours');
        });

        Schema::table('spaces', function (Blueprint $table) {
            $table->dropForeign(['pricing_currency']);
            $table->dropColumn(['hourly_rate', 'pricing_currency']);
            $table->unsignedInteger('hourly_rate_points')->nullable()->after('capacity');
        });

        Schema::table('plan_balances', function (Blueprint $table) {
            $table->dropColumn('amount');
            $table->unsignedInteger('amount_points')->default(0)->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price_points', 'overage_rate_points']);
            $table->decimal('price', 10, 2)->default(0);
            $table->string('pricing_currency', 3)->default('USD');
            $table->decimal('overage_rate', 10, 2)->nullable();
            $table->foreign('pricing_currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn('hourly_rate_points');
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->string('pricing_currency', 3)->nullable();
            $table->foreign('pricing_currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        Schema::table('plan_balances', function (Blueprint $table) {
            $table->dropColumn('amount_points');
            $table->decimal('amount', 10, 2)->default(0);
        });
    }
};
```

⚠️ `after()` is MySQL-only and ignored by SQLite; harmless in both.

- [ ] **Step 2: Update the three models**

`app/Domain/Membership/Models/Plan.php` — in `$fillable`, replace `'price'`, `'pricing_currency'`, `'overage_rate'` with `'price_points'`, `'overage_rate_points'`. In `casts()`, replace the `price`/`overage_rate` decimal casts with:

```php
'price_points' => 'integer',
'overage_rate_points' => 'integer',
```

`app/Domain/Membership/Models/PlanBalance.php` — replace `'amount'` with `'amount_points'` in `$fillable`; replace `'amount' => 'decimal:2'` with `'amount_points' => 'integer'`.

`app/Domain/Foundation/Models/Space.php` — replace `'hourly_rate'`, `'pricing_currency'` with `'hourly_rate_points'`; replace `'hourly_rate' => 'decimal:2'` with `'hourly_rate_points' => 'integer'`.

- [ ] **Step 3: Run the migration**

Run: `php artisan migrate:fresh`
Expected: all migrations run clean, no FK errors.

- [ ] **Step 4: Commit**

```bash
./vendor/bin/pint
git add database/migrations/2026_09_12_100000_convert_pricing_tables_to_points.php \
        app/Domain/Membership/Models/Plan.php \
        app/Domain/Membership/Models/PlanBalance.php \
        app/Domain/Foundation/Models/Space.php
git commit -m "feat(points): price plans, spaces and plan balances in points

Drops price/hourly_rate/amount and their pricing_currency columns for
whole-point equivalents. No backfill — all rows are seeded test data.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 3: Update seeders and factories to point values

Splitting this from Task 2 keeps a failing-seed review separate from a schema review.

**Files:**
- Modify: `database/seeders/PlanSeeder.php`, `database/seeders/SpaceSeeder.php`
- Modify: `database/factories/PlanFactory.php`, `database/factories/SpaceFactory.php`

**Interfaces:**
- Consumes: point columns from Task 2
- Produces: seeded data priced in points at 10 points per USD

- [ ] **Step 1: Convert the seeded plan prices**

At `point.rate_usd = 0.10`, **1 USD = 10 points**. In `database/seeders/PlanSeeder.php`, replace each `'price' => 'X.00', 'pricing_currency' => 'USD'` pair with `'price_points' => X*10`, and each `'overage_rate' => Y` with `'overage_rate_points' => Y*10`:

| Old price | New `price_points` |
|---|---|
| `'50.00'` | `500` |
| `'75.00'` | `750` |
| `'110.00'` | `1100` |
| `'22.00'` | `220` |
| `'199.00'` | `1990` |
| `'250.00'` | `2500` |

`'overage_rate' => 3.00` becomes `'overage_rate_points' => 30`.

For the nested `balances` arrays, replace `'amount' => '20.00'` with `'amount_points' => 200`, `'15.00'` → `150`, `'30.00'` → `300`, `'20.00'` → `200`. Apply the ×10 rule to every remaining balance row in the file.

- [ ] **Step 2: Convert the seeded space rates**

In `database/seeders/SpaceSeeder.php`, replace each `'hourly_rate' => X, 'pricing_currency' => 'USD'` with `'hourly_rate_points' => X*10`:

| Old rate | New `hourly_rate_points` |
|---|---|
| `3.00` | `30` |
| `12.00` | `120` |
| `18.00` | `180` |
| `120.00` | `1200` |

Apply to every space in the file.

- [ ] **Step 3: Update the factories**

`database/factories/PlanFactory.php` — replace the `price`/`pricing_currency`/`overage_rate` keys:

```php
'price_points' => 500,
'overage_rate_points' => 30,
```

`database/factories/SpaceFactory.php`:

```php
'hourly_rate_points' => 120,
```

There is **no** `PlanBalanceFactory` — plan balances are created through `PlanSeeder`'s nested arrays and in tests via `PlanBalance::create()`. Nothing to change here; update any test that creates a `PlanBalance` directly to pass `amount_points` instead of `amount`.

- [ ] **Step 4: Verify seeding works end to end**

Run: `php artisan migrate:fresh --seed`
Expected: completes with no error.

- [ ] **Step 5: Run the suite to find every remaining reference**

Run: `php artisan test`
Expected: FAIL — many tests still reference `price`, `hourly_rate`, `pricing_currency`. **This is the point of this step:** the failures enumerate exactly what Tasks 4–8 must update. Record the list; do not fix them here.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add database/seeders/ database/factories/
git commit -m "feat(points): seed plans and spaces priced in points

1 USD = 10 points at the default rate, so every seeded price is its
former dollar value x10.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 4: Point-aware API resources and validation

**Files:**
- Modify: `app/Http/Resources/PlanResource.php`, `app/Http/Resources/SpaceResource.php`
- Modify: `app/Http/Requests/Admin/StorePlanRequest.php`, `UpdatePlanRequest.php`, `StoreSpaceRequest.php`, `UpdateSpaceRequest.php`
- Create: `tests/Feature/Finance/PointPricingTest.php`

**Interfaces:**
- Consumes: `PointConversionService::pointsToUsd()`, `pointsToCurrency()`; `CurrencyResolver::resolve()`
- Produces: response fields `price_points` (int), `price_usd` (string), `price_display` (string), `currency` (string)

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Finance/PointPricingTest.php`:

```php
<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PointPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        app(SettingService::class)->setDefault('point.rate_usd', '0.10', SettingValueType::String);
    }

    public function test_a_plan_reports_its_point_price_and_usd_equivalent(): void
    {
        Plan::factory()->create(['price_points' => 500, 'is_active' => true]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertOk()
            ->assertJsonPath('data.0.price_points', 500)
            ->assertJsonPath('data.0.price_usd', '50.00')
            ->assertJsonPath('data.0.currency', 'USD');
    }

    public function test_the_currency_header_changes_display_but_not_the_stored_points(): void
    {
        ExchangeRate::factory()->create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
        ]);
        Plan::factory()->create(['price_points' => 500, 'is_active' => true]);

        $response = $this->withHeader('currency', 'SYP')->getJson('/api/v1/plans');

        $response->assertOk()
            ->assertJsonPath('data.0.price_points', 500)
            ->assertJsonPath('data.0.currency', 'SYP');

        $this->assertNotSame('50.00', $response->json('data.0.price_display'));
    }

    public function test_changing_the_rate_revalues_the_display_without_touching_the_row(): void
    {
        $plan = Plan::factory()->create(['price_points' => 500, 'is_active' => true]);

        app(SettingService::class)->set('point.rate_usd', '0.12', SettingValueType::String);

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonPath('data.0.price_usd', '60.00')
            ->assertJsonPath('data.0.price_points', 500);

        $this->assertSame(500, $plan->fresh()->price_points);
    }

    public function test_a_fractional_point_price_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['dashboard']);

        $this->postJson('/api/v1/admin/plans', [
            'name' => ['en' => 'Test', 'ar' => 'تجربة'],
            'price_points' => 12.5,
            'duration_days' => 30,
        ])->assertJsonValidationErrors('price_points');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Finance/PointPricingTest.php`
Expected: FAIL — `price_points` missing from the response.

- [ ] **Step 3: Rewrite `PlanResource`'s pricing block**

In `app/Http/Resources/PlanResource.php`, remove `'price'`, `'pricing_currency'`, and `'overage_rate'` from the `$data` array and replace the whole conversion block at the bottom of `toArray()` with:

```php
$points = app(\App\Domain\Finance\Services\PointConversionService::class);
$targetCurrency = app(CurrencyResolver::class)->resolve($request, $user);

$data['price_points'] = (int) $this->price_points;
$data['price_usd'] = $points->pointsToUsd((int) $this->price_points);
$data['price_display'] = $points->pointsToCurrency((int) $this->price_points, $targetCurrency)
    ?? $data['price_usd'];
$data['currency'] = $targetCurrency;
$data['overage_rate_points'] = $this->overage_rate_points !== null
    ? (int) $this->overage_rate_points
    : null;
```

Keep the existing `try { $user = $request->user('sanctum'); } catch (HttpException) { $user = null; }` block above it unchanged — it handles suspended accounts on public listings.

In the `balances` closure, replace `'amount' => (float) $b->amount` with:

```php
'amount_points' => (int) $b->amount_points,
```

⚠️ The old `(float)` cast there was a monetary float, contrary to decision #15; the integer cast fixes it.

Delete the now-dead `converted_amount`/`converted_currency` block — `price_display` supersedes it, and keeping a second pair of display fields invites drift.

- [ ] **Step 4: Apply the same shape to `SpaceResource`**

In `app/Http/Resources/SpaceResource.php`, remove `hourly_rate` and `pricing_currency` and add:

```php
$points = app(\App\Domain\Finance\Services\PointConversionService::class);
$targetCurrency = app(\App\Domain\Finance\Services\CurrencyResolver::class)
    ->resolve($request, $request->user());

$rate = $this->hourly_rate_points !== null ? (int) $this->hourly_rate_points : null;

$data['hourly_rate_points'] = $rate;
$data['hourly_rate_usd'] = $rate !== null ? $points->pointsToUsd($rate) : null;
$data['hourly_rate_display'] = $rate !== null
    ? ($points->pointsToCurrency($rate, $targetCurrency) ?? $points->pointsToUsd($rate))
    : null;
$data['currency'] = $targetCurrency;
```

- [ ] **Step 5: Update the Form Requests**

In `StorePlanRequest` and `UpdatePlanRequest`, remove the `price`, `pricing_currency`, and `overage_rate` rules and add:

```php
'price_points' => ['required', 'integer', 'min:0'],
'overage_rate_points' => ['nullable', 'integer', 'min:0'],
```

(In `UpdatePlanRequest`, use `'sometimes'` in place of `'required'` to match that file's existing convention.)

In `StoreSpaceRequest` and `UpdateSpaceRequest`, remove `hourly_rate` and `pricing_currency` and add:

```php
'hourly_rate_points' => ['nullable', 'integer', 'min:0'],
```

`integer` is what rejects `12.5` — Invariant 2's consequence enforced at the boundary rather than at charge time.

Plan balances are validated as **nested rules inside `StorePlanRequest`/`UpdatePlanRequest`**, not a separate Form Request. In both files replace:

```php
'balances.*.amount' => ['required', 'numeric', 'min:0'],
```

with:

```php
'balances.*.amount_points' => ['required', 'integer', 'min:0'],
```

Then update the controller code that persists `balances` to write `amount_points`.

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Finance/PointPricingTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 7: Fix the pre-existing tests this breaks**

Run: `php artisan test`

Update every test that constructs a plan or space with `price`/`hourly_rate`/`pricing_currency` to use the point columns (×10 of the old dollar value). Update assertions reading `price` or `converted_amount` to read `price_points`/`price_usd`/`price_display`.

⚠️ Do **not** change what a test asserts about *behaviour* — only the field names and units. A test asserting "a suspended user still gets a 200 on the public plan list" must still assert exactly that.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint
git add app/Http/Resources/ app/Http/Requests/ tests/
git commit -m "feat(points): report point prices with derived currency display

price_points is authoritative; price_usd/price_display/currency are
derived per request from the point rate and the resolved currency.
Replaces the converted_amount/converted_currency pair.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 5: Charge bookings in points

**Files:**
- Modify: `app/Domain/Booking/Services/AmountCalculator.php`
- Modify: `BookingCreationService.php`, `SessionClosureService.php`, `BookingCancellationService.php`, `BookingApprovalService.php`, `BookingExtensionService.php`
- Test: `tests/Feature/Booking/` (existing files)

**Interfaces:**
- Consumes: `Space::$hourly_rate_points`, `PointConversionService::pointsToUsd()`
- Produces: `AmountCalculator::forRange(Space, CarbonInterface, CarbonInterface): int` — whole points, rounded up

⚠️ The wallet still stores currency at this point. Each caller converts points → USD at the call site; **Task 8 removes that scaffolding.**

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Booking/` (or create `tests/Unit/Booking/AmountCalculatorTest.php`):

```php
<?php

namespace Tests\Unit\Booking;

use App\Domain\Booking\Services\AmountCalculator;
use App\Domain\Foundation\Models\Space;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmountCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_whole_hour_charges_the_exact_rate(): void
    {
        $space = Space::factory()->create(['hourly_rate_points' => 120]);
        $start = Carbon::parse('2026-09-12 10:00:00');

        $this->assertSame(120, app(AmountCalculator::class)->forRange($space, $start, $start->copy()->addHour()));
    }

    public function test_ninety_minutes_charges_one_and_a_half_times(): void
    {
        $space = Space::factory()->create(['hourly_rate_points' => 12]);
        $start = Carbon::parse('2026-09-12 10:00:00');

        $this->assertSame(18, app(AmountCalculator::class)->forRange($space, $start, $start->copy()->addMinutes(90)));
    }

    public function test_a_partial_point_rounds_up(): void
    {
        // 37 min at 12 pts/hr = 7.4 -> 8
        $space = Space::factory()->create(['hourly_rate_points' => 12]);
        $start = Carbon::parse('2026-09-12 10:00:00');

        $this->assertSame(8, app(AmountCalculator::class)->forRange($space, $start, $start->copy()->addMinutes(37)));
    }

    public function test_a_space_with_no_rate_is_free(): void
    {
        $space = Space::factory()->create(['hourly_rate_points' => null]);
        $start = Carbon::parse('2026-09-12 10:00:00');

        $this->assertSame(0, app(AmountCalculator::class)->forRange($space, $start, $start->copy()->addHour()));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Unit/Booking/AmountCalculatorTest.php`
Expected: FAIL — `forRange()` returns an array, not an int.

- [ ] **Step 3: Rewrite `AmountCalculator`**

Replace the body of `app/Domain/Booking/Services/AmountCalculator.php`:

```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Foundation\Models\Space;
use Carbon\CarbonInterface;

/**
 * Charges are whole points, rounded up (Invariant 2, debit direction):
 * a partial point always costs a full one, so a booking never charges
 * less than it consumed.
 *
 * Computed with bcmath at scale 4 and ceiled once at the very end —
 * rounding per step would compound. Returns a bare int because points
 * carry no currency; the old [amount, currency] tuple is gone.
 */
class AmountCalculator
{
    public function forRange(Space $space, CarbonInterface $start, CarbonInterface $end): int
    {
        $rate = $space->hourly_rate_points;

        if ($rate === null || $rate <= 0) {
            return 0;
        }

        $seconds = $start->diffInSeconds($end);
        $hours = bcdiv((string) $seconds, '3600', 6);
        $exact = bcmul($hours, (string) $rate, 4);

        $floor = bcdiv($exact, '1', 0);

        return (int) (bccomp($exact, $floor, 4) > 0 ? bcadd($floor, '1', 0) : $floor);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Booking/AmountCalculatorTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Update the five callers**

Each caller currently does `[$amount] = $this->amounts->forRange(...)` and passes `$amount` (a decimal string) to `WalletService`. In **each of the five services**, change the destructure to a scalar and convert at the call site:

```php
// TODO(spec-C): drop this conversion once the ledger stores points
$points = $this->amounts->forRange($space, $startAt, $endAt);
$amount = app(\App\Domain\Finance\Services\PointConversionService::class)->pointsToUsd($points);
```

Then pass `$amount` onward exactly as before. Also replace every `bccomp($amount, '0.00', 2)` guard with `$points <= 0`, which is clearer and unit-correct.

Files: `BookingCreationService.php`, `SessionClosureService.php`, `BookingCancellationService.php`, `BookingApprovalService.php`, `BookingExtensionService.php`.

⚠️ Mark every one with the `TODO(spec-C)` comment verbatim — Task 8 greps for it.

- [ ] **Step 6: Run the booking suite**

Run: `php artisan test tests/Feature/Booking`
Expected: PASS. Update any assertion comparing a charged amount, keeping the behaviour assertion intact.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint
git add app/Domain/Booking/ tests/
git commit -m "feat(points): calculate booking charges in whole points

forRange returns an int and rounds up, so a partial point costs a full
one. Callers convert points to USD at the call site until the ledger
itself stores points.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 6: Convert the wallet ledger to points

**Files:**
- Create: `database/migrations/2026_09_12_110000_convert_wallet_transactions_to_points.php`
- Modify: `app/Domain/Membership/Models/WalletTransaction.php`
- Modify: `database/factories/WalletTransactionFactory.php`

**Interfaces:**
- Produces: `wallet_transactions.amount` as a **signed integer** (points); new nullable `point_rate_usd` decimal(8,4)

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger becomes point-denominated
 * (docs/superpowers/specs/2026-09-12-add-point-c-wallet-design.md).
 * `amount` stays signed — positive credit, negative debit — so it is a
 * plain integer(), never unsignedInteger().
 *
 * No backfill: every row is disposable test data as of 2026-09-12.
 *
 * payment_currency / payment_amount / exchange_rate_to_base survive and
 * are joined by point_rate_usd. All four are audit-only (Invariant 3):
 * they record what was handed over and at what rates, and no balance
 * computation may read them — enforced by
 * tests/Guards/WalletBalanceIgnoresAuditColumnsTest.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn('amount');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->integer('amount')->default(0)->after('wallet_id');
            $table->decimal('point_rate_usd', 8, 4)->nullable()->after('exchange_rate_to_base');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn(['amount', 'point_rate_usd']);
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->default(0)->after('wallet_id');
        });
    }
};
```

⚠️ Two separate `Schema::table` calls: dropping and re-adding a column of the same name in one closure is unreliable across drivers.

- [ ] **Step 2: Update the model casts**

In `app/Domain/Membership/Models/WalletTransaction.php`, add `'point_rate_usd'` to `$fillable`, and in `casts()` replace `'amount' => 'decimal:2'` with:

```php
'amount' => 'integer',
'point_rate_usd' => 'decimal:4',
```

- [ ] **Step 3: Update the factory**

In `database/factories/WalletTransactionFactory.php`, change the amount default to an integer point value, e.g. `'amount' => 250`.

- [ ] **Step 4: Run the migration**

Run: `php artisan migrate:fresh --seed`
Expected: clean.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint
git add database/migrations/2026_09_12_110000_convert_wallet_transactions_to_points.php \
        app/Domain/Membership/Models/WalletTransaction.php \
        database/factories/WalletTransactionFactory.php
git commit -m "feat(points): store wallet transaction amounts as signed integer points

amount stays signed (credit positive, debit negative); point_rate_usd
joins the audit columns recording what was actually paid.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 7: Convert `WalletService` to integer points

The debit-resolution algorithm — signature grouping, soonest-expiry ordering, all-or-nothing category fallback — is **unchanged**. Only the unit changes.

**Files:**
- Modify: `app/Domain/Membership/Services/WalletService.php`
- Modify: `app/Domain/Membership/Exceptions/InsufficientBalanceException.php` (message only)
- Create: `tests/Guards/WalletBalanceIgnoresAuditColumnsTest.php`

**Interfaces:**
- Produces:
  - `creditGeneral(Wallet, int $points, WalletTransactionSource, ?string): WalletTransaction`
  - `creditCategorized(Wallet, WalletTransactionCategory, int $points, WalletTransactionSource, DateTimeInterface, ?int, array, ?string): WalletTransaction`
  - `debitGeneral(Wallet, int $points, ?string): WalletTransaction`
  - `debit(Wallet, User, WalletTransactionCategory, int $points, ?string): list<WalletTransaction>`
  - `spendOptions(User, WalletTransactionCategory): array` — balance fields become `int`

- [ ] **Step 1: Write the guard test first**

Create `tests/Guards/WalletBalanceIgnoresAuditColumnsTest.php`:

```php
<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * Invariant 3: payment_amount, payment_currency, exchange_rate_to_base
 * and point_rate_usd record what a member handed over at the till. They
 * are audit history, and a balance must never be computed from them —
 * points revalue when the rate changes, but these columns are frozen at
 * the moment of payment, so mixing them into a sum silently produces a
 * stale balance.
 */
class WalletBalanceIgnoresAuditColumnsTest extends TestCase
{
    use ScansSourceFiles;

    public function test_wallet_service_never_reads_an_audit_column(): void
    {
        $auditColumns = ['payment_amount', 'payment_currency', 'exchange_rate_to_base', 'point_rate_usd'];
        $contents = file_get_contents(app_path('Domain/Membership/Services/WalletService.php'));

        $violations = [];

        foreach ($auditColumns as $column) {
            if (str_contains($contents, $column)) {
                $violations[] = "WalletService references audit column [{$column}]";
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }
}
```

- [ ] **Step 2: Run it — it should already pass**

Run: `php artisan test tests/Guards/WalletBalanceIgnoresAuditColumnsTest.php`
Expected: PASS. It is a regression lock, not a red test — it guards Task 8's edits to the top-up path.

- [ ] **Step 3: Convert `WalletService` to points**

In `app/Domain/Membership/Services/WalletService.php`, apply these mechanical changes throughout:

- Every `bcadd`/`bcsub`/`bccomp` scale argument: `2` → `0`.
- Every `'0.00'` literal → `'0'`.
- `string $amount` parameters → `int $points` on `creditGeneral`, `creditCategorized`, `debitGeneral`, `debit`.
- Inside those methods, convert once at the top: `$amount = (string) $points;` and leave the bcmath body otherwise intact.
- `sumAmounts()` return type `string` → `int`, with `return (int) $total;`.
- `eligibleBalance()` and `lockedGeneralBalance()` return `int`.
- In `buildSpendOption()`, the three balance values become `int`; the `bccomp(...) > 0` comparisons become `> 0` integer comparisons.
- Update the class docblock: replace "computed on the spot via bcmath" with a note that balances are whole point counts summed with bcmath at scale 0.

⚠️ **Do not replace bcmath with native integer arithmetic.** The surrounding code and guards assume bcmath strings; a mixed regime is where sign and comparison bugs hide. Keep bcmath at scale 0 and cast once at the boundary.

- [ ] **Step 4: Update the exception message**

In `debitGeneral()`, change the message to points wording:

```php
throw new InsufficientBalanceException(sprintf(
    'Wallet %d has insufficient general balance: needs %d points, has %d (short %d).',
    $wallet->id,
    $points,
    $balance,
    $points - $balance
));
```

- [ ] **Step 5: Run the whole wallet suite**

Run: `php artisan test tests/Feature/Membership`
Expected: the existing wallet tests are the regression proof that the algorithm survived the unit change. Update each test's **amounts** from decimal strings to integer points (×10), but **do not change what any test asserts about behaviour** — signature grouping, expiry, the all-or-nothing fallback, and concurrent-debit locking must all still be asserted exactly as before.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add app/Domain/Membership/ tests/
git commit -m "feat(points): convert WalletService to integer point balances

Scale drops from 2 to 0 and signatures take int points. The debit
resolution algorithm - signature grouping, expiry ordering, the
all-or-nothing category fallback - is unchanged.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 8: Point-based top-up, atomic and deduplicated

Fixes the two defects found in the 2026-09-12 review, in the code this task rewrites anyway.

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Admin/Reception/WalletTopUpController.php`
- Modify: `app/Http/Requests/Admin/Reception/StoreWalletTopUpRequest.php`
- Modify: the five booking services (remove `TODO(spec-C)` scaffolding)
- Modify: `routes/api/v1/admin.php`
- Test: `tests/Feature/Booking/WalletTopUpControllerTest.php`

**Interfaces:**
- Consumes: `PointConversionService`, `WalletService`
- Produces: `POST admin/reception/wallet-top-ups/preview`; response gains `points_credited`, `previous_balance_points`, `new_balance_points`, `point_rate_usd`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Booking/WalletTopUpControllerTest.php`:

```php
public function test_a_cash_top_up_credits_points_and_records_what_was_paid(): void
{
    ExchangeRate::factory()->create([
        'currency_code' => 'SYP',
        'rate_to_base' => '0.0000680272',
    ]);

    $member = User::factory()->create();
    $member->assignRole('member');
    Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

    $operator = User::factory()->create();
    $operator->assignRole('admin');
    Sanctum::actingAs($operator, ['dashboard']);

    $response = $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
        'user_id' => $member->id,
        'amount' => '500000',
        'currency' => 'SYP',
        'payment_method' => 'cash',
    ]);

    // 500,000 SYP -> $34.01 -> /0.10 -> 340.1 -> floor -> 340
    $response->assertCreated()
        ->assertJsonPath('data.points_credited', 340)
        ->assertJsonPath('data.payment_currency', 'SYP')
        ->assertJsonPath('data.point_rate_usd', '0.1000');

    $this->assertDatabaseHas('wallet_transactions', [
        'amount' => 340,
        'payment_currency' => 'SYP',
        'payment_amount' => '500000.00',
        'performed_by_user_id' => $operator->id,
    ]);
}

public function test_a_payment_too_small_to_buy_one_point_is_rejected(): void
{
    $member = User::factory()->create();
    $member->assignRole('member');
    Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

    $operator = User::factory()->create();
    $operator->assignRole('admin');
    Sanctum::actingAs($operator, ['dashboard']);

    $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
        'user_id' => $member->id,
        'amount' => '0.05',
        'payment_method' => 'cash',
    ])->assertJsonValidationErrors('amount');
}
```

Add the needed `use` statements at the top of the file.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/Booking/WalletTopUpControllerTest.php`
Expected: FAIL — `points_credited` missing.

- [ ] **Step 3: Reject a payment that buys zero points**

In `app/Http/Requests/Admin/Reception/StoreWalletTopUpRequest.php`, inside the existing `withValidator()` `after` closure, append:

```php
$amount = $this->input('amount');

if ($amount !== null && ! $validator->errors()->has('amount') && ! $validator->errors()->has('currency')) {
    $points = app(\App\Domain\Finance\Services\PointConversionService::class);
    $conversion = app(\App\Domain\Finance\Services\CurrencyConversionService::class);

    $base = $conversion->baseCurrencyCode();
    $currency = $this->input('currency') ?? $base;

    $usd = $currency === $base
        ? (string) $amount
        : (string) $conversion->convert((float) $amount, $currency, $base);

    if ($points->usdToPoints($usd) < 1) {
        $validator->errors()->add('amount', __('api.wallet.amount_below_one_point'));
    }
}
```

Add `api.wallet.amount_below_one_point` to `lang/en/api.php` and `lang/ar/api.php`:

```php
// en
'amount_below_one_point' => 'The amount paid is too small to credit a single point.',
// ar
'amount_below_one_point' => 'المبلغ المدفوع أصغر من أن يُضاف كنقطة واحدة.',
```

- [ ] **Step 4: Rewrite the controller's `store()`**

Replace the body of `store()` in `WalletTopUpController.php`:

```php
public function store(
    StoreWalletTopUpRequest $request,
    WalletService $wallets,
    PointConversionService $points,
    CurrencyConversionService $conversion,
): JsonResponse {
    [$ownerType, $ownerId] = $request->validated('company_id')
        ? [OwnerType::Company, (int) $request->validated('company_id')]
        : [OwnerType::User, (int) $request->validated('user_id')];

    $wallet = $wallets->walletFor($ownerType, $ownerId);

    $baseCurrency = $conversion->baseCurrencyCode();
    $paymentCurrency = $request->validated('currency') ?? $baseCurrency;
    $paymentAmount = (string) $request->validated('amount');

    $exchangeRate = null;

    if ($paymentCurrency === $baseCurrency) {
        $usd = $paymentAmount;
    } else {
        $rateModel = ExchangeRate::current($paymentCurrency);
        $exchangeRate = (string) $rateModel->rate_to_base;
        $usd = number_format((float) $paymentAmount * (float) $exchangeRate, 2, '.', '');
    }

    // Credit direction: floor. The member is never credited more value
    // than the cash actually covers (Invariant 2).
    $pointsCredited = $points->usdToPoints($usd);
    $pointRate = $points->rate();

    $previousBalancePoints = $wallets->generalBalance($wallet);

    // One transaction: a credited balance must never exist without the
    // audit trail saying who took the money, in what currency, at what
    // rate. Previously these were two writes with no transaction.
    $transaction = DB::transaction(function () use (
        $wallets, $wallet, $pointsCredited, $request,
        $paymentCurrency, $paymentAmount, $exchangeRate, $pointRate
    ) {
        $transaction = $wallets->creditGeneral(
            $wallet,
            $pointsCredited,
            WalletTransactionSource::TopUp,
            $request->validated('description')
        );

        $transaction->forceFill([
            'performed_by_user_id' => $request->user()->id,
            'payment_method' => PaymentMethod::from($request->validated('payment_method')),
            'payment_currency' => $paymentCurrency,
            'payment_amount' => $paymentAmount,
            'exchange_rate_to_base' => $exchangeRate,
            'point_rate_usd' => $pointRate,
        ])->save();

        return $transaction;
    });

    $newBalancePoints = $previousBalancePoints + $pointsCredited;

    $this->logSensitiveAction('wallet_top_up', $transaction, [
        'owner_type' => $ownerType->value,
        'owner_id' => $ownerId,
        'points_credited' => $pointsCredited,
        'payment_amount' => $paymentAmount,
        'payment_currency' => $paymentCurrency,
        'payment_method' => $request->validated('payment_method'),
        'point_rate_usd' => $pointRate,
    ]);

    $owner = $ownerType === OwnerType::Company
        ? Company::find($ownerId)
        : User::find($ownerId);

    return response()->json([
        'data' => [
            'id' => $transaction->id,
            'transaction_code' => sprintf('TOP-%s-%04d', now()->format('Ymd'), $transaction->id),
            'points_credited' => $pointsCredited,
            'previous_balance_points' => $previousBalancePoints,
            'new_balance_points' => $newBalancePoints,
            'point_rate_usd' => (string) $transaction->point_rate_usd,
            'payment_amount' => $paymentAmount,
            'payment_currency' => $paymentCurrency,
            'exchange_rate' => $exchangeRate,
            'source' => $transaction->source->value,
            'payment_method' => $transaction->payment_method->value,
            'description' => $transaction->description,
            'performed_by_user_id' => $transaction->performed_by_user_id,
            'performed_by_name' => $request->user()->name,
            'owner_type' => $ownerType->value,
            'owner_id' => $ownerId,
            'recipient_name' => $ownerType === OwnerType::Company ? $owner?->legal_name : $owner?->name,
            'recipient_phone' => $ownerType === OwnerType::Company ? $owner?->contract_ref : $owner?->phone,
            'created_at' => $transaction->created_at?->toISOString() ?? now()->toISOString(),
        ],
    ], 201);
}
```

Add the `DB`, `PointConversionService`, and `CurrencyConversionService` imports.

- [ ] **Step 5: Delete the duplicated balance method**

Remove the private `calculateGeneralBalance()` from the controller entirely. It duplicated `WalletService`'s balance logic, which is actively dangerous now that the unit has changed.

Add its replacement to `WalletService` as a public method (it already has the private `lockedGeneralBalance`; this is the unlocked read):

```php
/**
 * Unlocked read of the general (never-expiring) point balance — for
 * display and receipts. Debits use lockedGeneralBalance() instead.
 */
public function generalBalance(Wallet $wallet): int
{
    $transactions = WalletTransaction::query()
        ->where('wallet_id', $wallet->id)
        ->where('category', WalletTransactionCategory::General)
        ->where(function ($query) {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        })
        ->get();

    return $this->sumAmounts($transactions);
}
```

Update the controller's `balance()` method to call `$wallets->generalBalance($wallet)` and to report points plus a derived currency figure:

```php
'balance_points' => $generalBalancePoints,
'balance_usd' => $points->pointsToUsd($generalBalancePoints),
'balance_display' => $points->pointsToCurrency($generalBalancePoints, $requestedCurrency ?? $baseCurrency),
'display_currency' => $requestedCurrency ?? $baseCurrency,
```

- [ ] **Step 6: Add the preview endpoint**

The dashboard must not duplicate the floor/rate logic in TypeScript (Invariant 4). Add to the controller:

```php
/**
 * What a given cash amount would credit, without writing anything.
 * Exists so the top-up dialog can show a live point preview without
 * reimplementing the rounding rules in TypeScript.
 */
public function preview(
    StoreWalletTopUpRequest $request,
    PointConversionService $points,
    CurrencyConversionService $conversion,
): JsonResponse {
    $baseCurrency = $conversion->baseCurrencyCode();
    $paymentCurrency = $request->validated('currency') ?? $baseCurrency;
    $paymentAmount = (string) $request->validated('amount');

    $usd = $paymentCurrency === $baseCurrency
        ? $paymentAmount
        : number_format((float) $paymentAmount * (float) ExchangeRate::current($paymentCurrency)->rate_to_base, 2, '.', '');

    return response()->json([
        'data' => [
            'points' => $points->usdToPoints($usd),
            'usd' => $usd,
            'payment_amount' => $paymentAmount,
            'payment_currency' => $paymentCurrency,
            'point_rate_usd' => $points->rate(),
        ],
    ]);
}
```

Register it in `routes/api/v1/admin.php` beside the existing top-up route:

```php
Route::post('reception/wallet-top-ups/preview', [WalletTopUpController::class, 'preview']);
```

⚠️ Place this **before** any `apiResource`/wildcard route for wallet-top-ups so `preview` is not swallowed as an `{id}` parameter.

- [ ] **Step 7: Remove the spec-B scaffolding**

Run: `grep -rn "TODO(spec-C)" app/`

In each of the five booking services, delete the `pointsToUsd()` conversion line and the TODO comment, and pass the integer `$points` straight to `WalletService`, which now takes ints. Also update the `WalletChoiceRequiredException` / `spendOptions` comparisons to integer comparisons.

Verify: `grep -rn "TODO(spec-C)" app/` returns nothing.

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS. Fix any remaining test that passes a decimal amount into the wallet or asserts a decimal balance.

- [ ] **Step 9: Commit**

```bash
./vendor/bin/pint
git add app/ routes/ lang/ tests/
git commit -m "feat(points): credit points on top-up, atomically

Receptionist enters cash taken; the system converts to points (floored)
and records the currency, FX rate and point rate that applied. The
credit and its audit fields now share one transaction, and the
controller's duplicated balance helper is replaced by
WalletService::generalBalance(). Adds a preview endpoint so the
dashboard need not reimplement rounding.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 9: Backend verification sweep

**Files:** none created — this is a verification gate before the frontend.

- [ ] **Step 1: Confirm no currency pricing columns survive**

Run:

```bash
grep -rn "pricing_currency\|hourly_rate'\|'price'\|overage_rate'" app/ database/ --include=*.php | grep -v "_points"
```

Expected: no hits outside migration `down()` methods.

- [ ] **Step 2: Confirm conversion is centralized**

Run: `grep -rn "point.rate_usd" app/`
Expected: exactly one file — `PointConversionService.php`.

- [ ] **Step 3: Run the full suite and style check**

Run: `php artisan test && ./vendor/bin/pint --test`
Expected: all green, no style diffs.

- [ ] **Step 4: Verify a clean install works**

Run: `php artisan migrate:fresh --seed`
Expected: clean, with plans priced in points.

- [ ] **Step 5: Smoke-test the API by hand**

Start `php artisan serve`, then:

```bash
curl -s localhost:8000/api/v1/plans | head -40
curl -s -H "currency: SYP" localhost:8000/api/v1/plans | head -40
```

Expected: `price_points` identical in both; `price_display`/`currency` differ.

- [ ] **Step 6: Commit any fixes**

```bash
./vendor/bin/pint
git add -A
git commit -m "chore(points): backend verification sweep

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 10: Frontend point types and formatting

Frontend work starts here. Base directory: `c:/Users/User/Desktop/Aleppo Digital District/ADDOS`.

**Files:**
- Create: `src/add-os/modules/payments/composables/usePoints.ts`
- Modify: `src/add-os/modules/payments/types/wallet-top-up.ts`, `src/add-os/modules/plans/types/plan.ts`
- Modify: `src/lang/ar.json`, `src/lang/en.json` (or the repo's equivalent locale files)

**Interfaces:**
- Produces: `usePoints()` returning `formatPoints(n: number): string`

- [ ] **Step 1: Inspect the existing conventions first**

Read `src/add-os/modules/payments/types/wallet-top-up.ts` and one existing composable to match the file's own import style, naming, and i18n access pattern. Follow what is there rather than introducing a new style.

- [ ] **Step 2: Update the TypeScript types**

In `plan.ts`, replace the price fields with:

```ts
price_points: number
price_usd: string
price_display: string
currency: string
overage_rate_points: number | null
```

In `wallet-top-up.ts`, add:

```ts
points_credited: number
previous_balance_points: number
new_balance_points: number
point_rate_usd: string
```

- [ ] **Step 3: Add the formatting composable**

Create `src/add-os/modules/payments/composables/usePoints.ts`:

```ts
import { useI18n } from 'vue-i18n'

/**
 * Formats point counts for display. Deliberately contains no conversion
 * arithmetic: the point rate lives on the server, and duplicating the
 * rounding rules here would drift the moment an admin changes the rate.
 * Ask the backend for converted values instead.
 */
export function usePoints() {
  const { t, locale } = useI18n()

  function formatPoints(points: number): string {
    const formatted = new Intl.NumberFormat(locale.value).format(points)

    return `${formatted} ${t('points.unit')}`
  }

  return { formatPoints }
}
```

- [ ] **Step 4: Add the translation keys**

English:

```json
"points": { "unit": "points", "label": "Points", "rate": "Point rate" }
```

Arabic:

```json
"points": { "unit": "نقطة", "label": "النقاط", "rate": "سعر النقطة" }
```

- [ ] **Step 5: Verify the build**

Run: `npm run build` (or the repo's typecheck script)
Expected: no type errors.

- [ ] **Step 6: Commit**

```bash
git add src/
git commit -m "feat(points): add point types and formatting composable

Formatting only - no conversion arithmetic on the client, since the
rate lives on the server.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 11: Point-aware top-up dialog

**Files:**
- Modify: `src/add-os/modules/members/components/WalletTopUpDialog.vue`
- Modify: the payments API client module (wherever `wallet-top-ups` is called)

- [ ] **Step 1: Add the preview API call**

In the payments API client, add:

```ts
export function previewWalletTopUp(payload: {
  user_id?: number
  company_id?: number
  amount: string
  currency?: string
  payment_method: string
}) {
  return api.post('/admin/reception/wallet-top-ups/preview', payload)
}
```

- [ ] **Step 2: Show a live point preview**

In `WalletTopUpDialog.vue`, debounce changes to the amount/currency fields (~300 ms) and call `previewWalletTopUp`, displaying the returned `points`:

> `≈ 340 نقطة`

⚠️ **Do not compute this in TypeScript.** The rounding and rate rules exist once, on the server (Invariant 4).

Handle the in-flight state so the preview never shows a stale number against a newly typed amount.

- [ ] **Step 3: Show the result after submitting**

On success, display `points_credited` and `new_balance_points` from the response in the confirmation, alongside the cash amount paid.

- [ ] **Step 4: Verify manually**

Run the dashboard against the local API. Enter `500000` SYP and confirm the preview reads ~340 points and the submitted result matches it.

- [ ] **Step 5: Commit**

```bash
git add src/
git commit -m "feat(points): preview and confirm points in the top-up dialog

Preview comes from the server so rounding rules are never duplicated
client-side.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 12: Points across the dashboard views

**Files:**
- Modify: `src/add-os/modules/payments/views/WalletTopUpsPage.vue`
- Modify: `src/add-os/modules/plans/components/PlanFormDrawer.vue`, `PlanStepper.vue`
- Modify: any space pricing form
- Modify: the settings page

- [ ] **Step 1: Add a points column to the top-ups table**

In `WalletTopUpsPage.vue`, add a `points_credited` column via `formatPoints()`, keeping the existing cash columns — the receipt must show both what was paid and what was credited.

- [ ] **Step 2: Convert the plan form to point input**

In `PlanFormDrawer.vue` and `PlanStepper.vue`, replace the price + currency inputs with a single integer point input bound to `price_points` (and `overage_rate_points`). Set `step="1"` and `min="0"`, and show the server-returned `price_usd` beneath as a read-only hint.

Remove the currency selector from these forms — price no longer carries a currency.

- [ ] **Step 3: Convert the space pricing form**

Same treatment for `hourly_rate_points`.

- [ ] **Step 4: Surface the point rate in settings**

Add `point.rate_usd` to the settings page with the `points.rate` label and a visible warning that changing it reprices the entire catalogue and revalues every wallet balance.

- [ ] **Step 5: Show balances as points first**

Wherever a wallet balance appears, show points as the primary figure with the currency equivalent secondary:

> `340 نقطة (≈ 24,990 ل.س)`

- [ ] **Step 6: Verify and commit**

Run: `npm run build`

```bash
git add src/
git commit -m "feat(points): price plans and spaces in points across the dashboard

Balances lead with points and show the currency equivalent second; the
point rate is editable in settings with a repricing warning.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Task 13: Frontend tests and final verification

**Files:**
- Modify: `src/add-os/modules/payments/views/__tests__/WalletTopUpsPage.spec.ts`
- Create: `src/add-os/modules/payments/composables/__tests__/usePoints.spec.ts`

- [ ] **Step 1: Test the composable**

Create `usePoints.spec.ts` asserting `formatPoints(340)` renders `340` plus the unit label, and that a large number is thousands-separated.

- [ ] **Step 2: Update the existing page specs**

Update `WalletTopUpsPage.spec.ts` and any plan specs to the new field names (`price_points`, `points_credited`). Keep every behavioural assertion intact — change field names and units only.

- [ ] **Step 3: Run the frontend suite**

Run: `npm run test`
Expected: PASS.

- [ ] **Step 4: Full-stack smoke test**

With the API running and the dashboard built:

1. Log in as an operations user.
2. Open Settings, confirm the point rate reads `0.10`.
3. Open Plans, confirm prices show as points with a USD hint.
4. Perform a top-up of 500,000 SYP; confirm ~340 points credited.
5. Change the point rate to `0.12`; reload Plans and confirm the USD hint rose while the point price held.
6. **Set the rate back to `0.10`.**

- [ ] **Step 5: Commit**

```bash
git add src/
git commit -m "test(points): cover point formatting and updated page specs

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Completion Criteria

- [ ] `php artisan test` passes in full
- [ ] `./vendor/bin/pint --test` reports no diffs
- [ ] `grep -rn "point.rate_usd" app/` returns only `PointConversionService.php`
- [ ] `grep -rn "TODO(spec-C)" app/` returns nothing
- [ ] No `pricing_currency` column remains on `plans` or `spaces`
- [ ] `php artisan migrate:fresh --seed` runs clean
- [ ] `npm run build` and `npm run test` pass in ADDOS
- [ ] The full-stack smoke test in Task 13 Step 4 passes, with the rate restored to `0.10`

## Deferred (explicitly not in this plan)

- Member-facing point purchase — top-up stays a reception action
- Point transfer between members
- Promotional/bonus point rules ("top up 1000, get 50 free")
- Changes to wallet categories, expiry, or restriction logic — unit-agnostic and untouched
