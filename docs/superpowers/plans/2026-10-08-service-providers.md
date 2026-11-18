# Service Providers (Phase 1: core and approvals) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A service provider (an intermediary with no seats of its own) works from the ADDOS dashboard on its own client companies — requests offices for them, assigns existing plans or designs new ones, tops up their wallets, adds their employees and names their admin — with every grant going through an approval queue that an admin, or any role given the permission, decides.

**Architecture:** A new `App\Domain\Providers` domain holds `Provider`, `ProviderRequest`, `CompanyInvitation`, their enums, and `ProviderRequestService`, which approves a request under a row lock by handing it to one `ProviderRequestHandler` per type (the same shape as the payment fulfillers). Providers reach the API through a new route group (`routes/api/v1/provider.php`) behind `auth:sanctum · abilities:dashboard · role:provider · provider.active`; every route on a company goes through the `provider.manage-company` gate (`ProviderPolicy`), which answers 404 for a company that is not theirs. The admin side is permission-gated (`providers.*`, `provider-requests.*`) on the Branches pilot pattern. Office requests reuse the existing private-office pipeline, carrying `provider_id` through to the company it creates.

**Tech Stack:** Laravel 12, PHP 8.3, spatie/laravel-permission, Sanctum, PHPUnit feature tests on in-memory SQLite, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-10-08-service-providers-design.md` (Arabic; this plan is its Phase 1).

## Global Constraints

- Run PHP as `/c/laragon/bin/php/php-8.3.33-nts-Win32-vs16-x64/php.exe` (the `php` on PATH is 8.2; the lock file needs ≥ 8.3). Below, `PHP` means that path.
- Tests: `PHP artisan test <path>`; style: `PHP vendor/bin/pint --test <files>` must pass for every touched PHP file.
- The full suite has **17 pre-existing failures** (audit-log `'1'` vs `1`, the EventAttendee enum guard, notifications on SQLite). A task is green when it adds none: compare against that list, never assume.
- Every update-style endpoint answers `{"message": __('api.<group>.<key>')}`; only `store()` returns the created resource (201).
- Copy goes in `lang/en/api.php` **and** `lang/ar/api.php`. The new `provider` group is added **once**, at the end of each file — grep first: a second top-level `'provider' =>` key would silently replace the first. Keys added to existing groups (`admin`, `member`, `role`) go inside those groups.
- Code, comments, docs in the repo are English.
- Do not read or search `vendor/`, `storage/logs`, `storage/framework`, `bootstrap/cache`.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

**Deliberate refinements of the spec (apply these, not the spec's wording):**

| Spec says | Plan does | Why |
|---|---|---|
| `provider_requests.result_type` + `result_id` (morph) | `result_id` only; its meaning follows `type` | A morph would need `Plan`/`Membership`/`WalletTransaction` in the morph map, which changes how *existing* activity-log rows for those models are written. |
| morph aliases `provider`, `provider_request` | none | Nothing stores them in a morph column once `result_type` is gone. |
| permissions `provider_requests.*` | `provider-requests.*` | Module names are the kebab-case URI segment everywhere else (`error-logs.view`). |
| "`provider` never combines with another role" → 422 | no new check; a test pins it | `UserController::store` assigns one role and `assignRole` calls `syncRoles([...])` — a second role is already impossible through the API. |

**What `result_id` holds, by type:** `plan_design` → the new plan's id · `plan_assignment` → the membership's id · `wallet_top_up` → the wallet transaction's id · `company_member` → the user's id when an account was linked, `null` when an invitation was parked · `company_admin` → the user's id.

## Review Focus

1. **A provider account whose link was removed, but which still holds the `provider` role** → 403 `api.provider.inactive` on every provider route, never a 500 (Task 3 pins it).
2. **A plan assignment whose company got a live membership between submission and approval** → 422 `api.admin.membership_already_active`, the request stays `pending`, nothing activated (Task 7 pins it).
3. **A provider assigning another provider's private plan** → refused at submission on `plan_id`, never queued (Task 6 pins it).
4. **A company moved to another provider** → the old provider's pending requests on it are cancelled and the old provider now gets 404 on it (Task 9 pins it).
5. **A member-app token (`member-app` ability) on a provider route** → 403, even when its holder has the provider role (Task 3 pins it).

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_10_08_110000_create_providers_tables.php` | `providers`, `provider_user`, `provider_requests`, `company_invitations`; `provider_id` on `companies`, `private_office_requests` (+`submitted_by`), `plans` |
| `app/Domain/Providers/Enums/{ProviderStatus,ProviderRequestType,ProviderRequestStatus,Payer}.php` | enums |
| `app/Domain/Providers/Models/{Provider,ProviderRequest,CompanyInvitation}.php` | models |
| `app/Domain/Providers/Policies/ProviderPolicy.php` | `manageCompany(User, Company)` |
| `app/Domain/Providers/Exceptions/ProviderRequestException.php` | refusal with message key + status |
| `app/Domain/Providers/Requests/ProviderRequestHandler.php` + `Handlers/*Handler.php` + `ProviderRequestHandlers.php` | one handler per type, and the type → handler map |
| `app/Domain/Providers/Services/ProviderRequestService.php` | submit, approve, reject, cancel, cancelPending |
| `app/Domain/Providers/Services/CompanyInvitationAcceptor.php` | links open invitations at sign-up |
| `app/Domain/Membership/Services/PlanCreationService.php` | plan + balances/features/discounts/grants, moved out of `PlanController::store` |
| `app/Support/Plans/PlanRules.php` | the plan validation array, shared by `StorePlanRequest` and provider plan design |
| `app/Http/Middleware/EnsureActiveProvider.php` | `provider.active` |
| `app/Http/Controllers/Api/V1/Provider/*` | provider surface |
| `app/Http/Controllers/Api/V1/Admin/{ProviderController,ProviderUserController,ProviderRequestController,CompanyProviderController}.php` | admin surface |
| `routes/api/v1/provider.php` | provider routes |
| `tests/Feature/Providers/*` | feature tests; `tests/Feature/Providers/ProviderTestCase.php` holds shared setup |
| `tests/Guards/ProviderRoutesStayIsolatedTest.php` | isolation guard |
| `docs/decisions/service-providers.md` | decision record |

---

### Task 1: Schema, models, enums, factories

**Files:**
- Create: `database/migrations/2026_10_08_110000_create_providers_tables.php`
- Create: `app/Domain/Providers/Enums/ProviderStatus.php`, `ProviderRequestType.php`, `ProviderRequestStatus.php`, `Payer.php`
- Create: `app/Domain/Providers/Models/Provider.php`, `ProviderRequest.php`, `CompanyInvitation.php`
- Create: `database/factories/ProviderFactory.php`, `database/factories/ProviderRequestFactory.php`
- Modify: `app/Domain/Identity/Models/Company.php`, `app/Domain/Identity/Models/User.php`, `app/Domain/Identity/Models/PrivateOfficeRequest.php`, `app/Domain/Membership/Models/Plan.php`
- Modify: `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` (register the new enum columns)
- Test: `tests/Feature/Providers/ProviderModelTest.php`

**Interfaces:**
- Produces: `Provider` (`users()`, `companies()`, `requests()`, `plans()`, `isActive()`, `static forUser(User): ?Provider`), `ProviderRequest` (`provider()`, `submitter()`, `company()`, `reviewer()`, `isPending()`, `scopePending()`), `CompanyInvitation` (`company()`, `scopeOpen()`), `Company::provider()`, `Company::invitations()`, `User::providers()`, `Plan::provider()`, `PrivateOfficeRequest::provider()`; enums `ProviderStatus{Active,Inactive}`, `ProviderRequestType{CompanyMember,CompanyAdmin,PlanDesign,PlanAssignment,WalletTopUp}`, `ProviderRequestStatus{Pending,Approved,Rejected,Cancelled}`, `Payer{Provider,Company}`; factories `Provider::factory()`, `ProviderRequest::factory()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Enums\ProviderStatus;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_provider_has_accounts_and_client_companies(): void
    {
        $provider = Provider::factory()->create();
        $user = User::factory()->create();
        $provider->users()->attach($user->id);
        $company = Company::factory()->create(['provider_id' => $provider->id]);

        $this->assertSame(ProviderStatus::Active, $provider->fresh()->status);
        $this->assertTrue($provider->companies->contains($company));
        $this->assertTrue($company->provider->is($provider));
        $this->assertTrue(Provider::forUser($user)->is($provider));
    }

    public function test_an_account_works_for_one_provider_only(): void
    {
        $user = User::factory()->create();
        Provider::factory()->create()->users()->attach($user->id);

        $this->expectException(QueryException::class);
        Provider::factory()->create()->users()->attach($user->id);
    }

    public function test_a_request_casts_its_type_status_and_payload(): void
    {
        $request = ProviderRequest::factory()->create([
            'type' => ProviderRequestType::WalletTopUp,
            'payload' => ['points' => 500, 'payer' => 'company'],
        ])->fresh();

        $this->assertSame(ProviderRequestType::WalletTopUp, $request->type);
        $this->assertSame(ProviderRequestStatus::Pending, $request->status);
        $this->assertSame(500, $request->payload['points']);
        $this->assertTrue($request->isPending());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PHP artisan test tests/Feature/Providers/ProviderModelTest.php`
Expected: FAIL — `Class "App\Domain\Providers\Models\Provider" not found`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service providers (docs/decisions/service-providers.md): intermediaries
 * that book seats at ADD for their client companies. `provider_user.user_id`
 * is unique — an account works for one provider. `provider_requests` is the
 * approval queue; `result_id` is what an approval created, its meaning
 * following `type`. `company_invitations` parks an approved employee whose
 * phone has no account yet, until they sign up with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contract_ref');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('provider_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->after('branch_id')
                ->constrained('providers')->nullOnDelete();
        });

        Schema::table('private_office_requests', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->after('converted_company_id')
                ->constrained('providers')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->after('provider_id')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->after('is_public')
                ->constrained('providers')->nullOnDelete();
        });

        Schema::create('provider_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status']);
            $table->index(['status', 'type']);
        });

        Schema::create('company_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->boolean('door_access_enabled')->default(false);
            $table->foreignId('provider_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'phone']);
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invitations');
        Schema::dropIfExists('provider_requests');

        Schema::table('plans', fn (Blueprint $table) => $table->dropConstrainedForeignId('provider_id'));
        Schema::table('private_office_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('provider_id');
        });
        Schema::table('companies', fn (Blueprint $table) => $table->dropConstrainedForeignId('provider_id'));

        Schema::dropIfExists('provider_user');
        Schema::dropIfExists('providers');
    }
};
```

- [ ] **Step 4: Write the enums**

`app/Domain/Providers/Enums/ProviderStatus.php`:

```php
<?php

namespace App\Domain\Providers\Enums;

enum ProviderStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
```

`app/Domain/Providers/Enums/ProviderRequestType.php`:

```php
<?php

namespace App\Domain\Providers\Enums;

/**
 * Everything a provider asks ADD to grant (docs/decisions/service-providers.md).
 * A new office (a new client company) is not here: it goes through the
 * private-office pipeline, which has a quote and a contract of its own.
 */
enum ProviderRequestType: string
{
    case CompanyMember = 'company_member';
    case CompanyAdmin = 'company_admin';
    case PlanDesign = 'plan_design';
    case PlanAssignment = 'plan_assignment';
    case WalletTopUp = 'wallet_top_up';

    /** Whether a request of this type is about one client company. */
    public function needsCompany(): bool
    {
        return $this !== self::PlanDesign;
    }
}
```

`app/Domain/Providers/Enums/ProviderRequestStatus.php`:

```php
<?php

namespace App\Domain\Providers\Enums;

enum ProviderRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
```

`app/Domain/Providers/Enums/Payer.php`:

```php
<?php

namespace App\Domain\Providers\Enums;

/**
 * Who an assignment or a top-up is invoiced to. Recorded from Phase 1 on;
 * read by invoicing (Phase 2).
 */
enum Payer: string
{
    case Provider = 'provider';
    case Company = 'company';
}
```

- [ ] **Step 5: Write the models**

`app/Domain/Providers/Models/Provider.php`:

```php
<?php

namespace App\Domain\Providers\Models;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Enums\ProviderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An intermediary that books seats at ADD for its client companies and has
 * none of its own (docs/decisions/service-providers.md). Created by an
 * admin; its accounts are dashboard accounts holding the `provider` role.
 */
class Provider extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'contract_ref',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProviderStatus::class,
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'provider_user')->withTimestamps();
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ProviderRequest::class);
    }

    /** The plans this provider designed. */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    public function isActive(): bool
    {
        return $this->status === ProviderStatus::Active;
    }

    /** The provider this account works for, whatever its status. */
    public static function forUser(User $user): ?self
    {
        return self::query()
            ->whereHas('users', fn ($query) => $query->where('users.id', $user->id))
            ->first();
    }
}
```

`app/Domain/Providers/Models/ProviderRequest.php`:

```php
<?php

namespace App\Domain\Providers\Models;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Enums\ProviderRequestType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a provider asked ADD to grant, waiting for (or past) a review.
 * `payload` holds the type's fields; `result_id` what approval created —
 * see ProviderRequestService.
 */
class ProviderRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'submitted_by',
        'type',
        'company_id',
        'payload',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProviderRequestType::class,
            'status' => ProviderRequestStatus::class,
            'payload' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ProviderRequestStatus::Pending);
    }

    public function isPending(): bool
    {
        return $this->status === ProviderRequestStatus::Pending;
    }
}
```

`app/Domain/Providers/Models/CompanyInvitation.php`:

```php
<?php

namespace App\Domain\Providers\Models;

use App\Domain\Identity\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approved employee whose phone had no member account yet. Linked to the
 * company when that phone signs up (CompanyInvitationAcceptor).
 */
class CompanyInvitation extends Model
{
    protected $fillable = [
        'company_id',
        'phone',
        'door_access_enabled',
        'provider_request_id',
    ];

    protected function casts(): array
    {
        return [
            'door_access_enabled' => 'boolean',
            'accepted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at');
    }
}
```

- [ ] **Step 6: Add the relations to existing models**

`app/Domain/Identity/Models/Company.php` — add `'provider_id'` to `$fillable` and these methods (with `use App\Domain\Providers\Models\CompanyInvitation;` and `use App\Domain\Providers\Models\Provider;`):

```php
    /** The provider managing this company; null when ADD deals with it directly. */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }
```

`app/Domain/Identity/Models/User.php` — add (with `use App\Domain\Providers\Models\Provider;`):

```php
    /** At most one row: `provider_user.user_id` is unique. */
    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(Provider::class, 'provider_user')->withTimestamps();
    }
```

`app/Domain/Identity/Models/PrivateOfficeRequest.php` — add `'provider_id'`, `'submitted_by'` to `$fillable` and:

```php
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
```

(import `Illuminate\Database\Eloquent\Relations\BelongsTo` and `App\Domain\Providers\Models\Provider`).

`app/Domain/Membership/Models/Plan.php` — add `'provider_id'` to `$fillable` and:

```php
    /** The provider that designed this plan; null for ADD's own plans. */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
```

- [ ] **Step 7: Write the factories**

`database/factories/ProviderFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Domain\Providers\Enums\ProviderStatus;
use App\Domain\Providers\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Provider>
 */
class ProviderFactory extends Factory
{
    protected $model = Provider::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'contract_ref' => 'P-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => ProviderStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ProviderStatus::Inactive]);
    }
}
```

`database/factories/ProviderRequestFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Domain\Identity\Models\Company;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderRequest>
 */
class ProviderRequestFactory extends Factory
{
    protected $model = ProviderRequest::class;

