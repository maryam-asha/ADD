# Office Leases (Phase 3) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A company (direct or a provider's) leases a private office for a fixed number of months at a fixed money price; the lease bills itself period by period through the Phase 2 invoices, opens the office's lock to the company's members for exactly its term, and is visible to staff, to the company's provider, and (without prices) to the company admin.

**Architecture:** A new `App\Domain\Leasing` domain owns `office_leases` and `office_lease_periods`. `OfficeLeaseService::contract()` turns a `quoted` private-office request into a lease in one transaction (company + wallet when new, space row lock + overlap check, the whole period schedule from `LeaseScheduleBuilder`, the invoices of every period already started via `InvoiceIssuer::issueForLeasePeriod()`), then opens the door after commit. `LeaseDailyRunner` (command `leases:run-daily`, 06:00 Damascus) issues due period invoices, opens doors, ends expired leases and closes doors — each step looks for an inconsistent state, so a failed TTLock call is retried the next day. Termination is `OfficeLeaseService::terminate()`. Admin routes are permission-gated (`office-leases.*`); provider routes are read-only and 404-isolated; the member route is company-admin only.

**Tech Stack:** Laravel 12, PHP 8.3, bcmath, Carbon (`addMonthsNoOverflow`), spatie/laravel-permission, spatie/activitylog, PHPUnit feature tests on in-memory SQLite, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-10-10-office-leases-design.md` (Arabic). Builds on `docs/decisions/service-providers.md` (Phases 1–2, invoicing included).

## Global Constraints

- Run PHP as `php` (the suite and Pint already run with it in this repo). Tests: `php artisan test <path>`; style: `./vendor/bin/pint --test <files>` on every touched PHP file (Pint only the files you touched, never whole directories).
- The full suite has **17 pre-existing failures** (audit-log `'1'` vs `1`, the EventAttendee enum guard, notifications on SQLite, MemberOperationsIsolationTest password recovery). A task is green when it adds none.
- The working tree carries unrelated uncommitted edits (`app/Http/Controllers/Api/V1/Auth/MemberAuthController.php`, `app/Http/Resources/CurrencyResource.php`, `tests/Feature/Identity/MemberLoginTest.php`, `tests/Feature/Public/CurrencyControllerTest.php`) and an untracked `.claude/launch.json` — never stage, commit, revert or reformat them. Stage files by explicit path.
- Money columns are `decimal(14,2)` (guarded by `MoneyIsDecimalOnlyTest`); amounts are bcmath strings end to end — never cast money to float.
- Enum-shaped columns are `string` + a PHP backed-enum cast, registered in `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` (`NoNewMysqlEnumColumnsTest` forbids MySQL ENUM).
- "Today" is `now()->toDateString()` everywhere in this feature (app timezone, as `InvoiceIssuer` already uses). Date columns are compared with `whereDate()`.
- Update/action endpoints answer `{"message": __('api.<group>.<key>')}`; creation returns the resource (201).
- Copy goes in `lang/en/api.php` **and** `lang/ar/api.php`, in a new top-level `'lease' => [...]` group added once at the end of each file (grep first: no `'lease' =>` top-level key exists today).
- No member-role response may contain a passcode or vendor id (`tests/Guards/LockCredentialsNeverReachMemberTest.php`, a locked decision — docs/decisions/qr-lock-unlock.md §4). No admin or provider lease response returns `passcode_value` either.
- Code, comments and docs in the repo are English. Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Never read or search `vendor/`, `storage/logs`, `storage/framework`, `bootstrap/cache`.

**Refinements of the spec (apply these; Task 13 writes them back into the spec and the decision record):**

| Spec says | Plan does | Why |
|---|---|---|
| The company admin sees the keypad code in the member app | **No keypad code in any member response**; members open the door from the app (QR unlock, `UnlockService`), which already honours company grants for members with `door_access_enabled` | A locked guard (`LockCredentialsNeverReachMemberTest`) forbids passcodes on the member surface. |
| `terminated_on` is "the termination date" (inclusive/exclusive unstated) | `terminated_on` is the **first day without access**: access runs through `terminated_on − 1 day`. Periods whose `starts_on >= terminated_on` are cancelled. Allowed range: `starts_on ≤ terminated_on ≤ ends_on` | Makes "terminate today → door closes now" (spec) and "a period starting before the date still bills" (spec) both hold with one rule. |
| First invoice at contract, the rest on each period's first day | At contract, invoice period 1 **and every period whose `starts_on ≤ today`** (a back-dated lease) | Those periods are already due; issuing them now equals what the runner would do next morning, without a gap. |
| Rent invoice has no points | `invoices.points` becomes nullable; `InvoiceDraft` carries either points or a fixed amount + currency | As spec; the exchange rate is still stored for reference, so a currency with no rate refuses the contract (422 `api.invoice.no_exchange_rate`). |
| (spec silent) An office under maintenance during a lease | The runner opens a door only when the space is `active`; `access:revoke-grants-on-maintenance` already revokes every grant on the lock (lease grants included); the next run after maintenance re-issues | One rule for every grant on a lock under maintenance. |

## Review Focus

1. **Two staff members contracting the same office at once** → the second waits on the space row lock, sees the first lease and is refused 409 `api.lease.office_taken`; nothing of the second is saved (Task 5 pins the sequential case; the `lockForUpdate` on the space row is what makes it hold).
2. **A lease back-dated two periods** → every started period is invoiced at contract, and the next `leases:run-daily` issues nothing more for them (Task 5 + Task 8 pin it).
3. **Terminating on the lease's own start date** → no access day at all, every period but the already-invoiced first is cancelled, and the office can be leased again from that same date (Task 7 pins it).
4. **A monthly lease starting on the 31st** → periods start 31 Jan, 28/29 Feb, 31 Mar, 30 Apr — never stuck on the 28th (Task 2 pins it).
5. **The office goes under maintenance mid-lease and back** → no grant while in maintenance, a fresh grant on the first run after it is `active` again (Task 8 pins it).

## File Map

**Create**
- `database/migrations/2026_10_11_100000_create_office_leases_tables.php`
- `app/Domain/Leasing/Enums/{OfficeLeaseStatus,LeasePeriodStatus}.php`
- `app/Domain/Leasing/Models/{OfficeLease,OfficeLeasePeriod}.php`
- `app/Domain/Leasing/Data/LeaseTerms.php`
- `app/Domain/Leasing/Exceptions/LeasingException.php`
- `app/Domain/Leasing/Services/{LeaseScheduleBuilder,OfficeLeaseService,LeaseDailyRunner}.php`
- `app/Console/Commands/RunDailyLeases.php`
- `app/Http/Requests/Admin/Concerns/ValidatesLeaseTerms.php`
- `app/Http/Requests/Admin/{StoreOfficeLeaseRequest,TerminateOfficeLeaseRequest,ListOfficeLeasesRequest}.php`
- `app/Http/Requests/Provider/StoreProviderOfficeRequestRequest.php`
- `app/Http/Controllers/Api/V1/Admin/OfficeLeaseController.php`
- `app/Http/Controllers/Api/V1/Provider/ProviderOfficeLeaseController.php`
- `app/Http/Controllers/Api/V1/Member/CompanyOfficeLeaseController.php`
- `app/Http/Resources/{OfficeLeaseResource,ProviderOfficeLeaseResource,MemberOfficeLeaseResource}.php`
- `database/factories/{OfficeLeaseFactory,OfficeLeasePeriodFactory}.php`
- `tests/Concerns/BuildsOfficeLeases.php`, `tests/Feature/Leasing/*`, `tests/Unit/Domain/Leasing/LeaseScheduleBuilderTest.php`
- `docs/decisions/office-leases.md`

**Modify**
- `app/Domain/Identity/Models/{Company,PrivateOfficeRequest}.php`, `app/Domain/Foundation/Models/Space.php`, `app/Domain/Access/Models/AccessGrant.php`, `app/Domain/Invoicing/Models/Invoice.php`
- `app/Domain/Invoicing/Data/{InvoiceDraft,InvoiceQuote}.php`, `app/Domain/Invoicing/Services/InvoiceIssuer.php`
- `app/Domain/Access/Services/PasscodeIssuanceService.php` (replace the unused `issueForTenancy()`)
- `app/Domain/Foundation/Services/SpaceBrowseService.php`
- `app/Http/Controllers/Api/V1/Admin/{CompanyController,PrivateOfficeRequestController}.php`, `app/Http/Controllers/Api/V1/Provider/ProviderOfficeRequestController.php`, `app/Http/Controllers/Api/V1/Provider/Concerns/ActsForProvider.php`
- `app/Http/Requests/Admin/{StoreCompanyRequest,StorePrivateOfficeRequestRequest}.php`
- `app/Http/Resources/{CompanyResource,PrivateOfficeRequestResource,InvoiceResource}.php`
- `app/Services/Permissions/PermissionSyncService.php`
- `routes/api/v1/{admin,provider,member}.php`, `routes/console.php`
- `lang/{en,ar}/api.php`
- `tests/Guards/{EnumColumnsHaveBackedEnumCastsTest,ProviderRoutesStayIsolatedTest}.php`
- Existing tests that create companies through the API: `tests/Feature/Identity/CompanyLifecycleTest.php`, `tests/Feature/Identity/AuditLoggingTest.php`, `tests/Feature/Membership/WalletProvisioningTest.php`, `tests/Feature/Providers/ProviderOfficeRequestTest.php`
- `CLAUDE.md`, `postman/ADD-OS.postman_collection.json`, `docs/superpowers/specs/2026-10-10-office-leases-design.md`

---

### Task 1: Schema, models, enums, factories

**Files:**
- Create: `database/migrations/2026_10_11_100000_create_office_leases_tables.php`, `app/Domain/Leasing/Enums/OfficeLeaseStatus.php`, `app/Domain/Leasing/Enums/LeasePeriodStatus.php`, `app/Domain/Leasing/Models/OfficeLease.php`, `app/Domain/Leasing/Models/OfficeLeasePeriod.php`, `database/factories/OfficeLeaseFactory.php`, `database/factories/OfficeLeasePeriodFactory.php`, `tests/Feature/Leasing/OfficeLeaseModelTest.php`
- Modify: `app/Domain/Identity/Models/Company.php`, `app/Domain/Identity/Models/PrivateOfficeRequest.php`, `app/Domain/Foundation/Models/Space.php`, `app/Domain/Access/Models/AccessGrant.php`, `app/Domain/Invoicing/Models/Invoice.php`, `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`

**Interfaces:**
- Produces: tables `office_leases`, `office_lease_periods`; columns `private_office_requests.company_id|payer`, `invoices.office_lease_period_id` (+ `points` nullable), `access_grants.office_lease_id`.
- Produces: `OfficeLease` (reference prefix `LSE`) with `accessEndsOn(): CarbonImmutable`, `grantsAccessOn(string $date): bool`, scopes `overlapping(int $spaceId, string $from, string $to)` and `holdingOn(string $date)`, relations `company()`, `space()`, `provider()`, `officeRequest()`, `periods()`, `accessGrants()`, `creator()`, `terminator()`.
- Produces: `OfficeLeasePeriod` with `lease()`, `invoice()`. `Company::officeLeases()`, `Space::officeLeases()`, `PrivateOfficeRequest::company()` + `payer` cast, `AccessGrant::lease()`, `Invoice::leasePeriod()`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leasing/OfficeLeaseModelTest.php`:

```php
<?php

namespace Tests\Feature\Leasing;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficeLeaseModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CurrencySeeder::class);
    }

    public function test_a_lease_gets_a_yearly_reference_and_its_periods(): void
    {
        $lease = OfficeLease::factory()->create();
        OfficeLeasePeriod::factory()->create(['office_lease_id' => $lease->id, 'sequence' => 1]);

        $this->assertMatchesRegularExpression('/^LSE-\d{4}-\d{5}$/', $lease->reference);
        $this->assertSame(OfficeLeaseStatus::Active, $lease->status);
        $this->assertCount(1, $lease->periods);
        $this->assertTrue($lease->company->officeLeases->contains($lease));
    }

    public function test_access_ends_the_day_before_a_termination_date(): void
    {
        $lease = OfficeLease::factory()->create(['starts_on' => '2026-01-01', 'duration_months' => 12, 'billing_cycle_months' => 3, 'ends_on' => '2026-12-31']);
        $this->assertSame('2026-12-31', $lease->accessEndsOn()->toDateString());

        $lease->update(['status' => OfficeLeaseStatus::Terminated, 'terminated_on' => '2026-06-01']);
        $this->assertSame('2026-05-31', $lease->fresh()->accessEndsOn()->toDateString());
        $this->assertTrue($lease->fresh()->grantsAccessOn('2026-05-31'));
        $this->assertFalse($lease->fresh()->grantsAccessOn('2026-06-01'));
        $this->assertFalse($lease->fresh()->grantsAccessOn('2025-12-31'));
    }

    public function test_an_ended_lease_grants_no_access(): void
    {
        $lease = OfficeLease::factory()->create(['status' => OfficeLeaseStatus::Ended]);

        $this->assertFalse($lease->grantsAccessOn(now()->toDateString()));
    }

    public function test_overlapping_uses_the_access_window(): void
    {
        $space = Space::factory()->ofType(SpaceType::Business)->create();
        OfficeLease::factory()->create(['space_id' => $space->id, 'starts_on' => '2026-01-01', 'duration_months' => 6, 'billing_cycle_months' => 1, 'ends_on' => '2026-06-30']);
        OfficeLease::factory()->create([
            'space_id' => $space->id, 'starts_on' => '2026-07-01', 'duration_months' => 6, 'billing_cycle_months' => 1, 'ends_on' => '2026-12-31',
            'status' => OfficeLeaseStatus::Terminated, 'terminated_on' => '2026-09-01',
        ]);

        $this->assertTrue(OfficeLease::query()->overlapping($space->id, '2026-06-30', '2026-07-15')->exists());
        $this->assertTrue(OfficeLease::query()->overlapping($space->id, '2026-08-31', '2026-10-01')->exists());
        $this->assertFalse(OfficeLease::query()->overlapping($space->id, '2026-09-01', '2027-08-31')->exists());
        $this->assertSame(1, OfficeLease::query()->holdingOn('2026-03-10')->count());
        $this->assertSame(0, OfficeLease::query()->holdingOn('2026-09-01')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leasing/OfficeLeaseModelTest.php`
Expected: FAIL — `Class "App\Domain\Leasing\Models\OfficeLease" not found`.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_10_11_100000_create_office_leases_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Office leases (docs/superpowers/specs/2026-10-10-office-leases-design.md):
     * a company holds one private office for whole months at a fixed money
     * price. Every billing period is created up front; an invoice points at
     * the period it bills (the "one FK column per source" rule of Phase 2),
     * and the lock grant points at its lease.
     */
    public function up(): void
    {
        Schema::create('office_leases', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('space_id')->constrained()->restrictOnDelete();
            $table->foreignId('private_office_request_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('billed_to', 20);
            $table->string('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->decimal('monthly_amount', 14, 2);
            $table->date('starts_on');
            $table->unsignedSmallInteger('duration_months');
            $table->unsignedSmallInteger('billing_cycle_months');
            $table->date('ends_on');
            $table->string('status', 20)->default('active');
            $table->date('terminated_on')->nullable();
            $table->foreignId('terminated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('termination_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['space_id', 'status']);
            $table->index(['company_id', 'status']);
            $table->index(['provider_id', 'status']);
            $table->index(['status', 'ends_on']);
        });

        Schema::create('office_lease_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_lease_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('upcoming');
            $table->timestamps();

            $table->unique(['office_lease_id', 'sequence']);
            $table->index(['status', 'starts_on']);
        });

        Schema::table('private_office_requests', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('converted_company_id')
                ->constrained('companies')->nullOnDelete();
            $table->string('payer', 20)->nullable()->after('company_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('office_lease_period_id')->nullable()->unique()->after('provider_request_id')
                ->constrained('office_lease_periods')->restrictOnDelete();
            $table->unsignedBigInteger('points')->nullable()->change();
        });

        Schema::table('access_grants', function (Blueprint $table) {
            $table->foreignId('office_lease_id')->nullable()->after('source_id')
                ->constrained('office_leases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('access_grants', fn (Blueprint $table) => $table->dropConstrainedForeignId('office_lease_id'));
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('office_lease_period_id');
            $table->unsignedBigInteger('points')->nullable(false)->change();
        });
        Schema::table('private_office_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('payer');
        });
        Schema::dropIfExists('office_lease_periods');
        Schema::dropIfExists('office_leases');
    }
};
```

- [ ] **Step 4: Write the enums**

`app/Domain/Leasing/Enums/OfficeLeaseStatus.php`:

```php
<?php

namespace App\Domain\Leasing\Enums;

/**
 * Stored states only. "Starts on …" is an active lease whose starts_on is
 * ahead, and "ending soon" is a filter on ends_on — neither is a state.
 */
enum OfficeLeaseStatus: string
{
    case Active = 'active';
    case Ended = 'ended';
    case Terminated = 'terminated';
}
```

`app/Domain/Leasing/Enums/LeasePeriodStatus.php`:

```php
<?php

namespace App\Domain\Leasing\Enums;

enum LeasePeriodStatus: string
{
    case Upcoming = 'upcoming';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';
}
```

- [ ] **Step 5: Write the models**

`app/Domain/Leasing/Models/OfficeLease.php`:

```php
<?php

namespace App\Domain\Leasing\Models;

use App\Concerns\HasReference;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company holding one private office for whole months at a fixed money
 * price (docs/superpowers/specs/2026-10-10-office-leases-design.md).
 * Created only by OfficeLeaseService::contract(); its periods are all
 * created with it. Access runs from starts_on through accessEndsOn().
 */
class OfficeLease extends Model
{
    use HasFactory, HasReference;

    protected $fillable = [
        'company_id',
        'space_id',
        'private_office_request_id',
        'provider_id',
        'billed_to',
        'currency',
        'monthly_amount',
        'starts_on',
        'duration_months',
        'billing_cycle_months',
        'ends_on',
        'status',
        'terminated_on',
        'terminated_by',
        'termination_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'billed_to' => Payer::class,
            'status' => OfficeLeaseStatus::class,
            'monthly_amount' => 'decimal:2',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'terminated_on' => 'immutable_date',
            'duration_months' => 'integer',
            'billing_cycle_months' => 'integer',
        ];
    }

    protected function referencePrefix(): string
    {
        return 'LSE';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function officeRequest(): BelongsTo
    {
        return $this->belongsTo(PrivateOfficeRequest::class, 'private_office_request_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(OfficeLeasePeriod::class)->orderBy('sequence');
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function terminator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'terminated_by');
    }

    /** The last day with access: the day before terminated_on, else ends_on. */
    public function accessEndsOn(): CarbonImmutable
    {
        return $this->terminated_on !== null ? $this->terminated_on->subDay() : $this->ends_on;
    }

    /** Whether the company may open the office on $date (Y-m-d). */
    public function grantsAccessOn(string $date): bool
    {
        return $this->status !== OfficeLeaseStatus::Ended
            && $this->starts_on->toDateString() <= $date
            && $this->accessEndsOn()->toDateString() >= $date;
    }

    /**
     * Leases of $spaceId whose access window meets [$from, $to] (Y-m-d,
     * inclusive). Ended leases count too: a back-dated lease must not
     * overlap one that already ran. terminated_on is the first day without
     * access, hence the strict comparison.
     */
    public function scopeOverlapping(Builder $query, int $spaceId, string $from, string $to): Builder
    {
        return $query->where('space_id', $spaceId)->holdingBetween($from, $to);
    }

    /** Leases whose access window includes $date. */
    public function scopeHoldingOn(Builder $query, string $date): Builder
    {
        return $query->holdingBetween($date, $date);
    }

    public function scopeHoldingBetween(Builder $query, string $from, string $to): Builder
    {
        return $query
            ->whereDate('starts_on', '<=', $to)
            ->where(fn (Builder $window) => $window
                ->where(fn (Builder $open) => $open->whereNull('terminated_on')->whereDate('ends_on', '>=', $from))
                ->orWhere(fn (Builder $cut) => $cut->whereNotNull('terminated_on')->whereDate('terminated_on', '>', $from)));
    }
}
```

`app/Domain/Leasing/Models/OfficeLeasePeriod.php`:

```php
<?php

