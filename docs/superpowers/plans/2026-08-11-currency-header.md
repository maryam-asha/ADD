# Currency Request Header + Postman Documentation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `currency: USD|SYP` request header overrides `preferred_currency` for a single request, `SYP` becomes the system-wide default (DB column default + `PlanResource`'s conversion fallback), and both this and the existing `lang` header get documented in the team's Postman collection.

**Architecture:** A small `CurrencyResolver` service (header → stored preference → `SYP` default) is called directly from `PlanResource` — no middleware, no listener, no global state, because `preferred_currency` has exactly one consumer today and that consumer already resolves the user itself, synchronously, at the point it needs the value. A new migration backfills existing `NULL` rows and makes the column default to `'SYP'`. A one-off script rewrites the Postman collection and environment files.

**Tech Stack:** Laravel 12, PHPUnit (class-based `test_*` methods), Sanctum.

## Global Constraints

- Header name exactly `currency`, values `USD`/`SYP` (the only two `Currency` enum cases), case-insensitive. An invalid or absent header must never cause a 4xx by itself.
- A valid header always overrides `preferred_currency`, unconditionally.
- `preferred_currency` becomes non-nullable with a DB-level default of `'SYP'` — no user, past or future, is ever left null.
- `converted_amount`/`converted_currency` on `PlanResource` become always-present fields (previously conditional on a preference being set) — this is an intentional, confirmed API contract change.
- No general per-request "current currency" mechanism — scoped to `PlanResource`, the only current consumer (YAGNI).
- Full spec: `docs/superpowers/specs/2026-08-11-currency-header-design.md`.

---

## Task 1: Database default + `UserFactory`

**Files:**
- Create: `database/migrations/2026_08_11_090000_default_preferred_currency_to_syp.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Guards/PreferredCurrencyDefaultsToSypTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: after this task, every `users` row has a real `preferred_currency` value (`'USD'` or `'SYP'`), never `null`. `User::factory()->create()` (no override) yields `preferred_currency === 'SYP'` on the in-memory model, not just the DB row — later tasks' tests rely on this to represent "no override" cleanly.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Guards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * docs/superpowers/specs/2026-08-11-currency-header-design.md §3: SYP is
 * the system-wide default for `preferred_currency`, enforced at the
 * database column level (not just application code) so no user is ever
 * left with a null preference.
 */
class PreferredCurrencyDefaultsToSypTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_column_default_is_syp(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'No Override',
            'phone' => '0911111111',
            'password' => 'hashed',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('users', ['id' => $id, 'preferred_currency' => 'SYP']);
    }
}
```

Note: a dedicated test for the migration's `whereNull(...)->update(...)` backfill step is intentionally not included — `RefreshDatabase` runs every migration against an already-empty table for each test, so there is never a pre-existing `NULL` row left over by the time a test could assert against it. This mirrors the earlier `lang` header plan's own precedent of documenting a scope decision rather than writing a test that can't meaningfully exercise the code it targets.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Guards/PreferredCurrencyDefaultsToSypTest.php`
Expected: FAIL — inserting a row without `preferred_currency` currently leaves it `NULL` (the column has no default yet), so `assertDatabaseHas(..., ['preferred_currency' => 'SYP'])` fails.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('preferred_currency')->update(['preferred_currency' => 'SYP']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('preferred_currency', 3)->default('SYP')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('preferred_currency', 3)->nullable()->default(null)->change();
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Guards/PreferredCurrencyDefaultsToSypTest.php`
Expected: PASS

- [ ] **Step 5: Update `UserFactory`**

Open `database/factories/UserFactory.php`. It already has this exact lesson documented for the `status` column — read the comment above `'status' => 'active',` in `definition()` before editing, then add a sibling line right after it:

```php
            // Same lesson as `status` above: the migration's column default
            // isn't re-fetched into this unrefreshed in-memory instance, so a
            // factory-created user with an unset `preferred_currency` would
            // read as null here even though the real DB row is 'SYP'.
            'preferred_currency' => 'SYP',
```

The surrounding `definition()` array should now read:

```php
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('09########'),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Set explicitly, not left to the migration's column default —
            // Eloquent doesn't re-fetch DB-side defaults into an unrefreshed
            // model (the same lesson already documented in the build plan's
            // Phase 2 notes for CompanyController::store), and
            // Sanctum::actingAs() in tests uses this in-memory instance
            // directly, so a factory-created user with an unset `status`
            // would otherwise fail any `status === 'active'` check even
            // though the actual DB row is correctly 'active'.
            'status' => 'active',
            // Same lesson as `status` above: the migration's column default
            // isn't re-fetched into this unrefreshed in-memory instance, so a
            // factory-created user with an unset `preferred_currency` would
            // read as null here even though the real DB row is 'SYP'.
            'preferred_currency' => 'SYP',
        ];
    }