    /** A top-up of a company of the same provider, pending. */
    public function definition(): array
    {
        $provider = Provider::factory();

        return [
            'provider_id' => $provider,
            'submitted_by' => null,
            'type' => ProviderRequestType::WalletTopUp,
            'company_id' => Company::factory()->for($provider),
            'payload' => ['points' => 100, 'payer' => 'company'],
            'status' => ProviderRequestStatus::Pending,
        ];
    }
}
```

No `newFactory()` is needed: `AppServiceProvider` registers `Factory::guessFactoryNamesUsing(...)`, which maps `App\Domain\*\Models\X` to `Database\Factories\XFactory`, as it does for `Company`.

- [ ] **Step 8: Register the enum columns in the guard**

In `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`, add the imports and these entries to `EXPECTED_CASTS`:

```php
        Provider::class => [
            'status' => ProviderStatus::class,
        ],
        ProviderRequest::class => [
            'type' => ProviderRequestType::class,
            'status' => ProviderRequestStatus::class,
        ],
```

- [ ] **Step 9: Run the tests**

Run: `PHP artisan test tests/Feature/Providers/ProviderModelTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
PHP vendor/bin/pint app/Domain/Providers app/Domain/Identity/Models app/Domain/Membership/Models/Plan.php database/factories/ProviderFactory.php database/factories/ProviderRequestFactory.php database/migrations/2026_10_08_110000_create_providers_tables.php tests/Feature/Providers tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php
git add -A app/Domain/Providers app/Domain/Identity/Models app/Domain/Membership/Models/Plan.php database/factories database/migrations/2026_10_08_110000_create_providers_tables.php tests/Feature/Providers tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php
git commit -m "feat(providers): providers, their accounts, requests and invitations

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The `provider` role

**Files:**
- Modify: `app/Support/ProtectedRoles.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/RoleController.php:54-61`
- Modify: `lang/en/api.php`, `lang/ar/api.php` (`role` group)
- Test: `tests/Feature/Providers/ProviderRoleTest.php`

**Interfaces:**
- Produces: a seeded, protected `provider` role that never holds an admin permission.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProviderRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    public function test_the_provider_role_is_seeded_and_protected(): void
    {
        $role = Role::findByName('provider', 'web');

        $this->patchJson("/api/v1/admin/roles/{$role->id}", ['name' => 'partner'])->assertStatus(422);
        $this->deleteJson("/api/v1/admin/roles/{$role->id}")->assertStatus(422);
    }

    public function test_the_provider_role_never_holds_an_admin_permission(): void
    {
        $role = Role::findByName('provider', 'web');

        $this->patchJson("/api/v1/admin/roles/{$role->id}", ['permissions' => ['branches.view']])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.role.provider_out_of_scope'));

        $this->assertCount(0, $role->fresh()->permissions);
    }

    public function test_giving_an_account_the_provider_role_replaces_its_other_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole('operations');

        $this->patchJson("/api/v1/admin/users/{$user->id}/role", ['role' => 'provider'])->assertOk();

        $this->assertSame(['provider'], $user->fresh()->getRoleNames()->all());
    }
}
```

(Check the role-assignment URI with `PHP artisan route:list --path=admin/users` and use the one registered.)

- [ ] **Step 2: Run to verify it fails**

Run: `PHP artisan test tests/Feature/Providers/ProviderRoleTest.php`
Expected: FAIL — `There is no role named 'provider'`.

- [ ] **Step 3: Implement**

`app/Support/ProtectedRoles.php`:

```php
/**
 * These role names must never be deletable or renamable through the
 * role-management endpoints — other code depends on these exact literal
 * strings existing (member-vs-dashboard login separation, first-admin
 * bootstrap, the provider route group).
 */
final class ProtectedRoles
{
    public const NAMES = ['member', 'operations', 'admin', 'provider'];

    /** Roles outside the admin permission system: they never hold an admin-panel permission. */
    public const OUTSIDE_ADMIN_PERMISSIONS = ['member', 'provider'];
}
```

`RoleController::update()` — replace the `member` block with:

```php
        // member and provider are entirely out of scope for the admin
        // permission system — neither ever touches the admin dashboard's
        // routes, so neither may hold an admin-panel permission
        // (docs/decisions/rbac-permission-pilot.md, docs/decisions/service-providers.md).
        if (in_array($role->name, ProtectedRoles::OUTSIDE_ADMIN_PERMISSIONS, true) && $request->has('permissions')) {
            return response()->json(['message' => __("api.role.{$role->name}_out_of_scope")], 422);
        }
```

`lang/en/api.php`, `role` group:

```php
        'provider_out_of_scope' => "Provider accounts don't participate in the admin permission system; the provider role can't be assigned any admin-panel permissions.",
```

`lang/ar/api.php`, `role` group:

```php
        'provider_out_of_scope' => 'حسابات المزوّدين خارج نظام صلاحيات لوحة الإدارة؛ لا يمكن إسناد أي صلاحية إدارية لدور المزوّد.',
```

`RoleSeeder` already loops over `ProtectedRoles::NAMES` — no change there; update its docblock's "only three become spatie role rows" sentence to say four, naming `provider`.

- [ ] **Step 4: Run the tests**

Run: `PHP artisan test tests/Feature/Providers/ProviderRoleTest.php tests/Feature/Identity`
Expected: PASS (and no new failures in Identity against the baseline).

- [ ] **Step 5: Commit**

```bash
PHP vendor/bin/pint app/Support/ProtectedRoles.php app/Http/Controllers/Api/V1/Admin/RoleController.php database/seeders/RoleSeeder.php tests/Feature/Providers/ProviderRoleTest.php
git add app/Support/ProtectedRoles.php app/Http/Controllers/Api/V1/Admin/RoleController.php database/seeders/RoleSeeder.php lang/en/api.php lang/ar/api.php tests/Feature/Providers/ProviderRoleTest.php
git commit -m "feat(providers): a protected provider role outside admin permissions

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The provider surface — route group, `provider.active`, `ProviderPolicy`, companies

**Files:**
- Create: `routes/api/v1/provider.php`
- Modify: `routes/api.php` (new group), `bootstrap/app.php` (alias)
- Create: `app/Http/Middleware/EnsureActiveProvider.php`
- Create: `app/Domain/Providers/Policies/ProviderPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php` (gate)
- Modify: `tests/Guards/RbacStaysFlatTest.php`
- Create: `app/Http/Controllers/Api/V1/Provider/Concerns/ActsForProvider.php`
- Create: `app/Http/Controllers/Api/V1/Provider/ProviderCompanyController.php`
- Create: `app/Http/Resources/Provider/ProviderCompanyResource.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php` (new `provider` group at the end)
- Test: `tests/Feature/Providers/ProviderTestCase.php`, `tests/Feature/Providers/ProviderCompanyTest.php`

**Interfaces:**
- Consumes: Task 1 models, Task 2 role.
- Produces: middleware alias `provider.active`; `EnsureActiveProvider::ATTRIBUTE = 'provider'`; gate `provider.manage-company` (`ProviderPolicy::manageCompany(User, Company): bool`); trait `ActsForProvider` with `provider(Request): Provider` and `authorizeCompany(Request, Company): void` (404 when not theirs); test base `ProviderTestCase` with `providerAccount(?Provider): User`, `clientCompany(Provider, array $attributes = []): Company` (company + its wallet), `branchWithSeats(int): Branch`.

- [ ] **Step 1: Write the shared test base**

`tests/Feature/Providers/ProviderTestCase.php`:

```php
<?php

namespace Tests\Feature\Providers;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Providers\Models\Provider;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class ProviderTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    /** A dashboard account of this provider (a new one when none is given), signed in. */
    protected function providerAccount(?Provider $provider = null): User
    {
        $provider ??= Provider::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('provider');
        $provider->users()->attach($user->id);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /** A client company of this provider, with the wallet CompanyController::store gives every company. */
    protected function clientCompany(Provider $provider, array $attributes = []): Company
    {
        $company = Company::factory()->create(['provider_id' => $provider->id, ...$attributes]);
        Wallet::firstOrCreate(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        return $company;
    }

    protected function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    /** The default branch with a co-working pool of this many seats. */
    protected function branchWithSeats(int $seats): Branch
    {
        Cache::forget('default_branch_id');
        $branch = Branch::factory()->create(['is_default' => true]);
        Space::factory()->create([
            'building_id' => Building::factory()->create(['branch_id' => $branch->id])->id,
            'capacity' => $seats,
        ]);

        return $branch;
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Providers/ProviderCompanyTest.php`:

```php
<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\Provider;
use Laravel\Sanctum\Sanctum;

class ProviderCompanyTest extends ProviderTestCase
{
    public function test_a_provider_lists_only_its_own_companies(): void
    {
        $provider = Provider::factory()->create();
        $this->providerAccount($provider);
        $mine = $this->clientCompany($provider);
        $this->clientCompany(Provider::factory()->create());
        Company::factory()->create(); // a direct company

        $this->getJson('/api/v1/provider/companies')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_another_providers_company_and_a_direct_company_are_not_found(): void
    {
        $this->providerAccount();
        $theirs = $this->clientCompany(Provider::factory()->create());
        $direct = Company::factory()->create();

        $this->getJson("/api/v1/provider/companies/{$theirs->id}")->assertNotFound();
        $this->getJson("/api/v1/provider/companies/{$direct->id}")->assertNotFound();
    }

    public function test_a_company_shows_its_members_and_open_invitations(): void
    {
        $provider = Provider::factory()->create();
        $this->providerAccount($provider);
        $company = $this->clientCompany($provider);
        $member = User::factory()->create(['phone' => '0933333333']);
        $company->members()->attach($member->id, ['is_admin' => true, 'door_access_enabled' => false]);

        $this->getJson("/api/v1/provider/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.members.0.id', $member->id)
            ->assertJsonPath('data.members.0.is_admin', true)
            ->assertJsonPath('data.invitations', []);
    }

    public function test_a_provider_removes_an_employee_without_approval(): void
    {
        $provider = Provider::factory()->create();
        $this->providerAccount($provider);
        $company = $this->clientCompany($provider);
        $member = User::factory()->create();
        $company->members()->attach($member->id);

        $this->deleteJson("/api/v1/provider/companies/{$company->id}/members/{$member->id}")
            ->assertOk()
            ->assertJsonPath('message', __('api.provider.member_removed'));

        $this->assertFalse($company->members()->where('users.id', $member->id)->exists());
    }

    public function test_an_inactive_provider_is_refused_everywhere(): void
    {
        $this->providerAccount(Provider::factory()->inactive()->create());

        $this->getJson('/api/v1/provider/companies')
            ->assertForbidden()
            ->assertJsonPath('message', __('api.provider.inactive'));
    }

    public function test_a_provider_account_unlinked_from_its_provider_is_refused(): void
    {
        $user = User::factory()->create();
        $user->assignRole('provider');
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/provider/companies')->assertForbidden();
    }

    public function test_a_member_app_token_never_reaches_the_provider_surface(): void
    {
        $user = $this->providerAccount();
        Sanctum::actingAs($user, ['member-app']);

        $this->getJson('/api/v1/provider/companies')->assertForbidden();
    }

    public function test_operations_and_members_cannot_use_the_provider_surface(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->getJson('/api/v1/provider/companies')->assertForbidden();
    }

    public function test_a_provider_cannot_reach_the_admin_or_member_api(): void
    {
        $this->providerAccount();

        $this->getJson('/api/v1/admin/companies')->assertForbidden();
        $this->getJson('/api/v1/member/memberships')->assertForbidden();
    }
}
```

- [ ] **Step 3: Run to verify they fail**

Run: `PHP artisan test tests/Feature/Providers/ProviderCompanyTest.php`
Expected: FAIL — 404 on `/api/v1/provider/companies` (no route).

- [ ] **Step 4: Implement the middleware**

`app/Http/Middleware/EnsureActiveProvider.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Domain\Providers\Models\Provider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `provider.active`: the account must be linked to a provider, and that
 * provider active. Deactivating a provider cuts every one of its accounts
 * off here, without touching their roles (docs/decisions/service-providers.md).
 * Puts the provider on the request for the controllers.
 */
class EnsureActiveProvider
{
    public const ATTRIBUTE = 'provider';

    public function handle(Request $request, Closure $next): Response
    {
        $provider = Provider::forUser($request->user());

        if ($provider === null || ! $provider->isActive()) {
            return response()->json(['message' => __('api.provider.inactive')], 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $provider);

        return $next($request);
    }
}
```

`bootstrap/app.php` — inside `$middleware->alias([...])`, after `'guest' => ...`:

```php
            // The provider route group (docs/decisions/service-providers.md):
            // the account's provider exists and is active.
            'provider.active' => EnsureActiveProvider::class,
```

(import `App\Http\Middleware\EnsureActiveProvider`).

- [ ] **Step 5: Implement the policy and the gate**

`app/Domain/Providers/Policies/ProviderPolicy.php`:

```php
<?php

namespace App\Domain\Providers\Policies;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\Provider;

/**
 * The second deliberate exception to flat RBAC after CompanyPolicy
 * (docs/decisions/service-providers.md): which companies a provider account
 * may act on is a fact about `companies.provider_id`, not about a role.
 * Registered as the `provider.manage-company` gate rather than as Company's
 * policy, which CompanyPolicy already is.
 */
class ProviderPolicy
{
    public function manageCompany(User $user, Company $company): bool
    {
        $provider = Provider::forUser($user);

        return $provider !== null && $company->provider_id === $provider->id;
    }
}
```

`app/Providers/AppServiceProvider.php` — next to `Gate::policy(Company::class, CompanyPolicy::class);`:

```php
        Gate::define('provider.manage-company', [ProviderPolicy::class, 'manageCompany']);
```

`tests/Guards/RbacStaysFlatTest.php` — the second test becomes:

```php
    public function test_company_and_provider_policies_are_the_only_policy_classes_in_the_app(): void
    {
        $policyFiles = [];

        foreach (glob(app_path('Domain/*/Policies/*.php')) as $file) {
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            $policyFiles[] = str_replace('\\', '/', $relative);
        }

        sort($policyFiles);

        $this->assertSame(
            [
                'app/Domain/Identity/Policies/CompanyPolicy.php',
                'app/Domain/Providers/Policies/ProviderPolicy.php',
            ],
            $policyFiles,
            'D.8 — CompanyPolicy and ProviderPolicy (docs/decisions/service-providers.md) are the only exceptions to flat RBAC; another Policy needs its own decision record, not a silent addition.'
        );
    }
```

- [ ] **Step 6: Implement the controller, trait and resource**

`app/Http/Controllers/Api/V1/Provider/Concerns/ActsForProvider.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Provider\Concerns;

use App\Domain\Identity\Models\Company;
use App\Domain\Providers\Models\Provider;
use App\Http\Middleware\EnsureActiveProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

trait ActsForProvider
{
    /** The provider EnsureActiveProvider resolved for this request. */
    protected function provider(Request $request): Provider
    {
        return $request->attributes->get(EnsureActiveProvider::ATTRIBUTE);
    }

    /**
     * 404, not 403, for a company that is not this provider's: a provider
     * must not learn that another provider's company exists.
     */
    protected function authorizeCompany(Request $request, Company $company): void
    {
        abort_if(Gate::denies('provider.manage-company', $company), 404);
    }
}
```

`app/Http/Resources/Provider/ProviderCompanyResource.php`:

```php
<?php

namespace App\Http\Resources\Provider;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client company as its provider sees it. `members` and `invitations`
 * only on the detail call; `membership` is the live company subscription
 * (set by the controller as `current_membership`), or null.
 */
class ProviderCompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $membership = $this->current_membership;

        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'branch_id' => $this->branch_id,
            'status' => $this->status,
            'members_count' => $this->whenCounted('members'),
            'membership' => $membership === null ? null : [
                'id' => $membership->id,
                'plan_id' => $membership->plan_id,
                'plan_name' => $membership->plan?->translate('name'),
                'seats' => $membership->seats,
                'current_period_end' => $membership->current_period_end?->toIso8601String(),
            ],
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'phone' => $member->phone,
                'is_admin' => (bool) $member->pivot->is_admin,
                'door_access_enabled' => (bool) $member->pivot->door_access_enabled,
            ])->values()),
            'invitations' => $this->whenLoaded('invitations', fn () => $this->invitations->map(fn ($invitation) => [
                'id' => $invitation->id,
                'phone' => $invitation->phone,
                'door_access_enabled' => $invitation->door_access_enabled,
                'created_at' => $invitation->created_at?->toIso8601String(),
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
```

(`Plan` uses `HasTranslations`, so `translate('name')` reads the current locale.)

`app/Http/Controllers/Api/V1/Provider/ProviderCompanyController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Services\MembershipResolver;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Resources\Provider\ProviderCompanyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A provider's client companies (docs/decisions/service-providers.md).
 * Removing an employee needs no approval — it takes access away, never
 * grants it. Adding one is a request (ProviderRequestController).
 */
class ProviderCompanyController extends Controller
{
    use ActsForProvider, LogsSensitiveActions;

    public function __construct(private readonly MembershipResolver $memberships) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companies = $this->provider($request)->companies()
            ->withCount('members')
            ->orderBy('legal_name')
            ->get()
            ->each(fn (Company $company) => $this->attachMembership($company));

        return ProviderCompanyResource::collection($companies);
    }

    public function show(Request $request, Company $company): ProviderCompanyResource
    {
        $this->authorizeCompany($request, $company);

        $company->load(['members', 'invitations' => fn ($query) => $query->open()])->loadCount('members');
        $this->attachMembership($company);

        return new ProviderCompanyResource($company);
    }

    public function destroyMember(Request $request, Company $company, User $user): JsonResponse
    {
        $this->authorizeCompany($request, $company);
        abort_unless($company->members()->where('users.id', $user->id)->exists(), 404);

        $company->members()->detach($user->id);

        $this->logSensitiveAction('company_member_removed_by_provider', $company, ['user_id' => $user->id]);

        return response()->json(['message' => __('api.provider.member_removed')]);
    }

    private function attachMembership(Company $company): void
    {
        $company->setAttribute('current_membership', $this->memberships->activeForOwner(OwnerType::Company, $company->id));
    }
}
```

- [ ] **Step 7: Register the routes**

`routes/api/v1/provider.php`:

```php
<?php

use App\Http\Controllers\Api\V1\Provider\ProviderCompanyController;
use Illuminate\Support\Facades\Route;

/*
 * Service providers (docs/decisions/service-providers.md). Everything here
 * sits behind auth:sanctum + abilities:dashboard + role:provider +
 * provider.active, applied once by the group wrapping this file in
 * routes/api.php. Every route on a {company} calls authorizeCompany()
 * (ProviderPolicy, 404 when not this provider's) — guarded by
 * ProviderRoutesStayIsolatedTest. Never point a route here at an Admin\ or
 * Member\ controller.
 */
Route::get('companies', [ProviderCompanyController::class, 'index']);
Route::get('companies/{company}', [ProviderCompanyController::class, 'show']);
Route::delete('companies/{company}/members/{user}', [ProviderCompanyController::class, 'destroyMember']);
```

`routes/api.php` — after the `member` group:

```php
    // Service providers (docs/decisions/service-providers.md): dashboard
    // accounts, so the same 'abilities:dashboard' boundary as the admin
    // group, but their own role and their own file — a provider never
    // reaches an admin route, and provider.active cuts off every account of
    // a deactivated provider.
    Route::middleware(['auth:sanctum', 'abilities:dashboard', 'role:provider', 'provider.active'])
        ->prefix('provider')
        ->group(base_path('routes/api/v1/provider.php'));
```

- [ ] **Step 8: Add the `provider` lang group**

At the end of `lang/en/api.php` (before the closing `];`) — grep first that no `'provider' =>` top-level key exists:

```php
    // Service providers (docs/decisions/service-providers.md).
    'provider' => [
        'inactive' => 'This provider account is not active.',
        'member_removed' => 'The employee was removed from the company.',
    ],
```

`lang/ar/api.php`:

```php
    'provider' => [
        'inactive' => 'حساب المزوّد هذا غير فعّال.',
        'member_removed' => 'أُزيل الموظف من الشركة.',
    ],
```

Later tasks add keys **inside** this group.

- [ ] **Step 9: Run the tests**

Run: `PHP artisan test tests/Feature/Providers tests/Guards/RbacStaysFlatTest.php`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
PHP vendor/bin/pint app/Http/Middleware/EnsureActiveProvider.php app/Domain/Providers app/Providers/AppServiceProvider.php app/Http/Controllers/Api/V1/Provider app/Http/Resources/Provider routes bootstrap/app.php tests/Feature/Providers tests/Guards/RbacStaysFlatTest.php
git add app/Http/Middleware/EnsureActiveProvider.php app/Domain/Providers app/Providers/AppServiceProvider.php app/Http/Controllers/Api/V1/Provider app/Http/Resources/Provider routes bootstrap/app.php lang tests/Feature/Providers tests/Guards/RbacStaysFlatTest.php
git commit -m "feat(providers): provider route group, isolation by company, client companies

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Admin — providers and their accounts

**Files:**
- Create: `app/Http/Controllers/Api/V1/Admin/ProviderController.php` (index, store, show, update)
- Create: `app/Http/Controllers/Api/V1/Admin/ProviderUserController.php` (store, destroy)
- Create: `app/Http/Requests/Admin/StoreProviderRequest.php`, `UpdateProviderRequest.php`
- Create: `app/Http/Resources/ProviderResource.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `app/Services/Permissions/PermissionSyncService.php` (`MANUAL_REGISTRATIONS`)
- Modify: `lang/{en,ar}/api.php` (`admin` group)
- Test: `tests/Feature/Providers/AdminProviderTest.php`

**Interfaces:**
- Consumes: `ProviderTestCase`.
- Produces: routes `admin/providers` (index/store/show/update), `admin/providers/{provider}/users/{user}` (POST/DELETE); permissions `providers.view|create|update|status|transfer_company`, `provider-requests.view|approve|reject` (all registered here so later tasks only add routes); `ProviderResource`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\Provider;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class AdminProviderTest extends ProviderTestCase
{
    public function test_an_admin_creates_and_lists_providers(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/providers', ['name' => 'Platform', 'contract_ref' => 'P-1'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Platform')
            ->assertJsonPath('data.status', 'active');

        $this->getJson('/api/v1/admin/providers')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_admin_updates_a_provider(): void
    {
        $this->actingAsAdmin();
        $provider = Provider::factory()->create();

        $this->patchJson("/api/v1/admin/providers/{$provider->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('message', __('api.admin.provider_updated'));

        $this->assertSame('Renamed', $provider->fresh()->name);
    }

    public function test_an_admin_links_a_provider_account_and_unlinks_it(): void
    {
        $this->actingAsAdmin();
        $provider = Provider::factory()->create();
        $account = User::factory()->create();
        $account->assignRole('provider');

        $this->postJson("/api/v1/admin/providers/{$provider->id}/users/{$account->id}")->assertOk();
        $this->assertTrue(Provider::forUser($account)->is($provider));

        $this->deleteJson("/api/v1/admin/providers/{$provider->id}/users/{$account->id}")->assertOk();
        $this->assertNull(Provider::forUser($account));
    }

    public function test_only_a_provider_role_account_can_be_linked(): void
    {
        $this->actingAsAdmin();
        $provider = Provider::factory()->create();
        $operator = User::factory()->create();
        $operator->assignRole('operations');

        $this->postJson("/api/v1/admin/providers/{$provider->id}/users/{$operator->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.admin.provider_user_not_provider_role'));
    }

    public function test_an_account_already_working_for_a_provider_cannot_be_linked_to_another(): void
    {
        $this->actingAsAdmin();
        $account = User::factory()->create();
        $account->assignRole('provider');
        Provider::factory()->create()->users()->attach($account->id);

        $this->postJson('/api/v1/admin/providers/'.Provider::factory()->create()->id."/users/{$account->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.admin.provider_user_taken'));
    }

    public function test_provider_management_follows_permissions_not_role_names(): void
    {
        $role = Role::findOrCreate('partners-desk', 'web');
        $role->syncPermissions(['providers.view']);
        $user = User::factory()->create();
        $user->assignRole('partners-desk');
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/admin/providers')->assertOk();
        $this->postJson('/api/v1/admin/providers', ['name' => 'X', 'contract_ref' => 'P-2'])->assertForbidden();
    }

    public function test_operations_without_the_permission_is_refused(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->getJson('/api/v1/admin/providers')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `PHP artisan test tests/Feature/Providers/AdminProviderTest.php`
Expected: FAIL — 404 on `admin/providers`.

- [ ] **Step 3: Register the permissions**

`PermissionSyncService::MANUAL_REGISTRATIONS` — add (imports for the four controllers; `ProviderRequestController` and `CompanyProviderController` are created in Tasks 7 and 9 — create them now as empty classes extending `Controller` so the constants resolve, each with a one-line docblock naming the task that fills it):

```php
        // Service providers (docs/decisions/service-providers.md). Every
        // route is permission-gated, so the decision on a provider request
        // can be given to operations, or any custom role, from the roles
        // screen.
        ProviderController::class => [
            'providers' => ['view', 'create', 'update', 'status'],
        ],
        ProviderUserController::class => [
            'providers' => ['update'],
        ],
        CompanyProviderController::class => [
            'providers' => ['transfer_company'],
        ],
        ProviderRequestController::class => [
            'provider-requests' => ['view', 'approve', 'reject'],
        ],