namespace App\Domain\Leasing\Models;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One billing period of a lease; its invoice points back at it (invoices.office_lease_period_id, unique). */
class OfficeLeasePeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'office_lease_id',
        'sequence',
        'starts_on',
        'ends_on',
        'amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'amount' => 'decimal:2',
            'status' => LeasePeriodStatus::class,
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(OfficeLease::class, 'office_lease_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'office_lease_period_id');
    }
}
```

- [ ] **Step 6: Relations and casts on existing models**

`app/Domain/Identity/Models/Company.php` — add the import `use App\Domain\Leasing\Models\OfficeLease;` and, after `invitations()`:

```php
    public function officeLeases(): HasMany
    {
        return $this->hasMany(OfficeLease::class);
    }
```

`app/Domain/Foundation/Models/Space.php` — add `use App\Domain\Leasing\Models\OfficeLease;` and, after `devices()`:

```php
    public function officeLeases(): HasMany
    {
        return $this->hasMany(OfficeLease::class);
    }
```

`app/Domain/Identity/Models/PrivateOfficeRequest.php` — add `'company_id'` and `'payer'` to `$fillable`, `'payer' => Payer::class` to `casts()` (import `App\Domain\Providers\Enums\Payer`), and:

```php
    /** The existing company asking for another office; null = a new company. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
```

`app/Domain/Access/Models/AccessGrant.php` — add `'office_lease_id'` to `$fillable` (after `'source_id'`), import `App\Domain\Leasing\Models\OfficeLease`, and:

```php
    public function lease(): BelongsTo
    {
        return $this->belongsTo(OfficeLease::class, 'office_lease_id');
    }
```

`app/Domain/Invoicing/Models/Invoice.php` — add `'office_lease_period_id'` to `$fillable` (after `'provider_request_id'`), import `App\Domain\Leasing\Models\OfficeLeasePeriod`, and:

```php
    public function leasePeriod(): BelongsTo
    {
        return $this->belongsTo(OfficeLeasePeriod::class, 'office_lease_period_id');
    }
```

- [ ] **Step 7: Factories**

`database/factories/OfficeLeaseFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Providers\Enums\Payer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A year-long, quarterly-billed USD lease starting today. Needs the USD
 * currency row (CurrencySeeder) — `currency` is a foreign key.
 *
 * @extends Factory<OfficeLease>
 */
class OfficeLeaseFactory extends Factory
{
    protected $model = OfficeLease::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'space_id' => Space::factory()->ofType(SpaceType::Business),
            'private_office_request_id' => PrivateOfficeRequest::factory()->state(['status' => PrivateOfficeRequestStatus::Contracted]),
            'provider_id' => null,
            'billed_to' => Payer::Company,
            'currency' => 'USD',
            'monthly_amount' => '500.00',
            'starts_on' => now()->toDateString(),
            'duration_months' => 12,
            'billing_cycle_months' => 3,
            'ends_on' => fn (array $attributes) => CarbonImmutable::parse($attributes['starts_on'])
                ->addMonthsNoOverflow($attributes['duration_months'])->subDay()->toDateString(),
            'status' => OfficeLeaseStatus::Active,
        ];
    }
}
```

`database/factories/OfficeLeasePeriodFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficeLeasePeriod>
 */
class OfficeLeasePeriodFactory extends Factory
{
    protected $model = OfficeLeasePeriod::class;

    public function definition(): array
    {
        return [
            'office_lease_id' => OfficeLease::factory(),
            'sequence' => 1,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addMonthsNoOverflow(3)->subDay()->toDateString(),
            'amount' => '1500.00',
            'status' => LeasePeriodStatus::Upcoming,
        ];
    }
}
```

- [ ] **Step 8: Register the enum columns**

`tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` — import `App\Domain\Leasing\Enums\LeasePeriodStatus`, `App\Domain\Leasing\Enums\OfficeLeaseStatus`, `App\Domain\Leasing\Models\OfficeLease`, `App\Domain\Leasing\Models\OfficeLeasePeriod`, `App\Domain\Identity\Models\PrivateOfficeRequest` (if not already imported) and add after the `InvoicePayment::class` entry:

```php
        OfficeLease::class => [
            'status' => OfficeLeaseStatus::class,
            'billed_to' => Payer::class,
        ],
        OfficeLeasePeriod::class => [
            'status' => LeasePeriodStatus::class,
        ],
```

If `PrivateOfficeRequest::class` already has an entry, add `'payer' => Payer::class,` to it; otherwise add:

```php
        PrivateOfficeRequest::class => [
            'status' => PrivateOfficeRequestStatus::class,
            'payer' => Payer::class,
        ],