```

- [ ] **Step 6: Add a test proving the factory fix**

Append to `tests/Guards/PreferredCurrencyDefaultsToSypTest.php`:

```php
    public function test_a_factory_created_user_with_no_override_has_syp_on_the_in_memory_model(): void
    {
        $user = \App\Domain\Identity\Models\User::factory()->create();

        $this->assertSame('SYP', $user->preferred_currency);
    }
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Guards/PreferredCurrencyDefaultsToSypTest.php`
Expected: PASS (2 tests)

- [ ] **Step 8: Run the full suite**

Run: `composer test`
Expected: all pre-existing tests pass, plus these 2 new ones. **Note:** `tests/Feature/Membership/PlanPriceConversionTest.php::test_no_converted_amount_when_preferred_currency_is_not_set` explicitly does `User::factory()->create(['preferred_currency' => null])` — after this migration, the column is `NOT NULL`, so this specific test will now FAIL with a database constraint violation (not an assertion failure — an exception). This is expected and tracked: Task 3 rewrites that entire test file, including removing this now-impossible test case. Confirm this is the *only* failure; anything else is a real regression.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_11_090000_default_preferred_currency_to_syp.php database/factories/UserFactory.php tests/Guards/PreferredCurrencyDefaultsToSypTest.php
git commit -m "feat: default preferred_currency to SYP at the database level"
```

---

## Task 2: `CurrencyResolver`

**Files:**
- Create: `app/Domain/Finance/Services/CurrencyResolver.php`
- Test: `tests/Unit/Domain/Finance/CurrencyResolverTest.php`

**Interfaces:**
- Consumes: `App\Domain\Finance\Enums\Currency` (existing — `Currency::Usd`, `Currency::Syp`, `Currency::tryFrom()`).
- Produces: `CurrencyResolver::resolve(Request $request, ?User $user): string` — always returns `'USD'` or `'SYP'`, never null, never anything else. Task 3 calls this directly from `PlanResource`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Services\CurrencyResolver;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class CurrencyResolverTest extends TestCase
{
    private function requestWithCurrencyHeader(?string $value): Request
    {
        $server = $value === null ? [] : ['HTTP_CURRENCY' => $value];

        return Request::create('/', 'GET', server: $server);
    }

    public function test_a_valid_header_wins_over_the_stored_preference(): void
    {
        $user = User::factory()->make(['preferred_currency' => 'SYP']);

        $resolved = (new CurrencyResolver)->resolve($this->requestWithCurrencyHeader('USD'), $user);

        $this->assertSame('USD', $resolved);
    }

    public function test_the_header_is_case_insensitive(): void
    {
        $user = User::factory()->make(['preferred_currency' => 'SYP']);

        $resolved = (new CurrencyResolver)->resolve($this->requestWithCurrencyHeader('usd'), $user);

        $this->assertSame('USD', $resolved);
    }

    public function test_an_invalid_header_falls_back_to_the_stored_preference(): void
    {
        $user = User::factory()->make(['preferred_currency' => 'USD']);

        $resolved = (new CurrencyResolver)->resolve($this->requestWithCurrencyHeader('EUR'), $user);

        $this->assertSame('USD', $resolved);
    }

    public function test_a_missing_header_falls_back_to_the_stored_preference(): void
    {
        $user = User::factory()->make(['preferred_currency' => 'USD']);

        $resolved = (new CurrencyResolver)->resolve($this->requestWithCurrencyHeader(null), $user);

        $this->assertSame('USD', $resolved);
    }

    public function test_no_user_and_no_header_falls_back_to_syp(): void
    {
        $resolved = (new CurrencyResolver)->resolve($this->requestWithCurrencyHeader(null), null);

        $this->assertSame('SYP', $resolved);
    }
}
```

`User::factory()->make(...)` builds an in-memory model without touching the database, so this test extends plain `Tests\TestCase` — no `RefreshDatabase` needed, it's a fast unit test.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Finance/CurrencyResolverTest.php`
Expected: FAIL — class `App\Domain\Finance\Services\CurrencyResolver` does not exist.

