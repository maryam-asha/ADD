# External Exchange-Rate Suggestion (sp-today) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin see a live sp-today USD/SYP quote next to the current exchange rate and, if they choose, accept it with one click — without the external source ever writing `exchange_rates` itself or running inside a request cycle.

**Architecture:** A new append-only `exchange_rate_suggestions` table holds candidate values fetched once a day by a scheduled Artisan command (`finance:fetch-exchange-rate-suggestion` → `ExchangeRateSuggestionIngestor` → `SpTodayRateClient`). Two new admin endpoints expose the current pending suggestion and let an admin dismiss it; the existing `POST /api/v1/admin/exchange-rates` gains one optional field (`suggestion_id`) to link an accepted rate back to the suggestion it came from. `exchange_rates` itself changes shape only by two additive columns (`source`, `suggestion_id`) — its write path, validation, and `set_by`-is-always-the-admin guarantee are untouched.

**Tech Stack:** Laravel 12, Laravel HTTP client (`Http::fake` in tests), spatie/laravel-permission (existing `role:admin|operations` middleware), spatie/activitylog (`LogsSensitiveActions` trait).

**Spec:** [docs/decisions/exchange-rate-external-suggestion.md](../../decisions/exchange-rate-external-suggestion.md) — read this first. It documents the live Phase 0 findings (the originally-assumed endpoint and city don't exist; the real base URL is `https://api-v2.sp-today.com/api/v1` with `GET /currencies`; there is no Aleppo-specific rate, only a nationwide `damascus` one, which this feature uses and labels honestly; and the `rate_to_base` vs. `rate_usd_to_syp` direction mismatch that would otherwise be a real money bug).

## Global Constraints

- `exchange_rates` stays append-only. No task in this plan adds an update or delete path to it.
- `set_by` on `exchange_rates` is always `$request->user()->id` — never client-supplied, never a system/service account. This is already enforced today; nothing here weakens it.
- `SpTodayRateClient` (the HTTP client) may only be referenced from `App\Console\Commands\FetchExchangeRateSuggestion` and `App\Domain\Finance\Services\ExchangeRateSuggestionIngestor` — enforced by a new guard test in Task 2. No controller, Form Request, middleware, or API Resource may reference it.
- No literal `sp-today` host string (in `https://...` form) may appear anywhere under `app/`, `database/`, or `routes/` — `tests/Guards/NetworkIsolationTest.php` already fails the build on this. The host only ever appears via `config('services.sptoday.base_url')`, reading `env('SPTODAY_BASE_URL', ...)`.
- No `$table->enum(...)` in any new migration — use `$table->string(...)` plus a PHP backed enum + model cast, per `tests/Guards/NoNewMysqlEnumColumnsTest.php` / `EnumColumnsHaveBackedEnumCastsTest.php`.
- Every update-style endpoint in this plan (`dismiss`) returns `{'message': ...}`, never the updated resource — per this repo's `CLAUDE.md` convention. `store()`'s accept-a-suggestion path is a create, so it keeps returning the created `ExchangeRateResource`, unchanged.
- All outbound HTTP in tests goes through `Http::fake()`. No test may make a real network call.

---

### Task 1: Schema — `exchange_rate_suggestions` table, `exchange_rates` additive columns, enums, models

**Files:**
- Create: `app/Domain/Finance/Enums/ExchangeRateSuggestionSource.php`
- Create: `app/Domain/Finance/Enums/ExchangeRateSuggestionStatus.php`
- Create: `app/Domain/Finance/Enums/ExchangeRateSource.php`
- Create: `database/migrations/2026_08_25_120000_create_exchange_rate_suggestions_table.php`
- Create: `database/migrations/2026_08_25_120100_add_source_and_suggestion_id_to_exchange_rates_table.php`
- Create: `app/Domain/Finance/Models/ExchangeRateSuggestion.php`
- Create: `database/factories/ExchangeRateSuggestionFactory.php`
- Modify: `app/Domain/Finance/Models/ExchangeRate.php` (add `source`/`suggestion_id` fillable, cast, relation)
- Modify: `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` (register the three new enum columns)
- Test: `tests/Unit/Domain/Finance/ExchangeRateSuggestionModelTest.php`

**Interfaces:**
- Produces: `App\Domain\Finance\Models\ExchangeRateSuggestion` with fillable `source, rate_usd_to_syp, raw_payload, fetched_at, status, accepted_rate_id, dismissed_by`; `casts()` maps `source => ExchangeRateSuggestionSource::class`, `status => ExchangeRateSuggestionStatus::class`, `rate_usd_to_syp => 'decimal:10'`, `raw_payload => 'array'`, `fetched_at => 'datetime'`. Relations `acceptedRate(): BelongsTo` (→ `ExchangeRate`), `dismissedBy(): BelongsTo` (→ `App\Domain\Identity\Models\User`).
- Produces: `ExchangeRate::casts()` gains `'source' => ExchangeRateSource::class`; fillable gains `source`, `suggestion_id`; new relation `suggestion(): BelongsTo` (→ `ExchangeRateSuggestion`).
- Consumes (later tasks rely on this): `ExchangeRateSuggestion::factory()` default state is `status = Pending`, `source = SpToday`, `rate_usd_to_syp = '13275.0000000000'`.

- [ ] **Step 1: Write the three enum classes**

`app/Domain/Finance/Enums/ExchangeRateSuggestionSource.php`:
```php
<?php

namespace App\Domain\Finance\Enums;

enum ExchangeRateSuggestionSource: string
{
    case SpToday = 'sp_today';
}
```

`app/Domain/Finance/Enums/ExchangeRateSuggestionStatus.php`:
```php
<?php

namespace App\Domain\Finance\Enums;

enum ExchangeRateSuggestionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';
    case Superseded = 'superseded';
}
```

`app/Domain/Finance/Enums/ExchangeRateSource.php`:
```php
<?php

namespace App\Domain\Finance\Enums;

enum ExchangeRateSource: string
{
    case Manual = 'manual';
    case ExternalAccepted = 'external_accepted';
}
```

- [ ] **Step 2: Write the `exchange_rate_suggestions` migration**

`database/migrations/2026_08_25_120000_create_exchange_rate_suggestions_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A candidate rate from an external source (sp-today) — an admin may accept
 * it, but it never writes exchange_rates itself and is never authoritative
 * on its own. docs/decisions/exchange-rate-external-suggestion.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rate_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            // SYP per 1 USD, exactly as sp-today quotes it — the opposite
            // direction from exchange_rates.rate_to_base. See the decision
            // doc's "direction problem" section before touching this value.
            $table->decimal('rate_usd_to_syp', 20, 10);
            $table->json('raw_payload');
            $table->dateTime('fetched_at');
            $table->string('status', 20)->default('pending');
            $table->foreignId('accepted_rate_id')->nullable()->constrained('exchange_rates')->nullOnDelete();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rate_suggestions');
    }
};
```

- [ ] **Step 3: Write the `exchange_rates` alteration migration**

`database/migrations/2026_08_25_120100_add_source_and_suggestion_id_to_exchange_rates_table.php`:
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
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('rate_to_base');
            $table->foreignId('suggestion_id')->nullable()->after('source')
                ->constrained('exchange_rate_suggestions')->nullOnDelete();
        });

        // Explicit, matching the decision doc: every row that existed before
        // this feature landed is a manual entry by definition.
        DB::table('exchange_rates')->update(['source' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suggestion_id');
            $table->dropColumn('source');
        });
    }
};
```

- [ ] **Step 4: Run migrations, verify they apply cleanly**

Run: `php artisan migrate --env=testing` (or just let the next `php artisan test` run refresh the sqlite test DB via `RefreshDatabase`)
Expected: both migrations run with no error, `exchange_rate_suggestions` exists, `exchange_rates` has `source`/`suggestion_id` columns.

- [ ] **Step 5: Write `ExchangeRateSuggestion` model**

`app/Domain/Finance/Models/ExchangeRateSuggestion.php`:
```php
<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\ExchangeRateSuggestionSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRateSuggestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'rate_usd_to_syp',
        'raw_payload',
        'fetched_at',
        'status',
        'accepted_rate_id',
        'dismissed_by',
    ];

    protected function casts(): array
    {
        return [
            'rate_usd_to_syp' => 'decimal:10',
            'raw_payload' => 'array',
            'fetched_at' => 'datetime',
            'source' => ExchangeRateSuggestionSource::class,
            'status' => ExchangeRateSuggestionStatus::class,
        ];
    }

    public function acceptedRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class, 'accepted_rate_id');
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }
}
```

- [ ] **Step 6: Write its factory**

`database/factories/ExchangeRateSuggestionFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Domain\Finance\Enums\ExchangeRateSuggestionSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRateSuggestion>
 */