```

(import `App\Domain\Identity\Enums\PrivateOfficeRequestStatus` if missing).

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Leasing/OfficeLeaseModelTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php tests/Feature/Invoicing`
Expected: PASS (the enum guard's one pre-existing EventAttendee violation aside — confirm the message names only EventAttendee).

Do **not** run migrations against the MySQL database in `.env`; the tests' in-memory SQLite runs `up()` on every test. `down()` is read-reviewed, not executed.

- [ ] **Step 10: Pint and commit**

```bash
./vendor/bin/pint database/migrations/2026_10_11_100000_create_office_leases_tables.php app/Domain/Leasing app/Domain/Identity/Models/Company.php app/Domain/Identity/Models/PrivateOfficeRequest.php app/Domain/Foundation/Models/Space.php app/Domain/Access/Models/AccessGrant.php app/Domain/Invoicing/Models/Invoice.php database/factories/OfficeLeaseFactory.php database/factories/OfficeLeasePeriodFactory.php tests/Feature/Leasing/OfficeLeaseModelTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php
git add database/migrations/2026_10_11_100000_create_office_leases_tables.php app/Domain/Leasing app/Domain/Identity/Models/Company.php app/Domain/Identity/Models/PrivateOfficeRequest.php app/Domain/Foundation/Models/Space.php app/Domain/Access/Models/AccessGrant.php app/Domain/Invoicing/Models/Invoice.php database/factories/OfficeLeaseFactory.php database/factories/OfficeLeasePeriodFactory.php tests/Feature/Leasing/OfficeLeaseModelTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php
git commit -m "feat(leasing): office lease and period tables, models and factories

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `LeaseScheduleBuilder` — the period schedule

**Files:**
- Create: `app/Domain/Leasing/Services/LeaseScheduleBuilder.php`, `tests/Unit/Domain/Leasing/LeaseScheduleBuilderTest.php`

**Interfaces:**
- Produces: `LeaseScheduleBuilder::isValidCycle(int $durationMonths, int $cycleMonths): bool` (static), `endsOn(string $startsOn, int $durationMonths): string`, `build(string $startsOn, int $durationMonths, int $cycleMonths, string $monthlyAmount): list<array{sequence:int, starts_on:string, ends_on:string, amount:string}>`. No database.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Domain/Leasing/LeaseScheduleBuilderTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Leasing;

use App\Domain\Leasing\Services\LeaseScheduleBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LeaseScheduleBuilderTest extends TestCase
{
    private LeaseScheduleBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new LeaseScheduleBuilder;
    }

    public function test_a_quarterly_year_has_four_periods_ending_on_the_lease_end(): void
    {
        $periods = $this->builder->build('2026-01-01', 12, 3, '500.00');

        $this->assertSame([
            ['sequence' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31', 'amount' => '1500.00'],
            ['sequence' => 2, 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30', 'amount' => '1500.00'],
            ['sequence' => 3, 'starts_on' => '2026-07-01', 'ends_on' => '2026-09-30', 'amount' => '1500.00'],
            ['sequence' => 4, 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31', 'amount' => '1500.00'],
        ], $periods);
        $this->assertSame('2026-12-31', $this->builder->endsOn('2026-01-01', 12));
    }

    public function test_a_monthly_lease_from_the_31st_never_sticks_on_the_28th(): void
    {
        $starts = array_column($this->builder->build('2026-01-31', 4, 1, '100.00'), 'starts_on');
        $ends = array_column($this->builder->build('2026-01-31', 4, 1, '100.00'), 'ends_on');

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $starts);
        $this->assertSame(['2026-02-27', '2026-03-30', '2026-04-29', '2026-05-30'], $ends);
        $this->assertSame('2026-05-30', $this->builder->endsOn('2026-01-31', 4));
    }

    public function test_a_leap_day_start(): void
    {
        $starts = array_column($this->builder->build('2028-02-29', 12, 6, '100.00'), 'starts_on');

        $this->assertSame(['2028-02-29', '2028-08-29'], $starts);
        $this->assertSame('2029-02-27', $this->builder->endsOn('2028-02-29', 12));
    }

    public function test_one_payment_for_the_whole_term(): void
    {
        $periods = $this->builder->build('2026-03-15', 6, 6, '333.33');

        $this->assertCount(1, $periods);
        $this->assertSame('1999.98', $periods[0]['amount']);
        $this->assertSame('2026-09-14', $periods[0]['ends_on']);
    }

    public function test_the_periods_add_up_to_the_monthly_amount_times_the_duration(): void
    {
        $total = array_reduce($this->builder->build('2026-01-01', 12, 1, '123.45'), fn ($sum, $p) => bcadd($sum, $p['amount'], 2), '0');

        $this->assertSame(bcmul('123.45', '12', 2), $total);
    }

    public function test_valid_cycles(): void
    {
        $this->assertTrue(LeaseScheduleBuilder::isValidCycle(12, 1));
        $this->assertTrue(LeaseScheduleBuilder::isValidCycle(12, 3));
        $this->assertTrue(LeaseScheduleBuilder::isValidCycle(12, 6));
        $this->assertTrue(LeaseScheduleBuilder::isValidCycle(12, 12));
        $this->assertTrue(LeaseScheduleBuilder::isValidCycle(7, 7));
        $this->assertFalse(LeaseScheduleBuilder::isValidCycle(10, 3));
        $this->assertFalse(LeaseScheduleBuilder::isValidCycle(12, 4));
        $this->assertFalse(LeaseScheduleBuilder::isValidCycle(12, 0));
        $this->assertFalse(LeaseScheduleBuilder::isValidCycle(0, 1));
    }

    public function test_an_invalid_cycle_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder->build('2026-01-01', 10, 3, '100.00');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Leasing/LeaseScheduleBuilderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the builder**

`app/Domain/Leasing/Services/LeaseScheduleBuilder.php`:

```php
<?php

namespace App\Domain\Leasing\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A lease's billing periods, computed without the database. Period k starts
 * k × cycle months after the lease start — always from the start date,
 * never chained from the previous period, so a start on the 31st returns
 * to the 31st after February instead of staying on the 28th.
 */
final class LeaseScheduleBuilder
{
    private const CYCLES = [1, 3, 6];

    /** Monthly, quarterly, half-yearly, or one payment for the whole term, dividing it exactly. */
    public static function isValidCycle(int $durationMonths, int $cycleMonths): bool
    {
        return $durationMonths >= 1
            && $cycleMonths >= 1
            && (in_array($cycleMonths, self::CYCLES, true) || $cycleMonths === $durationMonths)
            && $durationMonths % $cycleMonths === 0;
    }

    /** The lease's last day (Y-m-d). */
    public function endsOn(string $startsOn, int $durationMonths): string
    {
        return $this->monthsAfter($startsOn, $durationMonths)->subDay()->toDateString();
    }

    /** @return list<array{sequence: int, starts_on: string, ends_on: string, amount: string}> */
    public function build(string $startsOn, int $durationMonths, int $cycleMonths, string $monthlyAmount): array
    {
        if (! self::isValidCycle($durationMonths, $cycleMonths)) {
            throw new InvalidArgumentException("A {$cycleMonths}-month cycle does not divide a {$durationMonths}-month lease.");
        }

        $amount = bcmul($monthlyAmount, (string) $cycleMonths, 2);
        $periods = [];

        for ($k = 0, $count = intdiv($durationMonths, $cycleMonths); $k < $count; $k++) {
            $periods[] = [
                'sequence' => $k + 1,
                'starts_on' => $this->monthsAfter($startsOn, $k * $cycleMonths)->toDateString(),
                'ends_on' => $this->monthsAfter($startsOn, ($k + 1) * $cycleMonths)->subDay()->toDateString(),
                'amount' => $amount,
            ];
        }

        return $periods;
    }

    private function monthsAfter(string $startsOn, int $months): CarbonImmutable
    {
        return CarbonImmutable::parse($startsOn)->startOfDay()->addMonthsNoOverflow($months);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Leasing/LeaseScheduleBuilderTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Pint and commit**

```bash
./vendor/bin/pint app/Domain/Leasing/Services/LeaseScheduleBuilder.php tests/Unit/Domain/Leasing/LeaseScheduleBuilderTest.php
git add app/Domain/Leasing/Services/LeaseScheduleBuilder.php tests/Unit/Domain/Leasing/LeaseScheduleBuilderTest.php
git commit -m "feat(leasing): period schedule builder

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Fixed-amount invoices for lease periods

**Files:**
- Modify: `app/Domain/Invoicing/Data/InvoiceDraft.php`, `app/Domain/Invoicing/Data/InvoiceQuote.php`, `app/Domain/Invoicing/Services/InvoiceIssuer.php`, `app/Http/Resources/InvoiceResource.php`
- Create: `tests/Feature/Leasing/LeasingTestCase.php`, `tests/Concerns/BuildsOfficeLeases.php`, `tests/Feature/Leasing/LeasePeriodInvoiceTest.php`

**Interfaces:**
- Consumes: `OfficeLease`, `OfficeLeasePeriod`, `LeasePeriodStatus` (Task 1).
- Produces: `InvoiceDraft` gains `?int $points` and optional `?string $fixedAmount = null, ?string $fixedCurrency = null` (named args; existing callers unchanged). `InvoiceQuote::$points` becomes `?int`. `InvoiceIssuer::issue(InvoiceDraft, InvoiceTerms, ?User $issuer)` (issuer nullable). New `InvoiceIssuer::issueForLeasePeriod(OfficeLeasePeriod $period, ?User $issuer): Invoice` — issues and marks the period `invoiced`; the caller owns the transaction and the row lock.
- Produces (tests): `Tests\Concerns\BuildsOfficeLeases` with `leasableOffice(?Branch $branch = null, bool $withLock = true): Space`, `leasePayload(Space $space, array $overrides = []): array`, `fakeTtlock(): void`; `Tests\Feature\Leasing\LeasingTestCase` (extends `InvoicingTestCase`, uses the trait).

- [ ] **Step 1: Write the test helpers**

`tests/Concerns/BuildsOfficeLeases.php`:

```php
<?php

namespace Tests\Concerns;

use App\Domain\Finance\Models\Currency;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\Http;

/** Offices, lease request bodies and a fake TTLock, for any test that contracts a lease. */
trait BuildsOfficeLeases
{
    /** An active private office in $branch (a new branch when null), with a lock unless told otherwise. */
    protected function leasableOffice(?Branch $branch = null, bool $withLock = true): Space
    {
        $branch ??= Branch::factory()->create();
        $space = Space::factory()->ofType(SpaceType::Business)->create([
            'building_id' => Building::factory()->create(['branch_id' => $branch->id])->id,
        ]);

        if ($withLock) {
            Device::factory()->create(['branch_id' => $branch->id, 'space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);
        }

        return $space;
    }

    /**
     * The lease fields of a contract body: a year, billed quarterly, 500 USD a
     * month, starting tomorrow (so no lock call is made unless a test asks).
     */
    protected function leasePayload(Space $space, array $overrides = []): array
    {
        if (! Currency::query()->where('code', 'USD')->exists()) {
            $this->seed(CurrencySeeder::class);
        }

        return [
            'space_id' => $space->id,
            'starts_on' => now()->addDay()->toDateString(),
            'duration_months' => 12,
            'billing_cycle_months' => 3,
            'monthly_amount' => '500.00',
            'currency' => 'USD',
            ...$overrides,
        ];
    }

    /** TTLock answering every call: passcode 112233 / id 5 on creation, success on delete. */
    protected function fakeTtlock(): void
    {
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000], 200),
            'api.sciener.test/v3/keyboardPwd/get' => Http::response(['keyboardPwd' => '112233', 'keyboardPwdId' => 5], 200),
            'api.sciener.test/v3/keyboardPwd/delete' => Http::response(['errcode' => 0, 'errmsg' => ''], 200),
        ]);
    }
}
```

`tests/Feature/Leasing/LeasingTestCase.php`:

```php
<?php

namespace Tests\Feature\Leasing;

use Tests\Concerns\BuildsOfficeLeases;
use Tests\Feature\Invoicing\InvoicingTestCase;

/** Roles, permissions, currencies and settings (InvoicingTestCase), plus offices and a fake TTLock. */
abstract class LeasingTestCase extends InvoicingTestCase
{
    use BuildsOfficeLeases;
}
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Leasing/LeasePeriodInvoiceTest.php`:

```php
<?php

namespace Tests\Feature\Leasing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Services\InvoiceIssuer;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Domain\Invoicing\Services\InvoicePaymentService;

class LeasePeriodInvoiceTest extends LeasingTestCase
{
    private function period(array $leaseAttributes = [], array $periodAttributes = []): OfficeLeasePeriod
    {
        $lease = OfficeLease::factory()->create($leaseAttributes);

        return OfficeLeasePeriod::factory()->create([
            'office_lease_id' => $lease->id,
            'starts_on' => '2026-04-01',
            'ends_on' => '2026-06-30',
            'amount' => '1500.00',
            ...$periodAttributes,
        ]);
    }

    public function test_a_period_invoice_is_the_fixed_amount_without_points(): void
    {
        $period = $this->period();

        $invoice = app(InvoiceIssuer::class)->issueForLeasePeriod($period, null);

        $this->assertNull($invoice->points);
        $this->assertSame('1500.00', (string) $invoice->amount);
        $this->assertSame('1500.00', (string) $invoice->computed_amount);
        $this->assertSame('USD', $invoice->currency);
        $this->assertSame(0, bccomp((string) $invoice->exchange_rate_to_base, '1', 10));
        $this->assertSame($period->id, $invoice->office_lease_period_id);
        $this->assertNull($invoice->provider_id);
        $this->assertSame(Payer::Company, $invoice->billed_to);
        $this->assertNull($invoice->issued_by);
        $this->assertSame(now()->addDays(15)->toDateString(), $invoice->due_at->toDateString());
        $this->assertStringStartsWith('Office lease '.$period->lease->reference.':', $invoice->description);
        $this->assertStringContainsString('2026-04-01 – 2026-06-30', $invoice->description);
        $this->assertSame(LeasePeriodStatus::Invoiced, $period->fresh()->status);
    }

    public function test_a_provider_lease_bills_the_chosen_payer_on_the_provider_terms(): void
    {
        $provider = Provider::factory()->create(['payment_terms_days' => 30]);
        $period = $this->period(['provider_id' => $provider->id, 'billed_to' => Payer::Provider]);

        $invoice = app(InvoiceIssuer::class)->issueForLeasePeriod($period, null);

        $this->assertSame($provider->id, $invoice->provider_id);
        $this->assertSame(Payer::Provider, $invoice->billed_to);
        $this->assertSame(now()->addDays(30)->toDateString(), $invoice->due_at->toDateString());
    }

    public function test_a_currency_without_a_rate_is_refused(): void
    {
        $period = $this->period(['currency' => 'SYP', 'monthly_amount' => '5000000.00'], ['amount' => '15000000.00']);

        $this->expectException(InvoicingException::class);

        try {
            app(InvoiceIssuer::class)->issueForLeasePeriod($period, null);
        } finally {
            $this->assertSame(LeasePeriodStatus::Upcoming, $period->fresh()->status);
        }
    }

    public function test_a_period_invoice_takes_partial_payments_and_cancels_like_any_invoice(): void
    {
        $invoice = app(InvoiceIssuer::class)->issueForLeasePeriod($this->period(), null);
        $admin = $this->actingAsAdmin();
        $payments = app(InvoicePaymentService::class);

        $payment = $payments->record($invoice, [
            'amount' => '500.00', 'currency' => 'USD', 'method' => 'bank_transfer',
            'receipt_number' => 'TRF-1', 'paid_at' => now()->toDateString(),
        ], $admin);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        $payments->void($payment, 'Wrong invoice', $admin);
        $payments->cancel($invoice->fresh(), 'Issued in error', $admin);

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame(LeasePeriodStatus::Invoiced, $invoice->leasePeriod->status);
    }
}
```

`InvoicePaymentService` signatures: `record(Invoice $invoice, array $data, User $by)`, `void(InvoicePayment $payment, string $reason, User $by)`, `cancel(Invoice $invoice, string $reason, User $by)`. Reorder the imports alphabetically when Pint asks.

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leasing/LeasePeriodInvoiceTest.php`
Expected: FAIL — `Call to undefined method ...InvoiceIssuer::issueForLeasePeriod()`.

- [ ] **Step 4: Make the draft and the quote point-optional**

`app/Domain/Invoicing/Data/InvoiceDraft.php` — replace the class body:

```php
/**
 * What an invoice is for, whatever produced it. Either `points` (priced in
 * money at issue — plan assignments, top-ups) or a `fixedAmount` in
 * `fixedCurrency` (office lease periods). `$source` is the source's own FK
 * column: ['provider_request_id' => 7] or ['office_lease_period_id' => 3].
 */
final class InvoiceDraft
{
    /** @param  array<string, int>  $source */
    public function __construct(
        public readonly int $companyId,
        public readonly ?int $providerId,
        public readonly Payer $billedTo,
        public readonly ?int $points,
        public readonly string $description,
        public readonly array $source,
        public readonly ?string $fixedAmount = null,
        public readonly ?string $fixedCurrency = null,
    ) {}

    public function isFixed(): bool
    {
        return $this->fixedAmount !== null;
    }
}
```

`app/Domain/Invoicing/Data/InvoiceQuote.php` — change `public readonly int $points,` to `public readonly ?int $points,` and the `toArray()` docblock to `@return array<string, int|string|null>`.

- [ ] **Step 5: Teach the issuer fixed amounts and lease periods**

`app/Domain/Invoicing/Services/InvoiceIssuer.php`:

Add imports `App\Domain\Leasing\Enums\LeasePeriodStatus` and `App\Domain\Leasing\Models\OfficeLeasePeriod`.

Replace `quote()` with:

```php
    public function quote(InvoiceDraft $draft, InvoiceTerms $terms): InvoiceQuote
    {
        $currency = $draft->isFixed()
            ? $draft->fixedCurrency
            : ($terms->currency ?? $this->currencies->baseCurrencyCode());

        if ($draft->isFixed()) {
            $computed = $draft->fixedAmount;
        } else {
            try {
                $computed = $this->money->pointsToAmount($draft->points, $currency);
            } catch (PaymentPricingException) {
                throw new InvoicingException('api.invoice.no_exchange_rate', 422, ['currency' => $currency]);
            }
        }

        $rate = $currency === $this->currencies->baseCurrencyCode()
            ? '1'
            : $this->currencies->rateToBase($currency);

        // A fixed amount needs no conversion, but the rate is stored with
        // every invoice for reference — without one there is nothing to store.
        if ($rate === null) {
            throw new InvoicingException('api.invoice.no_exchange_rate', 422, ['currency' => $currency]);
        }

        return new InvoiceQuote(
            points: $draft->points,
            currency: $currency,
            computedAmount: $computed,
            amount: $draft->isFixed() ? $computed : ($terms->amount ?? $computed),
            exchangeRateToBase: (string) $rate,
            issuedAt: now()->toDateString(),
            dueAt: $terms->dueAt ?? now()->addDays($this->dueDays($draft->providerId))->toDateString(),
        );
    }
```

In `issue()`, change the signature to `public function issue(InvoiceDraft $draft, InvoiceTerms $terms, ?User $reviewer): Invoice`, the `'issued_by' => $reviewer->id,` line to `'issued_by' => $reviewer?->id,`, and `activity()->causedBy($reviewer)` stays (a null causer logs a system action).

Add after `issueForProviderRequest()`:

```php
    /**
     * Bills one lease period at its fixed amount and marks it invoiced. The
     * caller holds the period's row lock inside its transaction
     * (OfficeLeaseService::contract(), LeaseDailyRunner); $issuer is null
     * when the daily run issues it.
     */
    public function issueForLeasePeriod(OfficeLeasePeriod $period, ?User $issuer): Invoice
    {
        $lease = $period->lease()->with('space')->firstOrFail();

        $invoice = $this->issue(new InvoiceDraft(
            companyId: $lease->company_id,
            providerId: $lease->provider_id,
            billedTo: $lease->billed_to,
            points: null,
            description: sprintf(
                'Office lease %s: %s, %s – %s',
                $lease->reference,
                $lease->space->displayTitle('en'),
                $period->starts_on->toDateString(),
                $period->ends_on->toDateString(),
            ),
            source: ['office_lease_period_id' => $period->id],
            fixedAmount: (string) $period->amount,
            fixedCurrency: $lease->currency,
        ), new InvoiceTerms, $issuer);

        $period->update(['status' => LeasePeriodStatus::Invoiced]);

        return $invoice;
    }
```

Update the class docblock's last sentence to: `The draft is source-agnostic; each source gets a thin wrapper (issueForProviderRequest, issueForLeasePeriod).`

`app/Http/Resources/InvoiceResource.php` — after `'provider_request_id' => $this->provider_request_id,` add `'office_lease_period_id' => $this->office_lease_period_id,`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Leasing/LeasePeriodInvoiceTest.php tests/Feature/Invoicing`
Expected: PASS — the Phase 2 invoicing tests are unchanged.

- [ ] **Step 7: Pint and commit**

```bash
./vendor/bin/pint app/Domain/Invoicing/Data/InvoiceDraft.php app/Domain/Invoicing/Data/InvoiceQuote.php app/Domain/Invoicing/Services/InvoiceIssuer.php app/Http/Resources/InvoiceResource.php tests/Concerns/BuildsOfficeLeases.php tests/Feature/Leasing/LeasingTestCase.php tests/Feature/Leasing/LeasePeriodInvoiceTest.php
git add app/Domain/Invoicing/Data/InvoiceDraft.php app/Domain/Invoicing/Data/InvoiceQuote.php app/Domain/Invoicing/Services/InvoiceIssuer.php app/Http/Resources/InvoiceResource.php tests/Concerns/BuildsOfficeLeases.php tests/Feature/Leasing/LeasingTestCase.php tests/Feature/Leasing/LeasePeriodInvoiceTest.php
git commit -m "feat(invoicing): fixed-amount invoices for office lease periods

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: The office door for a lease

**Files:**
- Modify: `app/Domain/Access/Services/PasscodeIssuanceService.php`
- Create: `tests/Feature/Leasing/LeaseDoorAccessTest.php`

**Interfaces:**
- Consumes: `OfficeLease::accessEndsOn()`, `AccessGrant::lease()`, `office_lease_id` (Task 1); `leasableOffice()`, `fakeTtlock()` (Task 3).
- Produces: `PasscodeIssuanceService::issueForLease(OfficeLease $lease): AccessGrant` (activated at once, `expires_at` = end of `accessEndsOn()`), `revokeForLease(OfficeLease $lease): void`, `hasLock(Space $space): bool`. The unused `issueForTenancy()` is removed.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leasing/LeaseDoorAccessTest.php`:

```php
<?php

namespace Tests\Feature\Leasing;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Models\OfficeLease;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

class LeaseDoorAccessTest extends LeasingTestCase
{
    private function lease(array $attributes = []): OfficeLease
    {
        return OfficeLease::factory()->create([
            'space_id' => $this->leasableOffice()->id,
            'starts_on' => now()->toDateString(),
            'duration_months' => 3,
            'billing_cycle_months' => 3,
            'ends_on' => now()->addMonthsNoOverflow(3)->subDay()->toDateString(),
            ...$attributes,
        ]);
    }

    public function test_a_lease_grant_is_active_at_once_and_ends_with_the_lease(): void
    {
        $this->fakeTtlock();
        $lease = $this->lease();

        $grant = app(PasscodeIssuanceService::class)->issueForLease($lease);

        $this->assertSame(AccessGrantStatus::Activated, $grant->status);
        $this->assertSame(AccessSourceType::Tenancy, $grant->source_type);
        $this->assertSame($lease->id, $grant->office_lease_id);
        $this->assertSame($lease->company_id, $grant->grantee_id);
        $this->assertSame($lease->ends_on->endOfDay()->timestamp, $grant->expires_at->timestamp);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/keyboardPwd/get')
            && (int) $request['endDate'] === $lease->ends_on->endOfDay()->getTimestamp() * 1000);
    }

    public function test_expiring_unactivated_grants_leaves_lease_grants_alone(): void
    {
        $this->fakeTtlock();
        $grant = app(PasscodeIssuanceService::class)->issueForLease($this->lease());
        $this->travel(2)->days();

        app(PasscodeIssuanceService::class)->expireOverdue();

        $this->assertSame(AccessGrantStatus::Activated, $grant->fresh()->status);
    }

    public function test_a_member_with_door_access_opens_the_office_and_one_without_cannot(): void
    {
        $this->fakeTtlock();
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200)]);
        $lease = $this->lease();
        app(PasscodeIssuanceService::class)->issueForLease($lease);
        $lock = $lease->space->devices()->where('type', 'lock')->first();
        $lock->update(['qr_value' => 'office-sticker']);

        $allowed = User::factory()->create();
        $allowed->assignRole('member');
        $lease->company->members()->attach($allowed->id, ['door_access_enabled' => true]);
        $refused = User::factory()->create();
        $refused->assignRole('member');
        $lease->company->members()->attach($refused->id, ['door_access_enabled' => false]);

        Sanctum::actingAs($allowed, ['member-app']);
        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'office-sticker'])->assertOk();

        Sanctum::actingAs($refused, ['member-app']);
        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'office-sticker'])->assertForbidden();
    }

    public function test_revoking_a_lease_deletes_its_passcode(): void
    {
        $this->fakeTtlock();
        $lease = $this->lease();
        $grant = app(PasscodeIssuanceService::class)->issueForLease($lease);

        app(PasscodeIssuanceService::class)->revokeForLease($lease);

        $this->assertSame(AccessGrantStatus::Revoked, $grant->fresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/keyboardPwd/delete'));
    }

    public function test_has_lock(): void
    {
        $issuance = app(PasscodeIssuanceService::class);

        $this->assertTrue($issuance->hasLock($this->leasableOffice()));
        $this->assertFalse($issuance->hasLock($this->leasableOffice(withLock: false)));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leasing/LeaseDoorAccessTest.php`
Expected: FAIL — `Call to undefined method ...PasscodeIssuanceService::issueForLease()`.

- [ ] **Step 3: Replace `issueForTenancy()`**

`app/Domain/Access/Services/PasscodeIssuanceService.php` — import `App\Domain\Leasing\Models\OfficeLease`; delete `issueForTenancy()` (no caller: `grep -rn issueForTenancy app tests` must print nothing afterwards); add in its place:

```php
    /**
     * The office lock for a lease's company: activated at once (there is no
     * kiosk arrival for a tenancy, so expireOverdue() — Issued grants only —
     * never touches it) and live through the lease's last access day, on
     * the keypad and in our own expires_at alike.
     */
    public function issueForLease(OfficeLease $lease): AccessGrant
    {
        $lock = $this->lockFor($lease->space);
        $issuedAt = now();
        $endsAt = $lease->accessEndsOn()->endOfDay();

        $vendor = $this->ttlock->addPeriodPasscode($lock, $issuedAt, $endsAt);

        return AccessGrant::create([
            'lock_id' => $lock->id,
            'grantee_type' => OwnerType::Company,
            'grantee_id' => $lease->company_id,
            'source_type' => AccessSourceType::Tenancy,
            'source_id' => null,
            'office_lease_id' => $lease->id,
            'allocation_model' => AllocationModel::Tenancy,
            'passcode_type' => PasscodeType::Period,
            'passcode_value' => $vendor['passcode'],
            'vendor_keyboard_pwd_id' => $vendor['vendor_passcode_id'],
            'issued_at' => $issuedAt,
            'must_activate_by' => $issuedAt,
            'activated_at' => $issuedAt,
            'expires_at' => $endsAt,
            'status' => AccessGrantStatus::Activated,
        ]);
    }

    /** Ends every live grant of the lease, on the lock too. */
    public function revokeForLease(OfficeLease $lease): void
    {
        AccessGrant::query()
            ->where('office_lease_id', $lease->id)
            ->whereIn('status', [AccessGrantStatus::Issued, AccessGrantStatus::Activated])
            ->with('lock')
            ->get()
            ->each(fn (AccessGrant $grant) => $this->revoke($grant, $grant->lock));
    }

    public function hasLock(Space $space): bool
    {
        return $space->devices()->where('type', 'lock')->exists();
    }
```

Update the class docblock's first line to `issue (booking or lease)`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Leasing/LeaseDoorAccessTest.php tests/Unit/Domain/Access tests/Feature/Access tests/Guards/LockCredentialsNeverReachMemberTest.php`
Expected: PASS.

- [ ] **Step 5: Pint and commit**

```bash
./vendor/bin/pint app/Domain/Access/Services/PasscodeIssuanceService.php tests/Feature/Leasing/LeaseDoorAccessTest.php
git add app/Domain/Access/Services/PasscodeIssuanceService.php tests/Feature/Leasing/LeaseDoorAccessTest.php
git commit -m "feat(access): lease grants on the office lock, replacing the unused tenancy issuer

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `OfficeLeaseService::contract()` — from a quoted request to a lease

**Files:**
- Create: `app/Domain/Leasing/Data/LeaseTerms.php`, `app/Domain/Leasing/Exceptions/LeasingException.php`, `app/Domain/Leasing/Services/OfficeLeaseService.php`, `tests/Feature/Leasing/ContractLeaseTest.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php`

**Interfaces:**
- Consumes: Tasks 1–4 (`OfficeLease::overlapping()`, `LeaseScheduleBuilder`, `InvoiceIssuer::issueForLeasePeriod()`, `PasscodeIssuanceService::issueForLease()|hasLock()`).
- Produces: `LeaseTerms` (`spaceId`, `startsOn`, `durationMonths`, `billingCycleMonths`, `monthlyAmount`, `currency`, `contractRef`, `?Payer billedTo`; `LeaseTerms::fromArray(array $validated)`), `LeasingException(string $messageKey, int $status = 422, array $params = [])`.
- Produces: `OfficeLeaseService::contract(PrivateOfficeRequest $request, LeaseTerms $terms, User $actor, ?string $newCompanyName = null): OfficeLease` (throws `LeasingException`, or `InvoicingException` for a currency with no rate) and `openDoor(OfficeLease $lease): bool` (never throws). Task 7 adds `terminate()` and `closeDoor()` to the same class.
- Produces: the whole `lease` lang group (Tasks 6–7 use the rest of its keys).

- [ ] **Step 1: Add the lang group**

At the end of `lang/en/api.php`, before the closing `];`:

```php
    'lease' => [
        'request_not_quoted' => 'This request must be quoted before a lease can be made from it.',
        'request_has_no_company' => 'This request is for a new company; create the company with its lease instead.',
        'request_is_for_existing_company' => 'This request is for an existing company; create the lease from the request instead.',
        'company_inactive' => 'The company is not active.',
        'not_an_office' => 'Only a private office can be leased.',
        'office_unavailable' => 'This office is under maintenance or out of service.',
        'office_taken' => 'This office is already leased for part of these dates.',
        'payer_required' => 'Choose who pays: the provider or the company.',
        'invalid_cycle' => 'The billing period must be 1, 3 or 6 months, or the whole term, and divide the term exactly.',
        'not_active' => 'Only an active lease can be terminated.',
        'termination_out_of_range' => 'The termination date must fall between the lease start and end dates.',
        'terminated' => 'The lease was terminated.',
    ],
```

At the end of `lang/ar/api.php`, before the closing `];`:

```php
    'lease' => [
        'request_not_quoted' => 'يجب أن يكون الطلب في مرحلة عرض السعر قبل إنشاء عقد منه.',
        'request_has_no_company' => 'هذا الطلب لشركة جديدة؛ أنشئ الشركة مع عقدها.',
        'request_is_for_existing_company' => 'هذا الطلب لشركة موجودة؛ أنشئ العقد من الطلب.',
        'company_inactive' => 'الشركة غير نشطة.',
        'not_an_office' => 'لا يمكن تأجير إلا مكتب خاص.',
        'office_unavailable' => 'هذا المكتب في الصيانة أو خارج الخدمة.',
        'office_taken' => 'هذا المكتب مؤجَّر في جزء من هذه التواريخ.',
        'payer_required' => 'اختر من يدفع: المزوّد أو الشركة.',
        'invalid_cycle' => 'فترة الدفع يجب أن تكون شهراً أو 3 أو 6 أشهر أو كامل المدة، وأن تقسم المدة بالتساوي.',
        'not_active' => 'لا يمكن إنهاء إلا عقد سارٍ.',
        'termination_out_of_range' => 'تاريخ الإنهاء يجب أن يقع بين تاريخ بداية العقد ونهايته.',
        'terminated' => 'تم إنهاء العقد.',
    ],
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Leasing/ContractLeaseTest.php`:

```php
<?php

namespace Tests\Feature\Leasing;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Exceptions\LeasingException;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use Illuminate\Support\Facades\Http;