- [ ] **Step 3: Create `CurrencyResolver`**

```php
<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\Currency;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * Deliberately a plain resolver, not middleware — unlike the `lang`
 * header's SetLocaleFromHeader/SetLocaleFromUserPreference pair, there is
 * no auth-timing ordering trap here: PlanResource already resolves the
 * user itself, synchronously, at the exact point it needs this value.
 */
class CurrencyResolver
{
    public function resolve(Request $request, ?User $user): string
    {
        $header = strtoupper((string) $request->header('currency'));

        if (Currency::tryFrom($header) !== null) {
            return $header;
        }

        return $user?->preferred_currency ?? Currency::Syp->value;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Finance/CurrencyResolverTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Run the full suite**

Run: `composer test`
Expected: same result as the end of Task 1 (the one tracked `PlanPriceConversionTest` failure, plus these 5 new passing tests). Record the total.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Finance/Services/CurrencyResolver.php tests/Unit/Domain/Finance/CurrencyResolverTest.php
git commit -m "feat: add CurrencyResolver for header-driven currency overrides"
```

---

## Task 3: `PlanResource` integration + full test rewrite

**Files:**
- Modify: `app/Http/Resources/PlanResource.php`
- Modify (full rewrite): `tests/Feature/Membership/PlanPriceConversionTest.php`

**Interfaces:**
- Consumes: `CurrencyResolver::resolve()` (Task 2), `CurrencyConversionService::convert()` (existing, unchanged).
- Produces: no new interface — `PlanResource::toArray()`'s output shape gains always-present `converted_amount`/`converted_currency` (when the target currency differs from the plan's pricing currency and an exchange rate exists).

- [ ] **Step 1: Replace `PlanResource.php`**

```php
<?php

namespace App\Http\Resources;

use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Finance\Services\CurrencyResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'is_subscription' => $this->is_subscription,
            'price' => $this->price,
            'pricing_currency' => $this->pricing_currency,
            'duration_days' => $this->duration_days,
            'included_hours' => $this->included_hours,
            'overage_rate' => $this->overage_rate,
            'is_active' => $this->is_active,
            'order' => $this->order,
            'created_at' => $this->created_at,
        ];

        // Resolved directly from the guard, not the route — this lets
        // conversion opportunistically activate even on public routes with
        // no auth:sanctum middleware, as long as a valid bearer token is
        // sent (Unit 1 design, 2026-08-09). Resolving the guard fires
        // Sanctum's TokenAuthenticated event, which
        // EnsureAuthenticatedUserIsActive listens to and aborts (403) for a
        // suspended/blocked account — never intended to affect this
        // opportunistic, otherwise-always-200 public listing, so that abort
        // is treated the same as "no user resolvable for conversion".
        try {
            $user = $request->user('sanctum');
        } catch (HttpException) {
            $user = null;
        }

        // Docs/superpowers/specs/2026-08-11-currency-header-design.md §2:
        // a target currency is always resolved (defaulting to SYP), so
        // conversion is now attempted unconditionally rather than only
        // when a preference happens to be set.
        $targetCurrency = app(CurrencyResolver::class)->resolve($request, $user);

        if ($targetCurrency !== $this->pricing_currency) {
            $converted = app(CurrencyConversionService::class)->convert(
                (float) $this->price,
                $this->pricing_currency,
                $targetCurrency
            );

            if ($converted !== null) {
                $data['converted_amount'] = number_format($converted, 2, '.', '');
                $data['converted_currency'] = $targetCurrency;
            }
        }

        return $data;
    }
}
```

- [ ] **Step 2: Replace `PlanPriceConversionTest.php` entirely**