class ExchangeRateSuggestionFactory extends Factory
{
    protected $model = ExchangeRateSuggestion::class;

    public function definition(): array
    {
        return [
            'source' => ExchangeRateSuggestionSource::SpToday,
            // Matches the live sp-today USD/damascus sample recorded in
            // docs/decisions/exchange-rate-external-suggestion.md.
            'rate_usd_to_syp' => '13275.0000000000',
            'raw_payload' => [
                'ok' => true,
                'data' => [
                    'currencies' => [
                        ['code' => 'USD', 'cities' => ['damascus' => ['buy' => 13225, 'sell' => 13275]]],
                    ],
                ],
            ],
            'fetched_at' => now(),
            'status' => ExchangeRateSuggestionStatus::Pending,
        ];
    }
}
```

- [ ] **Step 7: Update `ExchangeRate` model**

Modify `app/Domain/Finance/Models/ExchangeRate.php` — add `source` and `suggestion_id` to `$fillable`, add `'source' => ExchangeRateSource::class` to `casts()`, add a `suggestion(): BelongsTo` relation, and import `ExchangeRateSource` and `ExchangeRateSuggestion`. The rest of the file (the `setBy()`/`currency()` relations and the `current()` static helper) is unchanged.

- [ ] **Step 8: Register the three new enum columns in the guard test**

Modify `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`: add these imports —
```php
use App\Domain\Finance\Enums\ExchangeRateSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
```
and add these two entries to `EXPECTED_CASTS` (`ExchangeRate::class` is new to this array; there's no existing entry to merge into):
```php
        ExchangeRate::class => [
            'source' => ExchangeRateSource::class,
        ],
        ExchangeRateSuggestion::class => [
            'source' => ExchangeRateSuggestionSource::class,
            'status' => ExchangeRateSuggestionStatus::class,
        ],
```

- [ ] **Step 9: Write a model unit test covering the schema + casts**

`tests/Unit/Domain/Finance/ExchangeRateSuggestionModelTest.php`:
```php
<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Enums\ExchangeRateSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateSuggestionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_suggestion_can_be_created_with_its_default_factory_state(): void
    {
        $suggestion = ExchangeRateSuggestion::factory()->create();

        $this->assertSame(ExchangeRateSuggestionSource::SpToday, $suggestion->source);
        $this->assertSame(ExchangeRateSuggestionStatus::Pending, $suggestion->status);
        $this->assertSame('13275.0000000000', $suggestion->rate_usd_to_syp);
        $this->assertIsArray($suggestion->raw_payload);
    }

    public function test_an_exchange_rate_defaults_to_manual_source_with_no_suggestion(): void
    {
        $rate = ExchangeRate::factory()->create();

        $this->assertSame(ExchangeRateSource::Manual, $rate->source);
        $this->assertNull($rate->suggestion_id);
    }

    public function test_an_exchange_rate_can_link_back_to_the_suggestion_it_came_from(): void
    {
        $suggestion = ExchangeRateSuggestion::factory()->create();
        $rate = ExchangeRate::factory()->create([
            'source' => ExchangeRateSource::ExternalAccepted,
            'suggestion_id' => $suggestion->id,
        ]);

        $this->assertTrue($rate->suggestion->is($suggestion));
    }
}
```

- [ ] **Step 10: Run the new test and the enum-cast guard, verify both pass**

Run: `php artisan test tests/Unit/Domain/Finance/ExchangeRateSuggestionModelTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php tests/Guards/NoNewMysqlEnumColumnsTest.php`
Expected: all PASS.

- [ ] **Step 11: Commit**

```bash
git add app/Domain/Finance/Enums/ExchangeRateSuggestionSource.php app/Domain/Finance/Enums/ExchangeRateSuggestionStatus.php app/Domain/Finance/Enums/ExchangeRateSource.php database/migrations/2026_08_25_120000_create_exchange_rate_suggestions_table.php database/migrations/2026_08_25_120100_add_source_and_suggestion_id_to_exchange_rates_table.php app/Domain/Finance/Models/ExchangeRateSuggestion.php app/Domain/Finance/Models/ExchangeRate.php database/factories/ExchangeRateSuggestionFactory.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php tests/Unit/Domain/Finance/ExchangeRateSuggestionModelTest.php
git commit -m "feat: add exchange_rate_suggestions schema and link exchange_rates to it"
```

---

### Task 2: `SpTodayRateClient` + config + architectural guard

**Files:**
- Modify: `config/services.php` (add `sptoday` block)
- Modify: `.env.example` (add `SP_TODAY_KEY`, `SPTODAY_BASE_URL`)
- Create: `app/Domain/Finance/Services/SpTodayRateClient.php`
- Create: `tests/Guards/SpTodayClientUsageIsScheduledOnlyTest.php`
- Test: `tests/Unit/Domain/Finance/Services/SpTodayRateClientTest.php`

**Interfaces:**
- Produces: `SpTodayRateClient::fetchUsdDamascusRates(): array` returning `['sell' => mixed, 'buy' => mixed, 'raw' => array]` on success, or throwing `\RuntimeException` (non-2xx response, non-`ok:true` body, missing USD entry, or missing `cities.damascus`).
- Consumes: `config('services.sptoday.base_url')`, `config('services.sptoday.api_key')` — both must exist before this class is used.

- [ ] **Step 1: Add the `sptoday` config block**

Modify `config/services.php` — insert before the closing `];`, after the existing `'whatsapp' => [...]` block:
```php
    'sptoday' => [
        'base_url' => env('SPTODAY_BASE_URL', 'https://api-v2.sp-today.com/api/v1'),
        'api_key' => env('SP_TODAY_KEY'),
    ],