```

- [ ] **Step 4: Implement requests, resource, controllers**

`app/Http/Requests/Admin/StoreProviderRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contract_ref' => ['required', 'string', 'max:255'],
        ];
    }
}
```

`UpdateProviderRequest.php`: same, with `'sometimes'` replacing `'required'`.

`app/Http/Resources/ProviderResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contract_ref' => $this->contract_ref,
            'status' => $this->status,
            'companies_count' => $this->whenCounted('companies'),
            'users' => $this->whenLoaded('users', fn () => $this->users->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
```

`app/Http/Controllers/Api/V1/Admin/ProviderController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Providers\Enums\ProviderStatus;
use App\Domain\Providers\Models\Provider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProviderRequest;
use App\Http\Requests\Admin\UpdateProviderRequest;
use App\Http\Resources\ProviderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Service providers (docs/decisions/service-providers.md). Does not extend
 * AdminResourceController: no `order` column, and a provider is
 * deactivated (updateStatus, Task 9), never deleted — its requests and
 * companies keep pointing at it.
 */
class ProviderController extends Controller
{
    use LogsSensitiveActions;

    public function index(): AnonymousResourceCollection
    {
        return ProviderResource::collection(Provider::query()->withCount('companies')->orderBy('name')->get());
    }

    public function store(StoreProviderRequest $request): ProviderResource
    {
        $provider = Provider::create([...$request->validated(), 'status' => ProviderStatus::Active]);

        $this->logSensitiveAction('provider_created', $provider);

        return new ProviderResource($provider->fresh());
    }

    public function show(Provider $provider): ProviderResource
    {
        return new ProviderResource($provider->load('users')->loadCount('companies'));
    }

    public function update(UpdateProviderRequest $request, Provider $provider): JsonResponse
    {
        $provider->update($request->validated());

        return response()->json(['message' => __('api.admin.provider_updated')]);
    }
}
```

`app/Http/Controllers/Api/V1/Admin/ProviderUserController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\Provider;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Links a dashboard account to the provider it works for. The account is
 * created first through UserController with the `provider` role; an account
 * works for one provider (`provider_user.user_id` is unique).
 */
class ProviderUserController extends Controller
{
    use LogsSensitiveActions;

    public function store(Provider $provider, User $user): JsonResponse
    {
        if (! $user->hasRole('provider')) {
            return response()->json(['message' => __('api.admin.provider_user_not_provider_role')], 422);
        }

        if ($user->providers()->exists()) {
            return response()->json(['message' => __('api.admin.provider_user_taken')], 422);
        }

        $provider->users()->attach($user->id);

        $this->logSensitiveAction('provider_user_linked', $provider, ['user_id' => $user->id]);

        return response()->json(['message' => __('api.admin.provider_user_linked')]);
    }

    public function destroy(Provider $provider, User $user): JsonResponse
    {
        abort_unless($provider->users()->where('users.id', $user->id)->exists(), 404);

        $provider->users()->detach($user->id);

        $this->logSensitiveAction('provider_user_unlinked', $provider, ['user_id' => $user->id]);

        return response()->json(['message' => __('api.admin.provider_user_unlinked')]);
    }
}
```

- [ ] **Step 5: Register the routes**

In `routes/api/v1/admin.php`, after the companies block:

```php
// Service providers (docs/decisions/service-providers.md). Permission-gated
// like Branches (docs/decisions/rbac-permission-pilot.md): the coarse
// role:admin|operations check is stripped and each route names its
// permission, so deciding provider requests can be given to any role.
Route::withoutMiddleware('role:admin|operations')->group(function () {
    Route::get('providers', [ProviderController::class, 'index'])->middleware('permission:providers.view');
    Route::post('providers', [ProviderController::class, 'store'])->middleware('permission:providers.create');
    Route::get('providers/{provider}', [ProviderController::class, 'show'])->middleware('permission:providers.view');
    Route::patch('providers/{provider}', [ProviderController::class, 'update'])->middleware('permission:providers.update');
    Route::post('providers/{provider}/users/{user}', [ProviderUserController::class, 'store'])->middleware('permission:providers.update');
    Route::delete('providers/{provider}/users/{user}', [ProviderUserController::class, 'destroy'])->middleware('permission:providers.update');
});
```

- [ ] **Step 6: Lang keys** (`admin` group, both files)

en:
```php
        'provider_updated' => 'Provider updated.',
        'provider_user_linked' => 'The account now works for this provider.',
        'provider_user_unlinked' => 'The account no longer works for this provider.',
        'provider_user_not_provider_role' => 'Only an account with the provider role can work for a provider.',
        'provider_user_taken' => 'This account already works for a provider.',
```
ar:
```php
        'provider_updated' => 'حُدّث المزوّد.',
        'provider_user_linked' => 'أصبح الحساب يعمل لهذا المزوّد.',
        'provider_user_unlinked' => 'لم يعد الحساب يعمل لهذا المزوّد.',
        'provider_user_not_provider_role' => 'لا يعمل لمزوّد إلا حساب يحمل دور المزوّد.',
        'provider_user_taken' => 'هذا الحساب يعمل لمزوّد بالفعل.',
```

- [ ] **Step 7: Run the tests**

Run: `PHP artisan test tests/Feature/Providers tests/Feature/Foundation/BranchPermissionEnforcementTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
PHP vendor/bin/pint app/Http/Controllers/Api/V1/Admin app/Http/Requests/Admin/StoreProviderRequest.php app/Http/Requests/Admin/UpdateProviderRequest.php app/Http/Resources/ProviderResource.php app/Services/Permissions/PermissionSyncService.php routes/api/v1/admin.php tests/Feature/Providers
git add app/Http/Controllers/Api/V1/Admin app/Http/Requests/Admin app/Http/Resources/ProviderResource.php app/Services/Permissions/PermissionSyncService.php routes/api/v1/admin.php lang tests/Feature/Providers
git commit -m "feat(providers): admins manage providers and link their accounts

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Office requests from a provider

**Files:**
- Create: `app/Http/Controllers/Api/V1/Provider/ProviderOfficeRequestController.php`
- Modify: `routes/api/v1/provider.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/PrivateOfficeRequestController.php` (index filter + eager load)
- Modify: `app/Http/Resources/PrivateOfficeRequestResource.php`, `app/Http/Resources/CompanyResource.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/CompanyController.php:51-57` (carry `provider_id`)
- Test: `tests/Feature/Providers/ProviderOfficeRequestTest.php`

**Interfaces:**
- Consumes: `ActsForProvider`, `StorePrivateOfficeRequestRequest` (existing).
- Produces: `GET|POST provider/office-requests`; `provider` and `provider_id` on `PrivateOfficeRequestResource`; `provider_id` on `CompanyResource`; a company created from a provider's office request belongs to that provider.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Providers;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Providers\Models\Provider;

class ProviderOfficeRequestTest extends ProviderTestCase
{
    public function test_a_provider_requests_offices_for_a_client(): void
    {
        $provider = Provider::factory()->create();
        $account = $this->providerAccount($provider);

        $this->postJson('/api/v1/provider/office-requests', ['prospect_name' => 'Acme', 'contact' => '0944444444'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.provider_id', $provider->id);

        $request = PrivateOfficeRequest::sole();
        $this->assertSame($account->id, $request->submitted_by);
    }

    public function test_a_provider_lists_only_its_own_office_requests(): void
    {
        $provider = Provider::factory()->create();
        $this->providerAccount($provider);
        PrivateOfficeRequest::factory()->create(['provider_id' => $provider->id]);
        PrivateOfficeRequest::factory()->create(['provider_id' => Provider::factory()->create()->id]);
        PrivateOfficeRequest::factory()->create();

        $this->getJson('/api/v1/provider/office-requests')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_admin_list_names_the_provider_and_filters_by_it(): void
    {
        $provider = Provider::factory()->create(['name' => 'Platform']);
        PrivateOfficeRequest::factory()->create(['provider_id' => $provider->id]);
        PrivateOfficeRequest::factory()->create();
        $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/private-office-requests?provider_id={$provider->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider.name', 'Platform');
    }

    public function test_the_company_contracted_from_a_providers_request_belongs_to_that_provider(): void
    {
        $provider = Provider::factory()->create();
        $request = PrivateOfficeRequest::factory()->create([
            'provider_id' => $provider->id,
            'status' => PrivateOfficeRequestStatus::Quoted,
        ]);
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/companies', [
            'private_office_request_id' => $request->id,
            'legal_name' => 'Acme LLC',
            'contract_ref' => 'C-9',
            'branch_id' => Branch::factory()->create()->id,
        ])->assertCreated()->assertJsonPath('data.provider_id', $provider->id);

        $this->assertSame($provider->id, Company::sole()->provider_id);
    }
}
```

(Check `StoreCompanyRequest`'s exact required fields and `PrivateOfficeRequestFactory`'s defaults; adjust the payload to what they require.)

- [ ] **Step 2: Run to verify they fail**

Run: `PHP artisan test tests/Feature/Providers/ProviderOfficeRequestTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`app/Http/Controllers/Api/V1/Provider/ProviderOfficeRequestController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePrivateOfficeRequestRequest;
use App\Http\Resources\PrivateOfficeRequestResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A new client company starts as a private-office request, on the existing
 * pipeline (requested -> quoted -> contracted): a quote and a contract are
 * real steps, so a provider's office request is not a provider request.
 * CompanyController::store carries `provider_id` onto the company.
 */
class ProviderOfficeRequestController extends Controller
{
    use ActsForProvider;

    public function index(Request $request): AnonymousResourceCollection
    {
        return PrivateOfficeRequestResource::collection(
            PrivateOfficeRequest::query()
                ->where('provider_id', $this->provider($request)->id)
                ->latest('id')
                ->get()
        );
    }

    public function store(StorePrivateOfficeRequestRequest $request): PrivateOfficeRequestResource
    {
        return new PrivateOfficeRequestResource(PrivateOfficeRequest::create([
            ...$request->validated(),
            'status' => PrivateOfficeRequestStatus::Requested,
            'provider_id' => $this->provider($request)->id,
            'submitted_by' => $request->user()->id,
        ]));
    }
}
```

`routes/api/v1/provider.php` — add (import the controller):

```php
Route::get('office-requests', [ProviderOfficeRequestController::class, 'index']);
Route::post('office-requests', [ProviderOfficeRequestController::class, 'store']);
```

`PrivateOfficeRequestResource::toArray` — add:

```php
            'provider_id' => $this->provider_id,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider === null ? null : [
                'id' => $this->provider->id,
                'name' => $this->provider->name,
            ]),
```

`Admin\PrivateOfficeRequestController` — add (import `Builder`, `Request`):

```php
    /** Each request names the provider that brought it, and the list filters by one. */
    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->with('provider')
            ->when($request->filled('provider_id'), fn (Builder $q) => $q->where('provider_id', $request->integer('provider_id')));
    }
```

(Check `AdminResourceController::applyIndexFilters`'s exact signature — `PlanController` overrides it — and match it.)

`CompanyController::store` — in `Company::create([...])` add `'provider_id' => $poRequest->provider_id,` and in `logSensitiveAction('company_created', ...)` add `'provider_id' => $company->provider_id`.

`CompanyResource` — add `'provider_id' => $this->provider_id,`.

- [ ] **Step 4: Run the tests**

Run: `PHP artisan test tests/Feature/Providers tests/Feature/Identity`
Expected: PASS, no new Identity failures.

- [ ] **Step 5: Commit**

```bash
PHP vendor/bin/pint app/Http/Controllers/Api/V1/Provider app/Http/Controllers/Api/V1/Admin/PrivateOfficeRequestController.php app/Http/Controllers/Api/V1/Admin/CompanyController.php app/Http/Resources routes/api/v1/provider.php tests/Feature/Providers
git add app/Http/Controllers/Api/V1/Provider app/Http/Controllers/Api/V1/Admin/PrivateOfficeRequestController.php app/Http/Controllers/Api/V1/Admin/CompanyController.php app/Http/Resources routes/api/v1/provider.php tests/Feature/Providers
git commit -m "feat(providers): providers request offices on the private-office pipeline

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Submitting provider requests, and the plans a provider may assign

**Files:**
- Create: `app/Support/Plans/PlanRules.php`
- Modify: `app/Http/Requests/Admin/StorePlanRequest.php` (rules come from `PlanRules`)
- Create: `app/Domain/Providers/Exceptions/ProviderRequestException.php`
- Create: `app/Domain/Providers/Services/ProviderRequestService.php` (`submit`, `cancel` here; `approve`/`reject` in Task 7)
- Create: `app/Http/Requests/Provider/StoreProviderRequestRequest.php`
- Create: `app/Http/Controllers/Api/V1/Provider/ProviderRequestController.php`
- Create: `app/Http/Controllers/Api/V1/Provider/ProviderPlanController.php`
- Create: `app/Http/Resources/ProviderRequestResource.php`
- Modify: `routes/api/v1/provider.php`, `lang/{en,ar}/api.php` (`provider` group)
- Test: `tests/Feature/Providers/SubmitProviderRequestTest.php`

**Interfaces:**
- Consumes: Task 1 models/enums, `ActsForProvider`, `MembershipResolver::activeForOwner(OwnerType, int): ?Membership`.
- Produces:
  - `PlanRules::rules(): array` — the plan validation array.
  - `ProviderRequestException(string $messageKey, int $status = 422, array $params = [])` with public readonly props.
  - `ProviderRequestService::submit(Provider $provider, User $submitter, ProviderRequestType $type, ?int $companyId, array $payload): ProviderRequest`
  - `ProviderRequestService::cancel(ProviderRequest $request): void` (throws `ProviderRequestException('api.provider.request_not_pending', 409)`)
  - `ProviderRequestService::duplicateExists(ProviderRequestType $type, ?int $companyId, array $payload): bool`
  - `ProviderRequestResource`.
  - Routes: `GET plans`, `GET|POST requests`, `GET requests/{providerRequest}`, `PATCH requests/{providerRequest}/cancel`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;

class SubmitProviderRequestTest extends ProviderTestCase
{
    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = Provider::factory()->create();
        $this->providerAccount($this->provider);
    }

    public function test_a_provider_asks_to_assign_a_public_plan_to_its_company(): void
    {
        $company = $this->clientCompany($this->provider);
        $plan = Plan::factory()->create();

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'plan_assignment',
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'seats' => 5,
            'payer' => 'provider',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'plan_assignment')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payload.seats', 5)
            ->assertJsonPath('data.payload.payer', 'provider');

        $this->assertSame(0, Membership::count());
    }

    public function test_another_providers_private_plan_cannot_be_assigned(): void
    {
        $company = $this->clientCompany($this->provider);
        $plan = Plan::factory()->create(['is_public' => false, 'provider_id' => Provider::factory()->create()->id]);

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'plan_assignment', 'company_id' => $company->id, 'plan_id' => $plan->id, 'seats' => 1, 'payer' => 'company',
        ])->assertStatus(422)->assertJsonValidationErrors('plan_id');
    }

    public function test_its_own_approved_private_plan_can_be_assigned(): void
    {
        $company = $this->clientCompany($this->provider);
        $plan = Plan::factory()->create(['is_public' => false, 'provider_id' => $this->provider->id]);

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'plan_assignment', 'company_id' => $company->id, 'plan_id' => $plan->id, 'seats' => 1, 'payer' => 'company',
        ])->assertCreated();
    }

    public function test_a_request_on_someone_elses_company_is_refused(): void
    {
        $theirs = $this->clientCompany(Provider::factory()->create());

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'wallet_top_up', 'company_id' => $theirs->id, 'points' => 100, 'payer' => 'company',
        ])->assertStatus(422)->assertJsonValidationErrors('company_id');
    }

    public function test_a_company_with_a_live_subscription_cannot_be_assigned_another(): void
    {
        $company = $this->clientCompany($this->provider);
        Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'plan_assignment', 'company_id' => $company->id, 'plan_id' => Plan::factory()->create()->id, 'seats' => 1, 'payer' => 'company',
        ])->assertStatus(422)->assertJsonValidationErrors('company_id');
    }

    public function test_the_same_employee_cannot_be_requested_twice(): void
    {
        $company = $this->clientCompany($this->provider);
        $body = ['type' => 'company_member', 'company_id' => $company->id, 'phone' => '0955555555', 'name' => 'Sara'];

        $this->postJson('/api/v1/provider/requests', $body)->assertCreated();
        $this->postJson('/api/v1/provider/requests', $body)->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_a_member_request_answers_the_same_whether_or_not_the_phone_has_an_account(): void
    {
        $company = $this->clientCompany($this->provider);
        User::factory()->create(['phone' => '0966666666']);

        $this->postJson('/api/v1/provider/requests', ['type' => 'company_member', 'company_id' => $company->id, 'phone' => '0966666666', 'name' => 'A'])
            ->assertCreated();
        $this->postJson('/api/v1/provider/requests', ['type' => 'company_member', 'company_id' => $company->id, 'phone' => '0977777777', 'name' => 'B'])
            ->assertCreated();
    }

    public function test_an_admin_can_only_be_requested_for_an_existing_member(): void
    {
        $company = $this->clientCompany($this->provider);
        $outsider = User::factory()->create();

        $this->postJson('/api/v1/provider/requests', ['type' => 'company_admin', 'company_id' => $company->id, 'user_id' => $outsider->id])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
    }

    public function test_a_plan_design_is_validated_like_an_admin_plan(): void
    {
        $this->postJson('/api/v1/provider/requests', ['type' => 'plan_design', 'name' => ['en' => 'X', 'ar' => 'س']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_subscription', 'price_points', 'duration_days']);

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'plan_design',
            'name' => ['en' => 'Platform Teams', 'ar' => 'فرق بلاتفورم'],
            'is_subscription' => true,
            'price_points' => 900,
            'duration_days' => 30,
        ])->assertCreated()->assertJsonPath('data.company', null);
    }

    public function test_a_provider_lists_shows_and_cancels_its_own_requests_only(): void
    {
        $mine = ProviderRequest::factory()->create(['provider_id' => $this->provider->id, 'company_id' => $this->clientCompany($this->provider)->id]);
        $theirs = ProviderRequest::factory()->create();

        $this->getJson('/api/v1/provider/requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/provider/requests/{$theirs->id}")->assertNotFound();
        $this->patchJson("/api/v1/provider/requests/{$theirs->id}/cancel")->assertNotFound();

        $this->patchJson("/api/v1/provider/requests/{$mine->id}/cancel")
            ->assertOk()->assertJsonPath('message', __('api.provider.request_cancelled'));
        $this->assertSame(ProviderRequestStatus::Cancelled, $mine->fresh()->status);

        $this->patchJson("/api/v1/provider/requests/{$mine->id}/cancel")->assertStatus(409);
    }

    public function test_the_plans_a_provider_may_assign_are_public_ones_and_its_own(): void
    {
        $public = Plan::factory()->create();
        $own = Plan::factory()->create(['is_public' => false, 'provider_id' => $this->provider->id]);
        Plan::factory()->create(['is_public' => false]);
        Plan::factory()->create(['is_public' => false, 'provider_id' => Provider::factory()->create()->id]);
        Plan::factory()->create(['is_active' => false]);

        $ids = collect($this->getJson('/api/v1/provider/plans')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$public->id, $own->id])->sort()->values()->all(), $ids);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `PHP artisan test tests/Feature/Providers/SubmitProviderRequestTest.php`
Expected: FAIL.

- [ ] **Step 3: Extract the plan rules**

Create `app/Support/Plans/PlanRules.php` holding the array `StorePlanRequest::rules()` builds today, verbatim:

```php
<?php

namespace App\Support\Plans;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Support\TranslatableField;
use Illuminate\Validation\Rule;

/**
 * What a plan is validated against when it is created — by an admin
 * (StorePlanRequest) or designed by a provider and created on approval
 * (StoreProviderRequestRequest). One list, so a provider's design can
 * never be something an admin could not have entered.
 */
final class PlanRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return array_merge(
            TranslatableField::rules('name'),
            TranslatableField::rules('subtitle', false),
            [
                // ... move the whole array from StorePlanRequest::rules() here, unchanged ...
            ],
        );
    }

    /**
     * The fields a provider does not set: ADD decides whether a plan is
     * active, public and where it sorts — a provider's plan is created
     * private (docs/decisions/service-providers.md).
     */
    public const PROVIDER_EXCLUDED = ['is_active', 'is_public', 'order'];
}
```

Move the array literally (the comment `// ... move ...` is an instruction to you, not code to keep). `StorePlanRequest::rules()` becomes `return PlanRules::rules();`; its `withValidator()` stays where it is. Run `PHP artisan test tests/Feature/Membership --filter=Plan` — no change in results.

- [ ] **Step 4: The exception and the service (submit, cancel)**

`app/Domain/Providers/Exceptions/ProviderRequestException.php`:

```php
<?php

namespace App\Domain\Providers\Exceptions;

use RuntimeException;

/** A provider request refused, with the message to show and its HTTP status. */
class ProviderRequestException extends RuntimeException
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

`app/Domain/Providers/Services/ProviderRequestService.php` (Task 7 adds `approve`, `reject`, `cancelPending`):

```php
<?php

namespace App\Domain\Providers\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Exceptions\ProviderRequestException;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Illuminate\Support\Facades\DB;

/**
 * The one path for provider requests (docs/decisions/service-providers.md):
 * a provider submits and may cancel while pending; a reviewer approves —
 * which hands the request to its type's handler under a row lock — or
 * rejects with a reason.
 */
class ProviderRequestService
{
    public function submit(Provider $provider, User $submitter, ProviderRequestType $type, ?int $companyId, array $payload): ProviderRequest
    {
        $request = ProviderRequest::create([
            'provider_id' => $provider->id,
            'submitted_by' => $submitter->id,
            'type' => $type,
            'company_id' => $companyId,
            'payload' => $payload,
            'status' => ProviderRequestStatus::Pending,
        ]);

        activity()->causedBy($submitter)->performedOn($request)
            ->withProperties(['type' => $type->value, 'company_id' => $companyId])
            ->log('provider_request_submitted');

        return $request;
    }

    public function cancel(ProviderRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $locked = ProviderRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $locked->isPending()) {
                throw new ProviderRequestException('api.provider.request_not_pending', 409);
            }

            $locked->forceFill(['status' => ProviderRequestStatus::Cancelled])->save();
        });
    }

    /**
     * A pending request asking for the same thing: the same employee, the
     * same admin, or any plan assignment for the same company. Top-ups and
     * plan designs may be asked for more than once.
     */
    public function duplicateExists(ProviderRequestType $type, ?int $companyId, array $payload): bool
    {
        $key = match ($type) {
            ProviderRequestType::CompanyMember => ['payload->phone', $payload['phone'] ?? null],
            ProviderRequestType::CompanyAdmin => ['payload->user_id', $payload['user_id'] ?? null],
            ProviderRequestType::PlanAssignment => null,
            default => false,
        };

        if ($key === false) {
            return false;
        }

        return ProviderRequest::query()
            ->pending()
            ->where('type', $type)
            ->where('company_id', $companyId)
            ->when($key !== null, fn ($query) => $query->where($key[0], $key[1]))
            ->exists();
    }
}
```

- [ ] **Step 5: The submission Form Request**

`app/Http/Requests/Provider/StoreProviderRequestRequest.php`:

```php
<?php