```php
<?php

namespace Tests\Feature\Membership;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanPriceConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_a_converted_amount_is_added_when_the_users_preferred_currency_differs(): void
    {
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '14700.0000', 'effective_from' => now()->subDay()]);
        $plan = Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD']);
        $admin = User::factory()->create(['preferred_currency' => 'SYP']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        // converted_amount is formatted as a decimal string (number_format),
        // matching every other money field on this resource (price,
        // pricing_currency, overage_rate) — not a raw JSON number, which
        // would silently drop the fractional part for whole-number amounts.
        $response->assertJsonPath('data.converted_amount', '147000.00');
        $response->assertJsonPath('data.converted_currency', 'SYP');
    }

    public function test_no_converted_amount_when_preferred_currency_matches_pricing_currency(): void
    {
        $plan = Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD']);
        $admin = User::factory()->create(['preferred_currency' => 'USD']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $this->assertArrayNotHasKey('converted_amount', $response->json('data'));
    }

    public function test_no_converted_amount_when_no_exchange_rate_exists(): void
    {
        $plan = Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD']);
        $admin = User::factory()->create(['preferred_currency' => 'SYP']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $this->assertArrayNotHasKey('converted_amount', $response->json('data'));
    }

    /**
     * Replaces the old `preferred_currency: null` test — that state no
     * longer exists after the SYP-default migration (the column is
     * NOT NULL). A member created with no override now genuinely has
     * `preferred_currency === 'SYP'` (UserFactory sets it explicitly,
     * matching the DB default), so this proves the *default*, not a
     * missing value.
     */
    public function test_a_new_member_with_no_currency_override_gets_syp_by_default(): void
    {
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '14700.0000', 'effective_from' => now()->subDay()]);
        $plan = Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $response->assertJsonPath('data.converted_amount', '147000.00');
        $response->assertJsonPath('data.converted_currency', 'SYP');
    }

    public function test_the_currency_header_overrides_the_stored_preference(): void
    {
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '14700.0000', 'effective_from' => now()->subDay()]);
        $plan = Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD']);
        $admin = User::factory()->create(['preferred_currency' => 'USD']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->withHeader('currency', 'SYP')->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $response->assertJsonPath('data.converted_amount', '147000.00');
        $response->assertJsonPath('data.converted_currency', 'SYP');
    }

    public function test_the_currency_header_works_for_anonymous_requests_too(): void
    {
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '14700.0000', 'effective_from' => now()->subDay()]);
        Plan::factory()->create(['price' => '14700.00', 'pricing_currency' => 'SYP', 'is_active' => true]);

        $response = $this->withHeader('currency', 'USD')->getJson('/api/v1/plans');

        $response->assertOk();
        $response->assertJsonPath('data.0.converted_amount', '1.00');
        $response->assertJsonPath('data.0.converted_currency', 'USD');
    }

    public function test_an_invalid_currency_header_value_falls_back_to_the_stored_preference(): void
    {
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '14700.0000', 'effective_from' => now()->subDay()]);
        $plan = Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD']);
        $admin = User::factory()->create(['preferred_currency' => 'SYP']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->withHeader('currency', 'EUR')->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $response->assertJsonPath('data.converted_currency', 'SYP');
    }

    /**
     * Resolving `$request->user('sanctum')` inside PlanResource fires
     * Sanctum's TokenAuthenticated event, which
     * EnsureAuthenticatedUserIsActive listens to and aborts (403) for a
     * suspended/blocked account. The public plans route has no
     * auth:sanctum middleware and was always a 200 regardless of token
     * state before conversion was added — a leftover token from a
     * since-blocked account must not turn it into an occasional 403 or
     * silently use that account's own preference.
     *
     * The blocked member's own preference is deliberately set to `USD`
     * (matching the plan's pricing currency, which would mean NO
     * conversion if their preference were somehow honored) so that seeing
     * a converted SYP amount here can only mean the request was correctly
     * treated as anonymous and given the SYP default — not that their
     * stored preference leaked through despite the failed auth.
     */
    public function test_a_blocked_members_stale_token_is_treated_as_anonymous_and_gets_the_syp_default(): void
    {
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '14700.0000', 'effective_from' => now()->subDay()]);
        Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD', 'is_active' => true]);
        $member = User::factory()->create(['status' => 'active', 'preferred_currency' => 'USD']);
        $member->assignRole('member');
        $token = $member->createToken('member-app')->plainTextToken;

        $member->update(['status' => 'blocked']);
        Auth::forgetGuards();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/plans');

        $response->assertOk();
        $response->assertJsonPath('data.0.converted_amount', '147000.00');
        $response->assertJsonPath('data.0.converted_currency', 'SYP');
    }
}
```

- [ ] **Step 3: Run the test file to verify it passes**

