# Wallet Top-up: Points-First Entry — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reception tops up a wallet by typing points (default) or cash; the dashboard converts between them instantly, the server verifies every top-up exactly, and a configurable minimum (default 10 points) applies.

**Architecture:** ADDCore gets an exact cash→points conversion (bcmath, nothing rounded before the floor), a `cash_step` per currency, a `wallet.top_up_min_points` setting, a required `points` field on top-up that must equal what `amount` buys, and a `top_up` config block on `wallet-balance`; the `preview` endpoint is deleted. ADDOS gets a BigInt mirror of the arithmetic (`top-up-math.ts`), one `useTopUpQuote` composable shared by the top-up dialog and the top-up page, and a cash-step field on the Currencies page.

**Tech Stack:** Laravel 12, PHPUnit, bcmath (ADDCore). Vue 3 `<script setup>`, Naive UI, vue-i18n, Vitest, pnpm (ADDOS).

**Spec:** `docs/superpowers/specs/2026-10-10-wallet-top-up-points-entry-design.md` (ADDCore). Read it before starting; it carries the reasoning this plan does not repeat.

## Where each task runs

| Tasks | Repo | Directory | Branch |
|---|---|---|---|
| 1–5, 12 | ADDCore | `C:\Users\User\Desktop\Aleppo Digital District\ADDCore\.claude\worktrees\wallet-charging-mechanism-182b3e` | `claude/wallet-charging-mechanism-182b3e` |
| 6–11 | ADDOS | `C:\Users\User\Desktop\Aleppo Digital District\ADDOS-top-up-points-entry` (created in Task 6) | `feat/top-up-points-entry`, off `feat/add-point-frontend` |

The main ADDOS checkout (`...\ADDOS`) has unrelated uncommitted work on `feat/add-point-frontend`. **Never edit, stash or commit there.** All ADDOS work happens in the new worktree.

Backend commands below run from the ADDCore worktree; frontend commands from the ADDOS worktree. Never read inside `vendor/` or `node_modules/`.

## Global Constraints

- No floats anywhere in point/cash conversion: PHP uses bcmath on strings, TypeScript uses BigInt (Invariant 5).
- Credit rule: `points = floor(amount × rate_to_base / point.rate_usd)`, exact, floored once, decided by the server. Nothing rounds before the floor.
- `POST admin/reception/wallet-top-ups`: `points` required, integer, `≥ wallet.top_up_min_points`; `amount` `numeric|min:0.01|decimal:0,2`; `points` must equal the server's floor or 422 on `points` with `__('api.wallet.quote_stale')`.
- Setting `wallet.top_up_min_points`: Int, default **10**, validated `integer|min:1`, read with fallback 10.
- `currencies.cash_step`: `decimal(14,2)`, default `1.00` for every row, validated `sometimes|numeric|gt:0|decimal:0,2`.
- Amount mode in the dashboard accepts only multiples of the currency's `cash_step`.
- After any 422 the dashboard re-reads the balance (fresh rates) and **never resubmits on its own**.
- New ADDCore strings go in `lang/en/api.php` **and** `lang/ar/api.php`, `wallet` group. New ADDOS strings go in `src/add-os/lang/en/en.json` **and** `src/add-os/lang/ar/ar.json`.
- PHP style: Laravel Pint defaults. ADDOS: the repo's eslint config (tabs, double quotes, no semicolons).
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Arabic-Indic digits** (`٣٤٠`, `٥٠٠٠`, decimal mark `٫`) typed on an Arabic keyboard at reception must quote exactly like `340` / `5000`. → `toAsciiDigits` test in Task 6, composable test in Task 7.
2. **A rate changes while the form is open** → server 422 on `points`, the form re-reads rates and shows the new figures, no automatic resubmit. → Task 4 backend test, Task 8 dialog test, Task 9 page test.
3. **Selected currency has no rate, or the config carries a malformed/zero rate or step** → no crash, a clear message, nothing submittable. → Task 7 tests `currencyUnavailable` and "does not break".
4. **Currency switched after typing points** → the same points are re-quoted in the new currency. → Task 7 test.
5. **Very large counts** (10,000,000 points) stay exact. → Task 6 test.

---

## Task 1: Exact cash → points conversion (ADDCore)

**Files:**
- Modify: `app/Domain/Finance/Services/CurrencyConversionService.php` (add a method after `convert()`)
- Modify: `app/Domain/Finance/Services/PointConversionService.php` (add a method after `usdToPointsCeil()`)
- Test: `tests/Unit/Domain/Finance/CurrencyConversionServiceTest.php`, `tests/Unit/Finance/PointConversionServiceTest.php`

**Interfaces:**
- Produces: `CurrencyConversionService::toBaseExact(string $amount, string $fromCurrency): ?string` — exact base-currency value, null when the currency has no current rate.
- Produces: `PointConversionService::currencyToPoints(string $amount, string $currencyCode): ?int` — floored points, null when no rate.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Domain/Finance/CurrencyConversionServiceTest.php` (inside the class):

```php
    /**
     * convert() rounds to cents; toBaseExact() must not. 501,200 SYP is
     * $34.09523264 exactly — convert() reports 34.10, which floors to one
     * point more than was paid for.
     */
    public function test_to_base_exact_keeps_every_digit(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);

        $usd = (new CurrencyConversionService)->toBaseExact('501200', 'SYP');

        $this->assertSame(0, bccomp('34.09523264', $usd, 20));
    }

    public function test_to_base_exact_passes_the_base_currency_through_untouched(): void
    {
        $this->assertSame('12.50', (new CurrencyConversionService)->toBaseExact('12.50', 'USD'));
    }

    public function test_to_base_exact_is_null_without_an_exchange_rate(): void
    {
        $this->assertNull((new CurrencyConversionService)->toBaseExact('5000', 'SYP'));
    }
```

Append to `tests/Unit/Finance/PointConversionServiceTest.php` (inside the class; `ExchangeRate` is already imported):

```php
    /**
     * The same vectors the dashboard's top-up-math.spec.ts asserts, so the
     * two implementations are proven to agree (spec 2026-10-10 §6).
     * Each new rate row is newer than the last (higher id, same
     * effective_from), and saving one clears ExchangeRate::current()'s cache.
     */
    public function test_currency_to_points_floors_the_exact_value_once(): void
    {
        $vectors = [
            // [amount, currency, rate_to_base (null = base currency), expected points]
            ['500000', 'SYP', '0.0000680272', 340],
            ['501200', 'SYP', '0.0000680272', 340], // the old path credited 341
            ['150000.00', 'SYP', '0.0000666667', 100],
            ['5000', 'SYP', '0.0068027211', 340],   // new pound
            ['0.30', 'USD', null, 3],               // float division gives 2
            ['10.05', 'USD', null, 100],
        ];

        foreach ($vectors as [$amount, $currency, $rate, $expected]) {
            if ($rate !== null) {
                ExchangeRate::factory()->create(['currency_code' => $currency, 'rate_to_base' => $rate]);
            }

            $this->assertSame($expected, $this->points->currencyToPoints($amount, $currency), "{$amount} {$currency}");
        }
    }

    public function test_currency_to_points_is_null_without_an_exchange_rate(): void
    {
        $this->assertNull($this->points->currencyToPoints('5000', 'SYP'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter="CurrencyConversionServiceTest|PointConversionServiceTest"`
Expected: the five new tests FAIL with `Call to undefined method ...toBaseExact()` / `...currencyToPoints()`; existing tests pass.

- [ ] **Step 3: Implement**

In `app/Domain/Finance/Services/CurrencyConversionService.php`, add after `convert()`:

```php
    /**
     * The exact base-currency value of an amount, as a bcmath string — no
     * rounding at all. For the top-up credit path, where the result is
     * floored into points: rounding to cents first, as convert() does, can
     * lift it across a point boundary and credit more than was paid.
     * Null when the currency has no current rate, matching convert().
     */
    public function toBaseExact(string $amount, string $fromCurrency): ?string
    {
        if ($fromCurrency === $this->baseCurrencyCode()) {
            return $amount;
        }

        $rate = ExchangeRate::current($fromCurrency);

        if ($rate === null) {
            return null;
        }

        return bcmul($amount, (string) $rate->rate_to_base, 20);
    }
```

In `app/Domain/Finance/Services/PointConversionService.php`, add after `usdToPointsCeil()`:

```php
    /**
     * Points a cash payment buys — credit direction. Floors once, on the
     * exact base-currency value (Invariant 2); nothing is rounded before the
     * floor. Null when the currency has no current exchange rate.
     */
    public function currencyToPoints(string $amount, string $currencyCode): ?int
    {
        $usd = $this->currency->toBaseExact($amount, $currencyCode);

        return $usd === null ? null : $this->usdToPoints($usd);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter="CurrencyConversionServiceTest|PointConversionServiceTest"`
Expected: all PASS.

Run: `php artisan test tests/Guards`
Expected: no new failures (the guards scan `app/` for `point.rate_usd` and float math on `rate()`; neither appears in the new code).

- [ ] **Step 5: Style and commit**

```bash
./vendor/bin/pint app/Domain/Finance/Services tests/Unit/Domain/Finance/CurrencyConversionServiceTest.php tests/Unit/Finance/PointConversionServiceTest.php
git add app/Domain/Finance/Services tests/Unit/Domain/Finance/CurrencyConversionServiceTest.php tests/Unit/Finance/PointConversionServiceTest.php
git commit -m "feat(points): exact cash-to-points conversion with no rounding before the floor

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 2: Per-currency cash step (ADDCore)

**Files:**
- Create: `database/migrations/2026_10_10_120000_add_cash_step_to_currencies_table.php`
- Modify: `app/Domain/Finance/Models/Currency.php`
- Modify: `app/Http/Requests/Admin/StoreCurrencyRequest.php`, `app/Http/Requests/Admin/UpdateCurrencyRequest.php`
- Modify: `app/Http/Resources/AdminCurrencyResource.php`
- Test: `tests/Feature/Admin/CurrencyControllerTest.php`

**Interfaces:**
- Produces: column `currencies.cash_step` (`decimal(14,2)`, default `1.00`); `Currency::$cash_step` cast `decimal:2` (a string such as `"1.00"`); `cash_step` in the admin currency API (request and response). Task 5 reads `$currency->cash_step`.

`CurrencySeeder` is deliberately **not** changed: it is an idempotent `updateOrCreate`, and listing `cash_step` there would reset an admin-edited step every time it re-runs.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Admin/CurrencyControllerTest.php`:

```php
    public function test_a_new_currency_defaults_to_a_cash_step_of_one(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/currencies', [
            'code' => 'EUR',
            'name' => ['en' => 'Euro', 'ar' => 'يورو'],
            'decimal_places' => 2,
        ])->assertCreated()->assertJsonPath('data.cash_step', '1.00');

        $this->assertSame('1.00', Currency::find('EUR')->cash_step);
    }

    public function test_an_admin_can_set_a_currencys_cash_step(): void
    {
        $this->actingAsAdmin();
        $currency = Currency::factory()->create(['code' => 'EUR']);

        $this->patchJson("/api/v1/admin/currencies/{$currency->code}", [
            'name' => ['en' => 'Euro', 'ar' => 'يورو'],
            'decimal_places' => 2,
            'cash_step' => '0.50',
        ])->assertOk();

        $this->assertSame('0.50', $currency->fresh()->cash_step);
        $this->getJson("/api/v1/admin/currencies/{$currency->code}")->assertJsonPath('data.cash_step', '0.50');
    }

    public function test_a_cash_step_must_be_positive_with_at_most_two_decimals(): void
    {
        $this->actingAsAdmin();
        $currency = Currency::factory()->create(['code' => 'EUR']);

        foreach (['0', '-1', '0.005', 'abc'] as $step) {
            $this->patchJson("/api/v1/admin/currencies/{$currency->code}", [
                'name' => ['en' => 'Euro', 'ar' => 'يورو'],
                'decimal_places' => 2,
                'cash_step' => $step,
            ])->assertJsonValidationErrors('cash_step');
        }
    }

    public function test_existing_currencies_get_a_cash_step_of_one(): void
    {
        $this->assertSame('1.00', Currency::find('SYP')->cash_step);
        $this->assertSame('1.00', Currency::find('USD')->cash_step);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter=CurrencyControllerTest`
Expected: the four new tests FAIL (no `cash_step` column / key).

- [ ] **Step 3: Implement**

Create `database/migrations/2026_10_10_120000_add_cash_step_to_currencies_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The smallest amount the reception till takes in this currency. The
     * dashboard rounds a points-first top-up up to a multiple of it and
     * accepts only multiples of it when cash is typed
     * (docs/superpowers/specs/2026-10-10-wallet-top-up-points-entry-design.md).
     * Every row starts at 1: SYP is the new pound, and the till already
     * takes whole pounds only.
     */
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->decimal('cash_step', 14, 2)->default(1)->after('decimal_places');
        });
    }

    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('cash_step');
        });
    }
};
```

In `app/Domain/Finance/Models/Currency.php`:
- add `'cash_step',` to `$fillable` right after `'decimal_places',`
- add `'cash_step' => 'decimal:2',` to `casts()` right after `'decimal_places' => 'integer',`
- add this property after `protected $keyType = 'string';`:

```php
    /**
     * A currency created without a step serializes the same "1.00" the
     * column default stores, instead of null until it is re-read.
     */
    protected $attributes = [
        'cash_step' => '1.00',
    ];
```

In both `StoreCurrencyRequest::rules()` and `UpdateCurrencyRequest::rules()`, add after the `decimal_places` line:

```php
                'cash_step' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,2'],
```

In `AdminCurrencyResource::toArray()`, add after `'decimal_places' => $this->decimal_places,`:

```php
            'cash_step' => $this->cash_step,
```

- [ ] **Step 4: Run to verify they pass**

Run: `php artisan test --filter="CurrencyControllerTest|CurrencyBaseControllerTest"`
Expected: all PASS.

- [ ] **Step 5: Style and commit**

```bash
./vendor/bin/pint database/migrations/2026_10_10_120000_add_cash_step_to_currencies_table.php app/Domain/Finance/Models/Currency.php app/Http/Requests/Admin/StoreCurrencyRequest.php app/Http/Requests/Admin/UpdateCurrencyRequest.php app/Http/Resources/AdminCurrencyResource.php tests/Feature/Admin/CurrencyControllerTest.php
git add database/migrations/2026_10_10_120000_add_cash_step_to_currencies_table.php app/Domain/Finance/Models/Currency.php app/Http/Requests/Admin/StoreCurrencyRequest.php app/Http/Requests/Admin/UpdateCurrencyRequest.php app/Http/Resources/AdminCurrencyResource.php tests/Feature/Admin/CurrencyControllerTest.php
git commit -m "feat(currencies): per-currency cash step for top-up rounding

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 3: Minimum top-up setting (ADDCore)

**Files:**
- Create: `app/Domain/Membership/Services/TopUpMinimum.php`
- Modify: `database/seeders/SettingSeeder.php`
- Modify: `app/Http/Requests/Admin/UpdateSettingRequest.php`
- Test: create `tests/Unit/Membership/TopUpMinimumTest.php`; modify `tests/Feature/Admin/SettingControllerTest.php`

