# Display Currency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** let a member choose a currency for *displaying* prices (USD or SYP), fully independent of the currency the system actually charges in, backed by a real admin-managed `exchange_rates` table.

**Architecture:** a new `App\Domain\Finance` namespace holds `ExchangeRate` (append-only rate history) and `CurrencyConversionService` (raw decimal conversion math, no display formatting). Two new member endpoints make `preferred_currency`/`preferred_language` writable for the first time. `PlanResource` opportunistically resolves the Sanctum user (even on routes without `auth:sanctum` middleware) to add an optional converted price.

**Tech Stack:** Laravel 12, PHPUnit, SQLite (in-memory, tests), spatie/activitylog (existing `LogsSensitiveActions` trait).

## Global Constraints

- Migrations are diffs against existing tables — never rebuild `users` from scratch.
- `preferred_currency` is display-only: it must never be read by any transaction/pricing/wallet code path.
- Currency values are restricted to `USD`/`SYP` only — reject anything else at validation.
- No display-string formatting (thousands separators, symbol placement) — return raw decimal + ISO code. That's a separate, later round.
- Money columns are `decimal`, never `float`/`double` (enforced by the existing `tests/Guards/MoneyIsDecimalOnlyTest.php`).
- Every new/changed feature test seeds roles via `RoleSeeder` and authenticates via `Laravel\Sanctum\Sanctum::actingAs($user, ['*'])`, matching existing convention.

---

### Task 1: `preferred_currency` column + `exchange_rates` table + `ExchangeRate` model

**Files:**
- Create: `database/migrations/2026_08_09_160000_add_preferred_currency_to_users_table.php`
- Create: `database/migrations/2026_08_09_160001_create_exchange_rates_table.php`
- Create: `app/Domain/Finance/Models/ExchangeRate.php`
- Create: `database/factories/ExchangeRateFactory.php`
- Test: `tests/Unit/Domain/Finance/ExchangeRateTest.php`

**Interfaces:**
- Produces: `ExchangeRate::current(): ?ExchangeRate` (static) — latest row where `effective_from <= now()`, used by Task 2/5. Columns: `id`, `rate_usd_to_syp` (decimal:4), `effective_from` (datetime), `set_by` (nullable FK → users), timestamps.
- Produces: `users.preferred_currency` (string(3), nullable) — used by Task 3/5/6.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_returns_the_latest_row_that_has_already_taken_effect(): void
    {
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '14000.0000', 'effective_from' => now()->subDays(5)]);
        $latestPast = ExchangeRate::factory()->create(['rate_usd_to_syp' => '14700.0000', 'effective_from' => now()->subDay()]);
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '15000.0000', 'effective_from' => now()->addDay()]);

        $current = ExchangeRate::current();

        $this->assertNotNull($current);
        $this->assertSame($latestPast->id, $current->id);
        $this->assertSame('14700.0000', $current->rate_usd_to_syp);
    }

    public function test_current_returns_null_when_no_rate_has_taken_effect_yet(): void
    {
        ExchangeRate::factory()->create(['effective_from' => now()->addDay()]);

        $this->assertNull(ExchangeRate::current());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Finance/ExchangeRateTest.php`
Expected: FAIL — class `App\Domain\Finance\Models\ExchangeRate` not found.

- [ ] **Step 3: Write the migrations**

```php
<?php
// database/migrations/2026_08_09_160000_add_preferred_currency_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('preferred_currency', 3)->nullable()->after('preferred_language');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferred_currency');
        });
    }
};
```

```php
<?php
// database/migrations/2026_08_09_160001_create_exchange_rates_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only by convention: no route ever updates or deletes a row
     * (docs/superpowers/plans/2026-08-09-display-currency.md) — the
     * "current" rate is whichever row has the latest effective_from that
     * has already passed, so history falls out of this table for free.
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->decimal('rate_usd_to_syp', 12, 4);
            $table->timestamp('effective_from')->index();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php
// app/Domain/Finance/Models/ExchangeRate.php

namespace App\Domain\Finance\Models;