Run: `php artisan test tests/Feature/Membership/PlanPriceConversionTest.php`
Expected: PASS (8 tests). If `test_the_currency_header_works_for_anonymous_requests_too` fails on the exact numeric value, double check `CurrencyConversionService::convert()`'s SYP→USD branch: `round($amount / $rateUsdToSyp, 2)` — `14700.00 / 14700.0000 = 1.00`, matching the assertion above.

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: 100% pass, zero known failures — the one tracked failure from Task 1's Step 8 is gone now that this file no longer contains the null-based test. Record the total.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/PlanResource.php tests/Feature/Membership/PlanPriceConversionTest.php
git commit -m "feat: always resolve a target currency in PlanResource, defaulting to SYP"
```

---

## Task 4: Postman collection + environment updates

**Files:**
- Modify: `postman/ADD-OS.postman_collection.json`
- Modify: `postman/ADD-OS-Local.postman_environment.json`
- Create (temporary, deleted at the end of this task — never committed): a PHP script in the OS scratch/temp directory

**Interfaces:**
- Consumes: nothing from earlier tasks — this task only touches Postman documentation, not application code.
- Produces: nothing later tasks depend on (this is the last task).

- [ ] **Step 1: Confirm current state before scripting**

Run: `grep -c '"key": "lang"' postman/ADD-OS.postman_collection.json`
Expected: `0` (no request currently sends a `lang` header — confirming this task's premise, since the header shipped in application code without ever being added here).

- [ ] **Step 2: Write the one-off script**

Write this to a file in the OS temp directory (e.g. `/tmp/update-postman.php` or your platform's scratch directory — NOT inside the repo):

```php
<?php

$repoRoot = '/absolute/path/to/ADDCore'; // set this to the actual repo root before running

$collectionPath = $repoRoot . '/postman/ADD-OS.postman_collection.json';
$data = json_decode(file_get_contents($collectionPath), true);

function addLangCurrencyHeaders(array &$items): void
{
    foreach ($items as &$it) {
        if (isset($it['request'])) {
            $headers = $it['request']['header'] ?? [];
            $keys = array_map(fn ($h) => strtolower($h['key']), $headers);

            if (! in_array('lang', $keys, true)) {
                $headers[] = ['key' => 'lang', 'value' => '{{lang}}', 'type' => 'text'];
            }
            if (! in_array('currency', $keys, true)) {
                $headers[] = ['key' => 'currency', 'value' => '{{currency}}', 'type' => 'text'];
            }

            $it['request']['header'] = $headers;
        }

        if (isset($it['item'])) {
            addLangCurrencyHeaders($it['item']);
        }
    }
}

addLangCurrencyHeaders($data['item']);

$newRequests = [
    [
        'name' => 'Update Language Preference',
        'request' => [
            'auth' => [
                'type' => 'bearer',
                'bearer' => [
                    ['key' => 'token', 'value' => '{{member_token}}'],
                ],
            ],
            'method' => 'PATCH',
            'header' => [
                ['key' => 'Content-Type', 'value' => 'application/json'],
                ['key' => 'lang', 'value' => '{{lang}}', 'type' => 'text'],
                ['key' => 'currency', 'value' => '{{currency}}', 'type' => 'text'],
            ],
            'body' => [
                'mode' => 'raw',
                'raw' => "{\n  \"preferred_language\": \"en\"\n}",
                'options' => ['raw' => ['language' => 'json']],
            ],
            'url' => [
                'raw' => '{{base_url}}/api/v1/member/preferences/language',
                'host' => ['{{base_url}}'],
                'path' => ['api', 'v1', 'member', 'preferences', 'language'],
            ],
            'description' => "Updates the authenticated member's stored language preference (`ar`/`en`). Distinct from the `lang` header: the header overrides the response language for this one request; this changes what a future request with no header falls back to.",
        ],
    ],
    [
        'name' => 'Update Currency Preference',
        'request' => [
            'auth' => [
                'type' => 'bearer',
                'bearer' => [
                    ['key' => 'token', 'value' => '{{member_token}}'],
                ],
            ],
            'method' => 'PATCH',
            'header' => [
                ['key' => 'Content-Type', 'value' => 'application/json'],
                ['key' => 'lang', 'value' => '{{lang}}', 'type' => 'text'],
                ['key' => 'currency', 'value' => '{{currency}}', 'type' => 'text'],
            ],
            'body' => [
                'mode' => 'raw',
                'raw' => "{\n  \"preferred_currency\": \"USD\"\n}",
                'options' => ['raw' => ['language' => 'json']],
            ],
            'url' => [
                'raw' => '{{base_url}}/api/v1/member/preferences/currency',
                'host' => ['{{base_url}}'],
                'path' => ['api', 'v1', 'member', 'preferences', 'currency'],
            ],
            'description' => "Updates the authenticated member's stored currency preference (`USD`/`SYP`, display-only — never affects real pricing/wallet records). Distinct from the `currency` header: the header overrides a plan-listing response's converted currency for this one request; this changes the stored default a request with no header falls back to.",
        ],
    ],
];