```

- [ ] **Step 2: Add the env entries**

Modify `.env.example` — insert after the `WHATSAPP_OTP_TEMPLATE=otp_verification` line (before the blank line that precedes the `OTP_FIXED_CODE` comment block):
```
SP_TODAY_KEY=
SPTODAY_BASE_URL=
```
Leave both empty — `SP_TODAY_KEY` is provisioned per-environment outside git; `SPTODAY_BASE_URL` falls back to the real base URL from `config/services.php` when unset.

- [ ] **Step 3: Write the failing test for the client**

`tests/Unit/Domain/Finance/Services/SpTodayRateClientTest.php`:
```php
<?php

namespace Tests\Unit\Domain\Finance\Services;

use App\Domain\Finance\Services\SpTodayRateClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpTodayRateClientTest extends TestCase
{
    private function fakeBody(array $overrides = []): array
    {
        return array_replace_recursive([
            'ok' => true,
            'data' => [
                'currencies' => [
                    ['code' => 'USD', 'cities' => ['damascus' => ['buy' => 13225, 'sell' => 13275], 'alhasakah' => ['buy' => 13250, 'sell' => 13300]]],
                    ['code' => 'EUR', 'cities' => ['damascus' => ['buy' => 15300, 'sell' => 15480]]],
                ],
            ],
        ], $overrides);
    }

    public function test_it_extracts_usd_damascus_buy_and_sell(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(), 200, [
            'X-RateLimit-Limit' => '100',
            'X-RateLimit-Remaining' => '99',
        ])]);

        $result = app(SpTodayRateClient::class)->fetchUsdDamascusRates();

        $this->assertSame(13275, $result['sell']);
        $this->assertSame(13225, $result['buy']);
        $this->assertSame($this->fakeBody(), $result['raw']);
    }

    public function test_it_throws_on_a_non_2xx_response(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response('', 401)]);

        $this->expectException(\RuntimeException::class);

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();
    }

    public function test_it_throws_when_ok_is_not_true(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response(['ok' => false], 200)]);

        $this->expectException(\RuntimeException::class);

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();
    }

    public function test_it_throws_when_usd_is_missing_from_the_currency_list(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(['data' => ['currencies' => [['code' => 'EUR', 'cities' => ['damascus' => ['buy' => 1, 'sell' => 2]]]]]]), 200)]);

        $this->expectException(\RuntimeException::class);

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();
    }

    public function test_it_throws_when_the_damascus_city_is_missing_for_usd(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(['data' => ['currencies' => [['code' => 'USD', 'cities' => ['alhasakah' => ['buy' => 1, 'sell' => 2]]]]]]), 200)]);

        $this->expectException(\RuntimeException::class);

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();
    }
}
```

- [ ] **Step 4: Run it, verify it fails**

Run: `php artisan test tests/Unit/Domain/Finance/Services/SpTodayRateClientTest.php`
Expected: FAIL — `Class "App\Domain\Finance\Services\SpTodayRateClient" not found`.

- [ ] **Step 5: Write `SpTodayRateClient`**

`app/Domain/Finance/Services/SpTodayRateClient.php`:
```php
<?php

namespace App\Domain\Finance\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to sp-today's currency-rates API. Only two callers may ever
 * reference this class — App\Console\Commands\FetchExchangeRateSuggestion
 * and App\Domain\Finance\Services\ExchangeRateSuggestionIngestor — enforced
 * by tests/Guards/SpTodayClientUsageIsScheduledOnlyTest.php. This is never
 * reachable from a request cycle.
 *
 * docs/decisions/exchange-rate-external-suggestion.md records the Phase 0
 * findings this class implements: the real base path (api/v1, not the
 * originally-assumed api-dashboard path), and that "damascus" is sp-today's
 * nationwide rate — there is no Aleppo-specific one.
 */
class SpTodayRateClient
{
    /**
     * @return array{sell: mixed, buy: mixed, raw: array}
     */
    public function fetchUsdDamascusRates(): array
    {
        $response = Http::baseUrl(config('services.sptoday.base_url'))
            ->withHeaders(['X-API-Key' => config('services.sptoday.api_key')])
            ->timeout(5)
            ->retry(1, 2000)
            ->get('/currencies');

        if (! $response->successful()) {
            throw new \RuntimeException("sp-today request failed with status {$response->status()}");
        }

        Log::info('sp-today rate fetch succeeded', [
            'rate_limit_remaining' => $response->header('X-RateLimit-Remaining'),
        ]);

        $body = $response->json();

        if (($body['ok'] ?? null) !== true) {
            throw new \RuntimeException('sp-today response did not report ok=true');
        }

        $usd = collect($body['data']['currencies'] ?? [])->firstWhere('code', 'USD');

        if (! $usd || ! isset($usd['cities']['damascus'])) {
            throw new \RuntimeException('sp-today response is missing the USD/damascus rate');
        }

        return [
            'sell' => $usd['cities']['damascus']['sell'] ?? null,
            'buy' => $usd['cities']['damascus']['buy'] ?? null,
            'raw' => $body,
        ];
    }
}
```

- [ ] **Step 6: Run the test again, verify it passes**

Run: `php artisan test tests/Unit/Domain/Finance/Services/SpTodayRateClientTest.php`
Expected: all 5 PASS.

- [ ] **Step 7: Write the architectural guard test**

`tests/Guards/SpTodayClientUsageIsScheduledOnlyTest.php`:
```php
<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * docs/decisions/exchange-rate-external-suggestion.md: SpTodayRateClient may
 * only ever be reached from the scheduled command that fetches suggestions,
 * never from anything a request could reach — a controller, Form Request,
 * middleware, or API Resource calling it directly would be exactly the kind
 * of external-source-as-authority shortcut the feature exists to prevent.
 */
class SpTodayClientUsageIsScheduledOnlyTest extends TestCase
{
    use ScansSourceFiles;

    private const ALLOWED_FILES = [
        'app/Console/Commands/FetchExchangeRateSuggestion.php',
        'app/Domain/Finance/Services/ExchangeRateSuggestionIngestor.php',
        'app/Domain/Finance/Services/SpTodayRateClient.php',
    ];