namespace App\Http\Requests\Provider;

use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\CompanyInvitation;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Http\Middleware\EnsureActiveProvider;
use App\Rules\SyrianPhoneNumber;
use App\Support\Plans\PlanRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Everything a provider request must satisfy, checked when it is
 * submitted so the reviewer never sees a malformed one; approval checks
 * again under a lock (ProviderRequestService::approve). A member request's
 * answer never depends on whether the phone already has an account.
 */
class StoreProviderRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function type(): ?ProviderRequestType
    {
        return ProviderRequestType::tryFrom((string) $this->input('type'));
    }

    public function provider(): Provider
    {
        return $this->attributes->get(EnsureActiveProvider::ATTRIBUTE);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $company = ['company_id' => ['required', 'integer']];
        $payer = ['payer' => ['required', Rule::enum(Payer::class)]];

        return ['type' => ['required', Rule::enum(ProviderRequestType::class)]] + match ($this->type()) {
            ProviderRequestType::CompanyMember => $company + [
                'phone' => ['required', new SyrianPhoneNumber],
                'name' => ['required', 'string', 'max:255'],
                'door_access_enabled' => ['sometimes', 'boolean'],
            ],
            ProviderRequestType::CompanyAdmin => $company + [
                'user_id' => ['required', 'integer'],
            ],
            ProviderRequestType::PlanAssignment => $company + $payer + [
                'plan_id' => ['required', 'integer'],
                'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
                'seats' => ['required', 'integer', 'min:1', 'max:1000'],
            ],
            ProviderRequestType::WalletTopUp => $company + $payer + [
                'points' => ['required', 'integer', 'min:1'],
            ],
            ProviderRequestType::PlanDesign => Arr::except(PlanRules::rules(), PlanRules::PROVIDER_EXCLUDED),
            null => [],
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->type();

            if ($type === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            $company = null;

            if ($type->needsCompany()) {
                $company = Company::query()->find($this->integer('company_id'));

                if ($company === null || $company->provider_id !== $this->provider()->id || $company->status->value !== 'active') {
                    $validator->errors()->add('company_id', __('api.provider.company_unavailable'));

                    return;
                }
            }

            match ($type) {
                ProviderRequestType::PlanAssignment => $this->checkAssignment($validator, $company),
                ProviderRequestType::CompanyMember => $this->checkMember($validator, $company),
                ProviderRequestType::CompanyAdmin => $this->checkAdmin($validator, $company),
                default => null,
            };

            // validated() is not available inside an after() hook, so the
            // duplicate check reads the same keys straight from the input.
            if ($validator->errors()->isEmpty() && app(ProviderRequestService::class)->duplicateExists($type, $company?->id, $this->only(['phone', 'user_id']))) {
                $validator->errors()->add($this->duplicateField($type), __('api.provider.duplicate_request'));
            }
        });
    }

    /** What the request carries beyond its type and company. */
    public function payload(): array
    {
        return Arr::except($this->validated(), ['type', 'company_id']);
    }

    private function checkAssignment(Validator $validator, Company $company): void
    {
        $plan = Plan::query()->find($this->integer('plan_id'));

        if ($plan === null || ! $plan->is_active || (! $plan->is_public && $plan->provider_id !== $this->provider()->id)) {
            $validator->errors()->add('plan_id', __('api.provider.plan_unavailable'));
        }

        if (app(MembershipResolver::class)->activeForOwner(OwnerType::Company, $company->id) !== null) {
            $validator->errors()->add('company_id', __('api.admin.membership_already_active'));
        }
    }

    /**
     * Already an employee, or already invited: the same refusal as a
     * duplicate pending request, and nothing about other accounts.
     */
    private function checkMember(Validator $validator, Company $company): void
    {
        $phone = (string) $this->input('phone');

        $taken = $company->members()->where('users.phone', $phone)->exists()
            || CompanyInvitation::query()->open()->where('company_id', $company->id)->where('phone', $phone)->exists();

        if ($taken) {
            $validator->errors()->add('phone', __('api.provider.duplicate_request'));
        }
    }

    private function checkAdmin(Validator $validator, Company $company): void
    {
        $member = $company->members()->where('users.id', $this->integer('user_id'))->first();

        if ($member === null) {
            $validator->errors()->add('user_id', __('api.provider.not_a_company_member'));
        } elseif ($member->pivot->is_admin) {
            $validator->errors()->add('user_id', __('api.provider.already_company_admin'));
        }
    }

    private function duplicateField(ProviderRequestType $type): string
    {
        return match ($type) {
            ProviderRequestType::CompanyMember => 'phone',
            ProviderRequestType::CompanyAdmin => 'user_id',
            default => 'company_id',
        };
    }
}
```

`payload()` is called by the controller only, after validation passed.

- [ ] **Step 6: The resource**

`app/Http/Resources/ProviderRequestResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A provider request. `result_id` is what approval created: a plan
 * (plan_design), a membership (plan_assignment), a wallet transaction
 * (wallet_top_up), a user (company_member when linked, company_admin) —
 * or null (pending, refused, or a member parked as an invitation).
 */
class ProviderRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'provider' => $this->whenLoaded('provider', fn () => ['id' => $this->provider->id, 'name' => $this->provider->name]),
            'company' => $this->company_id === null ? null : [
                'id' => $this->company_id,
                'legal_name' => $this->company?->legal_name,
            ],
            'payload' => $this->payload,
            'submitted_by' => $this->submitter === null ? null : ['id' => $this->submitter->id, 'name' => $this->submitter->name],
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'result_id' => $this->result_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 7: Controllers and routes**

`app/Http/Controllers/Api/V1/Provider/ProviderRequestController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Providers\Exceptions\ProviderRequestException;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\StoreProviderRequestRequest;
use App\Http\Resources\ProviderRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProviderRequestController extends Controller
{
    use ActsForProvider;

    public function __construct(private readonly ProviderRequestService $requests) {}

    /** Filters: `status`, `type`, `company_id`. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = ProviderRequest::query()
            ->where('provider_id', $this->provider($request)->id)
            ->with(['company', 'submitter'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        return ProviderRequestResource::collection($requests);
    }

    public function show(Request $request, ProviderRequest $providerRequest): ProviderRequestResource
    {
        $this->authorizeRequest($request, $providerRequest);

        return new ProviderRequestResource($providerRequest->load(['company', 'submitter']));
    }

    public function store(StoreProviderRequestRequest $request): JsonResponse
    {
        $created = $this->requests->submit(
            $this->provider($request),
            $request->user(),
            $request->type(),
            $request->type()->needsCompany() ? $request->integer('company_id') : null,
            $request->payload(),
        );

        return (new ProviderRequestResource($created->load(['company', 'submitter'])))->response()->setStatusCode(201);
    }

    public function cancel(Request $request, ProviderRequest $providerRequest): JsonResponse
    {
        $this->authorizeRequest($request, $providerRequest);

        try {
            $this->requests->cancel($providerRequest);
        } catch (ProviderRequestException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.provider.request_cancelled')]);
    }

    private function authorizeRequest(Request $request, ProviderRequest $providerRequest): void
    {
        abort_unless($providerRequest->provider_id === $this->provider($request)->id, 404);
    }
}
```

`app/Http/Controllers/Api/V1/Provider/ProviderPlanController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Membership\Models\Plan;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The plans this provider may assign: ADD's active public ones, and its own approved designs. */
class ProviderPlanController extends Controller
{
    use ActsForProvider;

    public function index(Request $request): AnonymousResourceCollection
    {
        $providerId = $this->provider($request)->id;

        $plans = Plan::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->where('is_public', true)->orWhere('provider_id', $providerId))
            ->with(['planModule', 'balances', 'features', 'discounts', 'resourceGrants.resourceType'])
            ->orderBy('order')
            ->get();

        return PlanResource::collection($plans);
    }
}
```

`routes/api/v1/provider.php` — add (imports):

```php
Route::get('plans', [ProviderPlanController::class, 'index']);

Route::get('requests', [ProviderRequestController::class, 'index']);
Route::post('requests', [ProviderRequestController::class, 'store']);
Route::get('requests/{providerRequest}', [ProviderRequestController::class, 'show']);
Route::patch('requests/{providerRequest}/cancel', [ProviderRequestController::class, 'cancel']);
```

- [ ] **Step 8: Lang keys** (`provider` group)

en:
```php
        'company_unavailable' => 'This company is not one of yours, or it is not active.',
        'plan_unavailable' => 'This plan cannot be assigned.',
        'duplicate_request' => 'This has already been requested or done.',
        'not_a_company_member' => 'This person is not an employee of the company.',
        'already_company_admin' => 'This employee is already the company admin.',
        'request_cancelled' => 'The request was cancelled.',
        'request_not_pending' => 'This request has already been decided.',
```
ar:
```php
        'company_unavailable' => 'هذه الشركة ليست من شركاتك، أو أنها غير فعّالة.',
        'plan_unavailable' => 'لا يمكن إسناد هذه الباقة.',
        'duplicate_request' => 'طُلب هذا أو تمّ من قبل.',
        'not_a_company_member' => 'هذا الشخص ليس موظفاً في الشركة.',
        'already_company_admin' => 'هذا الموظف مشرف الشركة بالفعل.',
        'request_cancelled' => 'أُلغي الطلب.',
        'request_not_pending' => 'بُتّ في هذا الطلب من قبل.',
```

- [ ] **Step 9: Run the tests**

Run: `PHP artisan test tests/Feature/Providers tests/Feature/Membership`
Expected: PASS; Membership unchanged against the baseline.

- [ ] **Step 10: Commit**