**Interfaces:**
- Produces: `App\Domain\Membership\Services\TopUpMinimum` with `SETTING_KEY = 'wallet.top_up_min_points'`, `DEFAULT_POINTS = 10`, `points(): int`. Tasks 4 and 5 consume `points()`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Membership/TopUpMinimumTest.php`:

```php
<?php

namespace Tests\Unit\Membership;

use App\Domain\Membership\Services\TopUpMinimum;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopUpMinimumTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_defaults_to_ten_points_before_the_setting_exists(): void
    {
        $this->assertSame(10, app(TopUpMinimum::class)->points());
    }

    public function test_it_follows_the_setting(): void
    {
        app(SettingService::class)->set(TopUpMinimum::SETTING_KEY, 25, SettingValueType::Int);

        $this->assertSame(25, app(TopUpMinimum::class)->points());
    }

    /** The settings page can only edit a row that exists, so the seeder must create it. */
    public function test_the_setting_seeder_creates_the_row_the_settings_page_edits(): void
    {
        $this->seed(SettingSeeder::class);

        $this->assertSame(10, app(SettingService::class)->get(TopUpMinimum::SETTING_KEY));
    }
}
```

Append to `tests/Feature/Admin/SettingControllerTest.php` (add `use App\Domain\Membership\Services\TopUpMinimum;` to the imports):

```php
    public function test_the_minimum_top_up_must_be_a_whole_number_of_at_least_one_point(): void
    {
        app(SettingService::class)->set(TopUpMinimum::SETTING_KEY, 10, SettingValueType::Int);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        foreach ([0, -5, 2.5, 'ten'] as $invalid) {
            $this->patchJson('/api/v1/admin/settings/wallet.top_up_min_points', ['value' => $invalid])
                ->assertJsonValidationErrors('value');
        }

        $this->patchJson('/api/v1/admin/settings/wallet.top_up_min_points', ['value' => 25])->assertOk();
        $this->assertSame(25, app(TopUpMinimum::class)->points());
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter="TopUpMinimumTest|SettingControllerTest"`
Expected: FAIL — `Class "App\Domain\Membership\Services\TopUpMinimum" not found`.

- [ ] **Step 3: Implement**

Create `app/Domain/Membership/Services/TopUpMinimum.php`:

```php
<?php

namespace App\Domain\Membership\Services;

use App\Domain\Settings\Services\SettingService;

/**
 * The fewest points one reception top-up may credit
 * (docs/superpowers/specs/2026-10-10-wallet-top-up-points-entry-design.md).
 * Read with a fallback, so a fresh install or a test database that never
 * ran SettingSeeder still enforces the agreed default.
 */
class TopUpMinimum
{
    public const SETTING_KEY = 'wallet.top_up_min_points';

    public const DEFAULT_POINTS = 10;

    public function __construct(private readonly SettingService $settings) {}

    public function points(): int
    {
        return (int) $this->settings->get(self::SETTING_KEY, self::DEFAULT_POINTS);
    }
}
```

In `database/seeders/SettingSeeder.php`, add `use App\Domain\Membership\Services\TopUpMinimum;` and append to `run()` after the `point.rate_usd` line:

```php
        $settings->setDefault(TopUpMinimum::SETTING_KEY, TopUpMinimum::DEFAULT_POINTS, SettingValueType::Int);
```

In `app/Http/Requests/Admin/UpdateSettingRequest.php`, add `use App\Domain\Membership\Services\TopUpMinimum;` and, in `rules()`, after the `PointConversionService::SETTING_KEY` branch:

```php
        if ($this->targetSetting()->key === TopUpMinimum::SETTING_KEY) {
            return ['value' => ['required', 'integer', 'min:1']];
        }
```

- [ ] **Step 4: Run to verify they pass**

Run: `php artisan test --filter="TopUpMinimumTest|SettingControllerTest"`
Expected: all PASS.

- [ ] **Step 5: Style and commit**

```bash
./vendor/bin/pint app/Domain/Membership/Services/TopUpMinimum.php database/seeders/SettingSeeder.php app/Http/Requests/Admin/UpdateSettingRequest.php tests/Unit/Membership/TopUpMinimumTest.php tests/Feature/Admin/SettingControllerTest.php
git add app/Domain/Membership/Services/TopUpMinimum.php database/seeders/SettingSeeder.php app/Http/Requests/Admin/UpdateSettingRequest.php tests/Unit/Membership/TopUpMinimumTest.php tests/Feature/Admin/SettingControllerTest.php
git commit -m "feat(wallet): configurable minimum top-up in points, default 10

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 4: Top-up requires points that match the amount (ADDCore)

**Files:**
- Modify: `app/Http/Requests/Admin/Reception/StoreWalletTopUpRequest.php` (replace the whole file)
- Modify: `app/Http/Controllers/Api/V1/Admin/Reception/WalletTopUpController.php` (class docblock, `store()`; delete `preview()` and `paymentInBaseCurrency()`)
- Modify: `routes/api/v1/admin.php` (delete the preview route and the comment above it)
- Modify: `lang/en/api.php`, `lang/ar/api.php` (`wallet` group)
- Modify: `docs/superpowers/specs/2026-09-12-add-point-c-wallet-design.md` (supersession note)
- Test: `tests/Feature/Booking/WalletTopUpControllerTest.php`

**Interfaces:**
- Consumes: `PointConversionService::currencyToPoints()` (Task 1), `TopUpMinimum::points()` and `TopUpMinimum::SETTING_KEY` (Task 3).
- Produces: request contract `{points:int, amount:string, currency?:string, payment_method, user_id|company_id, description?}`. A mismatch returns 422 with `errors.points[0] === __('api.wallet.quote_stale')`; below the minimum returns `errors.points[0] === __('api.wallet.min_points', ['min' => N])`. `POST .../wallet-top-ups/preview` no longer exists. The 201 body shape is unchanged.

- [ ] **Step 1: Update and add tests**

In `tests/Feature/Booking/WalletTopUpControllerTest.php`, add imports:

```php
use App\Domain\Membership\Services\TopUpMinimum;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
```

Add a `'points' => N,` line to the request payload of every existing test that posts to `wallet-top-ups`:

| Test | Add |
|---|---|
| `test_operations_can_top_up_a_members_wallet` | `'points' => 250,` |
| `test_operations_can_top_up_a_companys_wallet` | `'points' => 500,` |
| `test_top_up_requires_exactly_one_target` (both posts) | `'points' => 100,` |
| `test_top_up_rejects_an_invalid_payment_method` | `'points' => 100,` |
| `test_top_up_rejects_a_non_positive_amount` | `'points' => 10,` |
| `test_top_up_rejects_an_inactive_company` | `'points' => 500,` |
| `test_a_member_cannot_top_up_a_wallet` | `'points' => 100,` |
| `test_operations_can_top_up_in_foreign_currency_with_conversion` | `'points' => 100,` |
| `test_a_cash_top_up_credits_points_and_records_what_was_paid` | `'points' => 340,` |
| `test_a_sub_point_remainder_is_never_reported_as_credited` | `'points' => 100,` |
| `test_a_credited_top_up_always_carries_its_full_audit_trail` | `'points' => 250,` |

In `test_a_cash_top_up_credits_points_and_records_what_was_paid`, replace the comment `// 500,000 SYP -> $34.01 -> /0.10 -> 340.1 -> floor -> 340` with `// 500,000 SYP -> $34.0136 exactly -> /0.10 -> 340.136 -> floor -> 340`.

Delete these three tests: `test_a_payment_too_small_to_buy_one_point_is_rejected`, `test_preview_reports_the_points_a_payment_would_buy_without_writing_anything`, `test_preview_applies_the_same_floor_as_a_real_top_up`.

Append these tests:

```php
    private function memberWithWallet(): User
    {
        $member = User::factory()->create();
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        return $member;
    }

    public function test_a_top_up_below_the_minimum_is_rejected(): void
    {
        $this->actingAsOperations();
        $member = $this->memberWithWallet();

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 9,
            'amount' => '0.90',
            'payment_method' => 'cash',
        ])->assertJsonValidationErrors(['points' => __('api.wallet.min_points', ['min' => 10])]);

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_the_minimum_follows_the_setting(): void
    {
        app(SettingService::class)->set(TopUpMinimum::SETTING_KEY, 25, SettingValueType::Int);
        $this->actingAsOperations();
        $member = $this->memberWithWallet();

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 20,
            'amount' => '2.00',
            'payment_method' => 'cash',
        ])->assertJsonValidationErrors('points');

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 25,
            'amount' => '2.50',
            'payment_method' => 'cash',
        ])->assertCreated();
    }

    /**
     * Too many points would credit value nobody paid for; too few would
     * credit less than the operator promised the member. Both are refused.
     */
    public function test_points_that_do_not_match_the_amount_are_rejected_and_nothing_is_written(): void
    {
        $this->actingAsOperations();
        $member = $this->memberWithWallet();

        foreach ([249, 251] as $points) {
            $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
                'user_id' => $member->id,
                'points' => $points,
                'amount' => '25.00',
                'payment_method' => 'cash',
            ])->assertJsonValidationErrors(['points' => __('api.wallet.quote_stale')]);
        }

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_the_cents_rounding_that_over_credited_is_gone(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272']);
        $this->actingAsOperations();
        $member = $this->memberWithWallet();

        // $34.09523264 exactly. The old path rounded it to 34.10 and credited 341.
        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 341,
            'amount' => '501200',
            'currency' => 'SYP',
            'payment_method' => 'cash',
        ])->assertJsonValidationErrors('points');

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 340,
            'amount' => '501200',
            'currency' => 'SYP',
            'payment_method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.points_credited', 340);
    }

    public function test_a_rate_change_after_the_dashboard_quoted_is_rejected(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272']);
        $this->actingAsOperations();
        $member = $this->memberWithWallet();

        // The dashboard quoted 500,000 SYP as 340 points; then the rate moved
        // so the same cash now buys 350.
        ExchangeRate::factory()->create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000700000',
            'effective_from' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 340,
            'amount' => '500000',
            'currency' => 'SYP',
            'payment_method' => 'cash',
        ])->assertJsonValidationErrors(['points' => __('api.wallet.quote_stale')]);

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_an_amount_with_more_than_two_decimals_is_rejected(): void
    {
        $this->actingAsOperations();
        $member = $this->memberWithWallet();

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'points' => 100,
            'amount' => '10.005',
            'payment_method' => 'cash',
        ])->assertJsonValidationErrors('amount');
    }

    public function test_the_preview_endpoint_no_longer_exists(): void
    {
        $this->actingAsOperations();

        $this->postJson('/api/v1/admin/reception/wallet-top-ups/preview', [])->assertNotFound();
    }
```

- [ ] **Step 2: Run to verify the new tests fail**

Run: `php artisan test --filter=WalletTopUpControllerTest`
Expected: the seven new tests FAIL (no `points` rule, no `min_points`/`quote_stale` keys, preview route still answers). The updated existing tests PASS (the extra `points` field is ignored for now).

- [ ] **Step 3: Implement**

Replace `app/Http/Requests/Admin/Reception/StoreWalletTopUpRequest.php` with:

```php
<?php

namespace App\Http\Requests\Admin\Reception;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Services\TopUpMinimum;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The dashboard converts between points and cash itself and sends both
 * (docs/superpowers/specs/2026-10-10-wallet-top-up-points-entry-design.md
 * §3). This is where that client arithmetic is verified: `points` must be
 * exactly what `amount` buys at today's rates, floored once on the exact
 * value. A mismatch — usually an exchange rate that moved after the form
 * read it — is refused, so the operator is never told one number while the
 * wallet receives another.
 */
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
            'points' => ['required', 'integer', 'min:'.app(TopUpMinimum::class)->points()],
            // decimal:0,2 matches payment_amount's decimal(14,2), so the amount
            // verified below is exactly the amount stored.
            'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'currency' => ['nullable', 'string', 'exists:currencies,code'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'user_id' => ['required_without:company_id', 'prohibits:company_id', 'integer', 'exists:users,id'],
            'company_id' => ['required_without:user_id', 'integer', 'exists:companies,id'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'points.min' => __('api.wallet.min_points', ['min' => app(TopUpMinimum::class)->points()]),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('company_id')) {
                $company = Company::find($this->input('company_id'));
                if ($company && $company->status !== CompanyStatus::Active) {
                    $validator->errors()->add('company_id', 'Cannot top up wallet for an inactive company.');
                }
            }

            if ($this->filled('currency')) {
                $currencyCode = $this->input('currency');
                $baseCode = Currency::query()->where('is_base', true)->value('code') ?? 'USD';
                if ($currencyCode !== $baseCode && ExchangeRate::current($currencyCode) === null) {
                    $validator->errors()->add('currency', "No active exchange rate found for currency {$currencyCode}.");
                }
            }

            if ($validator->errors()->hasAny(['points', 'amount', 'currency'])) {
                return;
            }

            $currency = $this->input('currency') ?? app(CurrencyConversionService::class)->baseCurrencyCode();
            $buys = app(PointConversionService::class)->currencyToPoints((string) $this->input('amount'), $currency);

            if ($buys !== (int) $this->input('points')) {
                $validator->errors()->add('points', __('api.wallet.quote_stale'));
            }
        });
    }
}
```

In `app/Http/Controllers/Api/V1/Admin/Reception/WalletTopUpController.php`:

Replace the second paragraph of the class docblock (starting `The ledger is point-denominated.`) with:

```php
 * The ledger is point-denominated. The dashboard quotes the points and
 * StoreWalletTopUpRequest verifies them against the cash — floored once on
 * the exact value — so store() credits exactly the figure the operator read
 * out. Every currency figure this controller reports is derived back out of
 * the stored point count, never carried over from the payment, so a receipt
 * can never claim a value the wallet does not hold (Invariant 3).
```

In `store()`, replace everything from `$baseCurrency = $conversion->baseCurrencyCode();` down to and including `$pointRate = $points->rate();` with:

```php
        $baseCurrency = $conversion->baseCurrencyCode();
        $paymentCurrency = $request->validated('currency') ?? $baseCurrency;
        $paymentAmount = (string) $request->validated('amount');
        $exchangeRate = $paymentCurrency === $baseCurrency
            ? null
            : (string) ExchangeRate::current($paymentCurrency)->rate_to_base;

        // StoreWalletTopUpRequest has proved this is exactly what the amount
        // buys at today's rates, floored once (Invariant 2), and it is the
        // figure the operator read out to the member.
        $pointsCredited = (int) $request->validated('points');
        $pointRate = $points->rate();
```

Delete the whole `preview()` method with its docblock, and the whole `paymentInBaseCurrency()` method with its docblock.

In `routes/api/v1/admin.php`, delete these four lines:

```php
// Registered before the top-up route itself: should wallet-top-ups ever grow
// a wildcard segment (`{topUp}`), a literal /preview declared after it would
// be swallowed as an id.
Route::post('reception/wallet-top-ups/preview', [WalletTopUpController::class, 'preview']);
```

In `lang/en/api.php`, replace the `wallet` group with:

```php
    'wallet' => [
        'insufficient_balance' => 'Insufficient general balance to allocate this amount.',
        'insufficient_balance_for_plan' => 'Insufficient general balance to purchase this plan.',
        'min_points' => 'A top-up must be at least :min points.',
        'quote_stale' => 'Rates changed since this top-up was prepared. Review the new figures and submit again.',
    ],
```

In `lang/ar/api.php`, replace the `wallet` group with:

```php
    'wallet' => [
        'insufficient_balance' => 'الرصيد العام غير كافٍ لتخصيص هذا المبلغ.',
        'insufficient_balance_for_plan' => 'الرصيد العام غير كافٍ لشراء هذه الباقة.',
        'min_points' => 'يجب ألا يقل الشحن عن :min نقطة.',
        'quote_stale' => 'تغيّرت الأسعار منذ تجهيز هذا الشحن. راجع الأرقام الجديدة ثم أعد الإرسال.',
    ],
```

Confirm nothing else used the removed key: `grep -rn "amount_below_one_point" app lang tests` → no output.

In `docs/superpowers/specs/2026-09-12-add-point-c-wallet-design.md`, directly after the bullet ending `...drift the moment the rate\n  changes.` (the `WalletTopUpDialog.vue` preview bullet), add:

```markdown
  *Superseded 2026-10-10:* the dashboard now converts locally and the
  server verifies every top-up instead; the preview endpoint is gone. See
  [2026-10-10-wallet-top-up-points-entry-design.md](2026-10-10-wallet-top-up-points-entry-design.md).
```

- [ ] **Step 4: Run to verify they pass**

Run: `php artisan test --filter=WalletTopUpControllerTest`
Expected: all PASS.

Run: `php artisan route:list --path=wallet-top-ups`
Expected: only `POST api/v1/admin/reception/wallet-top-ups`.

- [ ] **Step 5: Style and commit**

```bash
./vendor/bin/pint app/Http/Requests/Admin/Reception/StoreWalletTopUpRequest.php app/Http/Controllers/Api/V1/Admin/Reception/WalletTopUpController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Booking/WalletTopUpControllerTest.php
git add app/Http/Requests/Admin/Reception/StoreWalletTopUpRequest.php app/Http/Controllers/Api/V1/Admin/Reception/WalletTopUpController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Booking/WalletTopUpControllerTest.php docs/superpowers/specs/2026-09-12-add-point-c-wallet-design.md
git commit -m "feat(wallet): top-up takes the quoted points and verifies them exactly

The dashboard now sends points alongside the cash; the server re-derives
them from the amount at today's rates and refuses any mismatch. Removes
the cents rounding that could over-credit by a point, and the preview
endpoint the dashboard no longer calls.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 5: `wallet-balance` carries the top-up quote config (ADDCore)

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Admin/Reception/WalletTopUpController.php` (`balance()` and a new private method)
- Test: `tests/Feature/Booking/WalletTopUpControllerTest.php`

**Interfaces:**
- Consumes: `TopUpMinimum::points()` (Task 3), `Currency::$cash_step` (Task 2).
- Produces: `data.top_up` on `GET admin/reception/wallet-balance`:
  `{ point_rate_usd: string, min_points: int, currencies: [{ code: string, rate_to_base: string, cash_step: string, decimal_places: int }] }`. Active currencies only, ordered by `order`; the base currency has `rate_to_base: "1"`; a non-base currency with no current rate is left out. Tasks 7–9 read this shape.

- [ ] **Step 1: Write the failing test**

Add imports to `tests/Feature/Booking/WalletTopUpControllerTest.php` if missing: `use App\Domain\Finance\Models\Currency;`. Append:

```php
    public function test_the_balance_carries_what_the_dashboard_needs_to_quote_a_top_up(): void
    {
        $this->actingAsOperations();
        $member = $this->memberWithWallet();
        app(SettingService::class)->set(TopUpMinimum::SETTING_KEY, 25, SettingValueType::Int);

        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0068027211']);
        Currency::find('SYP')->update(['cash_step' => '10.00']);
        // Active but no rate: cannot be quoted, so it must not be offered.
        Currency::factory()->create(['code' => 'GBP', 'is_base' => false, 'is_active' => true]);
        // Has a rate but is inactive: not offered either.
        Currency::factory()->create(['code' => 'EUR', 'is_base' => false, 'is_active' => false]);
        ExchangeRate::factory()->create(['currency_code' => 'EUR', 'rate_to_base' => '1.0800000000']);

        $response = $this->getJson("/api/v1/admin/reception/wallet-balance?user_id={$member->id}")->assertOk();

        $response->assertJsonPath('data.top_up.point_rate_usd', '0.10')
            ->assertJsonPath('data.top_up.min_points', 25);

        $currencies = collect($response->json('data.top_up.currencies'))->keyBy('code');
        $this->assertEqualsCanonicalizing(['SYP', 'USD'], $currencies->keys()->all());
        $this->assertSame('0.0068027211', $currencies['SYP']['rate_to_base']);
        $this->assertSame('10.00', $currencies['SYP']['cash_step']);
        $this->assertSame(2, $currencies['SYP']['decimal_places']);
        $this->assertSame('1', $currencies['USD']['rate_to_base']);
        $this->assertSame('1.00', $currencies['USD']['cash_step']);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=test_the_balance_carries_what_the_dashboard_needs_to_quote_a_top_up`
Expected: FAIL — `data.top_up` is missing.

- [ ] **Step 3: Implement**

In `WalletTopUpController.php` add imports:

```php
use App\Domain\Finance\Models\Currency;
use App\Domain\Membership\Services\TopUpMinimum;
```

Add `TopUpMinimum $minimum,` as the last parameter of `balance()`, and add this key at the end of the `'data' => [...]` array in `balance()`:

```php
                // Everything the dashboard needs to convert points <-> cash
                // locally; store() verifies whatever it sends.
                'top_up' => $this->topUpQuoteConfig($points, $conversion, $minimum),
```

Add this private method at the end of the class:

```php
    /**
     * Spec 2026-10-10 §4. A non-base currency with no current rate is left
     * out: it cannot be quoted, and store() refuses it anyway.
     *
     * @return array{point_rate_usd: string, min_points: int, currencies: list<array{code: string, rate_to_base: string, cash_step: string, decimal_places: int}>}
     */
    private function topUpQuoteConfig(
        PointConversionService $points,
        CurrencyConversionService $conversion,
        TopUpMinimum $minimum,
    ): array {
        $baseCurrency = $conversion->baseCurrencyCode();
        $currencies = [];

        foreach (Currency::query()->where('is_active', true)->orderBy('order')->get() as $currency) {
            $rate = $currency->code === $baseCurrency
                ? '1'
                : ExchangeRate::current($currency->code)?->rate_to_base;

            if ($rate === null) {
                continue;
            }

            $currencies[] = [
                'code' => $currency->code,
                'rate_to_base' => (string) $rate,
                'cash_step' => (string) $currency->cash_step,
                'decimal_places' => $currency->decimal_places,
            ];
        }

        return [
            'point_rate_usd' => $points->rate(),
            'min_points' => $minimum->points(),
            'currencies' => $currencies,
        ];
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=WalletTopUpControllerTest`
Expected: all PASS.

- [ ] **Step 5: Style and commit**

```bash
./vendor/bin/pint app/Http/Controllers/Api/V1/Admin/Reception/WalletTopUpController.php tests/Feature/Booking/WalletTopUpControllerTest.php
git add app/Http/Controllers/Api/V1/Admin/Reception/WalletTopUpController.php tests/Feature/Booking/WalletTopUpControllerTest.php
git commit -m "feat(wallet): wallet-balance carries the rates, step and minimum for local quoting

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 6: ADDOS worktree and exact top-up arithmetic

**Files:**
- Create: `src/add-os/modules/payments/utils/top-up-math.ts`
- Test: `src/add-os/modules/payments/utils/__tests__/top-up-math.spec.ts`

**Interfaces:**
- Produces (all pure; all inputs are API decimal strings, never JS numbers for money):
  - `toAsciiDigits(value: string): string`
  - `isPositiveAmount(value: string): boolean` — positive, at most 2 decimals
  - `normalizeDecimal(value: string): string` — `"5000.00"` → `"5000"`
  - `pointsForAmount(amount: string, rateToBase: string, pointRateUsd: string): number`
  - `amountForPoints(points: number, rateToBase: string, pointRateUsd: string, cashStep: string): string`
  - `isMultipleOf(amount: string, cashStep: string): boolean`
  - `remainderBelowOnePoint(amount: string, points: number, rateToBase: string, pointRateUsd: string, decimals: number): string | null`
  - Every function except `toAsciiDigits` and `isPositiveAmount` throws `RangeError` on a malformed decimal or a zero divisor; callers catch.

- [ ] **Step 1: Create the worktree and install**

```bash
git -C "C:/Users/User/Desktop/Aleppo Digital District/ADDOS" worktree add -b feat/top-up-points-entry "C:/Users/User/Desktop/Aleppo Digital District/ADDOS-top-up-points-entry" feat/add-point-frontend
```

```bash
pnpm --dir "C:/Users/User/Desktop/Aleppo Digital District/ADDOS-top-up-points-entry" install --frozen-lockfile
```

Record a baseline from the new worktree, before changing anything, and keep the output — Task 11 compares against it:

```bash
pnpm test:unit 2>&1 | tail -15
pnpm type-check 2>&1 | tail -15
pnpm lint:check 2>&1 | tail -15
```

Any failure or error here predates this work and is not yours to fix.

- [ ] **Step 2: Write the failing test**

Create `src/add-os/modules/payments/utils/__tests__/top-up-math.spec.ts`:

```ts
import { describe, expect, it } from "vitest"

import {
	amountForPoints,
	isMultipleOf,
	isPositiveAmount,
	normalizeDecimal,
	pointsForAmount,
	remainderBelowOnePoint,
	toAsciiDigits
} from "../top-up-math"

const POINT_RATE = "0.10"
/** The old-pound rate ADDCore's tests and ExchangeRateFactory use. */
const OLD_SYP = "0.0000680272"
/** 1/147 — the new pound. */
const NEW_SYP = "0.0068027211"

/** Same vectors as ADDCore's PointConversionServiceTest: the two sides must agree. */
describe("pointsForAmount", () => {
	it.each([
		["500000", OLD_SYP, 340],
		["501200", OLD_SYP, 340],
		["150000.00", "0.0000666667", 100],
		["5000", NEW_SYP, 340],
		["0.30", "1", 3],
		["10.05", "1", 100]
	])("%s at %s buys %i points", (amount, rate, expected) => {
		expect(pointsForAmount(amount, rate, POINT_RATE)).toBe(expected)
	})

	it("throws on a malformed amount instead of guessing", () => {
		expect(() => pointsForAmount("abc", "1", POINT_RATE)).toThrow(RangeError)
	})
})

describe("amountForPoints", () => {
	it.each([
		[340, OLD_SYP, "100", "499900"],
		[10, OLD_SYP, "100", "14800"],
		[345, "1", "1", "35"],
		[340, NEW_SYP, "1", "4998"],
		[340, NEW_SYP, "10", "5000"],
		[10, NEW_SYP, "1", "147"],
		[3, "1", "0.10", "0.3"],
		[340, OLD_SYP, "100.00", "499900"]
	])("%i points at %s with step %s costs %s", (points, rate, step, expected) => {
		expect(amountForPoints(points, rate, POINT_RATE, step)).toBe(expected)
	})

	it("always buys at least the points asked for", () => {
		const cases = [[OLD_SYP, "100"], [NEW_SYP, "1"], [NEW_SYP, "10"], ["1", "1"], ["1", "0.01"]] as const
		for (const [rate, step] of cases) {
			for (let points = 1; points <= 2000; points += 7) {
				const amount = amountForPoints(points, rate, POINT_RATE, step)
				expect(pointsForAmount(amount, rate, POINT_RATE)).toBeGreaterThanOrEqual(points)
			}
		}
	})

	it("stays exact for very large counts", () => {
		const amount = amountForPoints(10_000_000, OLD_SYP, POINT_RATE, "1")
		expect(amount).toBe("14700002353")
		expect(pointsForAmount(amount, OLD_SYP, POINT_RATE)).toBe(10_000_000)
	})

	it("throws on a zero rate or step instead of dividing by it", () => {
		expect(() => amountForPoints(10, "0", POINT_RATE, "1")).toThrow(RangeError)
		expect(() => amountForPoints(10, "1", POINT_RATE, "0")).toThrow(RangeError)
	})
})

describe("remainderBelowOnePoint", () => {
	it.each([
		["500000", 340, OLD_SYP, "199.92"],
		["5000", 340, NEW_SYP, "2"],
		["4998", 340, NEW_SYP, null],
		["34", 340, "1", null]
	])("%s buying %i points at %s leaves %s", (amount, points, rate, expected) => {
		expect(remainderBelowOnePoint(amount, points, rate, POINT_RATE, 2)).toBe(expected)
	})
})

describe("isMultipleOf", () => {
	it.each([
		["5000", "10", true],
		["4995", "10", false],
		["12.50", "1", false],
		["12.50", "0.50", true],
		["100", "100.00", true],
		["0.3", "0.10", true]
	])("%s is a multiple of %s: %s", (amount, step, expected) => {
		expect(isMultipleOf(amount, step)).toBe(expected)
	})
})

describe("isPositiveAmount", () => {
	it("accepts positive amounts with at most two decimals", () => {
		for (const ok of ["1", "0.01", "12.5", "500000", " 42 "]) expect(isPositiveAmount(ok)).toBe(true)
	})

	it("rejects zero, negatives, extra decimals and anything not a plain decimal", () => {
		for (const bad of ["", " ", "0", "0.00", "-5", "abc", "1.234", "1e3", "1,000"]) expect(isPositiveAmount(bad)).toBe(false)
	})
})

describe("normalizeDecimal", () => {
	it("drops trailing fractional zeros and a bare decimal point", () => {
		expect(normalizeDecimal("5000.00")).toBe("5000")
		expect(normalizeDecimal("12.50")).toBe("12.5")
		expect(normalizeDecimal("007")).toBe("7")
	})
})

describe("toAsciiDigits", () => {
	it("reads Arabic-Indic and Extended Arabic-Indic digits and the Arabic decimal mark", () => {
		expect(toAsciiDigits("٣٤٠")).toBe("340")
		expect(toAsciiDigits("۳۴۰")).toBe("340")
		expect(toAsciiDigits("١٢٫٥")).toBe("12.5")
		expect(toAsciiDigits("340")).toBe("340")
	})
})
```

- [ ] **Step 3: Run to verify it fails**

Run: `pnpm vitest run src/add-os/modules/payments/utils/__tests__/top-up-math.spec.ts`
Expected: FAIL — cannot resolve `../top-up-math`.

- [ ] **Step 4: Implement**

Create `src/add-os/modules/payments/utils/top-up-math.ts`:

```ts
/**
 * Exact point ↔ cash arithmetic for the top-up form: the dashboard's mirror of
 * the server's credit rule (ADDCore docs/superpowers/specs/
 * 2026-10-10-wallet-top-up-points-entry-design.md §3).
 *
 * Every value is parsed from the API's decimal strings into BigInt and never
 * becomes a JS number: `0.3 / 0.1` is 2.9999999999999996 in floating point,
 * which floors to 2 points where the server credits 3. The server re-derives
 * and verifies every top-up, so these functions decide what the operator sees,
 * never what is credited.
 */

/** A non-negative decimal as `units / 10^scale`. */
interface Decimal {
	units: bigint
	scale: number
}

const DECIMAL = /^\d+(?:\.\d+)?$/
const AMOUNT = /^\d+(?:\.\d{1,2})?$/

function parse(value: string): Decimal {
	const trimmed = value.trim()
	if (!DECIMAL.test(trimmed)) throw new RangeError(`Not a non-negative decimal: "${value}"`)
	const [whole, fraction = ""] = trimmed.split(".")
	return { units: BigInt(whole + fraction), scale: fraction.length }
}

function pow10(exponent: number): bigint {
	return 10n ** BigInt(exponent)
}

/** `units / 10^scale` as a plain decimal string, trailing fractional zeros dropped. */
function format(units: bigint, scale: number): string {
	const digits = units.toString().padStart(scale + 1, "0")
	if (scale === 0) return digits
	const whole = digits.slice(0, -scale)
	const fraction = digits.slice(-scale).replace(/0+$/, "")
	return fraction ? `${whole}.${fraction}` : whole
}

function divide(numerator: bigint, denominator: bigint): bigint {
	if (denominator === 0n) throw new RangeError("Division by zero in top-up arithmetic")
	return numerator / denominator
}

function divideUp(numerator: bigint, denominator: bigint): bigint {
	return divide(numerator + denominator - 1n, denominator)
}

/**
 * Arabic keyboards at reception type ٣٤٠ or ۳۴۰, and ٫ as the decimal mark.
 * Normalised to ASCII before anything parses them, so both quote alike.
 */
export function toAsciiDigits(value: string): string {
	return value
		.replace(/[\u0660-\u0669]/g, digit => String(digit.charCodeAt(0) - 0x0660))
		.replace(/[\u06F0-\u06F9]/g, digit => String(digit.charCodeAt(0) - 0x06F0))
		.replace(/\u066B/g, ".")
}

/** A positive amount with at most two decimals — what the server's `decimal:0,2` accepts. */
export function isPositiveAmount(value: string): boolean {
	const trimmed = value.trim()
	return AMOUNT.test(trimmed) && parse(trimmed).units > 0n
}

/** `"5000.00"` → `"5000"`: the same amount, written once. */
export function normalizeDecimal(value: string): string {
	const { units, scale } = parse(value)
	return format(units, scale)
}

/** Whole points `amount` buys: floor(amount × rateToBase / pointRateUsd) — the server's rule. */
export function pointsForAmount(amount: string, rateToBase: string, pointRateUsd: string): number {
	const a = parse(amount)
	const f = parse(rateToBase)
	const r = parse(pointRateUsd)
	// (a/10^as)·(f/10^fs) / (r/10^rs) = a·f·10^rs / (r·10^(as+fs))
	return Number(divide(a.units * f.units * pow10(r.scale), r.units * pow10(a.scale + f.scale)))
}

/**
 * The smallest multiple of `cashStep` that buys at least `points`: what to
 * collect for a points-first top-up. Rounding the cash up can buy more than
 * `points`; read the real count back with `pointsForAmount`.
 */
export function amountForPoints(points: number, rateToBase: string, pointRateUsd: string, cashStep: string): string {
	const f = parse(rateToBase)
	const r = parse(pointRateUsd)
	const s = parse(cashStep)
	// steps = ceil(p·R / (F·S)) = ceil(p·r·10^(fs+ss) / (f·s·10^rs))
	const steps = divideUp(BigInt(points) * r.units * pow10(f.scale + s.scale), f.units * s.units * pow10(r.scale))
	return format(steps * s.units, s.scale)
}

/** True when `amount` is a whole number of `cashStep`s. */
export function isMultipleOf(amount: string, cashStep: string): boolean {
	const a = parse(amount)
	const s = parse(cashStep)
	if (s.units === 0n) throw new RangeError("A cash step of zero")
	const scale = Math.max(a.scale, s.scale)
	return (a.units * pow10(scale - a.scale)) % (s.units * pow10(scale - s.scale)) === 0n
}

/**
 * The cash in `amount` beyond what its `points` are worth — too little to buy
 * another point — floored to `decimals` places, or null when that is nothing.
 */
export function remainderBelowOnePoint(amount: string, points: number, rateToBase: string, pointRateUsd: string, decimals: number): string | null {
	const a = parse(amount)
	const f = parse(rateToBase)
	const r = parse(pointRateUsd)
	// amount − p·R/F over the common denominator 10^as · f · 10^rs
	const numerator = a.units * f.units * pow10(r.scale) - BigInt(points) * r.units * pow10(f.scale + a.scale)
	const floored = divide(numerator * pow10(decimals), pow10(a.scale) * f.units * pow10(r.scale))
	return floored > 0n ? format(floored, decimals) : null
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `pnpm vitest run src/add-os/modules/payments/utils/__tests__/top-up-math.spec.ts`
Expected: all PASS.

- [ ] **Step 6: Lint and commit**

```bash
pnpm eslint --fix src/add-os/modules/payments/utils
git add src/add-os/modules/payments/utils
git commit -m "feat(points): exact BigInt top-up arithmetic mirroring the server

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 7: `useTopUpQuote` composable, types and strings (ADDOS)

**Files:**
- Modify: `src/add-os/modules/payments/types/wallet-top-up.ts`
- Create: `src/add-os/modules/payments/composables/useTopUpQuote.ts`
- Test: `src/add-os/modules/payments/composables/__tests__/useTopUpQuote.spec.ts`
- Modify: `src/add-os/modules/payments/composables/usePoints.ts` (header comment only)
- Modify: `src/add-os/lang/en/en.json`, `src/add-os/lang/ar/ar.json` (`points` object)
- Modify: `src/add-os/services/__tests__/wallet-top-ups.spec.ts`

**Interfaces:**
- Consumes: everything from Task 6.
- Produces:
  - Types `TopUpCurrency`, `TopUpConfig`; `WalletBalanceResponse.top_up: TopUpConfig`; `WalletTopUpPayload.points: number`.
  - `useTopUpQuote(currency: Readonly<Ref<string>>)` returning `{ config: Ref<TopUpConfig | null>, mode: Ref<TopUpEntryMode>, input: Ref<string>, quote: ComputedRef<TopUpQuote | null>, error: ComputedRef<TopUpQuoteError | null>, errorMessage: ComputedRef<string | null>, summary: ComputedRef<string | null>, note: ComputedRef<string | null>, minPoints: ComputedRef<number | null>, presets: ComputedRef<number[]>, addPreset(points: number): void, reset(): void }`.
  - `TopUpQuote = { points: number, requestedPoints: number | null, amount: string, remainder: string | null }`.
  - Must be called inside a component `setup()` (it uses `useI18n`).

`vue-tsc` will report the dialog and the page (they build payloads without `points`) until Tasks 8–9; Vitest does not type-check, so tests stay green. Task 11 runs the type-check.

- [ ] **Step 1: Types**

In `src/add-os/modules/payments/types/wallet-top-up.ts`:

Add to `WalletBalanceResponse`, after `display_currency?: string`:

```ts
	/** The rates, steps and minimum the top-up form converts with — see `TopUpConfig`. */
	top_up: TopUpConfig
```

In `WalletTopUpPayload`, add after `amount: string`:

```ts
	/**
	 * The whole points the form showed the operator. The server re-derives them
	 * from `amount` at its current rates and refuses a mismatch with a 422 on
	 * `points` — the form's arithmetic is a mirror, never the authority.
	 */
	points: number
```

Add after `WalletTopUpPayload`:

```ts
/** One currency the top-up form can quote in. */
export interface TopUpCurrency {
	code: string
	/** Units of the base currency per one unit of `code`, decimal string ("1" for the base itself). */
	rate_to_base: string
	/** The smallest amount the till takes in this currency, decimal string, e.g. "1.00". */
	cash_step: string
	decimal_places: number
}

/**
 * `wallet-balance`'s `top_up` block: everything the form needs to convert
 * points ↔ cash without asking the server on every keystroke (ADDCore spec
 * 2026-10-10-wallet-top-up-points-entry-design.md §4).
 */
export interface TopUpConfig {
	/** USD value of one point, decimal string, e.g. "0.10". */
	point_rate_usd: string
	/** The fewest points one top-up may credit. */
	min_points: number
	/** Active currencies that have a current rate; one missing here cannot be quoted. */
	currencies: TopUpCurrency[]
}
```

- [ ] **Step 2: Strings**

In `src/add-os/lang/en/en.json`, add to the existing `points` object (keep its current keys):

```json
"entryMode": { "points": "Points", "amount": "Amount" },
"pointsPlaceholder": "Number of points",
"collect": "Collect {amount} → {points}",
"buys": "Buys {points}",
"remainder": "{amount} is not enough for another point",
"raisedByRounding": "Raised to {points} by cash rounding",
"errors": {
	"invalidPoints": "Enter a whole number of points.",
	"invalidAmount": "Enter an amount above zero, with at most two decimals.",
	"belowMinimum": "A top-up must be at least {min}.",
	"notCashStep": "The amount must be a multiple of {step}.",
	"currencyUnavailable": "This currency has no exchange rate yet.",
	"quoteStale": "Rates changed since this top-up was prepared. Review the new figures and submit again."
}
```

In `src/add-os/lang/ar/ar.json`, add to the existing `points` object:

```json
"entryMode": { "points": "نقاط", "amount": "مبلغ" },
"pointsPlaceholder": "عدد النقاط",
"collect": "اقبض {amount} ← {points}",
"buys": "يشتري {points}",
"remainder": "{amount} لا تكفي لنقطة إضافية",
"raisedByRounding": "ارتفعت إلى {points} بسبب تقريب المبلغ",
"errors": {
	"invalidPoints": "أدخل عدداً صحيحاً من النقاط.",
	"invalidAmount": "أدخل مبلغاً أكبر من صفر، بخانتين عشريتين على الأكثر.",
	"belowMinimum": "الحد الأدنى للشحن {min}.",
	"notCashStep": "يجب أن يكون المبلغ من مضاعفات {step}.",
	"currencyUnavailable": "لا يوجد سعر صرف لهذه العملة بعد.",
	"quoteStale": "تغيّرت الأسعار منذ تجهيز هذا الشحن. راجع الأرقام الجديدة ثم أعد الإرسال."
}
```

Check both files still parse: `node -e "JSON.parse(require('fs').readFileSync('src/add-os/lang/en/en.json','utf8'));JSON.parse(require('fs').readFileSync('src/add-os/lang/ar/ar.json','utf8'))"` → no output.

- [ ] **Step 3: Write the failing composable test**

Create `src/add-os/modules/payments/composables/__tests__/useTopUpQuote.spec.ts`:

```ts
import type { TopUpConfig } from "@/add-os/modules/payments/types/wallet-top-up"
import { mount } from "@vue/test-utils"
import { describe, expect, it } from "vitest"
import { defineComponent, ref } from "vue"
import { createI18n } from "vue-i18n"

import { useTopUpQuote } from "../useTopUpQuote"

const CONFIG: TopUpConfig = {
	point_rate_usd: "0.10",
	min_points: 10,
	currencies: [
		{ code: "SYP", rate_to_base: "0.0068027211", cash_step: "1.00", decimal_places: 2 },
		{ code: "USD", rate_to_base: "1", cash_step: "1.00", decimal_places: 2 }
	]
}

const i18n = createI18n({
	legacy: false,
	locale: "en",
	messages: {
		en: {
			points: {
				unit: "points",
				collect: "Collect {amount} → {points}",
				buys: "Buys {points}",
				remainder: "{amount} is not enough for another point",
				raisedByRounding: "Raised to {points} by cash rounding",
				errors: {
					invalidPoints: "Enter a whole number of points.",
					invalidAmount: "Enter an amount above zero, with at most two decimals.",
					belowMinimum: "A top-up must be at least {min}.",
					notCashStep: "The amount must be a multiple of {step}.",
					currencyUnavailable: "This currency has no exchange rate yet."
				}
			}
		}
	}
})

/** The composable uses useI18n, so it has to run inside a component's setup. */
function setup(code = "SYP") {
	const currency = ref(code)
	let quote!: ReturnType<typeof useTopUpQuote>
	mount(defineComponent({
		setup() {
			quote = useTopUpQuote(currency)
			return () => null
		}
	}), { global: { plugins: [i18n] } })
	quote.config.value = structuredClone(CONFIG)
	return { quote, currency }
}

describe("useTopUpQuote", () => {
	it("quotes nothing until a balance read has delivered the rates", () => {
		const { quote } = setup()
		quote.config.value = null
		quote.input.value = "340"
		expect(quote.quote.value).toBeNull()
		expect(quote.error.value).toBeNull()
	})

	it("turns typed points into the cash to collect", () => {
		const { quote } = setup()
		quote.input.value = "340"
		expect(quote.quote.value).toEqual({ points: 340, requestedPoints: 340, amount: "4998", remainder: null })
		expect(quote.summary.value).toBe("Collect 4,998 SYP → 340 points")
		expect(quote.note.value).toBeNull()
	})

	it("shows the higher count when cash rounding raises it", () => {
		const { quote } = setup("USD")
		quote.input.value = "345"
		expect(quote.quote.value).toMatchObject({ points: 350, requestedPoints: 345, amount: "35" })
		expect(quote.note.value).toBe("Raised to 350 points by cash rounding")
	})

	it("turns a typed amount into points and shows what is left over", () => {
		const { quote } = setup()
		quote.mode.value = "amount"
		quote.input.value = "5000"
		expect(quote.quote.value).toEqual({ points: 340, requestedPoints: null, amount: "5000", remainder: "2" })
		expect(quote.summary.value).toBe("Buys 340 points")
		expect(quote.note.value).toBe("2 SYP is not enough for another point")
	})

	it("reads Arabic-Indic digits exactly as it reads ASCII ones", () => {
		const { quote } = setup()
		quote.input.value = "٣٤٠"
		expect(quote.quote.value?.amount).toBe("4998")
		quote.mode.value = "amount"
		quote.input.value = "٥٠٠٠"
		expect(quote.quote.value).toMatchObject({ points: 340, amount: "5000" })
	})

	it("refuses fewer points than the minimum, in either mode", () => {
		const { quote } = setup()
		quote.input.value = "9"
		expect(quote.error.value).toBe("belowMinimum")
		expect(quote.errorMessage.value).toBe("A top-up must be at least 10 points.")
		quote.mode.value = "amount"
		quote.input.value = "100" // buys 6 points
		expect(quote.error.value).toBe("belowMinimum")
	})

	it("accepts only whole points, and only plain positive amounts", () => {
		const { quote } = setup()
		for (const bad of ["12.5", "abc", "-5", "1e3"]) {
			quote.input.value = bad
			expect(quote.error.value).toBe("invalidPoints")
		}
		quote.mode.value = "amount"
		for (const bad of ["0", "0.00", "abc", "1.234", "-5"]) {
			quote.input.value = bad
			expect(quote.error.value).toBe("invalidAmount")
		}
	})

	it("in amount mode accepts only multiples of the currency's cash step", () => {
		const { quote } = setup()
		quote.config.value!.currencies[0].cash_step = "10.00"
		quote.mode.value = "amount"
		quote.input.value = "4995"
		expect(quote.error.value).toBe("notCashStep")
		expect(quote.errorMessage.value).toBe("The amount must be a multiple of 10 SYP.")
		quote.input.value = "5000"
		expect(quote.quote.value?.points).toBe(340)
	})

	it("re-quotes the same points when the currency changes", () => {
		const { quote, currency } = setup()
		quote.input.value = "340"
		expect(quote.quote.value?.amount).toBe("4998")
		currency.value = "USD"
		expect(quote.quote.value).toMatchObject({ points: 340, amount: "34" })
	})

	it("says the currency cannot be quoted when it has no rate", () => {
		const { quote, currency } = setup()
		currency.value = "EUR"
		quote.input.value = "340"
		expect(quote.quote.value).toBeNull()
		expect(quote.error.value).toBe("currencyUnavailable")
		expect(quote.errorMessage.value).toBe("This currency has no exchange rate yet.")
	})

	it("does not break on a rate or step the arithmetic cannot use", () => {
		const { quote } = setup()
		quote.input.value = "340"
		quote.config.value!.currencies[0].rate_to_base = "0"
		expect(quote.error.value).toBe("currencyUnavailable")
		quote.config.value!.currencies[0].rate_to_base = "abc"
		expect(quote.error.value).toBe("currencyUnavailable")
		quote.config.value!.currencies[0].rate_to_base = "0.0068027211"
		quote.config.value!.currencies[0].cash_step = "0"
		expect(quote.error.value).toBe("currencyUnavailable")
	})

	it("hides presets below the minimum, adds them up, and offers none in amount mode", () => {
		const { quote } = setup()
		quote.config.value!.min_points = 25
		expect(quote.presets.value).toEqual([50, 100, 500])
		quote.addPreset(50)
		quote.addPreset(100)
		expect(quote.input.value).toBe("150")
		quote.mode.value = "amount"
		expect(quote.presets.value).toEqual([])
		expect(quote.input.value).toBe("")
	})

	it("reset returns to an empty points box", () => {
		const { quote } = setup()
		quote.mode.value = "amount"
		quote.input.value = "5000"
		quote.reset()
		expect(quote.mode.value).toBe("points")
		expect(quote.input.value).toBe("")
	})
})
```

- [ ] **Step 4: Run to verify it fails**

Run: `pnpm vitest run src/add-os/modules/payments/composables/__tests__/useTopUpQuote.spec.ts`
Expected: FAIL — cannot resolve `../useTopUpQuote`.

- [ ] **Step 5: Implement**

Create `src/add-os/modules/payments/composables/useTopUpQuote.ts`:

```ts
import type { Ref } from "vue"
import type { TopUpConfig, TopUpCurrency } from "@/add-os/modules/payments/types/wallet-top-up"
import { computed, ref, watch } from "vue"
import { useI18n } from "vue-i18n"
import { usePoints } from "@/add-os/modules/payments/composables/usePoints"
import {
	amountForPoints,
	isMultipleOf,
	isPositiveAmount,
	normalizeDecimal,
	pointsForAmount,
	remainderBelowOnePoint,
	toAsciiDigits
} from "@/add-os/modules/payments/utils/top-up-math"
import { formatNumber } from "@/add-os/utils/format"

/** What the operator types; the other figure is derived. Points is the default. */
export type TopUpEntryMode = "points" | "amount"

export interface TopUpQuote {
	/** Whole points this top-up credits — sent as `points`, verified by the server. */
	points: number
	/** Points mode: what was typed, lower than `points` when cash rounding raised it. Null in amount mode. */
	requestedPoints: number | null
	/** Cash to collect, a plain decimal string sent as `amount`. */
	amount: string
	/** Amount mode: the cash too small to buy another point, or null. */
	remainder: string | null
}

export type TopUpQuoteError = "invalidPoints" | "invalidAmount" | "belowMinimum" | "notCashStep" | "currencyUnavailable"

type QuoteResult = { quote: TopUpQuote, error: null } | { quote: null, error: TopUpQuoteError | null }

/** Quick-add point counts; any below the configured minimum is hidden. */
const POINT_PRESETS = [10, 50, 100, 500]

const WHOLE_NUMBER = /^\d+$/

/**
 * Points ↔ cash for the top-up form, computed here instead of asking the
 * server on every keystroke (ADDCore spec
 * 2026-10-10-wallet-top-up-points-entry-design.md). Safe because the server
 * re-derives the points from the amount on submit and refuses a mismatch:
 * this decides what the operator sees, never what is credited.
 *
 * `config` is the `top_up` block of `wallet-balance`; the caller sets it on
 * every balance read, and re-reads after a stale-quote 422.
 */
export function useTopUpQuote(currency: Readonly<Ref<string>>) {
	const { t } = useI18n()
	const { formatPoints } = usePoints()

	const config = ref<TopUpConfig | null>(null)
	const mode = ref<TopUpEntryMode>("points")
	const input = ref("")

	// A points count is not an amount: switching what is typed starts the box over.
	watch(mode, () => {
		input.value = ""
	}, { flush: "sync" })

	const currencyConfig = computed<TopUpCurrency | null>(() => config.value?.currencies.find(c => c.code === currency.value) ?? null)
	const minPoints = computed(() => config.value?.min_points ?? null)

	const result = computed<QuoteResult>(() => {
		const raw = toAsciiDigits(input.value).trim()
		if (raw === "" || config.value === null) return { quote: null, error: null }

		const target = currencyConfig.value
		if (target === null) return { quote: null, error: "currencyUnavailable" }

		try {
			return mode.value === "points"
				? quoteFromPoints(raw, target, config.value.point_rate_usd, config.value.min_points)
				: quoteFromAmount(raw, target, config.value.point_rate_usd, config.value.min_points)
		} catch {
			// A rate or step the arithmetic cannot use (malformed, or zero): this
			// currency cannot be quoted, and the form must say so rather than break.
			return { quote: null, error: "currencyUnavailable" }
		}
	})

	const quote = computed(() => result.value.quote)
	const error = computed(() => result.value.error)

	function cash(amount: string): string {
		return `${formatNumber(normalizeDecimal(amount))} ${currency.value}`
	}

	/** The line under the input: what to collect, or what the cash buys. */
	const summary = computed(() => {
		const current = quote.value
		if (current === null) return null
		return mode.value === "points"
			? t("points.collect", { amount: cash(current.amount), points: formatPoints(current.points) })
			: t("points.buys", { points: formatPoints(current.points) })
	})

	/** A second line when the fraction landed somewhere the operator should see it. */
	const note = computed(() => {
		const current = quote.value
		if (current === null) return null
		if (current.remainder !== null) return t("points.remainder", { amount: cash(current.remainder) })
		if (current.requestedPoints !== null && current.points > current.requestedPoints) {
			return t("points.raisedByRounding", { points: formatPoints(current.points) })
		}
		return null
	})

	const errorMessage = computed(() => {
		switch (error.value) {
			case null:
				return null
			case "belowMinimum":
				return t("points.errors.belowMinimum", { min: formatPoints(minPoints.value ?? 0) })
			case "notCashStep":
				return t("points.errors.notCashStep", { step: cash(currencyConfig.value?.cash_step ?? "1") })
			default:
				return t(`points.errors.${error.value}`)
		}
	})

	const presets = computed(() => (mode.value === "points" ? POINT_PRESETS.filter(points => points >= (minPoints.value ?? 0)) : []))

	function addPreset(points: number) {
		const current = toAsciiDigits(input.value).trim()
		input.value = String((WHOLE_NUMBER.test(current) ? Number(current) : 0) + points)
	}

	/** Back to an empty points box, for the next top-up. */
	function reset() {
		mode.value = "points"
		input.value = ""
	}

	return { config, mode, input, quote, error, errorMessage, summary, note, minPoints, presets, addPreset, reset }
}

function quoteFromPoints(raw: string, target: TopUpCurrency, pointRate: string, min: number): QuoteResult {
	if (!WHOLE_NUMBER.test(raw)) return { quote: null, error: "invalidPoints" }
	const requested = Number(raw)
	if (requested < min) return { quote: null, error: "belowMinimum" }

	const amount = amountForPoints(requested, target.rate_to_base, pointRate, target.cash_step)
	return {
		quote: { points: pointsForAmount(amount, target.rate_to_base, pointRate), requestedPoints: requested, amount, remainder: null },
		error: null
	}
}

function quoteFromAmount(raw: string, target: TopUpCurrency, pointRate: string, min: number): QuoteResult {
	if (!isPositiveAmount(raw)) return { quote: null, error: "invalidAmount" }
	if (!isMultipleOf(raw, target.cash_step)) return { quote: null, error: "notCashStep" }

	const amount = normalizeDecimal(raw)
	const points = pointsForAmount(amount, target.rate_to_base, pointRate)
	if (points < min) return { quote: null, error: "belowMinimum" }

	return {
		quote: {
			points,
			requestedPoints: null,
			amount,
			remainder: remainderBelowOnePoint(amount, points, target.rate_to_base, pointRate, target.decimal_places)
		},
		error: null
	}
}
```

Replace the header comment of `src/add-os/modules/payments/composables/usePoints.ts` (the block above `export function usePoints()`) with:

```ts
/**
 * Formatting for point counts — and deliberately nothing else.
 *
 * Points are the unit of account: the backend stores every price and wallet
 * balance as a whole integer of points and derives currency for display.
 * Conversion arithmetic does not belong here. The one place the dashboard
 * converts at all is the top-up form, through `utils/top-up-math.ts` and
 * `useTopUpQuote`: exact BigInt arithmetic mirroring the server's rule, safe
 * only because the server re-derives and verifies every top-up it receives
 * (ADDCore spec 2026-10-10-wallet-top-up-points-entry-design.md). Anywhere
 * else, ask the backend — `price_usd` / `price_display` on priced resources —
 * and pass the number it returns through `formatPoints`.
 */
```

- [ ] **Step 6: Run to verify it passes**

Run: `pnpm vitest run src/add-os/modules/payments/composables`
Expected: all PASS (the new spec and the existing `usePoints.spec.ts`).

- [ ] **Step 7: Service spec payloads carry `points`**

In `src/add-os/services/__tests__/wallet-top-ups.spec.ts`, add `points: 500,` to each of the three payload literals (the member payload object, the company `createWalletTopUp({...})` call and the 422 call). In the company test, change the expected body to `{ amount: "50.00", points: 500, payment_method: "cash", company_id: 45, description: null }`.

Run: `pnpm vitest run src/add-os/services/__tests__/wallet-top-ups.spec.ts`
Expected: PASS.

- [ ] **Step 8: Lint and commit**

```bash
pnpm eslint --fix src/add-os/modules/payments src/add-os/services/__tests__/wallet-top-ups.spec.ts
git add src/add-os/modules/payments src/add-os/lang src/add-os/services/__tests__/wallet-top-ups.spec.ts
git commit -m "feat(points): useTopUpQuote converts points and cash locally for the top-up forms

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 8: Top-up dialog uses the quote (ADDOS)

**Files:**
- Modify: `src/add-os/modules/members/components/WalletTopUpDialog.vue`
- Test: `src/add-os/modules/members/components/__tests__/WalletTopUpDialog.spec.ts` (replace the whole file)

**Interfaces:**
- Consumes: `useTopUpQuote` (Task 7), `getWalletBalance` (returns `top_up`), `createWalletTopUp` (payload now has `points`).
- Produces: no new exports. The dialog's public props/events (`member`, `show`, `success`) are unchanged.

- [ ] **Step 1: Write the failing spec**

Replace `src/add-os/modules/members/components/__tests__/WalletTopUpDialog.spec.ts` with:

```ts
import { flushPromises, mount } from "@vue/test-utils"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { createI18n } from "vue-i18n"

import { ApiError } from "@/add-os/services/api"
import WalletTopUpDialog from "../WalletTopUpDialog.vue"

const TOP_UP_CONFIG = {
	point_rate_usd: "0.10",
	min_points: 10,
	currencies: [
		{ code: "SYP", rate_to_base: "0.0068027211", cash_step: "1.00", decimal_places: 2 },
		{ code: "USD", rate_to_base: "1", cash_step: "1.00", decimal_places: 2 }
	]
}

const STALE = "Rates changed since this top-up was prepared. Review the new figures and submit again."

const { createWalletTopUpMock, getWalletBalanceMock } = vi.hoisted(() => ({
	createWalletTopUpMock: vi.fn(),
	getWalletBalanceMock: vi.fn()
}))

vi.mock("@/add-os/services/wallet-top-ups", () => ({
	createWalletTopUp: createWalletTopUpMock,
	getWalletBalance: getWalletBalanceMock
}))

// The component calls useMessage() directly, which needs a provider it has no
// reason to mount. Mock only useMessage; every other naive-ui export stays real.
const successMessages: string[] = []
const errorMessages: string[] = []
vi.mock("naive-ui", async () => {
	const actual = await vi.importActual<typeof import("naive-ui")>("naive-ui")
	return {
		...actual,
		useMessage: () => ({
			success: (m: string) => successMessages.push(m),
			error: (m: string) => errorMessages.push(m)
		})
	}
})

const sampleMember = { id: 5, name: "Sara", phone: "0999111222" }

const i18n = createI18n({
	legacy: false,
	locale: "en",
	messages: {
		en: {
			points: {
				unit: "points",
				credited: "Points credited",
				newBalance: "New balance",
				entryMode: { points: "Points", amount: "Amount" },
				pointsPlaceholder: "Number of points",
				collect: "Collect {amount} → {points}",
				buys: "Buys {points}",
				remainder: "{amount} is not enough for another point",
				raisedByRounding: "Raised to {points} by cash rounding",
				errors: {
					invalidPoints: "Enter a whole number of points.",
					invalidAmount: "Enter an amount above zero, with at most two decimals.",
					belowMinimum: "A top-up must be at least {min}.",
					notCashStep: "The amount must be a multiple of {step}.",
					currencyUnavailable: "This currency has no exchange rate yet.",
					quoteStale: STALE
				}
			},
			members: {
				topUp: {
					title: "Top Up Member Wallet",
					amount: "Amount",
					amountPlaceholder: "Enter amount...",
					paymentMethod: "Payment Method",
					description: "Notes",
					descriptionPlaceholder: "Optional note...",
					submit: "Confirm Top-up",
					success: "Wallet topped up successfully."
				}
			},
			users: { form: { cancel: "Cancel" } },
			walletTopUps: { paymentMethod: { cash: "Cash" } },
			resourceCrud: { mutations: { genericError: "Something went wrong." } }
		}
	}
})

let mounted: ReturnType<typeof mount> | null = null

function mountDialog() {
	mounted = mount(WalletTopUpDialog, {
		props: { member: sampleMember as never, show: true, "onUpdate:show": () => {} },
		global: { plugins: [i18n] },
		attachTo: document.body
	})
	return mounted
}

// NModal teleports its body to document.body, so every query goes through the document.
function dialogText(): string {
	return document.body.textContent ?? ""
}

async function type(value: string) {
	const input = document.querySelector<HTMLInputElement>("[data-testid=\"top-up-input\"]")
	if (!input) throw new Error("top-up input not rendered")
	input.value = value
	input.dispatchEvent(new Event("input"))
	await flushPromises()
}

async function chooseMode(mode: "points" | "amount") {
	const radio = document.querySelector<HTMLInputElement>(`input[type="radio"][value="${mode}"]`)
	if (!radio) throw new Error(`${mode} mode radio not rendered`)
	radio.click()
	await flushPromises()
}

function submitButton(): HTMLButtonElement {
	const button = [...document.querySelectorAll<HTMLButtonElement>("button")].find(b => b.textContent?.includes("Confirm Top-up"))
	if (!button) throw new Error("submit button not rendered")
	return button
}

beforeEach(() => {
	createWalletTopUpMock.mockReset()
	getWalletBalanceMock.mockReset()
	getWalletBalanceMock.mockResolvedValue({ balance_points: 200, top_up: TOP_UP_CONFIG })
	successMessages.length = 0
	errorMessages.length = 0
})

afterEach(() => {
	mounted?.unmount()
	mounted = null
	document.body.innerHTML = ""
})

describe("walletTopUpDialog", () => {
	it("shows the member's balance before and after the top-up", async () => {
		mountDialog()
		await flushPromises()
		expect(getWalletBalanceMock).toHaveBeenCalledWith({ user_id: 5 })

		await type("340")

		const text = dialogText()
		expect(text).toContain("200 points")
		expect(text).toContain("+ 340 points")
		expect(text).toContain("540 points")
	})

	it("turns typed points into the cash to collect without asking the server", async () => {
		mountDialog()
		await flushPromises()
		await type("340")

		expect(dialogText()).toContain("Collect 4,998 SYP → 340 points")
		expect(getWalletBalanceMock).toHaveBeenCalledTimes(1)
		expect(createWalletTopUpMock).not.toHaveBeenCalled()
	})

	it("turns a typed amount into points in amount mode", async () => {
		mountDialog()
		await flushPromises()
		await chooseMode("amount")
		await type("5000")

		expect(dialogText()).toContain("Buys 340 points")
		expect(dialogText()).toContain("2 SYP is not enough for another point")
	})

	it("will not submit fewer points than the minimum", async () => {
		mountDialog()
		await flushPromises()
		await type("9")

		expect(dialogText()).toContain("A top-up must be at least 10 points.")
		expect(submitButton().disabled).toBe(true)
		submitButton().click()
		await flushPromises()
		expect(createWalletTopUpMock).not.toHaveBeenCalled()
	})

	it("submits the quoted points with the cash that buys them", async () => {
		createWalletTopUpMock.mockResolvedValue({ points_credited: 340, new_balance_points: 540 })
		mountDialog()
		await flushPromises()
		await type("340")

		submitButton().click()
		await flushPromises()

		expect(createWalletTopUpMock).toHaveBeenCalledWith({
			user_id: 5,
			points: 340,
			amount: "4998",
			currency: "SYP",
			payment_method: "cash",
			description: null
		})
	})

	it("on a stale quote, says why and re-reads the rates without resubmitting", async () => {
		createWalletTopUpMock.mockRejectedValue(new ApiError(422, JSON.stringify({ message: "invalid", errors: { points: [STALE] } })))
		mountDialog()
		await flushPromises()
		await type("340")

		submitButton().click()
		await flushPromises()

		expect(errorMessages).toContain(STALE)
		expect(getWalletBalanceMock).toHaveBeenCalledTimes(2)
		expect(createWalletTopUpMock).toHaveBeenCalledTimes(1)
	})

	it("reports credited points and the new balance after a successful top-up", async () => {
		createWalletTopUpMock.mockResolvedValue({
			id: 1,
			points_credited: 340,
			previous_balance_points: 60,
			new_balance_points: 400,
			point_rate_usd: "0.1000",
			amount: "34.00",
			source: "reception",
			payment_method: "cash",
			performed_by_user_id: 1
		})
		mountDialog()
		await flushPromises()
		await type("340")

		submitButton().click()
		await flushPromises()

		expect(createWalletTopUpMock).toHaveBeenCalledTimes(1)
		const reported = successMessages.join(" ")
		expect(reported).toContain("340 points")
		expect(reported).toContain("400 points")
	})
})
```

- [ ] **Step 2: Run to verify it fails**

Run: `pnpm vitest run src/add-os/modules/members/components/__tests__/WalletTopUpDialog.spec.ts`
Expected: FAIL — `top-up input not rendered` (the dialog still renders the old amount box).

- [ ] **Step 3: Implement — template**

In `WalletTopUpDialog.vue`, replace the whole block from `<!-- Amount: currency and digits in one box, the quote under it, quick amounts -->` through the closing `</div>` of that card (the one that ends right after the preset `<button>` grid, before `<!-- Payment method -->`) with:

```vue
			<!--
				Points (default) or cash, in the chosen currency. useTopUpQuote derives
				the other figure here, instantly; the server re-checks it on submit.
			-->
			<div class="flex flex-col gap-3 p-3.5 rounded-xl border border-[var(--add-border)]/60 bg-[var(--add-surface-raised)]">
				<div class="flex items-center justify-between gap-2">
					<span class="text-xs font-bold text-[var(--add-brand-charcoal-backbone)] uppercase tracking-wider">{{ t("members.topUp.amount") }}</span>
					<n-radio-group v-model:value="mode" size="small">
						<n-radio-button value="points">{{ t("points.entryMode.points") }}</n-radio-button>
						<n-radio-button value="amount">{{ t("points.entryMode.amount") }}</n-radio-button>
					</n-radio-group>
				</div>

				<div class="flex items-center w-full border border-[var(--add-border)] rounded-xl bg-[var(--add-surface-raised)] shadow-2xs overflow-hidden focus-within:border-[var(--add-brand-smart-olive)] focus-within:ring-1 focus-within:ring-[var(--add-brand-smart-olive)] transition-all">
					<div class="w-32 shrink-0 bg-[var(--add-surface-sunken)]/60 border-e border-[var(--add-border)]">
						<FormIsolatedSelect
							v-model:value="form.currency"
							:options="currencyOptions"
							:bordered="false"
							:consistent-menu-width="false"
							class="font-bold text-[var(--add-brand-charcoal-backbone)]"
						/>
					</div>
					<n-input
						v-model:value="input"
						:placeholder="mode === 'points' ? t('points.pointsPlaceholder') : t('members.topUp.amountPlaceholder')"
						:input-props="{ 'data-testid': 'top-up-input', 'inputmode': mode === 'points' ? 'numeric' : 'decimal' }"
						:bordered="false"
						class="flex-1 text-base font-bold font-numeric min-w-0"
					/>
					<span class="text-xs font-bold text-[var(--add-text-muted)] shrink-0 px-3 whitespace-nowrap">{{ mode === "points" ? t("points.unit") : form.currency }}</span>
				</div>

				<div class="min-h-5 flex flex-col gap-0.5 text-xs">
					<span v-if="errorMessage" class="text-[var(--add-danger-text)] font-medium">{{ errorMessage }}</span>
					<template v-else-if="summary">
						<span class="font-bold font-numeric text-[var(--add-primary-text)] add-numeric">{{ summary }}</span>
						<span v-if="note" class="text-[var(--add-text-muted)] add-numeric">{{ note }}</span>
					</template>
				</div>

				<div v-if="presets.length" class="grid grid-cols-4 gap-2">
					<button
						v-for="preset in presets"
						:key="preset"
						type="button"
						class="py-2 px-1.5 rounded-xl text-xs font-bold font-numeric border transition-all text-[var(--add-primary-text)] border-[var(--add-border)]/70 bg-[var(--add-surface-sunken)]/60 hover:bg-[var(--add-brand-smart-olive)]/15 hover:border-[var(--add-brand-smart-olive)] shadow-2xs cursor-pointer"
						@click="addPreset(preset)"
					>
						+ {{ formatNumber(preset) }}
					</button>
				</div>
			</div>
```

In the balance-preview card, replace:

```vue
						<span class="font-bold font-numeric text-[var(--add-primary-text)] text-sm mt-0.5">{{ previewPoints === null ? "—" : `+ ${formatPoints(previewPoints)}` }}</span>
						<span class="text-[var(--add-text-muted)] text-[10px] font-numeric add-numeric">{{ formattedInputAmount }}</span>
```

with:

```vue
						<span class="font-bold font-numeric text-[var(--add-primary-text)] text-sm mt-0.5">{{ quote === null ? "—" : `+ ${formatPoints(quote.points)}` }}</span>
						<span class="text-[var(--add-text-muted)] text-[10px] font-numeric add-numeric">{{ collectText }}</span>
```

On the submit `<n-button type="primary" ...>`, add `:disabled="quote === null"`.

- [ ] **Step 4: Implement — script**

Replace the whole `<script setup lang="ts">` block with:

```ts
<script setup lang="ts">
import type { FormInst, FormRules, SelectOption } from "naive-ui"
import type { Member } from "@/add-os/modules/members/types/member"
import type { PaymentMethod, WalletTopUpPayload } from "@/add-os/modules/payments/types/wallet-top-up"
import { NAvatar, NButton, NForm, NFormItem, NInput, NModal, NRadioButton, NRadioGroup, NSelect, NTag, useMessage } from "naive-ui"
import { getActivePinia } from "pinia"
import { computed, defineComponent, h, provide, ref, watch } from "vue"
import { useI18n } from "vue-i18n"
import { usePoints } from "@/add-os/modules/payments/composables/usePoints"
import { useTopUpQuote } from "@/add-os/modules/payments/composables/useTopUpQuote"
import { ApiError } from "@/add-os/services/api"
import { createWalletTopUp, getWalletBalance } from "@/add-os/services/wallet-top-ups"
import { formatNumber } from "@/add-os/utils/format"
import Icon from "@/components/common/Icon.vue"
import { useCurrencyStore } from "@/stores/currency"

const props = defineProps<{ member: Member | null }>()
const emit = defineEmits<{ (e: "success"): void }>()
const show = defineModel<boolean>("show", { required: true })
const { t } = useI18n()
const message = useMessage()
const { formatPoints } = usePoints()
const currencyStore = getActivePinia() ? useCurrencyStore() : null

const formRef = ref<FormInst | null>(null)
const isSubmitting = ref(false)

function emptyForm() {
	return {
		currency: currencyStore?.currency || "SYP",
		payment_method: "cash" as PaymentMethod,
		description: ""
	}
}

const form = ref(emptyForm())

const {
	config: quoteConfig,
	mode,
	input,
	quote,
	errorMessage,
	summary,
	note,
	presets,
	addPreset,
	reset: resetQuote
} = useTopUpQuote(computed(() => form.value.currency))

const DEFAULT_CURRENCIES = [
	{ code: "SYP", label: "SYP (ل.س)", value: "SYP" },
	{ code: "USD", label: "USD ($)", value: "USD" }
]

const currencyOptions = computed<SelectOption[]>(() => {
	if (currencyStore?.availableCurrencies?.length) {
		return currencyStore.availableCurrencies.map(c => ({
			label: `${c.code} (${c.symbol})`,
			value: c.code
		}))
	}
	return DEFAULT_CURRENCIES
})

/** The methods the desk takes, as the Wallet Top-up page shows them. */
const paymentMethodList = computed(() => [
	{ id: "cash" as PaymentMethod, label: t("walletTopUps.paymentMethod.cash"), icon: "carbon:money", iconBg: "bg-[var(--add-brand-smart-olive)]/15 text-[var(--add-primary-text)]" }
])

/**
 * A select that does not register with the surrounding form item, so the
 * currency inside the input box is not validated as part of it — the same
 * isolation the Wallet Top-up page uses.
 */
const FormIsolatedSelect = defineComponent({
	name: "FormIsolatedSelect",
	setup(_props, { attrs, slots }) {
		provide("n-form-item", null)
		return () => h(NSelect, attrs, slots)
	}
})

const initials = computed(() =>
	(props.member?.name ?? "?").split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join("").toUpperCase()
)

/** The cash under the points this top-up adds. */
const collectText = computed(() => `${quote.value === null ? "0" : formatNumber(quote.value.amount)} ${form.value.currency}`)

/** The member's general balance in points, as the server reported it. */
const currentBalancePoints = ref<number | null>(null)
const isLoadingBalance = ref(false)

/**
 * The balance read also carries the rates the quote converts with, which is
 * why it runs again after a stale-quote rejection.
 */
async function loadBalance() {
	if (!props.member) return
	isLoadingBalance.value = true
	try {
		const balance = await getWalletBalance({ user_id: props.member.id })
		currentBalancePoints.value = balance.balance_points
		quoteConfig.value = balance.top_up ?? null
	} catch (e) {
		if (!(e instanceof ApiError)) throw e
		currentBalancePoints.value = null
		quoteConfig.value = null
	} finally {
		isLoadingBalance.value = false
	}
}

/** Two whole point counts, added — the quote already did the conversion. */
const newBalancePoints = computed(() => {
	if (currentBalancePoints.value === null) return null
	return quote.value === null ? currentBalancePoints.value : currentBalancePoints.value + quote.value.points
})

const rules = computed<FormRules>(() => ({
	payment_method: [
		{ required: true, message: "Payment method required", trigger: "change" }
	]
}))

// Whenever the dialog is shown, for whichever member: also when it mounts
// already open.
watch(() => [show.value, props.member?.id], () => {
	if (show.value) void loadBalance()
}, { immediate: true })

watch(show, visible => {
	if (visible) form.value = emptyForm()
	resetQuote()
})

async function submitTopUp() {
	if (!props.member || quote.value === null) return
	try {
		await formRef.value?.validate()
	} catch {
		return
	}

	isSubmitting.value = true
	const payload: WalletTopUpPayload = {
		user_id: props.member.id,
		points: quote.value.points,
		amount: quote.value.amount,
		currency: form.value.currency,
		payment_method: form.value.payment_method,
		description: form.value.description.trim() || null
	}

	try {
		const topUp = await createWalletTopUp(payload)
		// Report what was bought, not only that something happened: points
		// credited and the resulting balance are the figures the operator reads
		// back to the member, alongside the cash they actually took.
		message.success(
			`${t("members.topUp.success")} ${t("points.credited")}: ${formatPoints(topUp.points_credited)} · ${t("points.newBalance")}: ${formatPoints(topUp.new_balance_points)}`
		)
		show.value = false
		emit("success")
	} catch (e) {
		if (!(e instanceof ApiError)) throw e
		const pointsError = e.status === 422 ? e.data?.errors?.points?.[0] : undefined
		if (pointsError !== undefined) {
			// The rates moved after this dialog read them. Re-read them so the
			// figures update, and leave submitting to the operator: the member
			// has to agree to the new numbers first.
			message.error(pointsError)
			await loadBalance()
			return
		}
		message.error(e.data?.message ?? t("resourceCrud.mutations.genericError"))
	} finally {
		isSubmitting.value = false
	}
}
</script>
```

- [ ] **Step 5: Run to verify it passes**

Run: `pnpm vitest run src/add-os/modules/members`
Expected: all PASS (the dialog spec, and `MemberProfilePage.spec.ts` / any other members spec that mounts the dialog).

- [ ] **Step 6: Lint and commit**

```bash
pnpm eslint --fix src/add-os/modules/members/components
git add src/add-os/modules/members/components
git commit -m "feat(members): top-up dialog takes points first and converts locally

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 9: Wallet top-up page uses the quote (ADDOS)

**Files:**
- Modify: `src/add-os/modules/payments/views/WalletTopUpsPage.vue`
- Test: `src/add-os/modules/payments/views/__tests__/WalletTopUpsPage.spec.ts`

**Interfaces:**
- Consumes: `useTopUpQuote` (Task 7).
- Produces: `defineExpose` changes: removes `previewPoints`, `previewError`; adds `input`, `mode`, `quote`, `quoteError`. Keeps `submit`, `form`, `recipientType`, `userBalanceScope`, `fieldErrors`, `selectedCurrency`, `receiptModalVisible`, `lastReceipt`, `closeReceiptModal`, `calculatedNewBalancePoints`. `form` no longer has `amount`.

- [ ] **Step 1: Update the spec**

In `WalletTopUpsPage.spec.ts`:

1. Remove `previewWalletTopUpMock` from the `vi.hoisted` block, from the `vi.mock("@/add-os/services/wallet-top-ups", ...)` factory, and its `mockResolvedValue` in `beforeEach`.
2. Add above `const i18n`:

```ts
const TOP_UP_CONFIG = {
	point_rate_usd: "0.10",
	min_points: 10,
	currencies: [
		{ code: "SYP", rate_to_base: "0.0068027211", cash_step: "1.00", decimal_places: 2 },
		{ code: "USD", rate_to_base: "1", cash_step: "1.00", decimal_places: 2 }
	]
}

const BALANCE = {
	owner_type: "user",
	owner_id: 5,
	owner_name: "Sara",
	owner_phone: "0999111222",
	balance_points: 1000,
	balance_usd: "100.00",
	balance_display: "14700.00",
	general_balance: "100.00",
	currency: "SYP",
	top_up: TOP_UP_CONFIG
}

const STALE = "Rates changed since this top-up was prepared. Review the new figures and submit again."
```

3. In `beforeEach`, replace the `getWalletBalanceMock.mockResolvedValue({...})` call with `getWalletBalanceMock.mockResolvedValue(BALANCE)`.
4. In the i18n `points` messages, replace `previewLoading`, `belowOnePoint`, `previewFailed` with:

```ts
				entryMode: { points: "Points", amount: "Amount" },
				pointsPlaceholder: "Number of points",
				collect: "Collect {amount} → {points}",
				buys: "Buys {points}",
				remainder: "{amount} is not enough for another point",
				raisedByRounding: "Raised to {points} by cash rounding",
				errors: {
					invalidPoints: "Enter a whole number of points.",
					invalidAmount: "Enter an amount above zero, with at most two decimals.",
					belowMinimum: "A top-up must be at least {min}.",
					notCashStep: "The amount must be a multiple of {step}.",
					currencyUnavailable: "This currency has no exchange rate yet.",
					quoteStale: STALE
				}
```

5. Replace these tests (same position in the file) with the versions below; leave every other test unchanged:

```ts
	it("posts the quoted points with the cash that buys them, then resets the form", async () => {
		const wrapper = mountPage()
		await flushPromises()

		wrapper.vm.form.user_id = 5
		await flushPromises()
		wrapper.vm.input = "500"
		wrapper.vm.form.description = "Front-desk cash top-up"
		await wrapper.vm.submit()
		await flushPromises()

		expect(createWalletTopUpMock).toHaveBeenCalledWith({
			points: 500,
			amount: "7350",
			currency: "SYP",
			payment_method: "cash",
			user_id: 5,
			description: "Front-desk cash top-up"
		})

		// A repeatable action screen: the next top-up must start blank.
		expect(wrapper.vm.input).toBe("")
		expect(wrapper.vm.form.user_id).toBeNull()
		expect(wrapper.vm.form.description).toBe("")
		wrapper.unmount()
	})

	it("posts a company top-up with company_id and no user_id", async () => {
		const wrapper = mountPage()
		await flushPromises()

		wrapper.vm.recipientType = "company"
		await flushPromises()
		wrapper.vm.form.company_id = 45
		await flushPromises()
		wrapper.vm.input = "500"
		await wrapper.vm.submit()
		await flushPromises()

		expect(createWalletTopUpMock).toHaveBeenCalledWith({
			points: 500,
			amount: "7350",
			currency: "SYP",
			payment_method: "cash",
			company_id: 45,
			description: null
		})
		expect(createWalletTopUpMock.mock.calls[0][0]).not.toHaveProperty("user_id")
		wrapper.unmount()
	})

	it("surfaces 422 field errors, re-reads the rates on a stale quote, and keeps the form filled", async () => {
		createWalletTopUpMock.mockRejectedValue(
			new ApiError(422, JSON.stringify({
				message: "The given data was invalid.",
				errors: { points: [STALE], payment_method: ["The selected payment method is invalid."] }
			}))
		)
		const wrapper = mountPage()
		await flushPromises()

		wrapper.vm.form.user_id = 5
		await flushPromises()
		wrapper.vm.input = "500"
		await wrapper.vm.submit()
		await flushPromises()

		expect(wrapper.vm.fieldErrors.points).toEqual([STALE])
		expect(wrapper.vm.fieldErrors.payment_method).toEqual(["The selected payment method is invalid."])
		expect(getWalletBalanceMock).toHaveBeenCalledTimes(2)
		expect(createWalletTopUpMock).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.input).toBe("500")
		expect(wrapper.vm.form.user_id).toBe(5)
		wrapper.unmount()
	})

	it("never posts both user_id and company_id, even when a stale id survives in the model", async () => {
		const wrapper = mountPage()
		await flushPromises()

		wrapper.vm.form.user_id = 5
		wrapper.vm.form.company_id = 45
		await flushPromises()
		wrapper.vm.input = "100"
		await wrapper.vm.submit()
		await flushPromises()

		const payload = createWalletTopUpMock.mock.calls[0][0]
		expect(payload.user_id).toBe(5)
		expect(payload).not.toHaveProperty("company_id")
		wrapper.unmount()
	})

	it("does not post at all when no recipient is selected", async () => {
		const wrapper = mountPage()
		await flushPromises()

		wrapper.vm.input = "100"
		await wrapper.vm.submit()
		await flushPromises()

		expect(createWalletTopUpMock).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it("does not post fewer points than the minimum, or a malformed entry", async () => {
		const wrapper = mountPage()
		await flushPromises()
		wrapper.vm.form.user_id = 5
		await flushPromises()

		for (const entry of ["0", "9", "12.5", "abc", "-5"]) {
			wrapper.vm.input = entry
			await wrapper.vm.submit()
			await flushPromises()
		}

		expect(createWalletTopUpMock).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it("in amount mode, posts only multiples of the currency's cash step", async () => {
		getWalletBalanceMock.mockResolvedValue({
			...BALANCE,
			top_up: { ...TOP_UP_CONFIG, currencies: [{ code: "SYP", rate_to_base: "0.0068027211", cash_step: "10.00", decimal_places: 2 }] }
		})
		const wrapper = mountPage()
		await flushPromises()
		wrapper.vm.form.user_id = 5
		await flushPromises()

		wrapper.vm.mode = "amount"
		wrapper.vm.input = "4995"
		await wrapper.vm.submit()
		await flushPromises()
		expect(createWalletTopUpMock).not.toHaveBeenCalled()

		wrapper.vm.input = "5000"
		await wrapper.vm.submit()
		await flushPromises()
		expect(createWalletTopUpMock).toHaveBeenCalledWith(expect.objectContaining({ points: 340, amount: "5000", currency: "SYP" }))
		wrapper.unmount()
	})

	it("shows and posts the higher count when cash rounding raises it", async () => {
		const wrapper = mountPage()
		await flushPromises()
		wrapper.vm.form.user_id = 5
		await flushPromises()
		wrapper.vm.selectedCurrency = "USD"
		await flushPromises()

		wrapper.vm.input = "345"
		await flushPromises()
		expect(wrapper.text()).toContain("Raised to 350 points by cash rounding")

		await wrapper.vm.submit()
		await flushPromises()
		expect(createWalletTopUpMock).toHaveBeenCalledWith(expect.objectContaining({ points: 350, amount: "35", currency: "USD" }))
		wrapper.unmount()
	})

	it("posts a company top-up when an affiliated member selects company balance scope", async () => {
		listUsersMock.mockResolvedValue([{ ...sampleUser, companies: [{ id: 45, legal_name: "Halab Tech", status: "active" }] }])
		const wrapper = mountPage()
		await flushPromises()

		wrapper.vm.form.user_id = 5
		await flushPromises()
		wrapper.vm.userBalanceScope = "company"
		await flushPromises()
		wrapper.vm.input = "500"
		await wrapper.vm.submit()
		await flushPromises()

		expect(createWalletTopUpMock).toHaveBeenCalledWith({
			points: 500,
			amount: "7350",
			currency: "SYP",
			payment_method: "cash",
			company_id: 45,
			description: "Top-up via member Sara"
		})
		wrapper.unmount()
	})

	it("posts a top-up with a custom selected currency (e.g. USD)", async () => {
		const wrapper = mountPage()
		await flushPromises()

		wrapper.vm.form.user_id = 5
		await flushPromises()
		wrapper.vm.selectedCurrency = "USD"
		await flushPromises()
		wrapper.vm.input = "1000"
		await wrapper.vm.submit()
		await flushPromises()

		expect(createWalletTopUpMock).toHaveBeenCalledWith({
			points: 1000,
			amount: "100",
			currency: "USD",
			payment_method: "cash",
			user_id: 5,
			description: null
		})
		wrapper.unmount()
	})

	it("blocks submit when selected company is inactive", async () => {
		listCompaniesMock.mockResolvedValue([{ ...sampleCompany, status: "inactive" }])
		const wrapper = mountPage()
		await flushPromises()

		wrapper.vm.recipientType = "company"
		await flushPromises()
		wrapper.vm.form.company_id = 45
		await flushPromises()
		wrapper.vm.input = "500"
		await wrapper.vm.submit()
		expect(createWalletTopUpMock).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it("opens the receipt modal with transaction details upon successful top-up", async () => {
		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.vm.receiptModalVisible).toBe(false)
		expect(wrapper.vm.lastReceipt).toBeNull()

		wrapper.vm.form.user_id = 5
		await flushPromises()
		wrapper.vm.input = "500"
		await wrapper.vm.submit()
		await flushPromises()

		expect(wrapper.vm.receiptModalVisible).toBe(true)
		expect(wrapper.vm.lastReceipt).toMatchObject({ id: 789, amount: "50.00", points_credited: 500 })

		wrapper.vm.closeReceiptModal()
		expect(wrapper.vm.receiptModalVisible).toBe(false)
		wrapper.unmount()
	})
```

The tests these replace: `posts a member top-up with user_id and no company_id, then resets the form`; `posts a company top-up with company_id and no user_id`; `surfaces 422 field errors against the fields the backend named, and keeps the form filled`; `never posts both user_id and company_id, even when a stale id survives in the model`; `does not post at all when no recipient is selected`; `does not post an amount below the 0.01 minimum, negative, or not a plain decimal`; `does not post a fractional or sub-one SYP amount`; `still accepts a fractional amount in a non-SYP currency`; `posts a company top-up when an affiliated member selects company balance scope`; `posts a top-up with a custom selected currency (e.g. USD)`; `blocks submit when selected company is inactive`; `opens the receipt modal with transaction details upon successful top-up`. Unchanged: `clears the company selection when the recipient type switches to member, and vice versa`, `does not trigger validation errors when currency or language changes`, `adapts the back arrow to document direction`.

- [ ] **Step 2: Run to verify it fails**

Run: `pnpm vitest run src/add-os/modules/payments/views/__tests__/WalletTopUpsPage.spec.ts`
Expected: FAIL — `wrapper.vm.input` is undefined and payloads lack `points`.

- [ ] **Step 3: Implement — template**

Replace the whole `<!-- Top-up Amount Section & Preset Chips -->` `<n-card>` (from that comment through its closing `</n-card>`, just before `<!-- Optional Description / Notes -->`) with:

```vue
							<!-- Top-up: points (default) or cash; useTopUpQuote derives the other figure -->
							<n-card size="small" class="shadow-sm border border-stone-200">
								<div class="flex flex-col gap-3">
									<div class="flex items-center justify-between gap-2">
										<span class="text-sm font-bold text-stone-900">{{ t("walletTopUps.form.amount") }}</span>
										<n-radio-group v-model:value="mode" size="small">
											<n-radio-button value="points">{{ t("points.entryMode.points") }}</n-radio-button>
											<n-radio-button value="amount">{{ t("points.entryMode.amount") }}</n-radio-button>
										</n-radio-group>
									</div>

									<n-form-item
										path="points"
										:feedback="fieldErrors.points?.[0] ?? fieldErrors.amount?.[0]"
										:validation-status="fieldErrors.points || fieldErrors.amount ? 'error' : undefined"
										class="m-0 w-full"
									>
										<div
											class="flex items-center w-full border border-stone-200 rounded-xl bg-white shadow-2xs overflow-hidden focus-within:border-teal-600 focus-within:ring-1 focus-within:ring-teal-600 transition-all"
										>
											<div class="w-32 sm:w-36 shrink-0 bg-stone-50/70 border-e border-stone-200">
												<FormIsolatedSelect
													v-model:value="selectedCurrency"
													:options="currencyOptions"
													:bordered="false"
													:consistent-menu-width="false"
													class="font-bold text-stone-700"
												/>
											</div>
											<n-input
												v-model:value="input"
												:placeholder="mode === 'points' ? t('points.pointsPlaceholder') : t('walletTopUps.form.amount')"
												:input-props="{ 'data-testid': 'top-up-input', 'inputmode': mode === 'points' ? 'numeric' : 'decimal' }"
												:bordered="false"
												class="flex-1 text-base font-bold min-w-0"
											/>
											<span class="text-xs font-bold text-stone-400 shrink-0 px-3 whitespace-nowrap">{{ mode === "points" ? t("points.unit") : selectedCurrency }}</span>
										</div>
									</n-form-item>

									<div class="min-h-5 flex flex-col gap-0.5 text-xs">
										<span v-if="quoteErrorMessage" class="text-red-600">{{ quoteErrorMessage }}</span>
										<template v-else-if="quoteSummary">
											<span class="font-bold text-teal-700 add-numeric">{{ quoteSummary }}</span>
											<span v-if="quoteNote" class="text-stone-500 add-numeric">{{ quoteNote }}</span>
										</template>
									</div>

									<div v-if="presets.length" class="grid grid-cols-4 gap-2">
										<button
											v-for="preset in presets"
											:key="preset"
											type="button"
											class="py-2.5 px-2 rounded-xl text-xs font-bold border transition-all text-teal-700 border-teal-200 bg-teal-50/50 hover:bg-teal-100 shadow-2xs cursor-pointer"
											@click="addPreset(preset)"
										>
											+ {{ formatNumber(preset) }}
										</button>
									</div>
								</div>
							</n-card>
```

In the Wallet Preview card, replace:

```vue
											<!-- The points the server quoted, above the cash that buys them. -->
											<span class="font-extrabold text-emerald-800 text-xs sm:text-sm mt-0.5">
												{{ previewPoints === null ? "—" : `+ ${formatPoints(previewPoints)}` }}
											</span>
											<span class="text-emerald-700/70 text-[10px] add-numeric">{{ formattedInputAmount }}</span>
```

with:

```vue
											<!-- The points this top-up credits, above the cash that buys them. -->
											<span class="font-extrabold text-emerald-800 text-xs sm:text-sm mt-0.5">
												{{ quote === null ? "—" : `+ ${formatPoints(quote.points)}` }}
											</span>
											<span class="text-emerald-700/70 text-[10px] add-numeric">{{ collectText }}</span>
```

On the submit `<n-button>`, change `:disabled="isSelectedCompanyInactive || isSubmitting"` to `:disabled="isSelectedCompanyInactive || isSubmitting || quote === null"`.

- [ ] **Step 4: Implement — script**

1. Imports: change `import { createWalletTopUp, getWalletBalance, previewWalletTopUp } from "@/add-os/services/wallet-top-ups"` to `import { createWalletTopUp, getWalletBalance } from "@/add-os/services/wallet-top-ups"`; change `import { formatCurrency, formatDateTime, isCurrencyCode } from "@/add-os/utils/format"` to `import { formatCurrency, formatDateTime, formatNumber, isCurrencyCode } from "@/add-os/utils/format"`; add `import { useTopUpQuote } from "@/add-os/modules/payments/composables/useTopUpQuote"`; remove `onBeforeUnmount` from the `vue` import; remove `NSpin` from the `naive-ui` import if nothing else in the template uses `<n-spin>` (search the file for `n-spin`).
2. Delete the block from `const AMOUNT_PLACEHOLDER = "Enter amount"` through the end of `function isValidAmount(...) {...}`, including the SYP comment between them.
3. In `emptyForm()`, delete the line `amount: "",`.
4. Delete the `amountPresets` computed.
5. Delete everything from `const PREVIEW_DEBOUNCE_MS = 300` through the end of `function schedulePreview() {...}`, and put this in its place:

```ts
/**
 * Points ↔ cash, converted here as the operator types (ADDCore spec
 * 2026-10-10-wallet-top-up-points-entry-design.md). The rates arrive with the
 * balance read; the server re-checks the quote on submit.
 */
const {
	config: quoteConfig,
	mode,
	input,
	quote,
	error: quoteError,
	errorMessage: quoteErrorMessage,
	summary: quoteSummary,
	note: quoteNote,
	presets,
	addPreset,
	reset: resetQuote
} = useTopUpQuote(selectedCurrency)
```

6. Replace the `formattedInputAmount` computed with:

```ts
/** The cash under the points this top-up adds. */
const collectText = computed(() => `${quote.value === null ? "0" : formatNumber(quote.value.amount)} ${selectedCurrency.value}`)
```

7. Replace the `calculatedNewBalancePoints` computed (keep the name) and its comment with:

```ts
/** The balance after this top-up: two whole point counts, added — the quote already converted. */
const calculatedNewBalancePoints = computed(() => {
	if (currentBalancePoints.value === null) return null
	if (quote.value === null) return currentBalancePoints.value
	return currentBalancePoints.value + quote.value.points
})
```

8. In `fetchBalance()`: in the `if (!ownerParams) {...}` branch add `quoteConfig.value = null`; after `currentBalancePoints.value = res.balance_points` add `quoteConfig.value = res.top_up ?? null`; in the `catch` branch add `quoteConfig.value = null`.
9. Delete the `watch([...], schedulePreview)` call with its comment, and `onBeforeUnmount(cancelPendingPreview)`.
10. In the `watch(recipientType, ...)` callback, replace `resetPreview()` with `resetQuote()`.
11. Delete `function addPresetAmount(...) {...}`.
12. In `rules`, delete the whole `amount: {...},` entry.
13. Replace the whole `async function submit() {...}` with:

```ts
async function submit() {
	try {
		await formRef.value?.validate()
	} catch {
		return
	}

	if (isSelectedCompanyInactive.value) {
		message.error(t("walletTopUps.cannotTopUpInactive"))
		return
	}

	const recipient = buildRecipient()
	const current = quote.value
	if (current === null || recipient === null) return

	const description = form.value.description.trim()
		|| (recipientType.value === "user" && userBalanceScope.value === "company" && selectedUser.value
			? `Top-up via member ${selectedUser.value.name}`
			: null)

	const payload = {
		points: current.points,
		amount: current.amount,
		currency: selectedCurrency.value,
		payment_method: form.value.payment_method,
		...recipient,
		description
	} as WalletTopUpPayload

	fieldErrors.value = {}
	isSubmitting.value = true
	try {
		const res = await createWalletTopUp(payload)
		lastReceipt.value = res
		receiptModalVisible.value = true
		message.success(t("walletTopUps.submit.success"))
		form.value = emptyForm()
		resetQuote()
		currentBalance.value = null
		currentBalancePoints.value = null
		balanceData.value = null
		formRef.value?.restoreValidation()
	} catch (caught) {
		if (!(caught instanceof ApiError)) throw caught
		if (caught.status === 422 && caught.data?.errors) {
			fieldErrors.value = caught.data.errors
			// A `points` error is the server refusing the quote — usually rates
			// that moved after this page read them. Re-read them so the figures
			// update; the operator confirms with the member and submits again.
			if (caught.data.errors.points) await fetchBalance()
		}
		else if (caught.status === 403) message.error(t("resourceCrud.mutations.permissionError"))
		else message.error(caught.data?.message ?? t("resourceCrud.mutations.genericError"))
	} finally {
		isSubmitting.value = false
	}
}
```

14. In `defineExpose({...})`, replace `previewPoints,` and `previewError,` with `input,`, `mode,`, `quote,`, `quoteError,`.

- [ ] **Step 5: Run to verify it passes**

Run: `pnpm vitest run src/add-os/modules/payments`
Expected: all PASS.

- [ ] **Step 6: Lint and commit**

```bash
pnpm eslint --fix src/add-os/modules/payments/views
git add src/add-os/modules/payments/views
git commit -m "feat(payments): wallet top-up page takes points first and converts locally

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 10: Cash step on the Currencies page; minimum's label on Settings (ADDOS)

**Files:**
- Modify: `src/add-os/modules/payments/types/currency.ts`
- Modify: `src/add-os/modules/payments/config/currencies.config.ts`
- Modify: `src/add-os/lang/en/en.json`, `src/add-os/lang/ar/ar.json` (`currencies.form`, `currencies.columns`, `systemSettings.fields`)
- Test: `src/add-os/modules/payments/config/__tests__/currencies.config.spec.ts`, `src/add-os/modules/payments/views/__tests__/CurrenciesPage.spec.ts`

**Interfaces:**
- Consumes: `cash_step` in the admin currency API (Task 2), setting key `wallet.top_up_min_points` (Task 3).
- Produces: `Currency.cash_step: string`; `CurrencyCreatePayload.cash_step: number`; `CurrencyUpdatePayload.cash_step: number`.

- [ ] **Step 1: Update the specs**

In `currencies.config.spec.ts`: add `cash_step: "1.00",` after `decimal_places: 2,` in both `baseRow` and `plainRow`. Change the `currencyEditPayload copies only the four updatable fields off a row` test to:

```ts
	it("currencyEditPayload copies only the updatable fields off a row, cash_step as a number", () => {
		expect(currencyEditPayload({ ...baseRow, cash_step: "0.50" })).toEqual({
			name: { ar: "دولار أمريكي", en: "US Dollar" },
			symbol: "$",
			decimal_places: 2,
			cash_step: 0.5,
			order: 2
		})
	})
```

and append inside the `describe` that holds `marks name and decimal_places required on edit`:

```ts
	it("offers cash_step on both forms and requires it", () => {
		expect(currencyCreateFields(t).find(field => field.key === "cash_step")?.required).toBe(true)
		expect(currencyEditFields.find(field => field.key === "cash_step")?.required).toBe(true)
	})
```

and append inside `describe("currency payload builders", ...)`:

```ts
	it("emptyCurrencyPayload starts a new currency at a cash step of 1", () => {
		expect(emptyCurrencyPayload().cash_step).toBe(1)
	})
```

In `CurrenciesPage.spec.ts`: add `cash_step: "1.00",` after `decimal_places: 2,` in both the `usd` and `syp` fixtures; add `cash_step: 1,` after `decimal_places: 2,` in the three `wrapper.vm.submit({...})` payloads.

- [ ] **Step 2: Run to verify it fails**

Run: `pnpm vitest run src/add-os/modules/payments/config src/add-os/modules/payments/views/__tests__/CurrenciesPage.spec.ts`
Expected: the new and changed config tests FAIL (`cash_step` field missing).

- [ ] **Step 3: Implement**

In `types/currency.ts`: add to `Currency` after `decimal_places: number`:

```ts
	/** The smallest amount the till takes in this currency, decimal string, e.g. "1.00". */
	cash_step: string
```

and add `cash_step: number` after `decimal_places: number` in both `CurrencyCreatePayload` and `CurrencyUpdatePayload`.

In `config/currencies.config.ts`:
- in `sharedCurrencyFields`, insert after the `decimal_places` descriptor:

```ts
	{ key: "cash_step", labelKey: "currencies.form.cashStep", type: "number", required: true },
```

- update the comment above `sharedCurrencyFields` to say "The fields both forms share" and mention that `cash_step` (the smallest amount the till takes, used to round top-ups) is optional server-side but always sent, defaulting to 1.
- in the columns array, insert after the `decimal_places` column:

```ts
		{
			title: t("currencies.columns.cashStep"),
			key: "cash_step",
			render: row => h("span", { class: NUMERIC_CELL_CLASS }, formatNumber(row.cash_step))
		},
```

- `emptyCurrencyPayload()` returns `{ code: "", name: { ar: "", en: "" }, symbol: "", decimal_places: 2, cash_step: 1, order: null }`.
- `currencyEditPayload(row)` returns `{ name: { ...row.name }, symbol: row.symbol ?? "", decimal_places: row.decimal_places, cash_step: Number(row.cash_step), order: row.order }`.

Strings — `en.json`: add `"cashStep": "Cash step"` to `currencies.form` and to `currencies.columns`; add to `systemSettings.fields`:

```json
"wallet_top_up_min_points": {
	"label": "Minimum top-up (points)",
	"hint": "The fewest points reception can credit in one top-up."
}
```

`ar.json`: add `"cashStep": "خطوة المبلغ النقدي"` to `currencies.form` and to `currencies.columns`; add to `systemSettings.fields`:

```json
"wallet_top_up_min_points": {
	"label": "الحد الأدنى للشحن (نقاط)",
	"hint": "أقل عدد من النقاط يمكن للاستقبال شحنه في عملية واحدة."
}
```

Check both JSON files parse (same `node -e` command as Task 7, Step 2).

- [ ] **Step 4: Run to verify it passes**

Run: `pnpm vitest run src/add-os/modules/payments src/add-os/modules/settings`
Expected: all PASS.

- [ ] **Step 5: Lint and commit**

```bash
pnpm eslint --fix src/add-os/modules/payments/config src/add-os/modules/payments/types src/add-os/modules/payments/views/__tests__/CurrenciesPage.spec.ts
git add src/add-os/modules/payments src/add-os/lang
git commit -m "feat(currencies): edit the cash step; label the minimum top-up setting

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 11: Remove the preview client and verify ADDOS

**Files:**
- Modify: `src/add-os/services/wallet-top-ups.ts`
- Modify: `src/add-os/modules/payments/types/wallet-top-up.ts`
- Modify: `src/add-os/lang/en/en.json`, `src/add-os/lang/ar/ar.json` (only keys proven unused)

- [ ] **Step 1: Remove the preview client**

In `services/wallet-top-ups.ts`: delete `const PREVIEW_URL = ...`, the whole `previewWalletTopUp` function with its docblock, and `WalletTopUpPreview` / `WalletTopUpPreviewPayload` from the type import.

In `types/wallet-top-up.ts`: delete `WalletTopUpPreview` and `WalletTopUpPreviewPayload` with their docblocks. In the `WalletTopUpPayload` docblock, keep the paragraph about `amount` being a string.

Run: `git grep -n "previewWalletTopUp\|WalletTopUpPreview" -- src` → no output.

- [ ] **Step 2: Drop strings nothing uses any more**

For each key below, run `git grep -n "<key>" -- src ':!src/add-os/lang'`. If there is no output, delete the key from both `en.json` and `ar.json`; if anything still uses it, leave it.

- `points.previewLoading` (search `previewLoading`)
- `points.previewFailed` (search `previewFailed`)
- `points.belowOnePoint` (search `belowOnePoint`)
- `walletTopUps.validation.amountFormat` (search `amountFormat"` and `amountFormat'`)
- `walletTopUps.validation.amountFormatSyp` (search `amountFormatSyp`)

Check both JSON files parse.

- [ ] **Step 3: Full verification**

Run each and compare with the Task 6 baseline:

```bash
pnpm type-check
```
Expected: no errors beyond the baseline. In particular none in `WalletTopUpDialog.vue`, `WalletTopUpsPage.vue`, `useTopUpQuote.ts`, `top-up-math.ts`, `wallet-top-up.ts`, `currency.ts` or their specs.

```bash
pnpm lint:check
```
Expected: no errors beyond the baseline.

```bash
pnpm test:unit
```
Expected: everything passes except failures already in the baseline.

- [ ] **Step 4: Commit**

```bash
git add src/add-os/services/wallet-top-ups.ts src/add-os/modules/payments/types/wallet-top-up.ts src/add-os/lang
git commit -m "chore(payments): drop the top-up preview client and its unused strings

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Task 12: Verify ADDCore

- [ ] **Step 1: Full suite against the baseline**

```bash
php artisan test 2>&1 | grep -E "FAILED|Tests:" 
```

The suite has about 17 failures that predate this branch: audit-log tests comparing `'1'` with `1` under SQLite, and the EventAttendee enum guard. Expected: only those. For any other failure, check it against main without touching this worktree:

```bash
git diff --name-only main...HEAD
```

If the failing test or the code it covers is in that list, it is a regression from this branch: fix it before finishing. If it is not, confirm by checking out main into a scratch worktree (`git worktree add ../addcore-main-check main`, run that one test there, then `git worktree remove ../addcore-main-check`).

- [ ] **Step 2: Style**

```bash
./vendor/bin/pint --test
```
Expected: passes. If it reports files this branch did not touch, leave them alone.

- [ ] **Step 3: Routes**

```bash
php artisan route:list --path=reception/wallet
```
Expected: `GET .../reception/wallet-balance` and `POST .../reception/wallet-top-ups`; no `preview`.

## Deploy order (for whoever ships it)

1. ADDCore: `php artisan migrate`, then `php artisan db:seed --class=SettingSeeder` (idempotent) so the minimum's row exists for the settings page.
2. ADDOS after that. Until it ships, the old dashboard's top-ups get a 422 (no `points`), so no wallet is credited wrongly — but reception cannot top up, so keep the gap short.