    public function test_sptodayrateclient_is_referenced_only_from_the_scheduled_ingestion_path(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(base_path('app')) as $path => $contents) {
            if (in_array($path, self::ALLOWED_FILES, true)) {
                continue;
            }

            if (str_contains($contents, 'SpTodayRateClient')) {
                $violations[] = "{$path} references SpTodayRateClient";
            }
        }

        $this->assertSame([], $violations, "SpTodayRateClient may only be used by the scheduled ingestion path:\n".implode("\n", $violations));
    }
}
```

- [ ] **Step 8: Run the guard, verify it passes**

Run: `php artisan test tests/Guards/SpTodayClientUsageIsScheduledOnlyTest.php`
Expected: PASS (only `SpTodayRateClient.php` itself exists under `app/` right now, and it's allowlisted — the guard will start actively enforcing once Task 3 adds the command and ingestor).

- [ ] **Step 9: Run the existing `NetworkIsolationTest` guard, verify it still passes**

Run: `php artisan test tests/Guards/NetworkIsolationTest.php`
Expected: PASS — the literal host only lives in `config/services.php`, which that guard doesn't scan.

- [ ] **Step 10: Commit**

```bash
git add config/services.php .env.example app/Domain/Finance/Services/SpTodayRateClient.php tests/Guards/SpTodayClientUsageIsScheduledOnlyTest.php tests/Unit/Domain/Finance/Services/SpTodayRateClientTest.php
git commit -m "feat: add SpTodayRateClient and its scheduled-only-usage guard"
```

---

### Task 3: `ExchangeRateSuggestionIngestor` + scheduled command + scheduler entry

**Files:**
- Create: `app/Domain/Finance/Services/ExchangeRateSuggestionIngestor.php`
- Create: `app/Console/Commands/FetchExchangeRateSuggestion.php`
- Modify: `routes/console.php` (add the daily schedule entry)
- Test: `tests/Feature/Console/FetchExchangeRateSuggestionCommandTest.php`

**Interfaces:**
- Consumes: `SpTodayRateClient::fetchUsdDamascusRates()` (Task 2), `ExchangeRateSuggestion` model + factory (Task 1).
- Produces: `ExchangeRateSuggestionIngestor::run(): void` — never throws; every failure path logs and returns. `finance:fetch-exchange-rate-suggestion` artisan command signature, calling `app(ExchangeRateSuggestionIngestor::class)->run()`.

- [ ] **Step 1: Write the failing command test (covers the full validation matrix via `Http::fake`)**

`tests/Feature/Console/FetchExchangeRateSuggestionCommandTest.php`:
```php
<?php

namespace Tests\Feature\Console;

use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FetchExchangeRateSuggestionCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fakeBody(int $sell, int $buy): array
    {
        return [
            'ok' => true,
            'data' => ['currencies' => [
                ['code' => 'USD', 'cities' => ['damascus' => ['buy' => $buy, 'sell' => $sell]]],
            ]],
        ];
    }

    public function test_a_successful_response_creates_a_pending_suggestion_with_the_exact_sell_price(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(13275, 13225), 200)]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseHas('exchange_rate_suggestions', [
            'rate_usd_to_syp' => '13275.0000000000',
            'status' => ExchangeRateSuggestionStatus::Pending->value,
            'source' => 'sp_today',
        ]);
    }

    public function test_sell_below_buy_creates_no_suggestion_and_logs_an_error(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(13000, 13225), 200)]);
        Log::spy();

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseCount('exchange_rate_suggestions', 0);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_missing_sell_field_creates_no_suggestion(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response([
            'ok' => true,
            'data' => ['currencies' => [['code' => 'USD', 'cities' => ['damascus' => ['buy' => 13225]]]]],
        ], 200)]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseCount('exchange_rate_suggestions', 0);
    }

    public function test_a_non_numeric_sell_field_creates_no_suggestion(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(0, 13225), 200, [])->status(200)]);
        Http::fake(['api-v2.sp-today.com/*' => Http::response([
            'ok' => true,
            'data' => ['currencies' => [['code' => 'USD', 'cities' => ['damascus' => ['buy' => 13225, 'sell' => 'not-a-number']]]]],
        ], 200)]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseCount('exchange_rate_suggestions', 0);
    }

    public function test_a_zero_sell_field_creates_no_suggestion(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(0, 0), 200)]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseCount('exchange_rate_suggestions', 0);
    }

    public function test_a_non_numeric_buy_field_creates_no_suggestion(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response([
            'ok' => true,
            'data' => ['currencies' => [['code' => 'USD', 'cities' => ['damascus' => ['sell' => 13275, 'buy' => 'not-a-number']]]]],
        ], 200)]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseCount('exchange_rate_suggestions', 0);
    }

    public function test_a_network_failure_creates_no_suggestion_and_does_not_throw(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseCount('exchange_rate_suggestions', 0);
    }

    public function test_a_401_response_creates_no_suggestion_and_does_not_throw(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response('', 401)]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseCount('exchange_rate_suggestions', 0);
    }

    public function test_a_second_successful_fetch_supersedes_the_first_pending_suggestion(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(13275, 13225), 200)]);
        $this->artisan('finance:fetch-exchange-rate-suggestion');
        $first = ExchangeRateSuggestion::sole();

        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(13400, 13350), 200)]);
        $this->artisan('finance:fetch-exchange-rate-suggestion');

        $this->assertSame(ExchangeRateSuggestionStatus::Superseded, $first->refresh()->status);
        $this->assertSame(1, ExchangeRateSuggestion::where('status', ExchangeRateSuggestionStatus::Pending)->count());
        $this->assertSame('13400.0000000000', ExchangeRateSuggestion::where('status', ExchangeRateSuggestionStatus::Pending)->sole()->rate_usd_to_syp);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `php artisan test tests/Feature/Console/FetchExchangeRateSuggestionCommandTest.php`
Expected: FAIL — the `finance:fetch-exchange-rate-suggestion` artisan command doesn't exist yet.

- [ ] **Step 3: Write `ExchangeRateSuggestionIngestor`**