foreach ($data['item'] as &$folder) {
    if ($folder['name'] === 'Member (App)') {
        foreach ($newRequests as $req) {
            $folder['item'][] = $req;
        }
    }
}
unset($folder);

file_put_contents($collectionPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

// The environment file uses 2-space indentation; PHP's JSON_PRETTY_PRINT
// is a fixed 4-space implementation, so re-indent after encoding to avoid
// reformatting every line of an otherwise-tiny file.
function reindentTo2Spaces(string $json): string
{
    $lines = explode("\n", $json);
    foreach ($lines as &$line) {
        if (preg_match('/^( +)/', $line, $m)) {
            $spaces = strlen($m[1]);
            $line = str_repeat(' ', intdiv($spaces, 4) * 2) . substr($line, $spaces);
        }
    }

    return implode("\n", $lines);
}

$envPath = $repoRoot . '/postman/ADD-OS-Local.postman_environment.json';
$env = json_decode(file_get_contents($envPath), true);

$existingKeys = array_map(fn ($v) => $v['key'], $env['values']);

if (! in_array('lang', $existingKeys, true)) {
    $env['values'][] = ['key' => 'lang', 'value' => 'ar', 'type' => 'default', 'enabled' => true];
}
if (! in_array('currency', $existingKeys, true)) {
    $env['values'][] = ['key' => 'currency', 'value' => 'SYP', 'type' => 'default', 'enabled' => true];
}

$encoded = reindentTo2Spaces(json_encode($env, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($envPath, $encoded . "\n");

echo "Done.\n";
```

Before running: replace `/absolute/path/to/ADDCore` with the actual absolute path to the repository root on the machine running this.

- [ ] **Step 3: Run the script**

Run: `php /path/to/update-postman.php` (wherever you saved it in the previous step)
Expected output: `Done.`

- [ ] **Step 4: Verify the collection changes**

Run: `grep -c '"key": "lang"' postman/ADD-OS.postman_collection.json`
Expected: `66` or more (one per existing request, plus the 2 new ones = at least 68 — the exact number depends on whether any request already happened to have a `lang`-keyed header, which Step 1 confirmed was zero, so expect exactly `68`).

Run: `grep -c '"Update Language Preference"\|"Update Currency Preference"' postman/ADD-OS.postman_collection.json`
Expected: `2`

Run: `php -l postman/ADD-OS.postman_collection.json 2>&1; python3 -c "import json; json.load(open('postman/ADD-OS.postman_collection.json'))" 2>&1 || php -r "json_decode(file_get_contents('postman/ADD-OS.postman_collection.json'), true); echo json_last_error() === JSON_ERROR_NONE ? 'valid JSON' : 'INVALID JSON';"`
Expected: confirms the file is still valid JSON (the `php -l` call will error since this isn't a PHP file — ignore that specific error and rely on the JSON-validity check).

- [ ] **Step 5: Verify the environment file changes**

Run: `cat postman/ADD-OS-Local.postman_environment.json`
Expected: the file's existing 5 variables are unchanged and still 2-space indented, with `lang` (value `ar`) and `currency` (value `SYP`) appended in the same style.

- [ ] **Step 6: Review the diff**

Run: `git diff --stat postman/`
Expected: both files show as modified, with the collection's diff touching every request (one `lang` + one `currency` header line added each) plus the two new request blocks, and the environment file's diff limited to two new lines.

- [ ] **Step 7: Delete the temporary script**

Run: `rm /path/to/update-postman.php` (the exact path used in Step 2) — it must not be committed or left anywhere in the repository.

- [ ] **Step 8: Run the full suite one final time**

Run: `composer test`
Expected: 100% pass — this task touches no application code, so the count should be identical to the end of Task 3.

- [ ] **Step 9: Commit**

```bash
git add postman/ADD-OS.postman_collection.json postman/ADD-OS-Local.postman_environment.json
git commit -m "docs: add lang/currency headers and preference-update requests to Postman"
```