```bash
PHP vendor/bin/pint app/Support/Plans app/Http/Requests/Admin/StorePlanRequest.php app/Domain/Providers app/Http/Requests/Provider app/Http/Controllers/Api/V1/Provider app/Http/Resources/ProviderRequestResource.php routes/api/v1/provider.php tests/Feature/Providers
git add app/Support/Plans app/Http/Requests/Admin/StorePlanRequest.php app/Domain/Providers app/Http/Requests/Provider app/Http/Controllers/Api/V1/Provider app/Http/Resources/ProviderRequestResource.php routes/api/v1/provider.php lang tests/Feature/Providers
git commit -m "feat(providers): providers submit requests and see the plans they may assign

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Approval and rejection — plan design, plan assignment, wallet top-up

**Files:**
- Create: `app/Domain/Membership/Services/PlanCreationService.php`; Modify: `app/Http/Controllers/Api/V1/Admin/PlanController.php` (`store` delegates)
- Create: `app/Domain/Providers/Requests/ProviderRequestHandler.php`, `app/Domain/Providers/Requests/ProviderRequestHandlers.php`
- Create: `app/Domain/Providers/Requests/Handlers/PlanDesignHandler.php`, `PlanAssignmentHandler.php`, `WalletTopUpHandler.php`
- Modify: `app/Domain/Providers/Services/ProviderRequestService.php` (`approve`, `reject`, notifications)
- Fill: `app/Http/Controllers/Api/V1/Admin/ProviderRequestController.php` (index, show, approve, reject)
- Create: `app/Http/Requests/Admin/ApproveProviderRequestRequest.php`, `RejectProviderRequestRequest.php`
- Modify: `routes/api/v1/admin.php`, `lang/{en,ar}/api.php` (`admin`), `lang/{en,ar}/notifications.php` (new `provider` group)
- Test: `tests/Feature/Providers/ApproveProviderRequestTest.php`

**Interfaces:**
- Consumes: Task 6 service/exception, `MembershipActivationService::activate(Plan, OwnerType, int, bool $charge, ?string $auditAction, ?int $branchId, int $seats, bool $overrideCapacity, array $deskIds, array $auditProperties): Membership`, `WalletService::creditGeneral(Wallet, int, WalletTransactionSource, ?string): WalletTransaction`, `MembershipResolver::activeForOwner`.
- Produces:
  - `PlanCreationService::create(array $validated): Plan`
  - `interface ProviderRequestHandler { public function handle(ProviderRequest $request, User $reviewer, array $overrides): ?int; }`
  - `ProviderRequestHandlers::for(ProviderRequestType $type): ProviderRequestHandler` (map constant `HANDLERS`; Task 8 adds two entries)
  - `ProviderRequestService::approve(ProviderRequest, User $reviewer, array $overrides = []): ProviderRequest`, `reject(ProviderRequest, User $reviewer, string $reason): ProviderRequest`
  - admin routes `provider-requests` (GET), `provider-requests/{providerRequest}` (GET), `.../approve` (POST), `.../reject` (POST).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class ApproveProviderRequestTest extends ProviderTestCase
{
    private Provider $provider;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = Provider::factory()->create();
        $this->account = $this->providerAccount($this->provider);
    }

    private function request(ProviderRequestType $type, array $payload, ?int $companyId = null): ProviderRequest
    {
        return ProviderRequest::factory()->create([
            'provider_id' => $this->provider->id,
            'submitted_by' => $this->account->id,
            'type' => $type,
            'company_id' => $companyId,
            'payload' => $payload,
        ]);
    }

    public function test_approving_an_assignment_activates_the_plan_for_the_company_without_charging_it(): void
    {
        $branch = $this->branchWithSeats(10);
        $company = $this->clientCompany($this->provider, ['branch_id' => $branch->id]);
        $plan = Plan::factory()->create(['price_points' => 900]);
        $request = $this->request(ProviderRequestType::PlanAssignment, ['plan_id' => $plan->id, 'seats' => 3, 'payer' => 'provider'], $company->id);
        $admin = $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('message', __('api.admin.provider_request_approved'));

        $membership = Membership::query()->where('owner_type', OwnerType::Company)->where('owner_id', $company->id)->sole();
        $this->assertSame(3, $membership->seats);
        $request->refresh();
        $this->assertSame(ProviderRequestStatus::Approved, $request->status);
        $this->assertSame($membership->id, $request->result_id);
        $this->assertSame($admin->id, $request->reviewed_by);
        $this->assertTrue(NotificationLog::query()->where('user_id', $this->account->id)->where('template_key', 'provider.request_approved')->exists());
    }

    public function test_an_assignment_whose_company_got_a_subscription_meanwhile_stays_pending(): void
    {
        $company = $this->clientCompany($this->provider);
        $request = $this->request(ProviderRequestType::PlanAssignment, ['plan_id' => Plan::factory()->create()->id, 'seats' => 1, 'payer' => 'company'], $company->id);
        Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.admin.membership_already_active'));

        $this->assertTrue($request->fresh()->isPending());
        $this->assertSame(1, Membership::count());
    }

    public function test_an_assignment_into_a_full_pool_is_refused_unless_the_reviewer_overrides(): void
    {
        $branch = $this->branchWithSeats(1);
        $company = $this->clientCompany($this->provider, ['branch_id' => $branch->id]);
        $request = $this->request(ProviderRequestType::PlanAssignment, ['plan_id' => Plan::factory()->create()->id, 'seats' => 5, 'payer' => 'company'], $company->id);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")
            ->assertStatus(422)->assertJsonPath('message', __('api.membership.seat_pool_full'));
        $this->assertTrue($request->fresh()->isPending());

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve", ['override_capacity' => true])->assertOk();
    }

    public function test_approving_twice_creates_one_subscription(): void
    {
        $branch = $this->branchWithSeats(10);
        $company = $this->clientCompany($this->provider, ['branch_id' => $branch->id]);
        $request = $this->request(ProviderRequestType::PlanAssignment, ['plan_id' => Plan::factory()->create()->id, 'seats' => 1, 'payer' => 'company'], $company->id);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();
        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertStatus(409);

        $this->assertSame(1, Membership::count());
    }

    public function test_a_request_on_a_company_deactivated_meanwhile_is_refused(): void
    {
        $company = $this->clientCompany($this->provider);
        $request = $this->request(ProviderRequestType::WalletTopUp, ['points' => 100, 'payer' => 'company'], $company->id);
        $company->update(['status' => CompanyStatus::Inactive]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")
            ->assertStatus(422)->assertJsonPath('message', __('api.provider.company_unavailable'));
    }

    public function test_approving_a_design_creates_a_private_plan_of_the_provider(): void
    {
        $request = $this->request(ProviderRequestType::PlanDesign, [
            'name' => ['en' => 'Platform Teams', 'ar' => 'فرق بلاتفورم'],
            'is_subscription' => true,
            'price_points' => 900,
            'duration_days' => 30,
            'features' => [['name' => ['en' => 'Desk', 'ar' => 'مكتب']]],
        ]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $plan = Plan::query()->findOrFail($request->fresh()->result_id);
        $this->assertFalse($plan->is_public);
        $this->assertSame($this->provider->id, $plan->provider_id);
        $this->assertCount(1, $plan->features);
    }

    public function test_approving_a_top_up_credits_the_company_wallet(): void
    {
        $company = $this->clientCompany($this->provider);
        $request = $this->request(ProviderRequestType::WalletTopUp, ['points' => 500, 'payer' => 'provider'], $company->id);
        $admin = $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $wallet = Wallet::query()->where('owner_type', OwnerType::Company)->where('owner_id', $company->id)->sole();
        $this->assertSame(500, app(WalletService::class)->generalBalance($wallet));
        $this->assertSame($admin->id, $wallet->transactions()->sole()->performed_by_user_id);
    }

    public function test_a_rejection_needs_a_reason_and_tells_the_provider(): void
    {
        $request = $this->request(ProviderRequestType::WalletTopUp, ['points' => 100, 'payer' => 'company'], $this->clientCompany($this->provider)->id);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/reject")->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/reject", ['reason' => 'No contract yet'])
            ->assertOk()->assertJsonPath('message', __('api.admin.provider_request_rejected'));

        $request->refresh();
        $this->assertSame(ProviderRequestStatus::Rejected, $request->status);
        $this->assertSame('No contract yet', $request->rejection_reason);
        $this->assertTrue(NotificationLog::query()->where('user_id', $this->account->id)->where('template_key', 'provider.request_rejected')->exists());
    }

    public function test_the_admin_queue_lists_pending_requests_by_default(): void
    {
        $company = $this->clientCompany($this->provider);
        $this->request(ProviderRequestType::WalletTopUp, ['points' => 1, 'payer' => 'company'], $company->id);
        $this->request(ProviderRequestType::WalletTopUp, ['points' => 2, 'payer' => 'company'], $company->id)
            ->forceFill(['status' => ProviderRequestStatus::Rejected])->save();
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/provider-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/provider-requests?status=rejected')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_deciding_follows_the_permission_so_operations_can_be_given_it(): void
    {
        $request = $this->request(ProviderRequestType::WalletTopUp, ['points' => 100, 'payer' => 'company'], $this->clientCompany($this->provider)->id);
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertForbidden();

        Role::findByName('operations', 'web')->givePermissionTo('provider-requests.approve');

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();
    }
}
```

(`WalletService::generalBalance()` returns `int`; `NotificationLog` is `App\Domain\Identity\Models\NotificationLog`.)

- [ ] **Step 2: Run to verify they fail**

Run: `PHP artisan test tests/Feature/Providers/ApproveProviderRequestTest.php`
Expected: FAIL.

- [ ] **Step 3: Extract plan creation**

`app/Domain/Membership/Services/PlanCreationService.php`:

```php
<?php

namespace App\Domain\Membership\Services;

use App\Domain\Membership\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Creates a plan with its balances, features, discounts and resource
 * grants in one transaction — for an admin (PlanController::store) and for
 * an approved provider design (PlanDesignHandler).
 */
class PlanCreationService
{
    /** @param  array<string, mixed>  $validated  PlanRules-shaped */
    public function create(array $validated): Plan
    {
        $balances = $validated['balances'] ?? [];
        $features = $validated['features'] ?? [];
        $discounts = $validated['discounts'] ?? [];
        $grants = $validated['resource_grants'] ?? [];
        unset($validated['balances'], $validated['features'], $validated['discounts'], $validated['resource_grants']);

        return DB::transaction(function () use ($validated, $balances, $features, $discounts, $grants) {
            $plan = Plan::create(array_merge(['is_active' => true, 'order' => 0], $validated));

            if ($balances !== []) {
                $plan->balances()->createMany($balances);
            }

            if ($features !== []) {
                $plan->features()->createMany($features);
            }

            if ($discounts !== []) {
                $plan->discounts()->createMany($discounts);
            }

            if ($grants !== []) {
                $plan->resourceGrants()->createMany($grants);
            }

            return $plan;
        });
    }
}
```

`PlanController::store` becomes:

```php
    public function store(StorePlanRequest $request, PlanCreationService $plans): PlanResource
    {
        $plan = $plans->create($request->validated());

        return new PlanResource($plan->load(['planModule', 'balances', 'features', 'discounts', 'resourceGrants.resourceType']));
    }
```

Run `PHP artisan test --filter=Plan` — unchanged.

- [ ] **Step 4: Handlers**

`app/Domain/Providers/Requests/ProviderRequestHandler.php`:

```php
<?php

namespace App\Domain\Providers\Requests;

use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\ProviderRequest;

/**
 * Carries out one type of provider request once a reviewer approves it.
 * Runs inside ProviderRequestService::approve's transaction, with the
 * request row locked; re-checks what may have changed since submission and
 * throws ProviderRequestException to refuse. Returns the id of what it
 * created (see ProviderRequestResource), or null.
 */
interface ProviderRequestHandler
{
    /** @param  array<string, mixed>  $overrides  reviewer-only switches, e.g. override_capacity */
    public function handle(ProviderRequest $request, User $reviewer, array $overrides): ?int;
}
```

`app/Domain/Providers/Requests/ProviderRequestHandlers.php`:

```php
<?php

namespace App\Domain\Providers\Requests;

use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Requests\Handlers\PlanAssignmentHandler;
use App\Domain\Providers\Requests\Handlers\PlanDesignHandler;
use App\Domain\Providers\Requests\Handlers\WalletTopUpHandler;

/** One handler per request type — a new type is a class and a line here. */
class ProviderRequestHandlers
{
    /** @var array<string, class-string<ProviderRequestHandler>> */
    private const HANDLERS = [
        'plan_design' => PlanDesignHandler::class,
        'plan_assignment' => PlanAssignmentHandler::class,
        'wallet_top_up' => WalletTopUpHandler::class,
    ];

    public function for(ProviderRequestType $type): ProviderRequestHandler
    {
        return app(self::HANDLERS[$type->value]);
    }
}
```

`Handlers/PlanDesignHandler.php`:

```php
<?php

namespace App\Domain\Providers\Requests\Handlers;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Services\PlanCreationService;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Requests\ProviderRequestHandler;

/** The design becomes a private plan only its provider can assign. */
class PlanDesignHandler implements ProviderRequestHandler
{
    public function __construct(private readonly PlanCreationService $plans) {}

    public function handle(ProviderRequest $request, User $reviewer, array $overrides): ?int
    {
        return $this->plans->create([
            ...$request->payload,
            'is_public' => false,
            'provider_id' => $request->provider_id,
        ])->id;
    }
}
```

`Handlers/PlanAssignmentHandler.php`:

```php
<?php

namespace App\Domain\Providers\Requests\Handlers;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Exceptions\SeatPoolExhaustedException;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\MembershipActivationService;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Providers\Exceptions\ProviderRequestException;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Requests\ProviderRequestHandler;

/**
 * Activates the plan for the client company with no charge: in the
 * provider flow money is invoiced, never taken from a wallet
 * (docs/decisions/service-providers.md; invoices are Phase 2). Only the
 * reviewer may sell past a full pool.
 */
class PlanAssignmentHandler implements ProviderRequestHandler
{
    public function __construct(
        private readonly MembershipActivationService $activations,
        private readonly MembershipResolver $memberships,
    ) {}

    public function handle(ProviderRequest $request, User $reviewer, array $overrides): ?int
    {
        $payload = $request->payload;
        $plan = Plan::query()->find($payload['plan_id']);

        if ($plan === null || ! $plan->is_active || (! $plan->is_public && $plan->provider_id !== $request->provider_id)) {
            throw new ProviderRequestException('api.provider.plan_unavailable');
        }

        if ($this->memberships->activeForOwner(OwnerType::Company, $request->company_id) !== null) {
            throw new ProviderRequestException('api.admin.membership_already_active');
        }

        try {
            $membership = $this->activations->activate(
                $plan,
                OwnerType::Company,
                $request->company_id,
                charge: false,
                auditAction: 'membership_activated_by_provider_request',
                branchId: $payload['branch_id'] ?? null,
                seats: (int) $payload['seats'],
                overrideCapacity: (bool) ($overrides['override_capacity'] ?? false),
                auditProperties: ['provider_request_id' => $request->id, 'payer' => $payload['payer']],
            );
        } catch (SeatPoolExhaustedException) {
            throw new ProviderRequestException('api.membership.seat_pool_full');
        }

        return $membership->id;
    }
}
```

`Handlers/WalletTopUpHandler.php`:

```php
<?php

namespace App\Domain\Providers\Requests\Handlers;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Providers\Exceptions\ProviderRequestException;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Requests\ProviderRequestHandler;

/**
 * Credits the client company's wallet — the pool its admin allocates to
 * employees from. Not a way of paying ADD: what it costs is invoiced
 * (Phase 2).
 */
class WalletTopUpHandler implements ProviderRequestHandler
{
    public function __construct(private readonly WalletService $wallets) {}

    public function handle(ProviderRequest $request, User $reviewer, array $overrides): ?int
    {
        $wallet = Wallet::query()
            ->where('owner_type', OwnerType::Company)
            ->where('owner_id', $request->company_id)
            ->first() ?? throw new ProviderRequestException('api.provider.company_unavailable');

        $transaction = $this->wallets->creditGeneral(
            $wallet,
            (int) $request->payload['points'],
            WalletTransactionSource::TopUp,
            "Provider request #{$request->id}",
        );

        $transaction->forceFill(['performed_by_user_id' => $reviewer->id])->save();

        return $transaction->id;
    }
}
```

- [ ] **Step 5: `approve` and `reject` in the service**

Add to `ProviderRequestService` (constructor `public function __construct(private readonly ProviderRequestHandlers $handlers) {}`, imports `Carbon`, `NotificationLog`, `ProviderRequestHandlers`):

```php
    /**
     * Carries the request out under a row lock. Everything that may have
     * changed since submission is checked again: the provider and the
     * company still active, the company still this provider's, and the
     * handler's own preconditions. A refusal leaves the request pending.
     */
    public function approve(ProviderRequest $request, User $reviewer, array $overrides = []): ProviderRequest
    {
        $approved = DB::transaction(function () use ($request, $reviewer, $overrides) {
            $locked = $this->lockPending($request);
            $this->assertStillValid($locked);

            $resultId = $this->handlers->for($locked->type)->handle($locked, $reviewer, $overrides);

            $locked->forceFill([
                'status' => ProviderRequestStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'result_id' => $resultId,
            ])->save();

            activity()->causedBy($reviewer)->performedOn($locked)
                ->withProperties(['type' => $locked->type->value, 'result_id' => $resultId, 'overrides' => $overrides])
                ->log('provider_request_approved');

            return $locked;
        });

        $this->notify($approved, 'provider.request_approved');

        return $approved;
    }

    public function reject(ProviderRequest $request, User $reviewer, string $reason): ProviderRequest
    {
        $rejected = DB::transaction(function () use ($request, $reviewer, $reason) {
            $locked = $this->lockPending($request);

            $locked->forceFill([
                'status' => ProviderRequestStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            activity()->causedBy($reviewer)->performedOn($locked)
                ->withProperties(['type' => $locked->type->value, 'reason' => $reason])
                ->log('provider_request_rejected');

            return $locked;
        });

        $this->notify($rejected, 'provider.request_rejected');

        return $rejected;
    }

    private function lockPending(ProviderRequest $request): ProviderRequest
    {
        $locked = ProviderRequest::query()->lockForUpdate()->findOrFail($request->id);

        if (! $locked->isPending()) {
            throw new ProviderRequestException('api.provider.request_not_pending', 409);
        }

        return $locked;
    }

    private function assertStillValid(ProviderRequest $request): void
    {
        if (! $request->provider->isActive()) {
            throw new ProviderRequestException('api.provider.inactive');
        }

        if ($request->company_id !== null) {
            $company = $request->company;

            if ($company === null || $company->provider_id !== $request->provider_id || $company->status->value !== 'active') {
                throw new ProviderRequestException('api.provider.company_unavailable');
            }
        }
    }

    /** The provider's accounts and whoever submitted (a company admin, for company_admin). */
    private function notify(ProviderRequest $request, string $templateKey): void
    {
        $recipients = $request->provider->users()->pluck('users.id')
            ->push($request->submitted_by)
            ->filter()
            ->unique();

        foreach ($recipients as $userId) {
            NotificationLog::create([
                'user_id' => $userId,
                'channel' => 'push',
                'template_key' => $templateKey,
                'status' => 'sent',
            ]);
        }
    }
```