`app/Domain/Finance/Services/ExchangeRateSuggestionIngestor.php`:
```php
<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\ExchangeRateSuggestionSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs the full accept/reject decision for one sp-today fetch. Every
 * failure path logs and returns — nothing here may throw past this class,
 * so a vendor hiccup never crashes the scheduled command.
 * docs/decisions/exchange-rate-external-suggestion.md.
 */
class ExchangeRateSuggestionIngestor
{
    public function __construct(private readonly SpTodayRateClient $client)
    {
    }

    public function run(): void
    {
        try {
            $rates = $this->client->fetchUsdDamascusRates();
        } catch (\Throwable $e) {
            Log::error('sp-today exchange rate suggestion fetch failed', ['reason' => $e->getMessage()]);

            return;
        }

        $sell = $rates['sell'] ?? null;
        $buy = $rates['buy'] ?? null;

        if (! is_numeric($sell) || (float) $sell <= 0) {
            Log::error('sp-today exchange rate suggestion rejected: sell field missing, non-numeric, or not positive', ['sell' => $sell]);

            return;
        }

        if (! is_numeric($buy)) {
            Log::error('sp-today exchange rate suggestion rejected: buy field missing or non-numeric (response contract break)', ['buy' => $buy]);

            return;
        }

        if ((float) $sell < (float) $buy) {
            Log::error('sp-today exchange rate suggestion rejected: sell is below buy (response contract break, not a market condition)', [
                'sell' => $sell,
                'buy' => $buy,
            ]);

            return;
        }

        DB::transaction(function () use ($sell, $rates) {
            ExchangeRateSuggestion::where('status', ExchangeRateSuggestionStatus::Pending)
                ->update(['status' => ExchangeRateSuggestionStatus::Superseded]);

            ExchangeRateSuggestion::create([
                'source' => ExchangeRateSuggestionSource::SpToday,
                'rate_usd_to_syp' => $sell,
                'raw_payload' => $rates['raw'],
                'fetched_at' => now(),
                'status' => ExchangeRateSuggestionStatus::Pending,
            ]);
        });
    }
}
```

- [ ] **Step 4: Write the artisan command**

`app/Console/Commands/FetchExchangeRateSuggestion.php`:
```php
<?php

namespace App\Console\Commands;

use App\Domain\Finance\Services\ExchangeRateSuggestionIngestor;
use Illuminate\Console\Command;

class FetchExchangeRateSuggestion extends Command
{
    protected $signature = 'finance:fetch-exchange-rate-suggestion';

    protected $description = 'Fetch the daily sp-today USD/SYP quote as a pending exchange-rate suggestion for an admin to review.';

    public function handle(ExchangeRateSuggestionIngestor $ingestor): int
    {
        $ingestor->run();

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Add the schedule entry**

Modify `routes/console.php` — append after the existing `kiosk:expire-stale-arrival-requests` block:
```php
/*
 * External exchange-rate suggestion (docs/decisions/exchange-rate-external-suggestion.md)
 * — sp-today's nationwide USD/SYP quote, fetched once a day as a candidate
 * for an admin to review and accept; never written to exchange_rates
 * automatically.
 */
Schedule::command('finance:fetch-exchange-rate-suggestion')->dailyAt('09:00')->timezone('Asia/Damascus')->withoutOverlapping();
```

- [ ] **Step 6: Run the test suite again, verify all pass**

Run: `php artisan test tests/Feature/Console/FetchExchangeRateSuggestionCommandTest.php tests/Guards/SpTodayClientUsageIsScheduledOnlyTest.php`
Expected: all PASS — the guard test now actively covers real usages (the command and the ingestor) and still passes because both are allowlisted.

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Finance/Services/ExchangeRateSuggestionIngestor.php app/Console/Commands/FetchExchangeRateSuggestion.php routes/console.php tests/Feature/Console/FetchExchangeRateSuggestionCommandTest.php
git commit -m "feat: fetch and validate a daily sp-today exchange-rate suggestion"
```

---

### Task 4: `GET /api/v1/admin/exchange-rates/suggestion`

**Files:**
- Create: `app/Http/Controllers/Api/V1/Admin/ExchangeRateSuggestionController.php`
- Modify: `routes/api/v1/admin.php` (register the route, import the controller)
- Test: `tests/Feature/Admin/ExchangeRateSuggestionControllerTest.php`