use App\Domain\Identity\Models\User;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    protected $fillable = [
        'rate_usd_to_syp',
        'effective_from',
        'set_by',
    ];

    protected function casts(): array
    {
        return [
            'rate_usd_to_syp' => 'decimal:4',
            'effective_from' => 'datetime',
        ];
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    public static function current(): ?self
    {
        return static::where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->first();
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php
// database/factories/ExchangeRateFactory.php

namespace Database\Factories;

use App\Domain\Finance\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    public function definition(): array
    {
        return [
            'rate_usd_to_syp' => '14700.0000',
            'effective_from' => now()->subDay(),
            'set_by' => null,
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Finance/ExchangeRateTest.php`
Expected: PASS (both tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_09_160000_add_preferred_currency_to_users_table.php database/migrations/2026_08_09_160001_create_exchange_rates_table.php app/Domain/Finance/Models/ExchangeRate.php database/factories/ExchangeRateFactory.php tests/Unit/Domain/Finance/ExchangeRateTest.php
git commit -m "feat: add preferred_currency column and exchange_rates table"
```

---

### Task 2: `CurrencyConversionService`

**Files:**
- Create: `app/Domain/Finance/Services/CurrencyConversionService.php`
- Test: `tests/Unit/Domain/Finance/CurrencyConversionServiceTest.php`

**Interfaces:**
- Consumes: `ExchangeRate::current(): ?ExchangeRate` (Task 1).
- Produces: `CurrencyConversionService::convert(float $amount, string $fromCurrency, string $toCurrency): ?float` — used by Task 5. Returns `null` when no rate exists or the pair isn't USD/SYP.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\CurrencyConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_converts_usd_to_syp_using_the_current_rate(): void
    {
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '14700.0000', 'effective_from' => now()->subDay()]);

        $result = (new CurrencyConversionService())->convert(10.0, 'USD', 'SYP');

        $this->assertSame(147000.0, $result);
    }

    public function test_it_converts_syp_to_usd_using_the_current_rate(): void
    {
        ExchangeRate::factory()->create(['rate_usd_to_syp' => '14700.0000', 'effective_from' => now()->subDay()]);

        $result = (new CurrencyConversionService())->convert(14700.0, 'SYP', 'USD');

        $this->assertSame(1.0, $result);
    }

    public function test_it_returns_the_same_amount_when_currencies_match(): void
    {
        $result = (new CurrencyConversionService())->convert(500.0, 'SYP', 'SYP');

        $this->assertSame(500.0, $result);
    }

    public function test_it_returns_null_when_no_exchange_rate_exists(): void
    {
        $result = (new CurrencyConversionService())->convert(10.0, 'USD', 'SYP');

        $this->assertNull($result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Finance/CurrencyConversionServiceTest.php`
Expected: FAIL — class `App\Domain\Finance\Services\CurrencyConversionService` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php
// app/Domain/Finance/Services/CurrencyConversionService.php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\ExchangeRate;

/**
 * Raw numeric conversion only — no locale/display formatting (that's a
 * separate, later round). USD/SYP is the only pair `exchange_rates`
 * models (docs/superpowers/plans/2026-08-09-display-currency.md).
 */
class CurrencyConversionService
{
    public function convert(float $amount, string $fromCurrency, string $toCurrency): ?float
    {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        $rate = ExchangeRate::current();

        if ($rate === null) {
            return null;
        }

        $rateUsdToSyp = (float) $rate->rate_usd_to_syp;

        if ($fromCurrency === 'USD' && $toCurrency === 'SYP') {
            return round($amount * $rateUsdToSyp, 2);
        }

        if ($fromCurrency === 'SYP' && $toCurrency === 'USD') {
            return round($amount / $rateUsdToSyp, 2);
        }

        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Finance/CurrencyConversionServiceTest.php`
Expected: PASS (all 4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Finance/Services/CurrencyConversionService.php tests/Unit/Domain/Finance/CurrencyConversionServiceTest.php
git commit -m "feat: add CurrencyConversionService for USD/SYP display conversion"
```

---

### Task 3: Member preference endpoints (currency + language)

**Files:**
- Modify: `app/Domain/Identity/Models/User.php` (add `preferred_currency` to `$fillable`)
- Modify: `app/Http/Resources/UserResource.php` (add `preferred_currency` field)
- Create: `app/Http/Requests/Member/UpdateCurrencyPreferenceRequest.php`
- Create: `app/Http/Requests/Member/UpdateLanguagePreferenceRequest.php`
- Create: `app/Http/Controllers/Api/V1/Member/PreferencesController.php`
- Modify: `routes/api/v1/member.php` (add 2 routes)
- Test: `tests/Feature/Member/PreferencesControllerTest.php`

**Interfaces:**
- Consumes: nothing from Tasks 1–2 directly (only the `preferred_currency` column from Task 1).
- Produces: `PATCH /api/v1/member/preferences/currency`, `PATCH /api/v1/member/preferences/language` — consumed by Task 6's guard test.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Member/PreferencesControllerTest.php

namespace Tests\Feature\Member;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PreferencesControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_a_member_can_update_their_preferred_currency(): void
    {
        $member = User::factory()->create(['preferred_currency' => null]);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/preferences/currency', [
            'preferred_currency' => 'USD',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.preferred_currency', 'USD');
        $this->assertDatabaseHas('users', ['id' => $member->id, 'preferred_currency' => 'USD']);
    }

    public function test_preferred_currency_is_rejected_when_not_usd_or_syp(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/preferences/currency', [
            'preferred_currency' => 'EUR',
        ]);

        $response->assertStatus(422);
    }

    public function test_a_member_can_update_their_preferred_language_post_signup(): void
    {
        $member = User::factory()->create(['preferred_language' => 'ar']);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/preferences/language', [
            'preferred_language' => 'en',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.preferred_language', 'en');
        $this->assertDatabaseHas('users', ['id' => $member->id, 'preferred_language' => 'en']);
    }

    public function test_preferred_language_is_rejected_when_not_ar_or_en(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/preferences/language', [
            'preferred_language' => 'fr',
        ]);

        $response->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Member/PreferencesControllerTest.php`
Expected: FAIL — route `/api/v1/member/preferences/currency` doesn't exist (404), plus `preferred_currency` not mass-assignable yet.

- [ ] **Step 3: Update the User model and UserResource**

In `app/Domain/Identity/Models/User.php`, change:

```php
    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'preferred_language',
        'status',
    ];
```

to:

```php
    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'preferred_language',
        'preferred_currency',
        'status',
    ];
```

In `app/Http/Resources/UserResource.php`, change:

```php
            'preferred_language' => $this->preferred_language,
            'status' => $this->status,
```

to:

```php
            'preferred_language' => $this->preferred_language,
            'preferred_currency' => $this->preferred_currency,
            'status' => $this->status,
```

- [ ] **Step 4: Write the Form Requests**

```php
<?php
// app/Http/Requests/Member/UpdateCurrencyPreferenceRequest.php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

/**
 * USD/SYP only — the only pair `exchange_rates` models (Unit 1 design,
 * 2026-08-09). Rejected outright rather than silently accepted with no
 * conversion path.
 */
class UpdateCurrencyPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preferred_currency' => ['required', 'string', 'in:USD,SYP'],
        ];
    }
}
```

```php
<?php
// app/Http/Requests/Member/UpdateLanguagePreferenceRequest.php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLanguagePreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preferred_language' => ['required', 'string', 'in:ar,en'],
        ];
    }
}
```

- [ ] **Step 5: Write the controller**

```php
<?php
// app/Http/Controllers/Api/V1/Member/PreferencesController.php

namespace App\Http\Controllers\Api\V1\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateCurrencyPreferenceRequest;
use App\Http\Requests\Member\UpdateLanguagePreferenceRequest;
use App\Http\Resources\UserResource;

/**
 * `preferred_currency` is display-only (Unit 1 design, 2026-08-09) — never
 * read by transaction/pricing logic (see tests/Guards/
 * PreferredCurrencyIsDisplayOnlyTest.php). `preferred_language` becoming
 * writable here is a reversal of prior behavior; see
 * docs/decisions/preferred-language-mutable.md.
 */
class PreferencesController extends Controller
{
    public function updateCurrency(UpdateCurrencyPreferenceRequest $request): UserResource
    {
        $request->user()->update($request->validated());

        return new UserResource($request->user());
    }

    public function updateLanguage(UpdateLanguagePreferenceRequest $request): UserResource
    {
        $request->user()->update($request->validated());

        return new UserResource($request->user());
    }
}
```

- [ ] **Step 6: Wire the routes**

In `routes/api/v1/member.php`, add the import alongside the others:

```php
use App\Http\Controllers\Api\V1\Member\PreferencesController;
```

and add at the end of the file:

```php

// Unit 1 design (2026-08-09): both writable by the member at any time.
// preferred_language becoming writable here is a reversal of prior
// behavior — see docs/decisions/preferred-language-mutable.md.
Route::patch('preferences/currency', [PreferencesController::class, 'updateCurrency']);
Route::patch('preferences/language', [PreferencesController::class, 'updateLanguage']);
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/Member/PreferencesControllerTest.php`
Expected: PASS (all 4 tests)

- [ ] **Step 8: Commit**

```bash
git add app/Domain/Identity/Models/User.php app/Http/Resources/UserResource.php app/Http/Requests/Member/UpdateCurrencyPreferenceRequest.php app/Http/Requests/Member/UpdateLanguagePreferenceRequest.php app/Http/Controllers/Api/V1/Member/PreferencesController.php routes/api/v1/member.php tests/Feature/Member/PreferencesControllerTest.php
git commit -m "feat: let members update preferred_currency and preferred_language"
```

---

### Task 4: Admin exchange-rate management + audit logging

**Files:**
- Create: `app/Http/Requests/Admin/StoreExchangeRateRequest.php`
- Create: `app/Http/Resources/ExchangeRateResource.php`
- Create: `app/Http/Controllers/Api/V1/Admin/ExchangeRateController.php`
- Modify: `routes/api/v1/admin.php` (add 2 routes)
- Test: `tests/Feature/Admin/ExchangeRateControllerTest.php`

**Interfaces:**
- Consumes: `ExchangeRate` model (Task 1), `App\Concerns\LogsSensitiveActions::logSensitiveAction(string $action, Model $subject, array $properties = [])` (existing).
- Produces: `GET /api/v1/admin/exchange-rates`, `POST /api/v1/admin/exchange-rates`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Admin/ExchangeRateControllerTest.php

namespace Tests\Feature\Admin;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ExchangeRateControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_an_admin_can_create_a_new_exchange_rate(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/exchange-rates', [
            'rate_usd_to_syp' => '14700.5000',
            'effective_from' => now()->toISOString(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('exchange_rates', [
            'rate_usd_to_syp' => '14700.5000',
            'set_by' => $admin->id,
        ]);
    }

    public function test_set_by_is_always_the_authenticated_admin_not_client_supplied(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $otherUser = User::factory()->create();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/admin/exchange-rates', [
            'rate_usd_to_syp' => '14700.0000',
            'effective_from' => now()->toISOString(),
            'set_by' => $otherUser->id,
        ])->assertCreated();

        $this->assertDatabaseHas('exchange_rates', ['set_by' => $admin->id]);
        $this->assertDatabaseMissing('exchange_rates', ['set_by' => $otherUser->id]);
    }

    public function test_creating_a_rate_writes_an_audit_log_entry(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/admin/exchange-rates', [
            'rate_usd_to_syp' => '14700.0000',
            'effective_from' => now()->toISOString(),
        ])->assertCreated();

        $activity = Activity::where('description', 'exchange_rate_created')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('14700.0000', $activity->properties['rate_usd_to_syp']);
    }

    public function test_index_returns_rates_ordered_by_effective_from_descending(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $older = ExchangeRate::factory()->create(['effective_from' => now()->subDays(10)]);
        $newer = ExchangeRate::factory()->create(['effective_from' => now()->subDay()]);

        $response = $this->getJson('/api/v1/admin/exchange-rates');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $newer->id);
        $response->assertJsonPath('data.1.id', $older->id);
    }

    public function test_an_operations_user_can_also_manage_exchange_rates(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->getJson('/api/v1/admin/exchange-rates')->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Admin/ExchangeRateControllerTest.php`
Expected: FAIL — routes don't exist (404).

- [ ] **Step 3: Write the Form Request and Resource**

```php
<?php
// app/Http/Requests/Admin/StoreExchangeRateRequest.php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rate_usd_to_syp' => ['required', 'numeric', 'gt:0'],
            'effective_from' => ['required', 'date'],
        ];
    }
}
```

```php
<?php
// app/Http/Resources/ExchangeRateResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExchangeRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rate_usd_to_syp' => $this->rate_usd_to_syp,
            'effective_from' => $this->effective_from,
            'set_by' => $this->set_by,
            'created_at' => $this->created_at,
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php
// app/Http/Controllers/Api/V1/Admin/ExchangeRateController.php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Models\ExchangeRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreExchangeRateRequest;
use App\Http\Resources\ExchangeRateResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
        $rate = ExchangeRate::create([
            ...$request->validated(),
            'set_by' => $request->user()->id,
        ]);

        $this->logSensitiveAction('exchange_rate_created', $rate, [
            'rate_usd_to_syp' => $rate->rate_usd_to_syp,
            'effective_from' => $rate->effective_from->toISOString(),
        ]);

        return new ExchangeRateResource($rate);
    }
}
```

- [ ] **Step 5: Wire the routes**

In `routes/api/v1/admin.php`, add the import:

```php
use App\Http\Controllers\Api\V1\Admin\ExchangeRateController;
```

and add, at top level (available to both `admin` and `operations`, same as `plans`/`companies`):

```php
Route::get('exchange-rates', [ExchangeRateController::class, 'index']);
Route::post('exchange-rates', [ExchangeRateController::class, 'store']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Admin/ExchangeRateControllerTest.php`
Expected: PASS (all 5 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Admin/StoreExchangeRateRequest.php app/Http/Resources/ExchangeRateResource.php app/Http/Controllers/Api/V1/Admin/ExchangeRateController.php routes/api/v1/admin.php tests/Feature/Admin/ExchangeRateControllerTest.php
git commit -m "feat: add admin exchange-rate management with audit logging"
```

---

### Task 5: Live converted price on `PlanResource`

**Files:**
- Modify: `app/Http/Resources/PlanResource.php`
- Test: `tests/Feature/Membership/PlanPriceConversionTest.php`

**Interfaces:**
- Consumes: `CurrencyConversionService::convert()` (Task 2), `users.preferred_currency` (Task 1/3).
- Produces: optional `converted_amount`/`converted_currency` fields on any `PlanResource` response.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Membership/PlanPriceConversionTest.php

namespace Tests\Feature\Membership;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $response->assertJsonPath('data.converted_amount', 147000.0);
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

    public function test_no_converted_amount_when_preferred_currency_is_not_set(): void
    {
        $plan = Plan::factory()->create(['price' => '10.00', 'pricing_currency' => 'USD']);
        $admin = User::factory()->create(['preferred_currency' => null]);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson("/api/v1/admin/plans/{$plan->id}");

        $response->assertOk();
        $this->assertArrayNotHasKey('converted_amount', $response->json('data'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Membership/PlanPriceConversionTest.php`
Expected: FAIL on the first test — `converted_amount` key missing from the response.

- [ ] **Step 3: Update PlanResource**

Replace the full contents of `app/Http/Resources/PlanResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Domain\Finance\Services\CurrencyConversionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
        // sent (Unit 1 design, 2026-08-09).
        $user = $request->user('sanctum');

        if ($user?->preferred_currency && $user->preferred_currency !== $this->pricing_currency) {
            $converted = app(CurrencyConversionService::class)->convert(
                (float) $this->price,
                $this->pricing_currency,
                $user->preferred_currency
            );

            if ($converted !== null) {
                $data['converted_amount'] = $converted;
                $data['converted_currency'] = $user->preferred_currency;
            }
        }

        return $data;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Membership/PlanPriceConversionTest.php`
Expected: PASS (all 4 tests)

- [ ] **Step 5: Run the full suite to check for regressions**

Run: `php artisan test`
Expected: PASS — in particular re-check any existing test asserting the exact shape of a `PlanResource` response (e.g. `tests/Feature/...Plan...`), since the array now conditionally contains 2 extra keys.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Resources/PlanResource.php tests/Feature/Membership/PlanPriceConversionTest.php
git commit -m "feat: add optional live-converted price to PlanResource"
```

---

### Task 6: Guard test — `preferred_currency` is display-only

**Files:**
- Test: `tests/Guards/PreferredCurrencyIsDisplayOnlyTest.php`

**Interfaces:**
- Consumes: `PATCH /api/v1/member/preferences/currency` (Task 3).

- [ ] **Step 1: Write the guard test**

```php
<?php
// tests/Guards/PreferredCurrencyIsDisplayOnlyTest.php

namespace Tests\Guards;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Unit 1 design (2026-08-09): `preferred_currency` is display-only and
 * must never influence pricing, transactions, or wallet logic. This
 * proves it rather than trusting the prose rule — the currency-preference
 * endpoint only ever writes to the `users` table, and this asserts a
 * plan's pricing columns are byte-for-byte unchanged after it runs.
 */
class PreferredCurrencyIsDisplayOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_changing_preferred_currency_does_not_mutate_pricing_records(): void
    {
        $plan = Plan::factory()->create(['pricing_currency' => 'USD', 'price' => '10.00']);
        $member = User::factory()->create(['preferred_currency' => 'USD']);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $planBefore = $plan->only(['pricing_currency', 'price']);

        $this->patchJson('/api/v1/member/preferences/currency', ['preferred_currency' => 'SYP'])
            ->assertOk();

        $plan->refresh();

        $this->assertSame($planBefore, $plan->only(['pricing_currency', 'price']));
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'pricing_currency' => 'USD', 'price' => '10.00']);
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `php artisan test tests/Guards/PreferredCurrencyIsDisplayOnlyTest.php`
Expected: PASS (the endpoint from Task 3 only ever updates `users`, so this should pass immediately — this guard exists to catch a *future* regression, not today's code)

- [ ] **Step 3: Commit**

```bash
git add tests/Guards/PreferredCurrencyIsDisplayOnlyTest.php
git commit -m "test: guard that preferred_currency never mutates pricing records"
```

---

### Task 7: Decision docs

**Files:**
- Create: `docs/decisions/preferred-language-mutable.md`
- Modify: `docs/decisions/money-model.md` (append a short note)
- Modify: `docs/decisions/README.md` (catalog entries for both)

**Interfaces:** none — documentation only, no code consumed or produced.

- [ ] **Step 1: Write the new decision doc**

```markdown
<!-- docs/decisions/preferred-language-mutable.md -->
# Member-Writable preferred_language

**Status:** resolved 2026-08-09
**Owner:** Maryam Asha

## What shipped

`preferred_language` was set once, hardcoded to `'ar'`, at first-time OTP
signup (`MemberAuthController::verifyOtp()`), and had no member-facing
write path at all — only readable via `GET /auth/me`.

## Decision

**`preferred_language` is now member-writable at any time, via
`PATCH /api/v1/member/preferences/language`.** This is a reversal of the
prior effectively-read-only behavior, not new scope — the column already
existed.

## Why

Landed alongside the new `preferred_currency` preference (display
currency), which needed the same "member can change this whenever they
like" mechanism. Since both preferences are conceptually symmetric,
`preferred_language` gained the same write path rather than staying
inconsistent with the new field next to it.

## What this changed in code

- New route: `PATCH /api/v1/member/preferences/language` →
  `Member\PreferencesController::updateLanguage`.
- New `App\Http\Requests\Member\UpdateLanguagePreferenceRequest`
  (`in:ar,en`).
- `MemberAuthController::verifyOtp()` is unchanged — the hardcoded `'ar'`
  default at signup still applies; only the *subsequent* mutability
  changed.

## Guard

None yet — this is a permissive change (a previously-closed write path is
now open), not a new constraint to enforce. If a future decision wants to
restrict which values are allowed, that's the place for a guard test.
```

- [ ] **Step 2: Amend the money-model decision doc**

Read `docs/decisions/money-model.md`, then append this note at the end of the file (after its existing content, as its own section):

```markdown

## Update — 2026-08-09

`exchange_rates` is now implemented: `app/Domain/Finance/Models/ExchangeRate.php`,
migration `2026_08_09_160001_create_exchange_rates_table.php`. It's used
for two purposes sharing the one table, per the original decision below:
live, unstored display conversion (`preferred_currency`, see
`docs/superpowers/plans/2026-08-09-display-currency.md`), and — not yet
built — `exchange_rate_snapshot` on actual financial transactions. This
is not a new decision, just infrastructure catching up to this one.
```

- [ ] **Step 3: Update the decisions catalog**

Read `docs/decisions/README.md` and add one line for
`preferred-language-mutable.md` to its existing catalog/list, following
the exact same format as the other entries already there.

- [ ] **Step 4: Commit**

```bash
git add docs/decisions/preferred-language-mutable.md docs/decisions/money-model.md docs/decisions/README.md
git commit -m "docs: record preferred_language mutability reversal and exchange_rates completion"
```