Rewrite `cancel()` to use `lockPending()` too.

- [ ] **Step 6: Admin controller, requests, routes**

`app/Http/Requests/Admin/ApproveProviderRequestRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ApproveProviderRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Sell past a full pool, by arrangement — recorded in the audit log.
            'override_capacity' => ['sometimes', 'boolean'],
        ];
    }
}
```

`RejectProviderRequestRequest.php`: `'reason' => ['required', 'string', 'max:1000']`.

`app/Http/Controllers/Api/V1/Admin/ProviderRequestController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Exceptions\ProviderRequestException;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveProviderRequestRequest;
use App\Http\Requests\Admin\RejectProviderRequestRequest;
use App\Http\Resources\ProviderRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The approval queue for every provider (docs/decisions/service-providers.md).
 * Office requests are not here: they stay on private-office-requests.
 */
class ProviderRequestController extends Controller
{
    public function __construct(private readonly ProviderRequestService $requests) {}

    /** Pending by default. Filters: `status`, `provider_id`, `type`, `company_id`. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status')->toString() ?: ProviderRequestStatus::Pending->value;

        return ProviderRequestResource::collection(
            ProviderRequest::query()
                ->with(['provider', 'company', 'submitter'])
                ->where('status', $status)
                ->when($request->filled('provider_id'), fn ($q) => $q->where('provider_id', $request->integer('provider_id')))
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
                ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
                ->oldest('id')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function show(ProviderRequest $providerRequest): ProviderRequestResource
    {
        return new ProviderRequestResource($providerRequest->load(['provider', 'company', 'submitter']));
    }

    public function approve(ApproveProviderRequestRequest $request, ProviderRequest $providerRequest): JsonResponse
    {
        try {
            $this->requests->approve($providerRequest, $request->user(), $request->validated());
        } catch (ProviderRequestException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.admin.provider_request_approved')]);
    }

    public function reject(RejectProviderRequestRequest $request, ProviderRequest $providerRequest): JsonResponse
    {
        try {
            $this->requests->reject($providerRequest, $request->user(), $request->validated('reason'));
        } catch (ProviderRequestException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.admin.provider_request_rejected')]);
    }
}
```

`routes/api/v1/admin.php` — inside the providers `withoutMiddleware` group from Task 4:

```php
    Route::get('provider-requests', [ProviderRequestController::class, 'index'])->middleware('permission:provider-requests.view');
    Route::get('provider-requests/{providerRequest}', [ProviderRequestController::class, 'show'])->middleware('permission:provider-requests.view');
    Route::post('provider-requests/{providerRequest}/approve', [ProviderRequestController::class, 'approve'])->middleware('permission:provider-requests.approve');
    Route::post('provider-requests/{providerRequest}/reject', [ProviderRequestController::class, 'reject'])->middleware('permission:provider-requests.reject');
```

- [ ] **Step 7: Lang**

`lang/{en,ar}/api.php`, `admin` group — en: `'provider_request_approved' => 'Request approved.'`, `'provider_request_rejected' => 'Request rejected.'`; ar: `'provider_request_approved' => 'وُوفق على الطلب.'`, `'provider_request_rejected' => 'رُفض الطلب.'`.

`lang/en/notifications.php` — new group at the end (grep first for an existing `'provider' =>`):

```php
    'provider' => [
        'request_approved' => [
            'title' => 'Request approved',
            'body' => 'Your request has been approved.',
        ],
        'request_rejected' => [
            'title' => 'Request rejected',
            'body' => 'Your request has been rejected.',
        ],
    ],
```

`lang/ar/notifications.php`:

```php
    'provider' => [
        'request_approved' => [
            'title' => 'قُبل الطلب',
            'body' => 'وُوفق على طلبك.',
        ],
        'request_rejected' => [
            'title' => 'رُفض الطلب',
            'body' => 'رُفض طلبك.',
        ],
    ],
```

- [ ] **Step 8: Run the tests**

Run: `PHP artisan test tests/Feature/Providers tests/Feature/Membership`
Expected: PASS; Membership unchanged against the baseline.

- [ ] **Step 9: Commit**

```bash
PHP vendor/bin/pint app/Domain/Membership/Services/PlanCreationService.php app/Http/Controllers/Api/V1/Admin/PlanController.php app/Domain/Providers app/Http/Controllers/Api/V1/Admin/ProviderRequestController.php app/Http/Requests/Admin routes/api/v1/admin.php tests/Feature/Providers
git add app/Domain/Membership/Services/PlanCreationService.php app/Http/Controllers/Api/V1/Admin/PlanController.php app/Domain/Providers app/Http/Controllers/Api/V1/Admin/ProviderRequestController.php app/Http/Requests/Admin routes/api/v1/admin.php lang tests/Feature/Providers
git commit -m "feat(providers): reviewers approve plan designs, assignments and top-ups

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Employees and company admins — handlers, invitations, the member-app path

**Files:**
- Create: `app/Domain/Providers/Requests/Handlers/CompanyMemberHandler.php`, `CompanyAdminHandler.php`
- Modify: `app/Domain/Providers/Requests/ProviderRequestHandlers.php` (two entries)
- Create: `app/Domain/Providers/Services/CompanyInvitationAcceptor.php`
- Modify: `app/Http/Controllers/Api/V1/Auth/MemberAuthController.php` (`verifyRegistration` transaction)
- Modify: `app/Http/Controllers/Api/V1/Member/CompanyMemberController.php` (`updateAdmin`)
- Modify: `lang/{en,ar}/api.php` (`member` group)
- Test: `tests/Feature/Providers/ProviderEmployeesTest.php`

**Interfaces:**
- Consumes: Task 7 service/handlers map, `ProviderRequestService::submit`, `duplicateExists`.
- Produces: `CompanyInvitationAcceptor::acceptFor(User $user): void`; handlers for `company_member` and `company_admin`; `PATCH member/companies/{company}/members/{user}/admin` answers 202 + `api.member.company_admin_requested` for a provider's company when granting.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\CompanyInvitation;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\CompanyInvitationAcceptor;
use Laravel\Sanctum\Sanctum;

class ProviderEmployeesTest extends ProviderTestCase
{
    private Provider $provider;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = Provider::factory()->create();
        $this->company = $this->clientCompany($this->provider);
    }

    private function memberRequest(string $phone, bool $door = true): ProviderRequest
    {
        return ProviderRequest::factory()->create([
            'provider_id' => $this->provider->id,
            'company_id' => $this->company->id,
            'type' => ProviderRequestType::CompanyMember,
            'payload' => ['phone' => $phone, 'name' => 'Sara', 'door_access_enabled' => $door],
        ]);
    }

    public function test_an_approved_employee_with_an_account_joins_the_company(): void
    {
        $member = User::factory()->create(['phone' => '0911111111']);
        $member->assignRole('member');
        $request = $this->memberRequest('0911111111');
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $pivot = $this->company->members()->where('users.id', $member->id)->sole()->pivot;
        $this->assertTrue((bool) $pivot->door_access_enabled);
        $this->assertFalse((bool) $pivot->is_admin);
        $this->assertSame($member->id, $request->fresh()->result_id);
    }

    public function test_an_approved_employee_without_an_account_joins_when_they_sign_up(): void
    {
        $request = $this->memberRequest('0922222222');
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();
        $this->assertNull($request->fresh()->result_id);
        $this->assertSame(1, CompanyInvitation::query()->open()->count());

        $member = User::factory()->create(['phone' => '0922222222']);
        app(CompanyInvitationAcceptor::class)->acceptFor($member);

        $this->assertTrue($this->company->members()->where('users.id', $member->id)->exists());
        $this->assertSame(0, CompanyInvitation::query()->open()->count());
    }

    public function test_a_phone_held_by_a_staff_account_is_never_linked(): void
    {
        $operator = User::factory()->create(['phone' => '0933333333']);
        $operator->assignRole('operations');
        $request = $this->memberRequest('0933333333');
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $this->assertFalse($this->company->members()->where('users.id', $operator->id)->exists());
    }

    public function test_approving_an_admin_request_makes_the_employee_admin(): void
    {
        $member = User::factory()->create();
        $this->company->members()->attach($member->id);
        $request = ProviderRequest::factory()->create([
            'provider_id' => $this->provider->id,
            'company_id' => $this->company->id,
            'type' => ProviderRequestType::CompanyAdmin,
            'payload' => ['user_id' => $member->id],
        ]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $this->assertTrue((bool) $this->company->members()->where('users.id', $member->id)->sole()->pivot->is_admin);
    }

    public function test_a_company_admin_of_a_provider_company_asks_instead_of_granting(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('member');
        $colleague = User::factory()->create();
        $this->company->members()->attach($admin->id, ['is_admin' => true]);
        $this->company->members()->attach($colleague->id);
        Sanctum::actingAs($admin, ['member-app']);

        $this->patchJson("/api/v1/member/companies/{$this->company->id}/members/{$colleague->id}/admin", ['is_admin' => true])
            ->assertStatus(202)
            ->assertJsonPath('message', __('api.member.company_admin_requested'));

        $this->assertFalse((bool) $this->company->members()->where('users.id', $colleague->id)->sole()->pivot->is_admin);
        $request = ProviderRequest::sole();
        $this->assertSame(ProviderRequestType::CompanyAdmin, $request->type);
        $this->assertSame($admin->id, $request->submitted_by);
    }

    public function test_a_company_admin_of_a_provider_company_still_revokes_directly(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('member');
        $other = User::factory()->create();
        $this->company->members()->attach($admin->id, ['is_admin' => true]);
        $this->company->members()->attach($other->id, ['is_admin' => true]);
        Sanctum::actingAs($admin, ['member-app']);

        $this->patchJson("/api/v1/member/companies/{$this->company->id}/members/{$other->id}/admin", ['is_admin' => false])
            ->assertOk();

        $this->assertFalse((bool) $this->company->members()->where('users.id', $other->id)->sole()->pivot->is_admin);
    }

    public function test_a_direct_company_admin_still_grants_directly(): void
    {
        $direct = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('member');
        $colleague = User::factory()->create();
        $direct->members()->attach($admin->id, ['is_admin' => true]);
        $direct->members()->attach($colleague->id);
        Sanctum::actingAs($admin, ['member-app']);

        $this->patchJson("/api/v1/member/companies/{$direct->id}/members/{$colleague->id}/admin", ['is_admin' => true])
            ->assertOk();

        $this->assertTrue((bool) $direct->members()->where('users.id', $colleague->id)->sole()->pivot->is_admin);
        $this->assertSame(0, ProviderRequest::count());
    }
}
```

Also add to the registration test file that already covers `register/verify` (find it with `grep -rln "register/verify" tests`) one test: a pending registration for a phone with an open invitation → after verify the new user is a member of the invited company. Copy that file's existing happy-path setup for the OTP and pending registration.

- [ ] **Step 2: Run to verify they fail**

Run: `PHP artisan test tests/Feature/Providers/ProviderEmployeesTest.php`
Expected: FAIL.

- [ ] **Step 3: Handlers**

`Handlers/CompanyMemberHandler.php`:

```php
<?php

namespace App\Domain\Providers\Requests\Handlers;

use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\CompanyInvitation;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Requests\ProviderRequestHandler;

/**
 * A member account with this phone joins the company now; otherwise the
 * phone is parked as an invitation, linked when it signs up
 * (CompanyInvitationAcceptor). A phone held by a non-member account (staff,
 * a provider) is never linked: the invitation stays open.
 */
class CompanyMemberHandler implements ProviderRequestHandler
{
    public function handle(ProviderRequest $request, User $reviewer, array $overrides): ?int
    {
        $company = $request->company;
        $phone = $request->payload['phone'];
        $door = (bool) ($request->payload['door_access_enabled'] ?? false);
        $user = User::query()->where('phone', $phone)->first();

        if ($user !== null && $user->hasRole('member')) {
            if (! $company->members()->where('users.id', $user->id)->exists()) {
                $company->members()->attach($user->id, ['door_access_enabled' => $door, 'is_admin' => false]);
            }

            return $user->id;
        }

        CompanyInvitation::query()->firstOrCreate(
            ['company_id' => $company->id, 'phone' => $phone],
            ['door_access_enabled' => $door, 'provider_request_id' => $request->id],
        );

        return null;
    }
}
```

`Handlers/CompanyAdminHandler.php`:

```php
<?php

namespace App\Domain\Providers\Requests\Handlers;

use App\Domain\Identity\Models\User;
use App\Domain\Providers\Exceptions\ProviderRequestException;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Requests\ProviderRequestHandler;

class CompanyAdminHandler implements ProviderRequestHandler
{
    public function handle(ProviderRequest $request, User $reviewer, array $overrides): ?int
    {
        $userId = (int) $request->payload['user_id'];
        $company = $request->company;

        if (! $company->members()->where('users.id', $userId)->exists()) {
            throw new ProviderRequestException('api.provider.not_a_company_member');
        }

        $company->members()->updateExistingPivot($userId, ['is_admin' => true]);

        return $userId;
    }
}
```

Add both to `ProviderRequestHandlers::HANDLERS`:

```php
        'company_member' => CompanyMemberHandler::class,
        'company_admin' => CompanyAdminHandler::class,
```

- [ ] **Step 4: The invitation acceptor and the sign-up hook**

`app/Domain/Providers/Services/CompanyInvitationAcceptor.php`:

```php
<?php

namespace App\Domain\Providers\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\CompanyInvitation;

/**
 * Links a newly signed-up member to every company an approved invitation
 * named their phone for. The phone was just verified by OTP, so the
 * invitation cannot be claimed by anyone else.
 */
class CompanyInvitationAcceptor
{
    public function acceptFor(User $user): void
    {
        CompanyInvitation::query()->open()->where('phone', $user->phone)->with('company')->get()
            ->each(function (CompanyInvitation $invitation) use ($user) {
                if (! $invitation->company->members()->where('users.id', $user->id)->exists()) {
                    $invitation->company->members()->attach($user->id, [
                        'door_access_enabled' => $invitation->door_access_enabled,
                        'is_admin' => false,
                    ]);
                }

                $invitation->forceFill(['accepted_user_id' => $user->id, 'accepted_at' => now()])->save();
            });
    }
}
```

`MemberAuthController::verifyRegistration` — inside the `DB::transaction`, after `Wallet::create([...])` and before `$pending->delete()`:

```php
            // A provider's client company may already be waiting for this
            // phone (docs/decisions/service-providers.md).
            app(CompanyInvitationAcceptor::class)->acceptFor($user);
```

- [ ] **Step 5: The member-app path**

`Member\CompanyMemberController::updateAdmin` becomes (inject `ProviderRequestService` via method or constructor; imports `ProviderRequestType`, `Provider`):

```php
    /**
     * For a provider's company, making someone admin is a grant, so it
     * becomes a request a reviewer decides (docs/decisions/service-providers.md)
     * — 202, nothing changed yet. Revoking stays direct: it takes access
     * away. A company ADD deals with directly is unchanged.
     */
    public function updateAdmin(UpdateCompanyMemberAdminRequest $request, Company $company, User $user, ProviderRequestService $requests): JsonResponse
    {
        Gate::authorize('manageMembers', $company);

        if ($company->provider_id !== null && $request->validated('is_admin')) {
            abort_unless($company->members()->where('users.id', $user->id)->exists(), 404);

            $payload = ['user_id' => $user->id];

            if (! $requests->duplicateExists(ProviderRequestType::CompanyAdmin, $company->id, $payload)) {
                $requests->submit($company->provider, $request->user(), ProviderRequestType::CompanyAdmin, $company->id, $payload);
            }

            return response()->json(['message' => __('api.member.company_admin_requested')], 202);
        }

        $company->members()->updateExistingPivot($user->id, [
            'is_admin' => $request->validated('is_admin'),
        ]);

        $this->logSensitiveAction('company_member_admin_changed', $company, [
            'user_id' => $user->id,
            'is_admin' => $request->validated('is_admin'),
        ]);

        return response()->json(['message' => __('api.member.admin_flag_updated')]);
    }
```