**Interfaces:**
- Consumes: `ExchangeRate::current('SYP')` (existing static helper on `App\Domain\Finance\Models\ExchangeRate`), `ExchangeRateSuggestion` (Task 1).
- Produces: `ExchangeRateSuggestionController::show(): JsonResponse` returning a flat object — `id`, `rate_usd_to_syp`, `source`, `fetched_at` (all `null` when no pending suggestion exists), `deviation_percent` (`null` when there's no pending suggestion **or** no current effective rate yet), `source_stale` (bool), `last_successful_fetch_at` (nullable ISO8601 string).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Admin/ExchangeRateSuggestionControllerTest.php`:
```php
<?php

namespace Tests\Feature\Admin;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExchangeRateSuggestionControllerTest extends TestCase
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

    public function test_it_returns_null_fields_when_no_pending_suggestion_exists(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/exchange-rates/suggestion');

        $response->assertOk();
        $response->assertJson([
            'id' => null,
            'rate_usd_to_syp' => null,
            'source' => null,
            'fetched_at' => null,
            'deviation_percent' => null,
            'source_stale' => true,
            'last_successful_fetch_at' => null,
        ]);
    }

    public function test_it_returns_the_latest_pending_suggestion(): void
    {
        $this->actingAsAdmin();
        ExchangeRateSuggestion::factory()->create(['status' => 'superseded', 'fetched_at' => now()->subDay()]);
        $pending = ExchangeRateSuggestion::factory()->create(['rate_usd_to_syp' => '13275.0000000000', 'fetched_at' => now()]);

        $response = $this->getJson('/api/v1/admin/exchange-rates/suggestion');

        $response->assertOk();
        $response->assertJsonPath('id', $pending->id);
        $response->assertJsonPath('rate_usd_to_syp', '13275.0000000000');
        $response->assertJsonPath('source', 'sp_today');
    }

    public function test_deviation_percent_is_null_without_a_current_effective_rate(): void
    {
        $this->actingAsAdmin();
        ExchangeRateSuggestion::factory()->create();

        $response = $this->getJson('/api/v1/admin/exchange-rates/suggestion');

        $response->assertJsonPath('deviation_percent', null);
    }

    public function test_deviation_percent_compares_both_numbers_in_the_same_direction(): void
    {
        $this->actingAsAdmin();
        // Current effective rate: 1 SYP = 0.0000680272 USD  <=>  1 USD ≈ 14700 SYP.
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000680272', 'effective_from' => now()->subDay()]);
        // Suggestion: 1 USD = 14994 SYP — roughly 2% higher than 14700.
        ExchangeRateSuggestion::factory()->create(['rate_usd_to_syp' => '14994.0000000000']);

        $response = $this->getJson('/api/v1/admin/exchange-rates/suggestion');

        $response->assertOk();
        $deviation = $response->json('deviation_percent');
        $this->assertGreaterThan(1.5, $deviation);
        $this->assertLessThan(2.5, $deviation);
    }

    public function test_source_stale_is_false_within_48_hours_of_the_last_successful_fetch(): void
    {
        $this->actingAsAdmin();
        ExchangeRateSuggestion::factory()->create(['status' => 'superseded', 'fetched_at' => now()->subHours(10)]);

        $response = $this->getJson('/api/v1/admin/exchange-rates/suggestion');

        $response->assertJsonPath('source_stale', false);
        $this->assertNotNull($response->json('last_successful_fetch_at'));
    }

    public function test_source_stale_flips_true_after_48_hours(): void
    {
        $this->actingAsAdmin();
        ExchangeRateSuggestion::factory()->create(['status' => 'superseded', 'fetched_at' => now()->subHours(49)]);

        $response = $this->getJson('/api/v1/admin/exchange-rates/suggestion');

        $response->assertJsonPath('source_stale', true);
    }

    public function test_the_route_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/exchange-rates/suggestion')->assertUnauthorized();
    }

    public function test_a_member_role_is_rejected(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/exchange-rates/suggestion')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `php artisan test tests/Feature/Admin/ExchangeRateSuggestionControllerTest.php`
Expected: FAIL — 404, the route doesn't exist yet.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/Api/V1/Admin/ExchangeRateSuggestionController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ExchangeRateSuggestionController extends Controller
{
    use LogsSensitiveActions;

    public function show(): JsonResponse
    {
        $suggestion = ExchangeRateSuggestion::where('status', ExchangeRateSuggestionStatus::Pending)
            ->latest('fetched_at')
            ->first();

        $lastSuccessfulFetchAt = ExchangeRateSuggestion::max('fetched_at');
        $sourceStale = $lastSuccessfulFetchAt === null
            || Carbon::parse($lastSuccessfulFetchAt)->lt(now()->subHours(48));

        return response()->json([
            'id' => $suggestion?->id,
            'rate_usd_to_syp' => $suggestion?->rate_usd_to_syp,
            'source' => $suggestion?->source?->value,
            'fetched_at' => $suggestion?->fetched_at?->toISOString(),
            'deviation_percent' => $this->deviationPercent($suggestion),
            'source_stale' => $sourceStale,
            'last_successful_fetch_at' => $lastSuccessfulFetchAt ? Carbon::parse($lastSuccessfulFetchAt)->toISOString() : null,
        ]);
    }

    /**
     * Both numbers must face the same direction before comparing —
     * rate_to_base is USD-per-1-SYP, rate_usd_to_syp is SYP-per-1-USD. See
     * docs/decisions/exchange-rate-external-suggestion.md, "the direction
     * problem".
     */
    private function deviationPercent(?ExchangeRateSuggestion $suggestion): ?float
    {
        if (! $suggestion) {
            return null;
        }

        $current = ExchangeRate::current('SYP');

        if (! $current) {
            return null;
        }

        $currentSypPerUsd = 1 / (float) $current->rate_to_base;

        return round((((float) $suggestion->rate_usd_to_syp - $currentSypPerUsd) / $currentSypPerUsd) * 100, 2);
    }
}
```

- [ ] **Step 4: Register the route**

Modify `routes/api/v1/admin.php` — add the import near the other Admin controller imports, and insert the route right after the existing `exchange-rates` lines (before the `Currencies` comment block):
```php
use App\Http\Controllers\Api\V1\Admin\ExchangeRateSuggestionController;
```
```php
Route::get('exchange-rates/suggestion', [ExchangeRateSuggestionController::class, 'show']);
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `php artisan test tests/Feature/Admin/ExchangeRateSuggestionControllerTest.php`
Expected: all 8 PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/Admin/ExchangeRateSuggestionController.php routes/api/v1/admin.php tests/Feature/Admin/ExchangeRateSuggestionControllerTest.php
git commit -m "feat: add GET admin/exchange-rates/suggestion endpoint"
```

---

### Task 5: `POST /api/v1/admin/exchange-rates/suggestion/{exchangeRateSuggestion}/dismiss`

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Admin/ExchangeRateSuggestionController.php` (add `dismiss()`)
- Modify: `routes/api/v1/admin.php` (register the route)
- Modify: `lang/en/api.php`, `lang/ar/api.php` (add message keys)
- Test: extend `tests/Feature/Admin/ExchangeRateSuggestionControllerTest.php`

**Interfaces:**
- Produces: `ExchangeRateSuggestionController::dismiss(Request $request, ExchangeRateSuggestion $exchangeRateSuggestion): JsonResponse` — `422` if not `pending`, else sets `status = Dismissed`, `dismissed_by = $request->user()->id`, logs `exchange_rate_suggestion_dismissed`, returns `{'message': __('api.admin.exchange_rate_suggestion_dismissed')}`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Admin/ExchangeRateSuggestionControllerTest.php`:
```php
    public function test_dismiss_marks_a_pending_suggestion_dismissed_and_records_who(): void
    {
        $admin = $this->actingAsAdmin();
        $suggestion = ExchangeRateSuggestion::factory()->create();

        $response = $this->postJson("/api/v1/admin/exchange-rates/suggestion/{$suggestion->id}/dismiss");

        $response->assertOk();
        $response->assertJson(['message' => __('api.admin.exchange_rate_suggestion_dismissed')]);
        $this->assertDatabaseHas('exchange_rate_suggestions', [
            'id' => $suggestion->id,
            'status' => 'dismissed',
            'dismissed_by' => $admin->id,
        ]);
    }

    public function test_dismiss_a_non_pending_suggestion_returns_422(): void
    {
        $this->actingAsAdmin();
        $suggestion = ExchangeRateSuggestion::factory()->create(['status' => 'accepted']);

        $this->postJson("/api/v1/admin/exchange-rates/suggestion/{$suggestion->id}/dismiss")
            ->assertStatus(422);
    }

    public function test_dismiss_writes_an_audit_log_entry(): void
    {
        $admin = $this->actingAsAdmin();
        $suggestion = ExchangeRateSuggestion::factory()->create();

        $this->postJson("/api/v1/admin/exchange-rates/suggestion/{$suggestion->id}/dismiss");

        $activity = \Spatie\Activitylog\Models\Activity::where('description', 'exchange_rate_suggestion_dismissed')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
    }
```

- [ ] **Step 2: Run them, verify they fail**

Run: `php artisan test tests/Feature/Admin/ExchangeRateSuggestionControllerTest.php`
Expected: the three new tests FAIL (404 — route doesn't exist).

- [ ] **Step 3: Add the lang keys**

Modify `lang/en/api.php` — add to the end of the existing `'admin' => [...]` array (after `'announcement_updated'`):
```php
        'exchange_rate_suggestion_dismissed' => 'Exchange rate suggestion dismissed.',
```

Modify `lang/ar/api.php` — same position:
```php
        'exchange_rate_suggestion_dismissed' => 'تم رفض اقتراح سعر الصرف.',
```

- [ ] **Step 4: Add `dismiss()` to the controller**

Modify `app/Http/Controllers/Api/V1/Admin/ExchangeRateSuggestionController.php` — add the import `use App\Domain\Finance\Models\ExchangeRateSuggestion;` is already present; add `use Illuminate\Http\Request;` and this method:
```php
    public function dismiss(Request $request, ExchangeRateSuggestion $exchangeRateSuggestion): JsonResponse
    {
        abort_if(
            $exchangeRateSuggestion->status !== ExchangeRateSuggestionStatus::Pending,
            422,
            'Only a pending suggestion can be dismissed.'
        );

        $exchangeRateSuggestion->update([
            'status' => ExchangeRateSuggestionStatus::Dismissed,
            'dismissed_by' => $request->user()->id,
        ]);

        $this->logSensitiveAction('exchange_rate_suggestion_dismissed', $exchangeRateSuggestion, [
            'rate_usd_to_syp' => $exchangeRateSuggestion->rate_usd_to_syp,
        ]);

        return response()->json(['message' => __('api.admin.exchange_rate_suggestion_dismissed')]);
    }
```

- [ ] **Step 5: Register the route**

Modify `routes/api/v1/admin.php` — right after the `exchange-rates/suggestion` GET route added in Task 4:
```php
Route::post('exchange-rates/suggestion/{exchangeRateSuggestion}/dismiss', [ExchangeRateSuggestionController::class, 'dismiss']);
```

- [ ] **Step 6: Run the tests, verify they pass**

Run: `php artisan test tests/Feature/Admin/ExchangeRateSuggestionControllerTest.php`
Expected: all 11 PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/V1/Admin/ExchangeRateSuggestionController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Admin/ExchangeRateSuggestionControllerTest.php
git commit -m "feat: add POST admin/exchange-rates/suggestion/{id}/dismiss endpoint"
```

---

### Task 6: `POST /api/v1/admin/exchange-rates` accepts an optional `suggestion_id`

**Files:**
- Modify: `app/Http/Requests/Admin/StoreExchangeRateRequest.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/ExchangeRateController.php`
- Test: extend `tests/Feature/Admin/ExchangeRateControllerTest.php`

**Interfaces:**
- Consumes: `ExchangeRateSuggestion` (Task 1), `ExchangeRateSource` (Task 1).
- Produces: `store()` still returns `ExchangeRateResource` (unchanged — this is a create, not an update, per the repo's own convention). When `suggestion_id` is present and valid: the created `ExchangeRate` gets `source = ExternalAccepted`, `suggestion_id` set; the suggestion transitions to `Accepted` with `accepted_rate_id` set; the audit log entry additionally carries `suggestion_id` and `suggested_rate_usd_to_syp`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Admin/ExchangeRateControllerTest.php` (add `use App\Domain\Finance\Models\ExchangeRateSuggestion;` to its imports):
```php
    public function test_accepting_a_suggestion_creates_an_externally_accepted_rate_and_marks_it_accepted(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $suggestion = ExchangeRateSuggestion::factory()->create();

        $response = $this->postJson('/api/v1/admin/exchange-rates', [
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000668',
            'effective_from' => now()->toISOString(),
            'suggestion_id' => $suggestion->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('exchange_rates', [
            'currency_code' => 'SYP',
            'source' => 'external_accepted',
            'suggestion_id' => $suggestion->id,
        ]);
        $this->assertDatabaseHas('exchange_rate_suggestions', [
            'id' => $suggestion->id,
            'status' => 'accepted',
        ]);
        $rate = ExchangeRate::where('suggestion_id', $suggestion->id)->sole();
        $this->assertTrue($suggestion->refresh()->acceptedRate->is($rate));
    }

    public function test_accepting_with_a_rate_modified_from_the_suggestion_still_records_both_numbers_in_the_audit_log(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $suggestion = ExchangeRateSuggestion::factory()->create(['rate_usd_to_syp' => '13275.0000000000']);

        $this->postJson('/api/v1/admin/exchange-rates', [
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000700', // admin edited the number before submitting
            'effective_from' => now()->toISOString(),
            'suggestion_id' => $suggestion->id,
        ])->assertCreated();

        $activity = \Spatie\Activitylog\Models\Activity::where('description', 'exchange_rate_created')->latest('id')->first();
        $this->assertSame('0.0000700', $activity->properties['rate_to_base']);
        $this->assertSame('13275.0000000000', $activity->properties['suggested_rate_usd_to_syp']);
    }

    public function test_accepting_a_non_pending_suggestion_returns_422(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $suggestion = ExchangeRateSuggestion::factory()->create(['status' => 'dismissed']);

        $this->postJson('/api/v1/admin/exchange-rates', [
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
            'effective_from' => now()->toISOString(),
            'suggestion_id' => $suggestion->id,
        ])->assertStatus(422);
    }

    public function test_accepting_a_suggestion_against_a_different_valid_currency_code_returns_422(): void
    {
        // EUR isn't seeded by default (only SYP/USD are — see the decision
        // doc's Phase 0 recon), so it's created inline here to prove the
        // *new* currency-mismatch rule fires, not just the pre-existing
        // currency_code exists-check that a genuinely-unknown code would
        // trip anyway.
        \App\Domain\Finance\Models\Currency::create([
            'code' => 'EUR', 'name' => ['en' => 'Euro', 'ar' => 'يورو'], 'symbol' => '€',
            'decimal_places' => 2, 'is_base' => false, 'is_active' => true, 'order' => 3,
        ]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $suggestion = ExchangeRateSuggestion::factory()->create();

        $this->postJson('/api/v1/admin/exchange-rates', [
            'currency_code' => 'EUR',
            'rate_to_base' => '0.00006',
            'effective_from' => now()->toISOString(),
            'suggestion_id' => $suggestion->id,
        ])->assertStatus(422)->assertJsonValidationErrors('currency_code');
    }

    public function test_manual_creation_without_a_suggestion_id_is_unchanged(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/admin/exchange-rates', [
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
            'effective_from' => now()->toISOString(),
        ])->assertCreated();

        $this->assertDatabaseHas('exchange_rates', ['currency_code' => 'SYP', 'source' => 'manual', 'suggestion_id' => null]);
    }
```

- [ ] **Step 2: Run them, verify they fail**

Run: `php artisan test tests/Feature/Admin/ExchangeRateControllerTest.php`
Expected: the new tests FAIL — `suggestion_id` is currently an unrecognized field the request silently ignores (mass-assignment isn't affected since the controller `create()`s from `$request->validated()`, so today it's simply dropped, and the rate is created with the old `source = 'manual'` default regardless of what was submitted).

- [ ] **Step 3: Extend `StoreExchangeRateRequest`**

Modify `app/Http/Requests/Admin/StoreExchangeRateRequest.php` — add `suggestion_id` to `rules()` and add a `withValidator()` hook:
```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The base currency (USD) never gets a row here — its rate to
            // itself is definitionally 1 — so is_base is explicitly excluded
            // rather than relying on "no one would pick it".
            'currency_code' => [
                'required',
                // A closure, not chained ->where() calls: Rule::exists()'s
                // string-based rule serialization mishandles a `false`
                // boolean where value (it collapses to an empty string,
                // which then matches no row at all) — a closure applies the
                // constraint directly against the query builder instead.
                Rule::exists('currencies', 'code')->where(function ($query) {
                    $query->where('is_active', true)->where('is_base', false);
                }),
            ],
            'rate_to_base' => ['required', 'numeric', 'gt:0'],
            'effective_from' => ['required', 'date'],
            // docs/decisions/exchange-rate-external-suggestion.md — accepting
            // a suggestion is purely additive to the manual-entry flow above.
            'suggestion_id' => [
                'nullable',
                'integer',
                Rule::exists('exchange_rate_suggestions', 'id')->where('status', 'pending'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // sp-today's suggestion is USD/SYP only — accepting it against
            // any other currency would write a nonsensical rate.
            if ($this->filled('suggestion_id') && $this->input('currency_code') !== 'SYP') {
                $validator->errors()->add('currency_code', 'A suggestion can only be applied to the SYP currency.');
            }
        });
    }
}
```

- [ ] **Step 4: Update `ExchangeRateController::store()`**

Modify `app/Http/Controllers/Api/V1/Admin/ExchangeRateController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Enums\ExchangeRateSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreExchangeRateRequest;
use App\Http\Resources\ExchangeRateResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Doesn't extend AdminResourceController — no `order` column, and no
 * update/destroy: a rate row is never mutated once written (Unit 1
 * design, 2026-08-09), same reasoning UserController uses for its own
 * deviations from the generic pattern.
 */
class ExchangeRateController extends Controller
{
    use LogsSensitiveActions;

    public function index(): AnonymousResourceCollection
    {
        return ExchangeRateResource::collection(
            ExchangeRate::query()->orderByDesc('effective_from')->get()
        );
    }

    public function store(StoreExchangeRateRequest $request): ExchangeRateResource
    {
        $suggestion = $request->filled('suggestion_id')
            ? ExchangeRateSuggestion::find($request->input('suggestion_id'))
            : null;

        $rate = DB::transaction(function () use ($request, $suggestion) {
            $rate = ExchangeRate::create([
                'currency_code' => $request->input('currency_code'),
                'rate_to_base' => $request->input('rate_to_base'),
                'effective_from' => $request->input('effective_from'),
                'set_by' => $request->user()->id,
                'source' => $suggestion ? ExchangeRateSource::ExternalAccepted : ExchangeRateSource::Manual,
                'suggestion_id' => $suggestion?->id,
            ]);

            $suggestion?->update([
                'status' => ExchangeRateSuggestionStatus::Accepted,
                'accepted_rate_id' => $rate->id,
            ]);

            return $rate;
        });

        $this->logSensitiveAction('exchange_rate_created', $rate, array_filter([
            'currency_code' => $rate->currency_code,
            'rate_to_base' => $rate->rate_to_base,
            'effective_from' => $rate->effective_from->toISOString(),
            'suggestion_id' => $suggestion?->id,
            'suggested_rate_usd_to_syp' => $suggestion?->rate_usd_to_syp,
        ], fn ($value) => $value !== null));

        return new ExchangeRateResource($rate);
    }
}
```

- [ ] **Step 5: Run the full file, verify everything passes**

Run: `php artisan test tests/Feature/Admin/ExchangeRateControllerTest.php`
Expected: all PASS, including the original 6 tests (unchanged behavior) and the new ones.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/Admin/StoreExchangeRateRequest.php app/Http/Controllers/Api/V1/Admin/ExchangeRateController.php tests/Feature/Admin/ExchangeRateControllerTest.php
git commit -m "feat: let POST admin/exchange-rates accept and record an sp-today suggestion_id"
```

---

### Task 7: Full-suite verification

**Files:** none (verification only).

- [ ] **Step 1: Record the baseline test count**

Run: `git stash && php artisan test 2>&1 | tail -5 && git stash pop`
(Skip this step if a baseline count from before this plan started is already known — the point is only to have a real "before" number for the delivery report, not to re-derive it if it's already in hand.)

- [ ] **Step 2: Run Pint**

Run: `./vendor/bin/pint`
Expected: no manual fixes needed beyond what Pint applies automatically; if it reformats any file touched by this plan, re-run the affected task's test file to confirm nothing broke.

- [ ] **Step 3: Run the full suite**

Run: `composer test`
Expected: 100% pass, zero failures, zero risky/skipped tests introduced by this plan. Record the total test count for the delivery report.

- [ ] **Step 4: Run the two most load-bearing guards explicitly, by name, one more time**

Run: `php artisan test tests/Guards/NetworkIsolationTest.php tests/Guards/SpTodayClientUsageIsScheduledOnlyTest.php tests/Guards/NoNewMysqlEnumColumnsTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
Expected: all PASS. If any fails, do not weaken the guard — fix the code that violates it.

---

### Task 8: Delivery report

**Files:** none — this produces the text deliverables the original task spec requires, without touching any file outside this repo (in particular, never edit a Postman collection directly).

- [ ] **Step 1: Confirm the Phase 0 appendix is complete**

Re-read `docs/decisions/exchange-rate-external-suggestion.md` — it already contains the live-verified endpoint, field names, and the no-Aleppo/nationwide-rate finding. No further action needed unless something in Tasks 1–6 surfaced a new gap; if so, add it there before reporting completion.

- [ ] **Step 2: Write the pasteable endpoint spec text**

Produce, as plain text in the final report (not as a file edit to any Postman collection):
- `GET /api/v1/admin/exchange-rates/suggestion` — headers, auth, sample 200 response (both "has a pending suggestion" and "no pending suggestion" cases), and the meaning of each field.
- `POST /api/v1/admin/exchange-rates/suggestion/{id}/dismiss` — auth, sample 200/422 responses.
- `POST /api/v1/admin/exchange-rates` — the one new optional field (`suggestion_id`), sample request/response for both the manual and accept-a-suggestion cases, and the 422 cases (`suggestion_id` not pending, `currency_code` mismatch).

## Self-Review

**Spec coverage:** every numbered section of the original task text (§1 governing principle, §2 prohibitions, §3 Phase 0, §4 schema, §5 ingestion + validation order, §6 GET/dismiss, §7 store() extension, §8 decision doc, §9 tests, §10 delivery) maps to a task above or to the decision doc written before this plan. The one place this plan deliberately diverges from the literal task text — the accept-endpoint's real body fields, and the SYP-currency-code + buy-field-numeric extra validations — is documented in the decision doc's "gaps" section, not silently absorbed.

**Placeholder scan:** every step above contains complete, real code — no `// TODO`, no "similar to Task N", no rules described without the rule itself.

**Type/name consistency:** `ExchangeRateSuggestionStatus`, `ExchangeRateSuggestionSource`, `ExchangeRateSource` are spelled identically across the migrations, models, controllers, and the enum-cast guard entries in every task. `ExchangeRateSuggestionController::show()`/`dismiss()` match the route registrations exactly. `ExchangeRateController::store()`'s new `$suggestion` variable and its `source`/`suggestion_id` fields match what Task 1's migration and model actually named them.