class ContractLeaseTest extends LeasingTestCase
{
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actor = $this->actingAsAdmin();
    }

    private function terms(Space $space, array $overrides = []): LeaseTerms
    {
        return LeaseTerms::fromArray([...$this->leasePayload($space), 'contract_ref' => 'C-2001', ...$overrides]);
    }

    private function service(): OfficeLeaseService
    {
        return app(OfficeLeaseService::class);
    }

    /** Runs $action and asserts it is refused with $key and $status. */
    private function assertRefused(callable $action, string $key, int $status = 422): void
    {
        try {
            $action();
            $this->fail("Expected the lease to be refused with {$key}.");
        } catch (LeasingException $e) {
            $this->assertSame($key, $e->messageKey);
            $this->assertSame($status, $e->status);
        }
    }

    public function test_a_new_company_gets_its_lease_periods_and_first_invoice(): void
    {
        $branch = Branch::factory()->create();
        $office = $this->leasableOffice($branch);
        $request = PrivateOfficeRequest::factory()->quoted()->create();

        $lease = $this->service()->contract($request, $this->terms($office), $this->actor, 'Acme LLC');

        $company = $lease->company;
        $this->assertSame('Acme LLC', $company->legal_name);
        $this->assertSame($branch->id, $company->branch_id);
        $this->assertSame('C-2001', $company->contract_ref);
        $this->assertSame($request->id, $company->created_from_request_id);
        $this->assertNull($company->provider_id);
        $this->assertTrue(Wallet::query()->where('owner_type', OwnerType::Company)->where('owner_id', $company->id)->exists());

        $this->assertSame(Payer::Company, $lease->billed_to);
        $this->assertSame($this->actor->id, $lease->created_by);
        $this->assertSame(now()->addDay()->addMonthsNoOverflow(12)->subDay()->toDateString(), $lease->ends_on->toDateString());
        $this->assertSame(
            [LeasePeriodStatus::Invoiced, LeasePeriodStatus::Upcoming, LeasePeriodStatus::Upcoming, LeasePeriodStatus::Upcoming],
            $lease->periods->pluck('status')->all(),
        );
        $invoice = $lease->periods->first()->invoice;
        $this->assertSame('1500.00', (string) $invoice->amount);
        $this->assertSame($this->actor->id, $invoice->issued_by);

        $request->refresh();
        $this->assertSame(PrivateOfficeRequestStatus::Contracted, $request->status);
        $this->assertSame('C-2001', $request->contract_ref);
        $this->assertSame($company->id, $request->converted_company_id);
        $this->assertSame(0, $lease->accessGrants()->count(), 'A lease starting tomorrow opens no door today.');
    }

    public function test_an_existing_company_gets_another_office_in_another_branch(): void
    {
        $company = Company::factory()->create();
        $office = $this->leasableOffice(Branch::factory()->create());
        $request = PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id]);

        $lease = $this->service()->contract($request, $this->terms($office), $this->actor);

        $this->assertSame($company->id, $lease->company_id);
        $this->assertSame(1, Company::query()->count());
        $this->assertSame($company->id, $request->fresh()->converted_company_id);
    }

    public function test_a_provider_company_bills_the_request_payer_unless_staff_choose(): void
    {
        $provider = Provider::factory()->create();
        $company = Company::factory()->create(['provider_id' => $provider->id]);

        $asRequested = $this->service()->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id, 'provider_id' => $provider->id, 'payer' => Payer::Provider]),
            $this->terms($this->leasableOffice()),
            $this->actor,
        );
        $overridden = $this->service()->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id, 'provider_id' => $provider->id, 'payer' => Payer::Provider]),
            $this->terms($this->leasableOffice(), ['billed_to' => 'company']),
            $this->actor,
        );

        $this->assertSame(Payer::Provider, $asRequested->billed_to);
        $this->assertSame($provider->id, $asRequested->provider_id);
        $this->assertSame(Payer::Provider, $asRequested->periods->first()->invoice->billed_to);
        $this->assertSame(Payer::Company, $overridden->billed_to);
    }

    public function test_a_provider_company_with_no_payer_anywhere_is_refused(): void
    {
        $provider = Provider::factory()->create();
        $request = PrivateOfficeRequest::factory()->quoted()->create(['provider_id' => $provider->id]);

        $this->assertRefused(
            fn () => $this->service()->contract($request, $this->terms($this->leasableOffice()), $this->actor, 'Client Co'),
            'api.lease.payer_required',
        );
        $this->assertSame(0, Company::query()->count());
    }

    public function test_a_back_dated_lease_invoices_every_started_period_and_opens_the_door(): void
    {
        $this->fakeTtlock();
        $office = $this->leasableOffice();
        $request = PrivateOfficeRequest::factory()->quoted()->create();

        $lease = $this->service()->contract($request, $this->terms($office, [
            'starts_on' => now()->subMonthsNoOverflow(7)->toDateString(),
            'billing_cycle_months' => 1,
        ]), $this->actor, 'Late LLC');

        $this->assertSame(8, $lease->periods()->where('status', LeasePeriodStatus::Invoiced)->count());
        $this->assertSame(4, $lease->periods()->where('status', LeasePeriodStatus::Upcoming)->count());
        $this->assertSame(AccessGrantStatus::Activated, $lease->accessGrants()->sole()->status);
    }

    public function test_a_lease_on_an_office_without_a_lock_is_kept_without_a_door(): void
    {
        $request = PrivateOfficeRequest::factory()->quoted()->create();

        $lease = $this->service()->contract($request, $this->terms($this->leasableOffice(withLock: false), [
            'starts_on' => now()->toDateString(),
        ]), $this->actor, 'No Lock LLC');

        $this->assertTrue($lease->exists);
        $this->assertSame(0, $lease->accessGrants()->count());
    }

    public function test_a_failing_lock_service_does_not_undo_the_lease(): void
    {
        Http::fake(['api.sciener.test/v3/keyboardPwd/get' => Http::response('', 500)]);
        $this->fakeTtlock();
        $request = PrivateOfficeRequest::factory()->quoted()->create();

        $lease = $this->service()->contract($request, $this->terms($this->leasableOffice(), [
            'starts_on' => now()->toDateString(),
        ]), $this->actor, 'Offline LLC');

        $this->assertTrue($lease->exists);
        $this->assertSame(0, $lease->accessGrants()->count());
    }

    public function test_an_office_taken_for_part_of_the_dates_is_refused(): void
    {
        $office = $this->leasableOffice();
        $this->service()->contract(PrivateOfficeRequest::factory()->quoted()->create(), $this->terms($office), $this->actor, 'First LLC');

        $this->assertRefused(
            fn () => $this->service()->contract(
                PrivateOfficeRequest::factory()->quoted()->create(),
                $this->terms($office, ['starts_on' => now()->addMonthsNoOverflow(11)->toDateString()]),
                $this->actor,
                'Second LLC',
            ),
            'api.lease.office_taken',
            409,
        );
        $this->assertSame(1, OfficeLease::query()->count());
        $this->assertSame(1, Company::query()->count());
    }

    public function test_the_office_is_free_again_the_day_after_a_lease_ends(): void
    {
        $office = $this->leasableOffice();
        $first = $this->service()->contract(PrivateOfficeRequest::factory()->quoted()->create(), $this->terms($office), $this->actor, 'First LLC');

        $second = $this->service()->contract(
            PrivateOfficeRequest::factory()->quoted()->create(),
            $this->terms($office, ['starts_on' => $first->ends_on->addDay()->toDateString()]),
            $this->actor,
            'Second LLC',
        );

        $this->assertTrue($second->exists);
    }

    public function test_refusals_save_nothing(): void
    {
        $quoted = fn (array $attributes = []) => PrivateOfficeRequest::factory()->quoted()->create($attributes);

        $this->assertRefused(fn () => $this->service()->contract(PrivateOfficeRequest::factory()->create(), $this->terms($this->leasableOffice()), $this->actor, 'X'), 'api.lease.request_not_quoted');

        $coSpace = Space::factory()->ofType(SpaceType::CoSpace)->create();
        $this->assertRefused(fn () => $this->service()->contract($quoted(), $this->terms($coSpace), $this->actor, 'X'), 'api.lease.not_an_office');

        $maintenance = $this->leasableOffice();
        $maintenance->update(['status' => OperationalStatus::Maintenance]);
        $this->assertRefused(fn () => $this->service()->contract($quoted(), $this->terms($maintenance), $this->actor, 'X'), 'api.lease.office_unavailable');

        $company = Company::factory()->create();
        $this->assertRefused(fn () => $this->service()->contract($quoted(['company_id' => $company->id]), $this->terms($this->leasableOffice()), $this->actor, 'X'), 'api.lease.request_is_for_existing_company');
        $this->assertRefused(fn () => $this->service()->contract($quoted(), $this->terms($this->leasableOffice()), $this->actor), 'api.lease.request_has_no_company');

        $company->update(['status' => CompanyStatus::Inactive]);
        $this->assertRefused(fn () => $this->service()->contract($quoted(['company_id' => $company->id]), $this->terms($this->leasableOffice()), $this->actor), 'api.lease.company_inactive');

        $this->assertSame(0, OfficeLease::query()->count());
        $this->assertSame(1, Company::query()->count());
    }

    public function test_a_currency_without_a_rate_rolls_everything_back(): void
    {
        $request = PrivateOfficeRequest::factory()->quoted()->create();

        try {
            $this->service()->contract($request, $this->terms($this->leasableOffice(), ['currency' => 'SYP', 'monthly_amount' => '5000000']), $this->actor, 'Rate LLC');
            $this->fail('Expected no_exchange_rate.');
        } catch (InvoicingException $e) {
            $this->assertSame('api.invoice.no_exchange_rate', $e->messageKey);
        }

        $this->assertSame(0, OfficeLease::query()->count());
        $this->assertSame(0, Company::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(PrivateOfficeRequestStatus::Quoted, $request->fresh()->status);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leasing/ContractLeaseTest.php`
Expected: FAIL — `Class "App\Domain\Leasing\Data\LeaseTerms" not found`.

- [ ] **Step 4: Write `LeaseTerms` and `LeasingException`**

`app/Domain/Leasing/Data/LeaseTerms.php`:

```php
<?php

namespace App\Domain\Leasing\Data;

use App\Domain\Providers\Enums\Payer;

/** What staff agreed for a lease, as validated by ValidatesLeaseTerms. Dates are Y-m-d. */
final class LeaseTerms
{
    public function __construct(
        public readonly int $spaceId,
        public readonly string $startsOn,
        public readonly int $durationMonths,
        public readonly int $billingCycleMonths,
        public readonly string $monthlyAmount,
        public readonly string $currency,
        public readonly string $contractRef,
        public readonly ?Payer $billedTo = null,
    ) {}

    /** @param  array<string, mixed>  $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            spaceId: (int) $validated['space_id'],
            startsOn: (string) $validated['starts_on'],
            durationMonths: (int) $validated['duration_months'],
            billingCycleMonths: (int) $validated['billing_cycle_months'],
            monthlyAmount: (string) $validated['monthly_amount'],
            currency: (string) $validated['currency'],
            contractRef: (string) $validated['contract_ref'],
            billedTo: isset($validated['billed_to']) ? Payer::from($validated['billed_to']) : null,
        );
    }
}
```

`app/Domain/Leasing/Exceptions/LeasingException.php`:

```php
<?php

namespace App\Domain\Leasing\Exceptions;

use RuntimeException;

/** A lease action refused, with the message to show and its HTTP status. */
class LeasingException extends RuntimeException
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

- [ ] **Step 5: Write `OfficeLeaseService` (contract and openDoor)**

`app/Domain/Leasing/Services/OfficeLeaseService.php`:

```php
<?php

namespace App\Domain\Leasing\Services;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Services\InvoiceIssuer;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Exceptions\LeasingException;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Providers\Enums\Payer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place a lease is made, ended early, and given or denied its door
 * (docs/superpowers/specs/2026-10-10-office-leases-design.md).
 */
class OfficeLeaseService
{
    public function __construct(
        private readonly LeaseScheduleBuilder $schedule,
        private readonly InvoiceIssuer $invoices,
        private readonly PasscodeIssuanceService $passcodes,
    ) {}

    /**
     * Closes a quoted office request with a lease. $newCompanyName given: the
     * request is for a new company, created here with its wallet in the
     * office's branch; null: the request names an existing company. One
     * transaction — a refusal or a pricing failure saves nothing. Every
     * period already started (the first always) is invoiced now; the door
     * opens after commit, and a lock failure never undoes the lease.
     */
    public function contract(PrivateOfficeRequest $request, LeaseTerms $terms, User $actor, ?string $newCompanyName = null): OfficeLease
    {
        $lease = DB::transaction(function () use ($request, $terms, $actor, $newCompanyName) {
            $request = PrivateOfficeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== PrivateOfficeRequestStatus::Quoted) {
                throw new LeasingException('api.lease.request_not_quoted');
            }

            // Locking the office row serialises two contracts for the same
            // office: the second sees the first lease below and is refused.
            $space = Space::query()->with('building')->whereKey($terms->spaceId)->lockForUpdate()->firstOrFail();
            $this->assertLeasable($space);

            $endsOn = $this->schedule->endsOn($terms->startsOn, $terms->durationMonths);

            if (OfficeLease::query()->overlapping($space->id, $terms->startsOn, $endsOn)->exists()) {
                throw new LeasingException('api.lease.office_taken', 409);
            }

            $company = $newCompanyName === null
                ? $this->existingCompany($request)
                : $this->newCompany($request, $newCompanyName, $terms->contractRef, $space->building->branch_id);

            $lease = OfficeLease::create([
                'company_id' => $company->id,
                'space_id' => $space->id,
                'private_office_request_id' => $request->id,
                'provider_id' => $company->provider_id,
                'billed_to' => $this->payer($company, $request, $terms->billedTo),
                'currency' => $terms->currency,
                'monthly_amount' => $terms->monthlyAmount,
                'starts_on' => $terms->startsOn,
                'duration_months' => $terms->durationMonths,
                'billing_cycle_months' => $terms->billingCycleMonths,
                'ends_on' => $endsOn,
                'status' => OfficeLeaseStatus::Active,
                'created_by' => $actor->id,
            ]);

            foreach ($this->schedule->build($terms->startsOn, $terms->durationMonths, $terms->billingCycleMonths, $terms->monthlyAmount) as $period) {
                $lease->periods()->create([...$period, 'status' => LeasePeriodStatus::Upcoming]);
            }

            OfficeLeasePeriod::query()
                ->where('office_lease_id', $lease->id)
                ->where(fn ($q) => $q->where('sequence', 1)->orWhereDate('starts_on', '<=', now()->toDateString()))
                ->orderBy('sequence')
                ->get()
                ->each(fn (OfficeLeasePeriod $period) => $this->invoices->issueForLeasePeriod($period, $actor));

            $request->update([
                'status' => PrivateOfficeRequestStatus::Contracted,
                'contract_ref' => $terms->contractRef,
                'converted_company_id' => $company->id,
            ]);

            return $lease;
        });

        activity()->causedBy($actor)->performedOn($lease)->withProperties([
            'company_id' => $lease->company_id,
            'space_id' => $lease->space_id,
            'private_office_request_id' => $lease->private_office_request_id,
        ])->log('lease_created');

        $this->openDoor($lease);

        return $lease->refresh();
    }

    /**
     * Issues the office lock grant when the lease grants access today, the
     * office is active, has a lock, and no live grant exists yet. Never
     * throws: a lock-service failure is logged and the daily run retries.
     */
    public function openDoor(OfficeLease $lease): bool
    {
        $lease->loadMissing('space');

        if (! $lease->grantsAccessOn(now()->toDateString())
            || $lease->space->status !== OperationalStatus::Active
            || ! $this->passcodes->hasLock($lease->space)
            || $lease->accessGrants()->whereIn('status', [AccessGrantStatus::Issued, AccessGrantStatus::Activated])->exists()) {
            return false;
        }

        try {
            $this->passcodes->issueForLease($lease);

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to issue the office lock grant for a lease', [
                'office_lease_id' => $lease->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function assertLeasable(Space $space): void
    {
        if ($space->space_type !== SpaceType::Business) {
            throw new LeasingException('api.lease.not_an_office');
        }

        if ($space->status !== OperationalStatus::Active) {
            throw new LeasingException('api.lease.office_unavailable');
        }
    }

    private function existingCompany(PrivateOfficeRequest $request): Company
    {
        if ($request->company_id === null) {
            throw new LeasingException('api.lease.request_has_no_company');
        }

        $company = Company::query()->findOrFail($request->company_id);

        if ($company->status !== CompanyStatus::Active) {
            throw new LeasingException('api.lease.company_inactive');
        }

        return $company;
    }

    /** The one path into `companies` (PRD §5.1), formerly inline in CompanyController::store. */
    private function newCompany(PrivateOfficeRequest $request, string $legalName, string $contractRef, int $branchId): Company
    {
        if ($request->company_id !== null) {
            throw new LeasingException('api.lease.request_is_for_existing_company');
        }

        $company = Company::create([
            'legal_name' => $legalName,
            'contract_ref' => $contractRef,
            'branch_id' => $branchId,
            'status' => CompanyStatus::Active,
            'created_from_request_id' => $request->id,
            'provider_id' => $request->provider_id,
        ]);

        Wallet::create([
            'owner_type' => OwnerType::Company,
            'owner_id' => $company->id,
        ]);

        return $company;
    }

    /** A direct company always pays itself; a provider's pays as staff chose, else as the request said. */
    private function payer(Company $company, PrivateOfficeRequest $request, ?Payer $chosen): Payer
    {
        if ($company->provider_id === null) {
            return Payer::Company;
        }

        return $chosen ?? $request->payer ?? throw new LeasingException('api.lease.payer_required');
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Leasing/ContractLeaseTest.php`
Expected: PASS (11 tests).

- [ ] **Step 7: Pint and commit**

```bash
./vendor/bin/pint app/Domain/Leasing/Data/LeaseTerms.php app/Domain/Leasing/Exceptions/LeasingException.php app/Domain/Leasing/Services/OfficeLeaseService.php tests/Feature/Leasing/ContractLeaseTest.php lang/en/api.php lang/ar/api.php
git add app/Domain/Leasing/Data/LeaseTerms.php app/Domain/Leasing/Exceptions/LeasingException.php app/Domain/Leasing/Services/OfficeLeaseService.php tests/Feature/Leasing/ContractLeaseTest.php lang/en/api.php lang/ar/api.php
git commit -m "feat(leasing): contract a quoted office request into a lease

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Contracting endpoints, office requests for existing companies

**Files:**
- Create: `app/Http/Requests/Admin/Concerns/ValidatesLeaseTerms.php`, `app/Http/Requests/Admin/StoreOfficeLeaseRequest.php`, `app/Http/Requests/Provider/StoreProviderOfficeRequestRequest.php`, `app/Http/Controllers/Api/V1/Admin/OfficeLeaseController.php`, `app/Http/Resources/OfficeLeaseResource.php`, `tests/Feature/Leasing/ContractLeaseEndpointsTest.php`
- Modify: `app/Http/Requests/Admin/StoreCompanyRequest.php`, `app/Http/Requests/Admin/StorePrivateOfficeRequestRequest.php`, `app/Http/Controllers/Api/V1/Admin/CompanyController.php`, `app/Http/Controllers/Api/V1/Admin/PrivateOfficeRequestController.php`, `app/Http/Controllers/Api/V1/Provider/ProviderOfficeRequestController.php`, `app/Http/Resources/CompanyResource.php`, `app/Http/Resources/PrivateOfficeRequestResource.php`, `app/Services/Permissions/PermissionSyncService.php`, `routes/api/v1/admin.php`, `tests/Feature/Identity/CompanyLifecycleTest.php`, `tests/Feature/Identity/AuditLoggingTest.php`, `tests/Feature/Membership/WalletProvisioningTest.php`, `tests/Feature/Providers/ProviderOfficeRequestTest.php`

**Interfaces:**
- Consumes: `OfficeLeaseService::contract()`, `LeaseTerms::fromArray()`, `LeasingException` (Task 5); `InvoiceMoney::exceedsDecimals()|decimals()` (Phase 2).
- Produces: `ValidatesLeaseTerms` trait (`leaseRules(): array`, `validateLeaseTerms(Validator): void`, `leaseTerms(): LeaseTerms`); `OfficeLeaseResource` (admin view; `ProviderOfficeLeaseResource` in Task 10 extends it and drops `staffFields()`); `OfficeLeaseController::store()` and its private `loadForView(OfficeLease): OfficeLease` (Task 7 and 9 add actions to this controller); permission names `office-leases.view|create|terminate`.
- Body of `POST admin/companies`: `private_office_request_id`, `legal_name`, `contract_ref`, `space_id`, `starts_on`, `duration_months`, `billing_cycle_months`, `monthly_amount`, `currency`, optional `billed_to`. `branch_id` is no longer accepted (the office's branch is used). Body of `POST admin/private-office-requests/{privateOfficeRequest}/lease`: the same minus `private_office_request_id` and `legal_name`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leasing/ContractLeaseEndpointsTest.php`:

```php
<?php

namespace Tests\Feature\Leasing;

use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\Provider;
use Laravel\Sanctum\Sanctum;

class ContractLeaseEndpointsTest extends LeasingTestCase
{
    public function test_admin_creates_a_company_with_its_lease(): void
    {
        $this->actingAsAdmin();
        $office = $this->leasableOffice();
        $request = PrivateOfficeRequest::factory()->quoted()->create();

        $response = $this->postJson('/api/v1/admin/companies', [
            'private_office_request_id' => $request->id,
            'legal_name' => 'Acme LLC',
            'contract_ref' => 'C-3001',
            ...$this->leasePayload($office),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.legal_name', 'Acme LLC')
            ->assertJsonPath('data.branch_id', $office->building->branch_id)
            ->assertJsonPath('data.office_leases.0.space.id', $office->id)
            ->assertJsonPath('data.office_leases.0.monthly_amount', '500.00')
            ->assertJsonCount(4, 'data.office_leases.0.periods')
            ->assertJsonPath('data.office_leases.0.periods.0.invoice.amount', '1500.00')
            ->assertJsonPath('data.office_leases.0.periods.1.invoice', null);
    }

    public function test_the_lease_from_a_request_needs_its_permission(): void
    {
        $company = Company::factory()->create();
        $request = PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id]);
        $body = ['contract_ref' => 'C-3002', ...$this->leasePayload($this->leasableOffice())];

        $operations = User::factory()->create();
        $operations->assignRole('operations');
        Sanctum::actingAs($operations, ['*']);
        $this->postJson("/api/v1/admin/private-office-requests/{$request->id}/lease", $body)->assertForbidden();

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/private-office-requests/{$request->id}/lease", $body)
            ->assertCreated()
            ->assertJsonPath('data.company.id', $company->id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.billed_to', 'company')
            ->assertJsonPath('data.access.status', 'none')
            ->assertJsonPath('data.access.warning', null);
    }

    public function test_lease_fields_are_validated(): void
    {
        $this->actingAsAdmin();
        $company = Company::factory()->create();
        $request = PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id]);
        $office = $this->leasableOffice();
        $url = "/api/v1/admin/private-office-requests/{$request->id}/lease";

        $this->postJson($url, ['contract_ref' => 'C', ...$this->leasePayload($office, ['duration_months' => 10, 'billing_cycle_months' => 3])])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_cycle_months');
        $this->postJson($url, ['contract_ref' => 'C', ...$this->leasePayload($office, ['currency' => 'SYP', 'monthly_amount' => '100.50'])])
            ->assertUnprocessable()->assertJsonValidationErrors('monthly_amount');
        $this->postJson($url, ['contract_ref' => 'C', ...$this->leasePayload($office, ['monthly_amount' => '0'])])
            ->assertUnprocessable()->assertJsonValidationErrors('monthly_amount');
        $this->postJson($url, ['contract_ref' => 'C', ...$this->leasePayload($office, ['starts_on' => '10/01/2026'])])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->postJson($url, $this->leasePayload($office))
            ->assertUnprocessable()->assertJsonValidationErrors('contract_ref');
    }

    public function test_a_taken_office_answers_409_with_the_message(): void
    {
        $this->actingAsAdmin();
        $office = $this->leasableOffice();
        $first = PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]);
        $second = PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]);

        $this->postJson("/api/v1/admin/private-office-requests/{$first->id}/lease", ['contract_ref' => 'A', ...$this->leasePayload($office)])->assertCreated();
        $this->postJson("/api/v1/admin/private-office-requests/{$second->id}/lease", ['contract_ref' => 'B', ...$this->leasePayload($office)])
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.lease.office_taken'));
    }

    public function test_admin_files_an_office_request_for_an_existing_company(): void
    {
        $this->actingAsAdmin();
        $company = Company::factory()->create();

        $this->postJson('/api/v1/admin/private-office-requests', [
            'prospect_name' => 'Acme second office',
            'contact' => '+963944444444',
            'company_id' => $company->id,
        ])->assertCreated()->assertJsonPath('data.company_id', $company->id);
    }

    public function test_a_provider_requests_an_office_for_one_of_its_companies(): void
    {
        $provider = Provider::factory()->create();
        $own = $this->clientCompany($provider);
        $inactive = $this->clientCompany($provider);
        $inactive->update(['status' => CompanyStatus::Inactive]);
        $someoneElses = $this->clientCompany(Provider::factory()->create());
        $this->providerAccount($provider);
        $body = ['prospect_name' => 'Second office', 'contact' => '+963944444444', 'payer' => 'provider'];

        $this->postJson('/api/v1/provider/office-requests', [...$body, 'company_id' => $own->id])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $own->id)
            ->assertJsonPath('data.payer', 'provider');
        $this->postJson('/api/v1/provider/office-requests', [...$body, 'company_id' => $someoneElses->id])->assertNotFound();
        $this->postJson('/api/v1/provider/office-requests', [...$body, 'company_id' => $inactive->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.provider.company_unavailable'));
        $this->postJson('/api/v1/provider/office-requests', ['prospect_name' => 'New client', 'contact' => '+963944444444'])
            ->assertUnprocessable()->assertJsonValidationErrors('payer');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leasing/ContractLeaseEndpointsTest.php`
Expected: FAIL — 404 on `.../lease`, and `office_leases` missing from the company response.

- [ ] **Step 3: The shared validation**

`app/Http/Requests/Admin/Concerns/ValidatesLeaseTerms.php`:

```php
<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Domain\Invoicing\Services\InvoiceMoney;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Services\LeaseScheduleBuilder;
use App\Domain\Providers\Enums\Payer;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** The lease fields shared by POST admin/companies and POST admin/private-office-requests/{id}/lease. */
trait ValidatesLeaseTerms
{
    /** @return array<string, mixed> */
    protected function leaseRules(): array
    {
        return [
            'contract_ref' => ['required', 'string', 'max:255'],
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'duration_months' => ['required', 'integer', 'min:1', 'max:120'],
            'billing_cycle_months' => ['required', 'integer', 'min:1', 'max:120'],
            'monthly_amount' => ['required', 'decimal:0,2', 'gt:0'],
            'currency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'billed_to' => ['nullable', Rule::enum(Payer::class)],
        ];
    }

    protected function validateLeaseTerms(Validator $validator): void
    {
        if (! $validator->errors()->hasAny(['duration_months', 'billing_cycle_months'])
            && ! LeaseScheduleBuilder::isValidCycle((int) $this->input('duration_months'), (int) $this->input('billing_cycle_months'))) {
            $validator->errors()->add('billing_cycle_months', __('api.lease.invalid_cycle'));
        }

        if (! $validator->errors()->hasAny(['monthly_amount', 'currency'])) {
            $money = app(InvoiceMoney::class);
            $currency = (string) $this->input('currency');

            if ($money->exceedsDecimals((string) $this->input('monthly_amount'), $currency)) {
                $validator->errors()->add('monthly_amount', __('api.invoice.too_many_decimals', ['places' => $money->decimals($currency)]));
            }
        }
    }

    public function leaseTerms(): LeaseTerms
    {
        return LeaseTerms::fromArray($this->validated());
    }
}
```

`app/Http/Requests/Admin/StoreOfficeLeaseRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesLeaseTerms;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** A lease for the existing company a quoted office request names. */
class StoreOfficeLeaseRequest extends FormRequest
{
    use ValidatesLeaseTerms;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->leaseRules();
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateLeaseTerms($validator)];
    }
}
```

`app/Http/Requests/Admin/StoreCompanyRequest.php` — `use App\Http\Requests\Admin\Concerns\ValidatesLeaseTerms;` + `use ValidatesLeaseTerms;` in the class; `rules()` becomes:

```php
    public function rules(): array
    {
        return [
            'private_office_request_id' => ['required', 'integer', 'exists:private_office_requests,id'],
            'legal_name' => ['required', 'string', 'max:255'],
            ...$this->leaseRules(),
        ];
    }
```

and inside the existing `withValidator()` `after` closure, as its first line: `$this->validateLeaseTerms($validator);`. Update the class docblock: add the sentence `The company's first lease comes in the same body (ValidatesLeaseTerms); its branch is the office's branch.`

`app/Http/Requests/Admin/StorePrivateOfficeRequestRequest.php` — `rules()` gains (imports `App\Domain\Providers\Enums\Payer`, `Illuminate\Validation\Rule`):

```php
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'payer' => ['nullable', Rule::enum(Payer::class)],
```

`app/Http/Requests/Provider/StoreProviderOfficeRequestRequest.php`:

```php
<?php

namespace App\Http\Requests\Provider;

use App\Domain\Providers\Enums\Payer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A provider's office request: a new client company (no company_id) or
 * another office for one of its companies. The provider always says who
 * pays the rent.
 */
class StoreProviderOfficeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'prospect_name' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer'],
            'payer' => ['required', Rule::enum(Payer::class)],
        ];
    }
}
```

- [ ] **Step 4: The admin lease resource**

`app/Http/Resources/OfficeLeaseResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Domain\Access\Enums\AccessGrantStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lease as staff see it. Load company, space.building, provider,
 * periods.invoice and accessGrants for the full view (OfficeLeaseController
 * ::loadForView). Never carries a passcode.
 */
class OfficeLeaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'company' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'legal_name' => $this->company->legal_name]),
            'space' => $this->whenLoaded('space', fn () => [
                'id' => $this->space->id,
                'name' => $this->space->displayTitle(),
                'branch_id' => $this->space->relationLoaded('building') ? $this->space->building?->branch_id : null,
            ]),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider === null ? null : ['id' => $this->provider->id, 'name' => $this->provider->name]),
            'billed_to' => $this->billed_to,
            'currency' => $this->currency,
            'monthly_amount' => (string) $this->monthly_amount,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'access_ends_on' => $this->accessEndsOn()->toDateString(),
            'duration_months' => $this->duration_months,
            'billing_cycle_months' => $this->billing_cycle_months,
            'terminated_on' => $this->terminated_on?->toDateString(),
            'periods' => $this->whenLoaded('periods', fn () => $this->periods->map(fn ($period) => [
                'sequence' => $period->sequence,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'amount' => (string) $period->amount,
                'status' => $period->status,
                'invoice' => $period->relationLoaded('invoice') && $period->invoice !== null ? [
                    'id' => $period->invoice->id,
                    'reference' => $period->invoice->reference,
                    'status' => $period->invoice->status,
                    'is_overdue' => $period->invoice->isOverdue(),
                    'amount' => (string) $period->invoice->amount,
                    'paid_amount' => (string) $period->invoice->paid_amount,
                    'remaining' => $period->invoice->remaining(),
                    'due_at' => $period->invoice->due_at?->toDateString(),
                ] : null,
            ])->values()),
            'created_at' => $this->created_at,
            ...$this->staffFields(),
        ];
    }

    /** ADD-internal fields — the provider view leaves these out. */
    protected function staffFields(): array
    {
        return [
            'private_office_request_id' => $this->private_office_request_id,
            'termination_reason' => $this->termination_reason,
            'access' => $this->whenLoaded('accessGrants', fn () => $this->access()),
        ];
    }

    /** @return array{status: string, expires_at: string|null, warning: string|null} */
    private function access(): array
    {
        $live = $this->accessGrants->first(fn ($grant) => in_array($grant->status, [AccessGrantStatus::Issued, AccessGrantStatus::Activated], true));
        $hasLock = $this->relationLoaded('space') && $this->space->devices()->where('type', 'lock')->exists();

        return [
            'status' => $live !== null ? 'active' : 'none',
            'expires_at' => $live?->expires_at?->toIso8601String(),
            'warning' => $hasLock ? null : 'no_lock',
        ];
    }
}
```

`app/Http/Resources/CompanyResource.php` — after the `'provider' => ...` entry add:

```php
            'office_leases' => OfficeLeaseResource::collection($this->whenLoaded('officeLeases')),
```

`app/Http/Resources/PrivateOfficeRequestResource.php` — after `'provider_id' => $this->provider_id,` add:

```php
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => $this->company === null ? null : [
                'id' => $this->company->id,
                'legal_name' => $this->company->legal_name,
            ]),
            'payer' => $this->payer,
```

- [ ] **Step 5: Controllers**

`app/Http/Controllers/Api/V1/Admin/OfficeLeaseController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Leasing\Exceptions\LeasingException;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOfficeLeaseRequest;
use App\Http\Resources\OfficeLeaseResource;
use Illuminate\Http\JsonResponse;

/**
 * Office leases (docs/superpowers/specs/2026-10-10-office-leases-design.md).
 * Permission-gated per route (office-leases.*). A new company's first lease
 * is made by CompanyController::store instead.
 */
class OfficeLeaseController extends Controller
{
    public function store(StoreOfficeLeaseRequest $request, PrivateOfficeRequest $privateOfficeRequest, OfficeLeaseService $leases): JsonResponse
    {
        try {
            $lease = $leases->contract($privateOfficeRequest, $request->leaseTerms(), $request->user());
        } catch (LeasingException|InvoicingException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return (new OfficeLeaseResource($this->loadForView($lease)))->response()->setStatusCode(201);
    }

    private function loadForView(OfficeLease $lease): OfficeLease
    {
        return $lease->load(['company', 'space.building', 'provider', 'periods.invoice', 'accessGrants']);
    }
}
```

`app/Http/Controllers/Api/V1/Admin/CompanyController.php` — replace `store()` with (imports: `App\Domain\Invoicing\Exceptions\InvoicingException`, `App\Domain\Leasing\Exceptions\LeasingException`, `App\Domain\Leasing\Services\OfficeLeaseService`; drop the now-unused `CompanyStatus`, `PrivateOfficeRequestStatus`, `OwnerType`, `Wallet`, `DB` imports if nothing else in the file uses them):

```php
    /**
     * The one path into `companies` (PRD §5.1: created exclusively by
     * operations, never self-service): the company, its wallet and its first
     * lease, closing the private-office pipeline (OfficeLeaseService::contract).
     */
    public function store(StoreCompanyRequest $request, OfficeLeaseService $leases): JsonResponse
    {
        try {
            $lease = $leases->contract(
                PrivateOfficeRequest::query()->findOrFail($request->validated('private_office_request_id')),
                $request->leaseTerms(),
                $request->user(),
                $request->validated('legal_name'),
            );
        } catch (LeasingException|InvoicingException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $company = $lease->company;

        $this->logSensitiveAction('company_created', $company, [
            'private_office_request_id' => $company->created_from_request_id,
            'provider_id' => $company->provider_id,
        ]);

        return (new CompanyResource($company->load([
            'provider', 'officeLeases.company', 'officeLeases.space.building', 'officeLeases.provider', 'officeLeases.periods.invoice', 'officeLeases.accessGrants',
        ])))->response()->setStatusCode(201);
    }
```

`app/Http/Controllers/Api/V1/Admin/PrivateOfficeRequestController.php` — in `applyIndexFilters()` change `->with('provider')` to `->with(['provider', 'company'])`.

`app/Http/Controllers/Api/V1/Provider/ProviderOfficeRequestController.php` — `store()` becomes (imports `App\Domain\Identity\Models\Company`, `App\Http\Requests\Provider\StoreProviderOfficeRequestRequest`; remove the `StorePrivateOfficeRequestRequest` import):

```php
    public function store(StoreProviderOfficeRequestRequest $request): JsonResponse|PrivateOfficeRequestResource
    {
        if ($request->filled('company_id')) {
            $company = Company::query()->find($request->integer('company_id'));
            abort_if($company === null, 404);
            $this->authorizeCompany($request, $company);

            if ($company->status !== CompanyStatus::Active) {
                return response()->json(['message' => __('api.provider.company_unavailable')], 422);
            }
        }

        return new PrivateOfficeRequestResource(PrivateOfficeRequest::create([
            ...$request->validated(),
            'status' => PrivateOfficeRequestStatus::Requested,
            'provider_id' => $this->provider($request)->id,
            'submitted_by' => $request->user()->id,
        ]));
    }
```

Add `use App\Domain\Identity\Enums\CompanyStatus;` and `use Illuminate\Http\JsonResponse;`. Update the class docblock: `A new client company, or another office for one of the provider's companies, starts as a private-office request on the existing pipeline …`.

- [ ] **Step 6: Route and permission**

`routes/api/v1/admin.php` — import `App\Http\Controllers\Api\V1\Admin\OfficeLeaseController`; inside the `Route::withoutMiddleware('role:admin|operations')->group(...)` block that holds the provider and invoice routes, after the invoice routes:

```php

    // Office leases (docs/superpowers/specs/2026-10-10-office-leases-design.md).
    Route::post('private-office-requests/{privateOfficeRequest}/lease', [OfficeLeaseController::class, 'store'])->middleware('permission:office-leases.create');
```

`app/Services/Permissions/PermissionSyncService.php` — import the controller and add to `MANUAL_REGISTRATIONS` after `ProviderRequestController::class`:

```php
        // Office leases (docs/superpowers/specs/2026-10-10-office-leases-design.md).
        // Ending a lease early is its own grant.
        OfficeLeaseController::class => [
            'office-leases' => ['view', 'create', 'terminate'],
        ],
```

- [ ] **Step 7: Existing tests that create companies**

These post to `admin/companies` with the old body and now need the lease fields. In each file add `use Tests\Concerns\BuildsOfficeLeases;` and `use BuildsOfficeLeases;` in the class, then in every `postJson('/api/v1/admin/companies', [...])` remove the `'branch_id' => ...` entry and append `...$this->leasePayload($this->leasableOffice($branch)),` (or `$this->leasableOffice()` where the test has no `$branch`):

- `tests/Feature/Identity/CompanyLifecycleTest.php` — 3 calls. Rename `test_operations_creates_a_company_without_branch_id_and_uses_default_branch` to `test_the_company_takes_the_branch_of_its_office`, build `$office = $this->leasableOffice($branch = Branch::factory()->create());`, and assert `data.branch_id` / the `companies.branch_id` row equal `$branch->id` (drop the default-branch setup). The "not quoted" test still expects the 422 on `private_office_request_id`.
- `tests/Feature/Identity/AuditLoggingTest.php` — 1 call (this test is one of the 17 pre-existing failures; it must fail only on its old audit-log assertion, not on validation).
- `tests/Feature/Membership/WalletProvisioningTest.php` — 1 call.
- `tests/Feature/Providers/ProviderOfficeRequestTest.php` — 1 call to `admin/companies`; plus every `postJson('/api/v1/provider/office-requests', [...])` gains `'payer' => 'provider'`.

Run `grep -rn "admin/companies'" tests` afterwards: every POST must carry `space_id`.

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Leasing tests/Feature/Identity/CompanyLifecycleTest.php tests/Feature/Identity/AuditLoggingTest.php tests/Feature/Membership/WalletProvisioningTest.php tests/Feature/Providers tests/Feature/Admin`
Expected: PASS, except the pre-existing `AuditLoggingTest::company creation is audit logged` failure on `'1'` vs `1`.

- [ ] **Step 9: Pint and commit**

```bash
./vendor/bin/pint app/Http/Requests/Admin/Concerns/ValidatesLeaseTerms.php app/Http/Requests/Admin/StoreOfficeLeaseRequest.php app/Http/Requests/Provider/StoreProviderOfficeRequestRequest.php app/Http/Requests/Admin/StoreCompanyRequest.php app/Http/Requests/Admin/StorePrivateOfficeRequestRequest.php app/Http/Controllers/Api/V1/Admin/OfficeLeaseController.php app/Http/Controllers/Api/V1/Admin/CompanyController.php app/Http/Controllers/Api/V1/Admin/PrivateOfficeRequestController.php app/Http/Controllers/Api/V1/Provider/ProviderOfficeRequestController.php app/Http/Resources/OfficeLeaseResource.php app/Http/Resources/CompanyResource.php app/Http/Resources/PrivateOfficeRequestResource.php app/Services/Permissions/PermissionSyncService.php routes/api/v1/admin.php tests/Feature/Leasing/ContractLeaseEndpointsTest.php tests/Feature/Identity/CompanyLifecycleTest.php tests/Feature/Identity/AuditLoggingTest.php tests/Feature/Membership/WalletProvisioningTest.php tests/Feature/Providers/ProviderOfficeRequestTest.php
git add app/Http/Requests/Admin/Concerns/ValidatesLeaseTerms.php app/Http/Requests/Admin/StoreOfficeLeaseRequest.php app/Http/Requests/Provider/StoreProviderOfficeRequestRequest.php app/Http/Requests/Admin/StoreCompanyRequest.php app/Http/Requests/Admin/StorePrivateOfficeRequestRequest.php app/Http/Controllers/Api/V1/Admin/OfficeLeaseController.php app/Http/Controllers/Api/V1/Admin/CompanyController.php app/Http/Controllers/Api/V1/Admin/PrivateOfficeRequestController.php app/Http/Controllers/Api/V1/Provider/ProviderOfficeRequestController.php app/Http/Resources/OfficeLeaseResource.php app/Http/Resources/CompanyResource.php app/Http/Resources/PrivateOfficeRequestResource.php app/Services/Permissions/PermissionSyncService.php routes/api/v1/admin.php tests/Feature/Leasing/ContractLeaseEndpointsTest.php tests/Feature/Identity/CompanyLifecycleTest.php tests/Feature/Identity/AuditLoggingTest.php tests/Feature/Membership/WalletProvisioningTest.php tests/Feature/Providers/ProviderOfficeRequestTest.php
git commit -m "feat(leasing): contract endpoints; office requests for existing companies

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Ending a lease early

**Files:**
- Modify: `app/Domain/Leasing/Services/OfficeLeaseService.php`, `app/Http/Controllers/Api/V1/Admin/OfficeLeaseController.php`, `routes/api/v1/admin.php`
- Create: `app/Http/Requests/Admin/TerminateOfficeLeaseRequest.php`, `tests/Feature/Leasing/TerminateLeaseTest.php`

**Interfaces:**
- Consumes: `OfficeLeaseService::contract()` (Task 5), `PasscodeIssuanceService::revokeForLease()` (Task 4), `loadForView()` (Task 6).
- Produces: `OfficeLeaseService::terminate(OfficeLease $lease, string $terminatedOn, string $reason, User $actor): OfficeLease` and `closeDoor(OfficeLease $lease): void` (never throws; Task 8 uses it). Route `POST admin/office-leases/{officeLease}/terminate` (`office-leases.terminate`), body `terminated_on` (Y-m-d), `reason`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leasing/TerminateLeaseTest.php`:

```php
<?php

namespace Tests\Feature\Leasing;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Foundation\Models\Space;
use Laravel\Sanctum\Sanctum;

class TerminateLeaseTest extends LeasingTestCase
{
    private function lease(Space $office, array $overrides = []): OfficeLease
    {
        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]),
            LeaseTerms::fromArray([...$this->leasePayload($office, ['billing_cycle_months' => 1, 'duration_months' => 6]), 'contract_ref' => 'C', ...$overrides]),
            auth()->user(),
        );
    }

    /** @return list<string> */
    private function statuses(OfficeLease $lease): array
    {
        return $lease->periods()->get()->map(fn ($p) => $p->status->value)->all();
    }

    public function test_terminating_today_closes_the_door_and_cancels_what_is_left(): void
    {
        $this->fakeTtlock();
        $this->actingAsAdmin();
        $lease = $this->lease($this->leasableOffice(), ['starts_on' => now()->subMonthsNoOverflow(2)->toDateString()]);
        $this->assertSame(AccessGrantStatus::Activated, $lease->accessGrants()->sole()->status);

        $this->postJson("/api/v1/admin/office-leases/{$lease->id}/terminate", [
            'terminated_on' => now()->toDateString(),
            'reason' => 'Contract clause 7',
        ])->assertOk()->assertJsonPath('message', __('api.lease.terminated'));

        $lease->refresh();
        $this->assertSame(OfficeLeaseStatus::Terminated, $lease->status);
        $this->assertSame('Contract clause 7', $lease->termination_reason);
        $this->assertSame(['invoiced', 'invoiced', 'invoiced', 'cancelled', 'cancelled', 'cancelled'], $this->statuses($lease));
        $this->assertSame(AccessGrantStatus::Revoked, $lease->accessGrants()->sole()->status);
    }

    public function test_terminating_on_a_future_date_keeps_the_door_and_the_periods_before_it(): void
    {
        $this->fakeTtlock();
        $this->actingAsAdmin();
        $lease = $this->lease($this->leasableOffice(), ['starts_on' => now()->toDateString()]);

        $this->postJson("/api/v1/admin/office-leases/{$lease->id}/terminate", [
            'terminated_on' => now()->addMonthsNoOverflow(2)->toDateString(),
            'reason' => 'Moving out',
        ])->assertOk();

        $this->assertSame(['invoiced', 'upcoming', 'cancelled', 'cancelled', 'cancelled', 'cancelled'], $this->statuses($lease));
        $this->assertSame(AccessGrantStatus::Activated, $lease->accessGrants()->sole()->status);
    }

    public function test_terminating_on_the_start_date_frees_the_office_from_that_date(): void
    {
        $this->actingAsAdmin();
        $office = $this->leasableOffice();
        $lease = $this->lease($office);

        $this->postJson("/api/v1/admin/office-leases/{$lease->id}/terminate", [
            'terminated_on' => $lease->starts_on->toDateString(),
            'reason' => 'Signed by mistake',
        ])->assertOk();

        $this->assertSame(['invoiced', 'cancelled', 'cancelled', 'cancelled', 'cancelled', 'cancelled'], $this->statuses($lease));
        $this->assertFalse($lease->fresh()->grantsAccessOn($lease->starts_on->toDateString()));
        $this->assertTrue($this->lease($office)->exists, 'The office can be leased again from the same start date.');
    }

    public function test_refusals(): void
    {
        $this->actingAsAdmin();
        $lease = $this->lease($this->leasableOffice());
        $url = "/api/v1/admin/office-leases/{$lease->id}/terminate";

        $this->postJson($url, ['terminated_on' => $lease->starts_on->subDay()->toDateString(), 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonPath('message', __('api.lease.termination_out_of_range'));
        $this->postJson($url, ['terminated_on' => $lease->ends_on->addDay()->toDateString(), 'reason' => 'x'])
            ->assertUnprocessable();
        $this->postJson($url, ['terminated_on' => $lease->starts_on->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson($url, ['terminated_on' => $lease->ends_on->toDateString(), 'reason' => 'x'])->assertOk();
        $this->postJson($url, ['terminated_on' => $lease->ends_on->toDateString(), 'reason' => 'again'])
            ->assertStatus(409)->assertJsonPath('message', __('api.lease.not_active'));

        $operations = User::factory()->create();
        $operations->assignRole('operations');
        Sanctum::actingAs($operations, ['*']);
        $this->postJson($url, ['terminated_on' => $lease->ends_on->toDateString(), 'reason' => 'x'])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leasing/TerminateLeaseTest.php`
Expected: FAIL — 404 on `.../terminate`.

- [ ] **Step 3: `terminate()` and `closeDoor()`**

Add to `app/Domain/Leasing/Services/OfficeLeaseService.php`:

```php
    /**
     * Ends an active lease early (spec "Exceptional termination").
     * $terminatedOn is the first day without access; it may be the start
     * date (no access at all) up to the end date. Periods starting on or
     * after it are cancelled; issued invoices stay. The door closes now
     * when the date has come, else on the daily run of that date.
     */
    public function terminate(OfficeLease $lease, string $terminatedOn, string $reason, User $actor): OfficeLease
    {
        $lease = DB::transaction(function () use ($lease, $terminatedOn, $reason, $actor) {
            $locked = OfficeLease::query()->whereKey($lease->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OfficeLeaseStatus::Active) {
                throw new LeasingException('api.lease.not_active', 409);
            }

            if ($terminatedOn < $locked->starts_on->toDateString() || $terminatedOn > $locked->ends_on->toDateString()) {
                throw new LeasingException('api.lease.termination_out_of_range');
            }

            $locked->update([
                'status' => OfficeLeaseStatus::Terminated,
                'terminated_on' => $terminatedOn,
                'terminated_by' => $actor->id,
                'termination_reason' => $reason,
            ]);

            OfficeLeasePeriod::query()
                ->where('office_lease_id', $locked->id)
                ->where('status', LeasePeriodStatus::Upcoming)
                ->whereDate('starts_on', '>=', $terminatedOn)
                ->update(['status' => LeasePeriodStatus::Cancelled]);

            return $locked;
        });

        activity()->causedBy($actor)->performedOn($lease)->withProperties([
            'terminated_on' => $terminatedOn,
            'reason' => $reason,
        ])->log('lease_terminated');

        if ($terminatedOn <= now()->toDateString()) {
            $this->closeDoor($lease);
        }

        return $lease;
    }

    /** Revokes the lease's live grants. Never throws; a failure is logged. */
    public function closeDoor(OfficeLease $lease): void
    {
        try {
            $this->passcodes->revokeForLease($lease);
        } catch (\Throwable $e) {
            Log::error('Failed to revoke the office lock grant of a lease', [
                'office_lease_id' => $lease->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
```

- [ ] **Step 4: Request, action, route**

`app/Http/Requests/Admin/TerminateOfficeLeaseRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TerminateOfficeLeaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'terminated_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
```

In `OfficeLeaseController` add (import `App\Http\Requests\Admin\TerminateOfficeLeaseRequest`):

```php
    public function terminate(TerminateOfficeLeaseRequest $request, OfficeLease $officeLease, OfficeLeaseService $leases): JsonResponse
    {
        try {
            $leases->terminate($officeLease, $request->validated('terminated_on'), $request->validated('reason'), $request->user());
        } catch (LeasingException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.lease.terminated')]);
    }
```

`routes/api/v1/admin.php`, after the `.../lease` route:

```php
    Route::post('office-leases/{officeLease}/terminate', [OfficeLeaseController::class, 'terminate'])->middleware('permission:office-leases.terminate');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Leasing`
Expected: PASS.

- [ ] **Step 6: Pint and commit**

```bash
./vendor/bin/pint app/Domain/Leasing/Services/OfficeLeaseService.php app/Http/Controllers/Api/V1/Admin/OfficeLeaseController.php app/Http/Requests/Admin/TerminateOfficeLeaseRequest.php routes/api/v1/admin.php tests/Feature/Leasing/TerminateLeaseTest.php
git add app/Domain/Leasing/Services/OfficeLeaseService.php app/Http/Controllers/Api/V1/Admin/OfficeLeaseController.php app/Http/Requests/Admin/TerminateOfficeLeaseRequest.php routes/api/v1/admin.php tests/Feature/Leasing/TerminateLeaseTest.php
git commit -m "feat(leasing): exceptional termination

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: The daily run

**Files:**
- Create: `app/Domain/Leasing/Services/LeaseDailyRunner.php`, `app/Console/Commands/RunDailyLeases.php`, `tests/Feature/Leasing/LeaseDailyRunnerTest.php`
- Modify: `routes/console.php`

**Interfaces:**
- Consumes: `InvoiceIssuer::issueForLeasePeriod()` (Task 3), `OfficeLeaseService::openDoor()|closeDoor()` (Tasks 5, 7).
- Produces: `LeaseDailyRunner::run(): array{invoices: int, doors_opened: int, ended: int, doors_closed: int}` (+ the four public step methods); command `leases:run-daily`, scheduled daily at 06:00 Asia/Damascus.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leasing/LeaseDailyRunnerTest.php`:

```php
<?php

namespace Tests\Feature\Leasing;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\LeaseDailyRunner;
use App\Domain\Leasing\Services\OfficeLeaseService;
use Illuminate\Support\Facades\Http;

class LeaseDailyRunnerTest extends LeasingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    private function lease(Space $office, array $overrides = []): OfficeLease
    {
        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]),
            LeaseTerms::fromArray([...$this->leasePayload($office), 'contract_ref' => 'C', ...$overrides]),
            auth()->user(),
        );
    }

    private function run(): array
    {
        return app(LeaseDailyRunner::class)->run();
    }

    public function test_a_period_is_invoiced_on_its_first_day_once(): void
    {
        $lease = $this->lease($this->leasableOffice(withLock: false));
        $this->travelTo($lease->periods()->where('sequence', 2)->first()->starts_on);

        $this->assertSame(1, $this->run()['invoices']);
        $this->assertSame(0, $this->run()['invoices']);
        $this->assertSame(2, Invoice::query()->whereNotNull('office_lease_period_id')->count());
        $this->assertNull(Invoice::query()->latest('id')->first()->issued_by);
    }

    public function test_missed_days_catch_up_on_the_next_run(): void
    {
        $lease = $this->lease($this->leasableOffice(withLock: false));
        $this->travelTo($lease->periods()->where('sequence', 3)->first()->starts_on->addDays(5));

        $this->assertSame(2, $this->run()['invoices']);
    }

    public function test_a_back_dated_lease_leaves_nothing_for_the_run(): void
    {
        $this->lease($this->leasableOffice(withLock: false), ['starts_on' => now()->subMonthsNoOverflow(7)->toDateString(), 'billing_cycle_months' => 1]);

        $this->assertSame(0, $this->run()['invoices']);
    }

    public function test_the_door_opens_on_the_first_day_and_closes_after_the_last(): void
    {
        $this->fakeTtlock();
        $lease = $this->lease($this->leasableOffice(), ['duration_months' => 3, 'billing_cycle_months' => 3]);
        $this->assertSame(0, $this->run()['doors_opened']);

        $this->travelTo($lease->starts_on->setTime(6, 0));
        $this->assertSame(1, $this->run()['doors_opened']);
        $this->assertSame(0, $this->run()['doors_opened']);

        $this->travelTo($lease->ends_on->addDay()->setTime(6, 0));
        $result = $this->run();
        $this->assertSame(1, $result['ended']);
        $this->assertSame(1, $result['doors_closed']);
        $this->assertSame(OfficeLeaseStatus::Ended, $lease->fresh()->status);
        $this->assertSame(AccessGrantStatus::Revoked, $lease->accessGrants()->sole()->status);
    }

    public function test_a_future_termination_closes_the_door_on_its_date(): void
    {
        $this->fakeTtlock();
        $lease = $this->lease($this->leasableOffice(), ['starts_on' => now()->toDateString()]);
        app(OfficeLeaseService::class)->terminate($lease, now()->addDays(10)->toDateString(), 'Moving out', auth()->user());

        $this->travel(9)->days();
        $this->assertSame(0, $this->run()['doors_closed']);

        $this->travel(1)->days();
        $this->assertSame(1, $this->run()['doors_closed']);
        $this->assertSame(AccessGrantStatus::Revoked, $lease->accessGrants()->sole()->status);
    }

    public function test_maintenance_takes_the_door_away_and_the_run_gives_it_back(): void
    {
        $this->fakeTtlock();
        $office = $this->leasableOffice();
        $lease = $this->lease($office, ['starts_on' => now()->toDateString()]);

        $office->update(['status' => OperationalStatus::Maintenance]);
        $this->artisan('access:revoke-grants-on-maintenance')->assertSuccessful();
        $this->assertSame(0, $this->run()['doors_opened']);

        $office->update(['status' => OperationalStatus::Active]);
        $this->assertSame(1, $this->run()['doors_opened']);
        $this->assertSame(1, $lease->accessGrants()->where('status', AccessGrantStatus::Activated)->count());
    }

    public function test_a_lock_failure_on_one_lease_does_not_stop_the_others(): void
    {
        Http::fake(['api.sciener.test/v3/keyboardPwd/get' => Http::sequence()
            ->push('', 500)
            ->push(['keyboardPwd' => '445566', 'keyboardPwdId' => 6], 200)
            ->push(['keyboardPwd' => '778899', 'keyboardPwdId' => 7], 200)]);
        $this->fakeTtlock();
        $first = $this->lease($this->leasableOffice());
        $second = $this->lease($this->leasableOffice());
        $this->travelTo($first->starts_on->setTime(6, 0));

        $this->assertSame(1, $this->run()['doors_opened']);
        $this->assertSame(0, $first->accessGrants()->count());
        $this->assertSame(1, $second->accessGrants()->count());

        $this->travel(1)->days();
        $this->assertSame(1, $this->run()['doors_opened']);
        $this->assertSame(1, $first->accessGrants()->count());
    }

    public function test_the_command_is_scheduled_daily(): void
    {
        $this->artisan('leases:run-daily')->assertSuccessful();

        $this->artisan('schedule:list')->expectsOutputToContain('leases:run-daily');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leasing/LeaseDailyRunnerTest.php`
Expected: FAIL — `Class "App\Domain\Leasing\Services\LeaseDailyRunner" not found`.

- [ ] **Step 3: Write the runner**

`app/Domain/Leasing/Services/LeaseDailyRunner.php`:

```php
<?php

namespace App\Domain\Leasing\Services;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Invoicing\Services\InvoiceIssuer;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The once-a-day lease run (leases:run-daily). Every step looks for a state
 * that should not exist — a started period with no invoice, a lease with
 * access and no live grant, a live grant on a lease without access — so a
 * missed day or a failed lock call is simply caught up on the next run.
 * One lease or period failing never stops the rest.
 */
class LeaseDailyRunner
{
    private const LIVE = [AccessGrantStatus::Issued, AccessGrantStatus::Activated];

    public function __construct(
        private readonly InvoiceIssuer $invoices,
        private readonly OfficeLeaseService $leases,
    ) {}

    /** @return array{invoices: int, doors_opened: int, ended: int, doors_closed: int} */
    public function run(): array
    {
        return [
            'invoices' => $this->issueDueInvoices(),
            'doors_opened' => $this->openDoors(),
            'ended' => $this->endExpired(),
            'doors_closed' => $this->closeDoors(),
        ];
    }

    /** Started periods still upcoming: one invoice each, under the period's row lock. */
    public function issueDueInvoices(): int
    {
        $issued = 0;

        OfficeLeasePeriod::query()
            ->where('status', LeasePeriodStatus::Upcoming)
            ->whereDate('starts_on', '<=', $this->today())
            ->chunkById(100, function ($periods) use (&$issued) {
                foreach ($periods as $period) {
                    try {
                        $issued += DB::transaction(function () use ($period) {
                            $locked = OfficeLeasePeriod::query()->whereKey($period->id)->lockForUpdate()->first();

                            if ($locked?->status !== LeasePeriodStatus::Upcoming) {
                                return 0;
                            }

                            $this->invoices->issueForLeasePeriod($locked, null);

                            return 1;
                        });
                    } catch (\Throwable $e) {
                        Log::error('Failed to invoice an office lease period', [
                            'office_lease_period_id' => $period->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $issued;
    }

    /** Leases with access today and no live grant (openDoor() decides the rest). */
    public function openDoors(): int
    {
        $opened = 0;

        OfficeLease::query()
            ->where('status', '!=', OfficeLeaseStatus::Ended)
            ->whereDate('starts_on', '<=', $this->today())
            ->whereDoesntHave('accessGrants', fn ($q) => $q->whereIn('status', self::LIVE))
            ->chunkById(100, function ($leases) use (&$opened) {
                foreach ($leases as $lease) {
                    if ($this->leases->openDoor($lease)) {
                        $opened++;
                    }
                }
            });

        return $opened;
    }

    /** Active leases past their last day. */
    public function endExpired(): int
    {
        $ended = 0;

        OfficeLease::query()
            ->where('status', OfficeLeaseStatus::Active)
            ->whereDate('ends_on', '<', $this->today())
            ->chunkById(100, function ($leases) use (&$ended) {
                foreach ($leases as $lease) {
                    $lease->update(['status' => OfficeLeaseStatus::Ended]);
                    activity()->performedOn($lease)->log('lease_ended');
                    $ended++;
                }
            });

        return $ended;
    }

    /** Leases with a live grant that no longer grant access today. */
    public function closeDoors(): int
    {
        $closed = 0;
        $today = $this->today();

        OfficeLease::query()
            ->whereHas('accessGrants', fn ($q) => $q->whereIn('status', self::LIVE))
            ->chunkById(100, function ($leases) use (&$closed, $today) {
                foreach ($leases as $lease) {
                    if (! $lease->grantsAccessOn($today)) {
                        $this->leases->closeDoor($lease);
                        $closed++;
                    }
                }
            });

        return $closed;
    }

    private function today(): string
    {
        return now()->toDateString();
    }
}
```

- [ ] **Step 4: Command and schedule**

`app/Console/Commands/RunDailyLeases.php`:

```php
<?php

namespace App\Console\Commands;

use App\Domain\Leasing\Services\LeaseDailyRunner;
use Illuminate\Console\Command;

class RunDailyLeases extends Command
{
    protected $signature = 'leases:run-daily';

    protected $description = 'Issue due office-lease invoices, open and close office doors, and end expired leases.';

    public function handle(LeaseDailyRunner $runner): int
    {
        $result = $runner->run();

        $this->info(sprintf(
            'Invoices issued: %d, doors opened: %d, leases ended: %d, doors closed: %d',
            $result['invoices'],
            $result['doors_opened'],
            $result['ended'],
            $result['doors_closed'],
        ));

        return self::SUCCESS;
    }
}
```

`routes/console.php` — append:

```php

/*
 * Office leases (docs/superpowers/specs/2026-10-10-office-leases-design.md):
 * invoice each billing period on its first day, open the office lock on the
 * lease's first day, end leases past their last day and close their lock.
 * Every step catches up on what a missed run left behind.
 */
Schedule::command('leases:run-daily')->dailyAt('06:00')->timezone('Asia/Damascus')->withoutOverlapping();
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Leasing tests/Guards`
Expected: PASS (guards: only the pre-existing EventAttendee failure).

- [ ] **Step 6: Pint and commit**

```bash
./vendor/bin/pint app/Domain/Leasing/Services/LeaseDailyRunner.php app/Console/Commands/RunDailyLeases.php routes/console.php tests/Feature/Leasing/LeaseDailyRunnerTest.php
git add app/Domain/Leasing/Services/LeaseDailyRunner.php app/Console/Commands/RunDailyLeases.php routes/console.php tests/Feature/Leasing/LeaseDailyRunnerTest.php
git commit -m "feat(leasing): daily run - period invoices, doors, lease ends

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Admin lease list and detail

**Files:**
- Create: `app/Http/Requests/Admin/ListOfficeLeasesRequest.php`, `tests/Feature/Leasing/AdminOfficeLeaseListTest.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/OfficeLeaseController.php`, `app/Http/Controllers/Api/V1/Admin/CompanyController.php`, `routes/api/v1/admin.php`

**Interfaces:**
- Consumes: `OfficeLeaseResource`, `loadForView()` (Task 6).
- Produces: `GET admin/office-leases` (paginated; filters `company_id`, `provider_id`, `space_id`, `branch_id`, `status`, `ending_within_days`, `per_page`) and `GET admin/office-leases/{officeLease}`, both `office-leases.view`. `OfficeLeaseController::filtered(Request $request, ?Builder $query = null): Builder` (static; Task 10 reuses it). `GET admin/companies/{company}` includes `office_leases`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leasing/AdminOfficeLeaseListTest.php`:

```php
<?php

namespace Tests\Feature\Leasing;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use Laravel\Sanctum\Sanctum;

class AdminOfficeLeaseListTest extends LeasingTestCase
{
    private function lease(Company $company, array $overrides = [], bool $withLock = true, ?Branch $branch = null): OfficeLease
    {
        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id, 'payer' => Payer::Company]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice($branch, $withLock)), 'contract_ref' => 'C', ...$overrides]),
            auth()->user(),
        );
    }

    public function test_lists_and_filters_leases(): void
    {
        $this->actingAsAdmin();
        $provider = Provider::factory()->create();
        $client = Company::factory()->create(['provider_id' => $provider->id]);
        $direct = Company::factory()->create();
        $branch = Branch::factory()->create();

        $this->lease($client, ['duration_months' => 1, 'billing_cycle_months' => 1]);
        $this->lease($direct, branch: $branch);
        $ended = $this->lease($direct);
        $ended->update(['status' => OfficeLeaseStatus::Ended]);

        $this->getJson('/api/v1/admin/office-leases')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/admin/office-leases?provider_id='.$provider->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.provider.id', $provider->id);
        $this->getJson('/api/v1/admin/office-leases?company_id='.$direct->id)->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/office-leases?branch_id='.$branch->id)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/office-leases?status=ended')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/office-leases?ending_within_days=40')->assertJsonCount(1, 'data')->assertJsonPath('data.0.company.id', $client->id);
        $this->getJson('/api/v1/admin/office-leases?status=bogus')->assertUnprocessable();
    }

    public function test_detail_shows_periods_invoices_and_the_lock_warning_but_never_a_passcode(): void
    {
        $this->fakeTtlock();
        $this->actingAsAdmin();
        $withLock = $this->lease(Company::factory()->create(), ['starts_on' => now()->toDateString()]);
        $withoutLock = $this->lease(Company::factory()->create(), withLock: false);

        $response = $this->getJson("/api/v1/admin/office-leases/{$withLock->id}")
            ->assertOk()
            ->assertJsonPath('data.reference', $withLock->reference)
            ->assertJsonCount(4, 'data.periods')
            ->assertJsonPath('data.periods.0.invoice.status', 'unpaid')
            ->assertJsonPath('data.access.status', 'active')
            ->assertJsonPath('data.access.warning', null);
        $this->assertStringNotContainsString('112233', $response->getContent());
        $this->assertStringNotContainsString('passcode', $response->getContent());

        $this->getJson("/api/v1/admin/office-leases/{$withoutLock->id}")
            ->assertJsonPath('data.access.status', 'none')
            ->assertJsonPath('data.access.warning', 'no_lock');
    }

    public function test_the_company_detail_carries_its_leases(): void
    {
        $this->actingAsAdmin();
        $company = Company::factory()->create();
        $lease = $this->lease($company);

        $this->getJson("/api/v1/admin/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.office_leases.0.reference', $lease->reference);
    }

    public function test_viewing_needs_its_permission(): void
    {
        $operations = User::factory()->create();
        $operations->assignRole('operations');
        Sanctum::actingAs($operations, ['*']);

        $this->getJson('/api/v1/admin/office-leases')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leasing/AdminOfficeLeaseListTest.php`
Expected: FAIL — 404 on `GET admin/office-leases`.

- [ ] **Step 3: Request, actions, routes**

`app/Http/Requests/Admin/ListOfficeLeasesRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters for the lease lists (admin, and the provider's after it scopes its own). */
class ListOfficeLeasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'space_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(OfficeLeaseStatus::class)],
            'ending_within_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

In `OfficeLeaseController` add (imports `App\Http\Requests\Admin\ListOfficeLeasesRequest`, `Illuminate\Database\Eloquent\Builder`, `Illuminate\Http\Request`, `Illuminate\Http\Resources\Json\AnonymousResourceCollection`, `App\Domain\Leasing\Enums\OfficeLeaseStatus`):

```php
    public function index(ListOfficeLeasesRequest $request): AnonymousResourceCollection
    {
        return OfficeLeaseResource::collection(
            self::filtered($request)
                ->with(['company', 'space.building', 'provider'])
                ->latest('id')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function show(OfficeLease $officeLease): OfficeLeaseResource
    {
        return new OfficeLeaseResource($this->loadForView($officeLease));
    }

    /** The list filters, shared with the provider's list (which scopes its companies first). */
    public static function filtered(Request $request, ?Builder $query = null): Builder
    {
        $today = now()->toDateString();

        return ($query ?? OfficeLease::query())
            ->when($request->filled('company_id'), fn (Builder $q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('provider_id'), fn (Builder $q) => $q->where('provider_id', $request->integer('provider_id')))
            ->when($request->filled('space_id'), fn (Builder $q) => $q->where('space_id', $request->integer('space_id')))
            ->when($request->filled('branch_id'), fn (Builder $q) => $q->whereHas('space.building', fn (Builder $b) => $b->where('branch_id', $request->integer('branch_id'))))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('ending_within_days'), fn (Builder $q) => $q
                ->where('status', OfficeLeaseStatus::Active)
                ->whereDate('ends_on', '>=', $today)
                ->whereDate('ends_on', '<=', now()->addDays($request->integer('ending_within_days'))->toDateString()));
    }
```

`CompanyController::show()` becomes:

```php
    public function show(Company $company): CompanyResource
    {
        return new CompanyResource($company->load(['provider', 'officeLeases.space.building']));
    }
```

`routes/api/v1/admin.php`, with the other lease routes:

```php
    Route::get('office-leases', [OfficeLeaseController::class, 'index'])->middleware('permission:office-leases.view');
    Route::get('office-leases/{officeLease}', [OfficeLeaseController::class, 'show'])->middleware('permission:office-leases.view');
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Leasing tests/Feature/Providers/AdminCompanyListTest.php tests/Feature/Identity/CompanyLifecycleTest.php`
Expected: PASS.

- [ ] **Step 5: Pint and commit**

```bash
./vendor/bin/pint app/Http/Requests/Admin/ListOfficeLeasesRequest.php app/Http/Controllers/Api/V1/Admin/OfficeLeaseController.php app/Http/Controllers/Api/V1/Admin/CompanyController.php routes/api/v1/admin.php tests/Feature/Leasing/AdminOfficeLeaseListTest.php
git add app/Http/Requests/Admin/ListOfficeLeasesRequest.php app/Http/Controllers/Api/V1/Admin/OfficeLeaseController.php app/Http/Controllers/Api/V1/Admin/CompanyController.php routes/api/v1/admin.php tests/Feature/Leasing/AdminOfficeLeaseListTest.php
git commit -m "feat(leasing): admin lease list and detail

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: The provider's leases (read only)

**Files:**
- Create: `app/Http/Controllers/Api/V1/Provider/ProviderOfficeLeaseController.php`, `app/Http/Resources/ProviderOfficeLeaseResource.php`, `tests/Feature/Leasing/ProviderOfficeLeaseTest.php`
- Modify: `app/Http/Controllers/Api/V1/Provider/Concerns/ActsForProvider.php`, `routes/api/v1/provider.php`, `tests/Guards/ProviderRoutesStayIsolatedTest.php`

**Interfaces:**
- Consumes: `OfficeLeaseController::filtered()`, `ListOfficeLeasesRequest` (Task 9), `OfficeLeaseResource` (Task 6).
- Produces: `GET provider/office-leases`, `GET provider/office-leases/{officeLease}`, `GET provider/companies/{company}/office-leases`; `ActsForProvider::authorizeLease(Request, OfficeLease)`. A provider sees the leases of the companies it manages **now** (`companies.provider_id`), not `office_leases.provider_id` — a company moved to another provider takes its leases with it.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leasing/ProviderOfficeLeaseTest.php`:

```php
<?php

namespace Tests\Feature\Leasing;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;

class ProviderOfficeLeaseTest extends LeasingTestCase
{
    private function lease(Company $company): OfficeLease
    {
        $admin = $this->actingAsAdmin();

        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id, 'payer' => Payer::Provider]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice()), 'contract_ref' => 'C']),
            $admin,
        );
    }

    public function test_a_provider_reads_its_companies_leases_with_prices_but_no_staff_fields(): void
    {
        $provider = Provider::factory()->create();
        $mine = $this->clientCompany($provider);
        $lease = $this->lease($mine);
        $this->lease($this->clientCompany(Provider::factory()->create()));
        $this->providerAccount($provider);

        $this->getJson('/api/v1/provider/office-leases')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $lease->reference);

        $this->getJson("/api/v1/provider/office-leases/{$lease->id}")
            ->assertOk()
            ->assertJsonPath('data.monthly_amount', '500.00')
            ->assertJsonCount(4, 'data.periods')
            ->assertJsonPath('data.periods.0.invoice.amount', '1500.00')
            ->assertJsonMissingPath('data.termination_reason')
            ->assertJsonMissingPath('data.access')
            ->assertJsonMissingPath('data.private_office_request_id');

        $this->getJson("/api/v1/provider/companies/{$mine->id}/office-leases")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_another_providers_lease_or_company_is_not_found(): void
    {
        $provider = Provider::factory()->create();
        $theirs = $this->clientCompany(Provider::factory()->create());
        $lease = $this->lease($theirs);
        $this->providerAccount($provider);

        $this->getJson("/api/v1/provider/office-leases/{$lease->id}")->assertNotFound();
        $this->getJson("/api/v1/provider/companies/{$theirs->id}/office-leases")->assertNotFound();
    }

    public function test_a_transferred_company_takes_its_leases_along(): void
    {
        $old = Provider::factory()->create();
        $new = Provider::factory()->create();
        $company = $this->clientCompany($old);
        $lease = $this->lease($company);
        $company->update(['provider_id' => $new->id]);

        $this->providerAccount($old);
        $this->getJson("/api/v1/provider/office-leases/{$lease->id}")->assertNotFound();

        $this->providerAccount($new);
        $this->getJson("/api/v1/provider/office-leases/{$lease->id}")->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leasing/ProviderOfficeLeaseTest.php`
Expected: FAIL — 404 on `GET provider/office-leases` (route missing).

- [ ] **Step 3: Resource, authorization, controller, routes**

`app/Http/Resources/ProviderOfficeLeaseResource.php`:

```php
<?php

namespace App\Http\Resources;

/** A lease as its company's provider sees it: prices, periods and invoices, no ADD staff fields. */
class ProviderOfficeLeaseResource extends OfficeLeaseResource
{
    protected function staffFields(): array
    {
        return [];
    }
}
```

`app/Http/Controllers/Api/V1/Provider/Concerns/ActsForProvider.php` — import `App\Domain\Leasing\Models\OfficeLease` and add:

```php
    /** 404, as for companies: the lease of a company this provider does not manage now does not exist for it. */
    protected function authorizeLease(Request $request, OfficeLease $lease): void
    {
        abort_unless($lease->company()->where('provider_id', $this->provider($request)->id)->exists(), 404);
    }
```

`app/Http/Controllers/Api/V1/Provider/ProviderOfficeLeaseController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Identity\Models\Company;
use App\Domain\Leasing\Models\OfficeLease;
use App\Http\Controllers\Api\V1\Admin\OfficeLeaseController;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListOfficeLeasesRequest;
use App\Http\Resources\ProviderOfficeLeaseResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The office leases of the provider's companies — read only
 * (docs/superpowers/specs/2026-10-10-office-leases-design.md).
 */
class ProviderOfficeLeaseController extends Controller
{
    use ActsForProvider;

    public function index(ListOfficeLeasesRequest $request): AnonymousResourceCollection
    {
        $query = OfficeLease::query()->whereHas('company', fn ($q) => $q->where('provider_id', $this->provider($request)->id));

        return ProviderOfficeLeaseResource::collection(
            OfficeLeaseController::filtered($request, $query)
                ->with(['company', 'space.building'])
                ->latest('id')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function show(Request $request, OfficeLease $officeLease): ProviderOfficeLeaseResource
    {
        $this->authorizeLease($request, $officeLease);

        return new ProviderOfficeLeaseResource($officeLease->load(['company', 'space.building', 'periods.invoice']));
    }

    public function forCompany(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorizeCompany($request, $company);

        return ProviderOfficeLeaseResource::collection(
            $company->officeLeases()->with(['company', 'space.building'])->latest('id')->get()
        );
    }
}
```

`routes/api/v1/provider.php` — import the controller and add after the office-request routes:

```php
Route::get('office-leases', [ProviderOfficeLeaseController::class, 'index']);
Route::get('office-leases/{officeLease}', [ProviderOfficeLeaseController::class, 'show']);
Route::get('companies/{company}/office-leases', [ProviderOfficeLeaseController::class, 'forCompany']);
```

`tests/Guards/ProviderRoutesStayIsolatedTest.php` — in the second test's loop, after the invoice check:

```php
            if (str_contains($source, 'OfficeLease $officeLease') && ! str_contains($source, 'authorizeLease(')) {
                $violations[] = basename($file).' (office lease)';
            }
```

and extend its assertion message: `… and one taking an {officeLease} must call authorizeLease().`; update the class docblock's parenthesis to `(authorizeCompany / authorizeInvoice / authorizeLease — 404 otherwise)`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Leasing tests/Guards/ProviderRoutesStayIsolatedTest.php tests/Feature/Providers`
Expected: PASS.

- [ ] **Step 5: Pint and commit**

```bash
./vendor/bin/pint app/Http/Controllers/Api/V1/Provider/ProviderOfficeLeaseController.php app/Http/Resources/ProviderOfficeLeaseResource.php app/Http/Controllers/Api/V1/Provider/Concerns/ActsForProvider.php routes/api/v1/provider.php tests/Guards/ProviderRoutesStayIsolatedTest.php tests/Feature/Leasing/ProviderOfficeLeaseTest.php
git add app/Http/Controllers/Api/V1/Provider/ProviderOfficeLeaseController.php app/Http/Resources/ProviderOfficeLeaseResource.php app/Http/Controllers/Api/V1/Provider/Concerns/ActsForProvider.php routes/api/v1/provider.php tests/Guards/ProviderRoutesStayIsolatedTest.php tests/Feature/Leasing/ProviderOfficeLeaseTest.php
git commit -m "feat(leasing): providers read their companies' leases

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: The company admin's view in the member app

**Files:**
- Create: `app/Http/Controllers/Api/V1/Member/CompanyOfficeLeaseController.php`, `app/Http/Resources/MemberOfficeLeaseResource.php`, `tests/Feature/Leasing/MemberOfficeLeaseTest.php`
- Modify: `routes/api/v1/member.php`

**Interfaces:**
- Consumes: `Company::officeLeases()`, `CompanyPolicy::manageMembers` (company admin = `is_admin` pivot).
- Produces: `GET member/companies/{company}/office-leases` — company admin only (403 otherwise). Fields: `reference`, `status`, `space {id, name}`, `branch_id`, `starts_on`, `ends_on`, `terminated_on`, `access_ends_on`. No prices, periods, invoices or lock data.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leasing/MemberOfficeLeaseTest.php`:

```php
<?php

namespace Tests\Feature\Leasing;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use Laravel\Sanctum\Sanctum;

class MemberOfficeLeaseTest extends LeasingTestCase
{
    private function lease(Company $company): OfficeLease
    {
        $admin = $this->actingAsAdmin();

        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice()), 'contract_ref' => 'C']),
            $admin,
        );
    }

    private function member(Company $company, bool $isAdmin): User
    {
        $user = User::factory()->create();
        $user->assignRole('member');
        $company->members()->attach($user->id, ['is_admin' => $isAdmin, 'door_access_enabled' => true]);

        return $user;
    }

    public function test_the_company_admin_sees_office_and_dates_without_prices(): void
    {
        $company = Company::factory()->create();
        $lease = $this->lease($company);
        Sanctum::actingAs($this->member($company, true), ['member-app']);

        $response = $this->getJson("/api/v1/member/companies/{$company->id}/office-leases")
            ->assertOk()
            ->assertJsonPath('data.0.reference', $lease->reference)
            ->assertJsonPath('data.0.starts_on', $lease->starts_on->toDateString())
            ->assertJsonPath('data.0.ends_on', $lease->ends_on->toDateString())
            ->assertJsonPath('data.0.space.id', $lease->space_id)
            ->assertJsonMissingPath('data.0.monthly_amount')
            ->assertJsonMissingPath('data.0.periods')
            ->assertJsonMissingPath('data.0.currency');
        $this->assertStringNotContainsString('1500', $response->getContent());
    }

    public function test_a_plain_member_or_an_outsider_is_refused(): void
    {
        $company = Company::factory()->create();
        $this->lease($company);

        Sanctum::actingAs($this->member($company, false), ['member-app']);
        $this->getJson("/api/v1/member/companies/{$company->id}/office-leases")->assertForbidden();

        Sanctum::actingAs($this->member(Company::factory()->create(), true), ['member-app']);
        $this->getJson("/api/v1/member/companies/{$company->id}/office-leases")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leasing/MemberOfficeLeaseTest.php`
Expected: FAIL — 404 (route missing).

- [ ] **Step 3: Resource, controller, route**

`app/Http/Resources/MemberOfficeLeaseResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lease as the company admin sees it in the member app: which office and
 * for how long. Deliberately carries no price, period, invoice or lock
 * field (spec decision 9; LockCredentialsNeverReachMemberTest).
 */
class MemberOfficeLeaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'status' => $this->status,
            'space' => ['id' => $this->space->id, 'name' => $this->space->displayTitle()],
            'branch_id' => $this->space->building?->branch_id,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'terminated_on' => $this->terminated_on?->toDateString(),
            'access_ends_on' => $this->accessEndsOn()->toDateString(),
        ];
    }
}
```

`app/Http/Controllers/Api/V1/Member/CompanyOfficeLeaseController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberOfficeLeaseResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** The company's offices and their dates, for its admin (CompanyPolicy::manageMembers). */
class CompanyOfficeLeaseController extends Controller
{
    public function index(Company $company): AnonymousResourceCollection
    {
        Gate::authorize('manageMembers', $company);

        return MemberOfficeLeaseResource::collection(
            $company->officeLeases()->with('space.building')->latest('starts_on')->get()
        );
    }
}
```

`routes/api/v1/member.php` — import the controller; after the `companies/{company}/wallet-allocations` route:

```php

// The company admin's view of the company's office leases — dates only, no
// prices (docs/superpowers/specs/2026-10-10-office-leases-design.md).
Route::get('companies/{company}/office-leases', [CompanyOfficeLeaseController::class, 'index']);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Leasing/MemberOfficeLeaseTest.php tests/Guards/LockCredentialsNeverReachMemberTest.php`
Expected: PASS.

- [ ] **Step 5: Pint and commit**

```bash
./vendor/bin/pint app/Http/Controllers/Api/V1/Member/CompanyOfficeLeaseController.php app/Http/Resources/MemberOfficeLeaseResource.php routes/api/v1/member.php tests/Feature/Leasing/MemberOfficeLeaseTest.php
git add app/Http/Controllers/Api/V1/Member/CompanyOfficeLeaseController.php app/Http/Resources/MemberOfficeLeaseResource.php routes/api/v1/member.php tests/Feature/Leasing/MemberOfficeLeaseTest.php
git commit -m "feat(leasing): company admins see their office leases, without prices

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Leased offices leave the browse list

**Files:**
- Modify: `app/Domain/Foundation/Services/SpaceBrowseService.php`
- Create: `tests/Feature/Leasing/LeasedOfficesLeaveBrowseTest.php`

**Interfaces:**
- Consumes: `Space::officeLeases()`, `OfficeLease::scopeHoldingOn()` (Task 1).
- Produces: `SpaceBrowseService::browse()` omits a space whose lease grants access today.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Leasing/LeasedOfficesLeaveBrowseTest.php`:

```php
<?php

namespace Tests\Feature\Leasing;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Services\SpaceBrowseService;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;

class LeasedOfficesLeaveBrowseTest extends LeasingTestCase
{
    private function officeCount(Branch $branch): int
    {
        return count(app(SpaceBrowseService::class)->browse(['branch_id' => $branch->id, 'space_type' => 'business'])['spaces']);
    }

    public function test_an_office_is_hidden_while_leased_and_back_afterwards(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $leased = $this->leasableOffice($branch, withLock: false);
        $this->leasableOffice($branch, withLock: false);
        $this->assertSame(2, $this->officeCount($branch));

        $lease = OfficeLease::factory()->create(['space_id' => $leased->id, 'starts_on' => now()->toDateString()]);
        $this->assertSame(1, $this->officeCount($branch));

        $lease->update(['status' => OfficeLeaseStatus::Terminated, 'terminated_on' => now()->toDateString()]);
        $this->assertSame(2, $this->officeCount($branch));
    }

    public function test_an_office_leased_from_next_month_is_still_listed_today(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $office = $this->leasableOffice($branch, withLock: false);
        OfficeLease::factory()->create(['space_id' => $office->id, 'starts_on' => now()->addMonthNoOverflow()->toDateString()]);

        $this->assertSame(1, $this->officeCount($branch));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Leasing/LeasedOfficesLeaveBrowseTest.php`
Expected: FAIL — `Failed asserting that 2 is identical to 1`.

- [ ] **Step 3: Filter the browse query**

`app/Domain/Foundation/Services/SpaceBrowseService.php`, in `browse()` step 3, change the query start to:

```php
        // 3. Query Active Spaces for Branch — a private office held by a
        // lease today is not on offer (docs/superpowers/specs/2026-10-10-office-leases-design.md).
        $spacesQuery = Space::query()
            ->active()
            ->whereDoesntHave('officeLeases', fn ($q) => $q->holdingOn(now()->toDateString()))
            ->with(['resources', 'amenities', 'building', 'zone.floor'])
            ->whereHas('building', fn ($q) => $q->where('branch_id', $branch->id));
```

(replace the existing `// 3. Query Active Spaces for Branch` comment line with the two lines above).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Leasing/LeasedOfficesLeaveBrowseTest.php tests/Feature/Foundation tests/Feature/Member`
Expected: PASS (only pre-existing failures, if any, in those folders — compare with `git stash`-free baseline by name: none of the 17 are in them).

- [ ] **Step 5: Pint and commit**

```bash
./vendor/bin/pint app/Domain/Foundation/Services/SpaceBrowseService.php tests/Feature/Leasing/LeasedOfficesLeaveBrowseTest.php
git add app/Domain/Foundation/Services/SpaceBrowseService.php tests/Feature/Leasing/LeasedOfficesLeaveBrowseTest.php
git commit -m "feat(leasing): a leased office is not offered in the space list

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Decision record, spec amendments, CLAUDE.md, Postman

**Files:**
- Create: `docs/decisions/office-leases.md`
- Modify: `docs/superpowers/specs/2026-10-10-office-leases-design.md`, `docs/decisions/service-providers.md`, `CLAUDE.md`, `postman/ADD-OS.postman_collection.json`

**Interfaces:**
- Consumes: everything above. Produces documentation only.

- [ ] **Step 1: Amend the spec**

In `docs/superpowers/specs/2026-10-10-office-leases-design.md`:
- Status line: `**الحالة:** معتمد ومنفّذ (الخطة docs/superpowers/plans/2026-10-11-office-leases.md).`
- Under "الدخول من الباب", replace the bullet `مدير الشركة يرى رمز لوحة المفاتيح في استجابة عقود الأعضاء.` with `لا رمز لوحة مفاتيح في أي استجابة لتطبيق الأعضاء (قرار مقفل: LockCredentialsNeverReachMemberTest)؛ الأعضاء يفتحون الباب من التطبيق.` and in the member-app section remove `ورمز لوحة المفاتيح إن كان فعّالاً`.
- Under "الإنهاء الاستثنائي", add as the first bullet: `terminated_on هو **أول يوم بلا دخول**: الدخول حتى اليوم السابق له. المسموح: من تاريخ البداية حتى تاريخ النهاية. تُلغى الفترات التي تبدأ في terminated_on أو بعده.` and change `تبدأ بعد terminated_on` accordingly.
- Under "إنشاء العقد", change `فاتورة الفترة الأولى` to `فاتورة الفترة الأولى، وفاتورة كل فترة بدأت قبل اليوم (عقد بتاريخ سابق)`.
- Add to "قواعد ثابتة": `مكتب في الصيانة: لا يُصدر له رمز؛ رموز قفله تُلغى كما لأي حجز، ويُعاد الرمز في أول تشغيل يومي بعد عودته.`

- [ ] **Step 2: Write the decision record**

`docs/decisions/office-leases.md`:

```markdown
# Office leases (Phase 3)

**Status:** shipped 2026-10-11. **Owner:** Maryam Asha.
**Spec:** docs/superpowers/specs/2026-10-10-office-leases-design.md (Arabic). **Plan:** docs/superpowers/plans/2026-10-11-office-leases.md.

## What this adds

A company (direct or a provider's) leases one private office (`spaces.space_type = business`) per lease, for whole months, at a fixed money price per month in a currency staff choose. `App\Domain\Leasing` owns `office_leases` and `office_lease_periods`.

- **Every lease comes from a private-office request.** A new company's request is closed by `POST admin/companies`, which now takes the first lease's fields and derives the company's branch from the office; an existing company files another request (`private_office_requests.company_id`) closed by `POST admin/private-office-requests/{id}/lease` (`office-leases.create`). Providers file requests for their companies with a `payer`. Both paths run `OfficeLeaseService::contract()` in one transaction, under the request's and the office's row locks.
- **Billing.** All periods are created with the lease (`LeaseScheduleBuilder`: 1, 3 or 6 months, or the whole term; each period counted from the lease start, so a 31st start returns to the 31st). Period 1, and every period already started, is invoiced at contract; `leases:run-daily` (06:00 Damascus) invoices each later period on its first day. A rent invoice has `points = null` and its fixed amount; `invoices.office_lease_period_id` (unique) links it and prevents a second invoice. Cancelling a rent invoice leaves the period and the lease as they are.
- **Payer.** A direct company pays itself. A provider's company pays as staff chose at contract, else as the request said (`billed_to`).
- **Door.** On the first day the company gets an activated grant on the office lock (`PasscodeIssuanceService::issueForLease()`, replacing the never-called `issueForTenancy()`), expiring at the end of the last access day; members with `door_access_enabled` open it through the existing QR unlock. No keypad code reaches the member app. An office without a lock leases fine and shows `access.warning = no_lock` to staff. Under maintenance the grant is revoked like any other and re-issued on the first run after.
- **States.** `active`, `ended` (the run, after `ends_on`), `terminated`. `terminated_on` is the first day without access; termination (`office-leases.terminate`, reason required) cancels periods starting on or after it and closes the door now or on that date.
- **Who sees what.** Staff: `GET admin/office-leases` (+ filters) and `/{id}` with periods, invoices and access state. Provider: its current companies' leases with prices and invoices, no staff fields (404 for others). Company admin: `GET member/companies/{company}/office-leases`, office and dates only.
- **Browse.** An office held by a lease today is not listed by `SpaceBrowseService`.

## Rulings made while implementing

| Spec says | Done | Why |
|---|---|---|
| Company admin sees the keypad code | No passcode on the member surface | Locked guard `LockCredentialsNeverReachMemberTest` (qr-lock-unlock.md §4). |
| Termination date semantics unstated | `terminated_on` = first day without access | One rule makes "terminate today closes the door now" and "a period starting before the date still bills" both hold. |
| First invoice at contract | Period 1 plus every started period | A back-dated lease's past periods are already due. |
| (silent) Which provider sees a lease | The company's current provider | A transferred company takes its leases along, as it takes its invoices' company. |

## Explicitly not done here

- Auto-renewal (Phase 4), reports (Phase 5).
- Deposits, early-termination fees, changing a running lease's price or term, moving a lease to another office — terminate and contract again.
- Suspending the door for unpaid rent (overdue invoices are shown, staff act).
- A failed TTLock delete is logged and the grant marked revoked, as for every other revocation; the vendor passcode then lapses at the end date it was created with.

## Deploy

`php artisan migrate`, then `php artisan permissions:sync` (creates `office-leases.view|create|terminate`; admin gets them). The scheduler must run (`leases:run-daily`). The migration makes `invoices.points` nullable.
```

- [ ] **Step 3: Cross-reference from the providers record and CLAUDE.md**

`docs/decisions/service-providers.md` — in "## Phase order" change `3 office\nleases` to `3 office leases (shipped — docs/decisions/office-leases.md)`, and in "## Explicitly not done here" remove `**Office leases (Phase 3)**, ` from the first bullet.

`CLAUDE.md` — after the **Service providers** paragraph add:

```markdown
**Office leases** (`docs/decisions/office-leases.md`). A company holds a private office (`space_type = business`) through an `OfficeLease` (`App\Domain\Leasing`): whole months, a fixed money price, periods of 1/3/6 months or the whole term, all created up front. Every lease comes from a `quoted` private-office request — `POST admin/companies` (new company: the body carries the first lease, the branch comes from the office) or `POST admin/private-office-requests/{id}/lease` (existing company) — and both go through `OfficeLeaseService::contract()`; never create a lease, a period or a lease grant elsewhere. Rent is invoiced per period through `InvoiceIssuer::issueForLeasePeriod()` (fixed amount, `points` null, `invoices.office_lease_period_id` unique); `leases:run-daily` issues due periods, opens and closes the office lock (`PasscodeIssuanceService::issueForLease()|revokeForLease()`), and ends expired leases. `terminated_on` is the first day without access. Lease admin routes are permission-gated (`office-leases.view|create|terminate`) like the provider routes; providers read their companies' leases; company admins see dates only.
```

And in the `routes/api/v1/admin.php` bullet's "Documented exceptions" sentence, extend the list: `…and the providers / provider-requests / company-provider / invoices / office-leases routes…` with `permission:office-leases.*` added to the enumerated middleware.

- [ ] **Step 4: Postman**

Add to `postman/ADD-OS.postman_collection.json` (keep the file's 4-space indent and `ensure_ascii` style — use a short Python script like the one in commit `abf18ff`, which loads, inserts and dumps with `indent=4, ensure_ascii=True`):

- Folder **Admin (Dashboard) → Office Leases** (new): `List Office Leases` (`GET admin/office-leases`, query params `company_id`, `provider_id`, `space_id`, `branch_id`, `status`, `ending_within_days`, `per_page`, all disabled), `Show Office Lease` (`GET admin/office-leases/{{office_lease_id}}`), `Create Lease from Request` (`POST admin/private-office-requests/{{private_office_request_id}}/lease`, body `{"contract_ref": "C-2026-014", "space_id": "{{space_id}}", "starts_on": "2026-11-01", "duration_months": 12, "billing_cycle_months": 3, "monthly_amount": "500.00", "currency": "USD", "billed_to": "provider"}`), `Terminate Lease` (`POST admin/office-leases/{{office_lease_id}}/terminate`, body `{"terminated_on": "2027-05-01", "reason": "Contract clause 7"}`). Descriptions name the permission and, for terminate, that `terminated_on` is the first day without access.
- **Admin → Companies → Create Company**: body becomes `{"private_office_request_id": "{{private_office_request_id}}", "legal_name": "Acme LLC", "contract_ref": "C-2026-013", "space_id": "{{space_id}}", "starts_on": "2026-11-01", "duration_months": 12, "billing_cycle_months": 3, "monthly_amount": "500.00", "currency": "USD"}`; description: `Creates the company, its wallet and its first lease; the branch is the office's.`
- **Admin → Private Office Requests → Create**: add `"company_id": null, "payer": null` to the body with a description line `company_id: another office for an existing company.`
- **Provider (Dashboard)**: `Create Office Request` body gains `"company_id": "{{provider_company_id}}", "payer": "provider"` (description: `company_id optional — omit for a new client company; payer required.`); new `List Office Leases`, `Show Office Lease`, `Company Office Leases` (`GET provider/companies/{{provider_company_id}}/office-leases`).
- **Member (App) → 6. Company**: `Company Office Leases` (`GET member/companies/{{company_id}}/office-leases`, description `Company admin only. Office and dates, no prices.`).

Check with `git diff --stat postman/` that only additions and the edited bodies changed.

- [ ] **Step 5: Full suite**

Run: `php artisan test` (takes several minutes — run it in the background and wait).
Expected: exactly the 17 pre-existing failures, no new ones. `./vendor/bin/pint --test` on every file this plan touched: clean.

- [ ] **Step 6: Commit**

```bash
git add docs/decisions/office-leases.md docs/superpowers/specs/2026-10-10-office-leases-design.md docs/decisions/service-providers.md CLAUDE.md postman/ADD-OS.postman_collection.json
git commit -m "docs(leasing): decision record, spec rulings, CLAUDE.md and Postman

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