Update the class docblock: a provider's company routes a grant through a request.

`lang/{en,ar}/api.php`, `member` group — en: `'company_admin_requested' => 'Your request has been sent for approval.'`; ar: `'company_admin_requested' => 'أُرسل طلبك للموافقة.'`.

- [ ] **Step 6: Run the tests**

Run: `PHP artisan test tests/Feature/Providers tests/Feature/Identity`
Expected: PASS; Identity unchanged against the baseline.

- [ ] **Step 7: Commit**

```bash
PHP vendor/bin/pint app/Domain/Providers app/Http/Controllers/Api/V1/Auth/MemberAuthController.php app/Http/Controllers/Api/V1/Member/CompanyMemberController.php tests/Feature
git add app/Domain/Providers app/Http/Controllers/Api/V1/Auth/MemberAuthController.php app/Http/Controllers/Api/V1/Member/CompanyMemberController.php lang tests/Feature
git commit -m "feat(providers): approved employees join or wait for sign-up; admin grants go through approval

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Deactivating a provider, moving a company

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Admin/ProviderController.php` (`updateStatus`)
- Create: `app/Http/Requests/Admin/UpdateProviderStatusRequest.php`, `UpdateCompanyProviderRequest.php`
- Fill: `app/Http/Controllers/Api/V1/Admin/CompanyProviderController.php` (`update`)
- Modify: `app/Domain/Providers/Services/ProviderRequestService.php` (`cancelPending`)
- Modify: `routes/api/v1/admin.php`, `lang/{en,ar}/api.php` (`admin`)
- Test: `tests/Feature/Providers/ProviderLifecycleTest.php`

**Interfaces:**
- Consumes: Tasks 4, 7.
- Produces: `ProviderRequestService::cancelPending(Builder $requests, string $reason): int`; `PATCH admin/providers/{provider}/status`; `PATCH admin/companies/{company}/provider`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Enums\ProviderStatus;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Laravel\Sanctum\Sanctum;

class ProviderLifecycleTest extends ProviderTestCase
{
    public function test_deactivating_a_provider_cancels_its_pending_requests_and_keeps_its_companies_running(): void
    {
        $provider = Provider::factory()->create();
        $account = User::factory()->create();
        $account->assignRole('provider');
        $provider->users()->attach($account->id);
        $company = $this->clientCompany($provider);
        $membership = Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $pending = ProviderRequest::factory()->create(['provider_id' => $provider->id, 'company_id' => $company->id]);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/providers/{$provider->id}/status", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('message', __('api.admin.provider_status_updated'));

        $this->assertSame(ProviderStatus::Inactive, $provider->fresh()->status);
        $this->assertSame(ProviderRequestStatus::Cancelled, $pending->fresh()->status);
        $this->assertSame('provider_deactivated', $pending->fresh()->rejection_reason);
        $this->assertTrue(Membership::query()->live()->whereKey($membership->id)->exists());
        $this->assertSame($provider->id, $company->fresh()->provider_id);

        Sanctum::actingAs($account, ['*']);
        $this->getJson('/api/v1/provider/companies')->assertForbidden();
    }

    public function test_reactivating_does_not_bring_cancelled_requests_back(): void
    {
        $provider = Provider::factory()->inactive()->create();
        $cancelled = ProviderRequest::factory()->create(['provider_id' => $provider->id, 'status' => ProviderRequestStatus::Cancelled]);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/providers/{$provider->id}/status", ['status' => 'active'])->assertOk();

        $this->assertSame(ProviderRequestStatus::Cancelled, $cancelled->fresh()->status);
    }

    public function test_a_company_moves_to_another_provider_and_the_old_one_loses_it(): void
    {
        $old = Provider::factory()->create();
        $new = Provider::factory()->create();
        $company = $this->clientCompany($old);
        $pending = ProviderRequest::factory()->create(['provider_id' => $old->id, 'company_id' => $company->id]);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/companies/{$company->id}/provider", ['provider_id' => $new->id])
            ->assertOk()->assertJsonPath('message', __('api.admin.company_provider_updated'));

        $this->assertSame($new->id, $company->fresh()->provider_id);
        $this->assertSame(ProviderRequestStatus::Cancelled, $pending->fresh()->status);

        $this->providerAccount($old);
        $this->getJson("/api/v1/provider/companies/{$company->id}")->assertNotFound();
    }

    public function test_a_company_moved_to_add_directly_behaves_like_a_direct_company(): void
    {
        $company = $this->clientCompany(Provider::factory()->create());
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/companies/{$company->id}/provider", ['provider_id' => null])->assertOk();

        $this->assertNull($company->fresh()->provider_id);
    }

    public function test_status_and_transfer_follow_their_permissions(): void
    {
        $provider = Provider::factory()->create();
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->patchJson("/api/v1/admin/providers/{$provider->id}/status", ['status' => 'inactive'])->assertForbidden();
        $this->patchJson('/api/v1/admin/companies/'.$this->clientCompany($provider)->id.'/provider', ['provider_id' => null])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `PHP artisan test tests/Feature/Providers/ProviderLifecycleTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`ProviderRequestService::cancelPending` (import `Illuminate\Database\Eloquent\Builder`):

```php
    /**
     * Cancels every pending request in $requests, recording why — when a
     * provider is deactivated or a company leaves it. Returns how many.
     *
     * @param  Builder<ProviderRequest>  $requests
     */
    public function cancelPending(Builder $requests, string $reason): int
    {
        return $requests->where('status', ProviderRequestStatus::Pending)->update([
            'status' => ProviderRequestStatus::Cancelled->value,
            'rejection_reason' => $reason,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);
    }
```

`UpdateProviderStatusRequest`: `'status' => ['required', Rule::enum(ProviderStatus::class)]`.

`UpdateCompanyProviderRequest`: `'provider_id' => ['present', 'nullable', 'integer', 'exists:providers,id']`.

`ProviderController::updateStatus`:

```php
    /**
     * Deactivating cuts every account off (EnsureActiveProvider) and cancels
     * the provider's pending requests. Its companies keep running; an admin
     * moves them with CompanyProviderController. Reactivating brings no
     * cancelled request back.
     */
    public function updateStatus(UpdateProviderStatusRequest $request, Provider $provider, ProviderRequestService $requests): JsonResponse
    {
        $before = $provider->status;
        $status = ProviderStatus::from($request->validated('status'));

        DB::transaction(function () use ($provider, $status, $requests) {
            $provider->update(['status' => $status]);

            if ($status === ProviderStatus::Inactive) {
                $requests->cancelPending(ProviderRequest::query()->where('provider_id', $provider->id), 'provider_deactivated');
            }
        });

        $this->logSensitiveAction('provider_status_changed', $provider, ['before' => $before, 'after' => $status]);

        return response()->json(['message' => __('api.admin.provider_status_updated')]);
    }
```

`CompanyProviderController`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\Company;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCompanyProviderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Moves a company to another provider, or to ADD directly (null). The old
 * provider's pending requests on it are cancelled: they were asked by
 * someone who no longer manages it.
 */
class CompanyProviderController extends Controller
{
    use LogsSensitiveActions;

    public function update(UpdateCompanyProviderRequest $request, Company $company, ProviderRequestService $requests): JsonResponse
    {
        $before = $company->provider_id;
        $after = $request->validated('provider_id');

        DB::transaction(function () use ($company, $before, $after, $requests) {
            if ($before !== null && $before !== $after) {
                $requests->cancelPending(
                    ProviderRequest::query()->where('provider_id', $before)->where('company_id', $company->id),
                    'company_transferred',
                );
            }

            $company->update(['provider_id' => $after]);
        });

        $this->logSensitiveAction('company_provider_changed', $company, ['before' => $before, 'after' => $after]);

        return response()->json(['message' => __('api.admin.company_provider_updated')]);
    }
}
```

Routes, inside the providers `withoutMiddleware` group:

```php
    Route::patch('providers/{provider}/status', [ProviderController::class, 'updateStatus'])->middleware('permission:providers.status');
    Route::patch('companies/{company}/provider', [CompanyProviderController::class, 'update'])->middleware('permission:providers.transfer_company');
```

Lang, `admin` group — en: `'provider_status_updated' => 'Provider status updated.'`, `'company_provider_updated' => "The company's provider was changed."`; ar: `'provider_status_updated' => 'حُدّثت حالة المزوّد.'`, `'company_provider_updated' => 'تغيّر مزوّد الشركة.'`.

- [ ] **Step 4: Run the tests**

Run: `PHP artisan test tests/Feature/Providers`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
PHP vendor/bin/pint app/Http/Controllers/Api/V1/Admin app/Http/Requests/Admin app/Domain/Providers routes/api/v1/admin.php tests/Feature/Providers
git add app/Http/Controllers/Api/V1/Admin app/Http/Requests/Admin app/Domain/Providers routes/api/v1/admin.php lang tests/Feature/Providers
git commit -m "feat(providers): deactivate a provider, move a company between providers

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Isolation guard, decision record, docs, Postman

**Files:**
- Create: `tests/Guards/ProviderRoutesStayIsolatedTest.php`
- Create: `docs/decisions/service-providers.md`
- Modify: `CLAUDE.md`, `postman/ADD-OS.postman_collection.json`

- [ ] **Step 1: Write the guard**

```php
<?php

namespace Tests\Guards;

use Tests\TestCase;

/**
 * docs/decisions/service-providers.md: the provider surface never borrows
 * an admin or member controller, and every provider controller that takes
 * a company checks it is the provider's own (authorizeCompany — 404
 * otherwise). Written prose is a request, not a guarantee (PRD §9).
 */
class ProviderRoutesStayIsolatedTest extends TestCase
{
    public function test_provider_routes_point_only_at_provider_controllers(): void
    {
        $routes = file_get_contents(base_path('routes/api/v1/provider.php'));

        preg_match_all('/use\s+App\\\\Http\\\\Controllers\\\\Api\\\\V1\\\\(\w+)\\\\/', $routes, $matches);

        $this->assertSame([], array_values(array_diff(array_unique($matches[1]), ['Provider'])),
            'routes/api/v1/provider.php may only use App\Http\Controllers\Api\V1\Provider\* controllers.');
    }

    public function test_every_provider_controller_taking_a_company_authorizes_it(): void
    {
        $violations = [];

        foreach (glob(app_path('Http/Controllers/Api/V1/Provider/*Controller.php')) as $file) {
            $source = file_get_contents($file);

            if (str_contains($source, 'Company $company') && ! str_contains($source, 'authorizeCompany(')) {
                $violations[] = basename($file);
            }
        }

        $this->assertSame([], $violations, 'Provider controllers taking a {company} must call authorizeCompany().');
    }
}
```

Run: `PHP artisan test tests/Guards/ProviderRoutesStayIsolatedTest.php` — Expected: PASS (it pins what Tasks 3–6 built). Sanity-check it bites: temporarily delete the `authorizeCompany` call in `ProviderCompanyController::show`, run, see FAIL, restore.

- [ ] **Step 2: The decision record**

`docs/decisions/service-providers.md` — follow the shape of `docs/decisions/unified-payments.md` (Status / Owner / Type / Design + Plan links). Sections:
- **What this adds** — the provider, its accounts, its client companies, the approval queue (the spec's decision table, condensed).
- **Decisions this revises** — (1) "company accounts are created by operations only" becomes "by operations, or on a provider's office request that operations takes through the same private-office pipeline"; (2) D.8 (`docs/decisions/rbac-scoping.md`): `ProviderPolicy` is a second exception beside `CompanyPolicy`, because which companies a provider acts on is `companies.provider_id`, not a role; `scope_type`/`scope_id` stay banned (`RbacStaysFlatTest`).
- **The model** — tables, the request types with `result_id` meanings, handlers, `provider.active`, the 404 rule.
- **Rulings made while implementing** — the four refinements in this plan's Global Constraints table.
- **Explicitly not done here** — invoices (Phase 2: approval activates with no charge; do not ship to production without Phase 2 unless collection outside the system is accepted), auto-renewal (Phase 3), reports (Phase 4); no per-provider contractual discount.
- **Deploy** — run `php artisan migrate` then `php artisan permissions:sync` (nobody, admin included, reaches the provider admin routes before it); `php artisan db:seed --class=RoleSeeder` creates the `provider` role.

Also add one line at the top of `docs/decisions/rbac-scoping.md` pointing to the revision.

- [ ] **Step 3: CLAUDE.md**

Under **Route composition**, after the `member.php` bullet:

```markdown
- `routes/api/v1/provider.php` — service providers (`docs/decisions/service-providers.md`): everything here sits behind `auth:sanctum` + `abilities:dashboard` + `role:provider` + `provider.active` (the account's provider exists and is active), applied by the group in `api.php`. Every route on a `{company}` calls `authorizeCompany()` (`ProviderPolicy` via the `provider.manage-company` gate — 404 when the company is not this provider's); only `Provider\*` controllers here (`ProviderRoutesStayIsolatedTest`).
```

After the **Payments** paragraph, a new paragraph:

```markdown
**Service providers** (`docs/decisions/service-providers.md`). A `Provider` (`App\Domain\Providers`) is an intermediary with no seats of its own that manages client companies (`companies.provider_id`; null = ADD deals with the company directly). Its accounts hold the protected `provider` role (never an admin permission) and are linked through `provider_user` (one provider per account). Everything a provider asks ADD to grant — an employee, a company admin, a plan design, a plan assignment, a wallet top-up — is a `ProviderRequest` decided through `ProviderRequestService::approve()`/`reject()`, which hands the request to one `ProviderRequestHandler` per type under a row lock; deciding is the `provider-requests.approve|reject` permission, so it can be given to any role. A new client company is a private-office request carrying `provider_id`, on the existing pipeline. An approved employee with no account is a `company_invitation`, linked at `register/verify`. These approval rules apply to provider companies only; direct companies are unchanged. Phase 1 activates assigned plans with no charge — invoicing is Phase 2.
```

- [ ] **Step 4: Postman**

In `postman/ADD-OS.postman_collection.json`, add a top-level folder **Provider** (requests: List Companies, Show Company, Remove Employee, List/Create Office Requests, List Plans, List/Create/Show/Cancel Requests — one Create Request example per type with the payloads from Task 6's tests) and, inside the existing **Admin** folder, a **Providers** folder (List/Create/Show/Update Provider, Link/Unlink Account, Update Status, Move Company, List/Show/Approve/Reject Provider Requests). Copy an existing request item's JSON shape (auth, headers, `{{base_url}}`) — open the collection, find the Branches folder, mirror it. Validate the file is still JSON: `PHP -r "json_decode(file_get_contents('postman/ADD-OS.postman_collection.json'), flags: JSON_THROW_ON_ERROR); echo 'ok';"`.

- [ ] **Step 5: Full suite**

Run: `PHP artisan test`
Expected: only the 17 pre-existing failures. Run `PHP vendor/bin/pint --test` on every file this plan touched.

- [ ] **Step 6: Commit**

```bash
git add tests/Guards/ProviderRoutesStayIsolatedTest.php docs/decisions/service-providers.md docs/decisions/rbac-scoping.md CLAUDE.md postman/ADD-OS.postman_collection.json
git commit -m "docs(providers): decision record, isolation guard, CLAUDE.md and Postman

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
