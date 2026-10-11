# Auto-Renewal (Phase 4) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A provider company's plan subscription, and any office lease, can renew automatically a few days before it ends — the next subscription created (or the lease extended) and invoiced in advance — and when it can't (an overdue invoice, no seats, the office taken, …) the renewal stops with a reason staff and the provider can see, and staff can renew by hand.

**Architecture:** A new `App\Domain\Renewals` domain holds `MembershipRenewalService` (creates the next `Membership` through `MembershipActivationService::activate()` with a future `startsAt`, then `InvoiceIssuer::issueForMembershipRenewal()`), `LeaseRenewalService` (appends periods to the same `OfficeLease` and extends its door grant), `PayerStanding` (overdue check), `RenewalSettings` (turn auto-renew on/off) and `RenewalRunner` (command `renewals:run`, hourly). Refusals are `RenewalRefused(RenewalStopReason)`, stored on the subject as `renewal_stop_reason`. "Live" membership becomes "started and not ended", and plan balances are granted when a membership starts, not when it is created.

**Tech Stack:** Laravel 12, PHP 8.3, bcmath, Carbon, spatie/laravel-permission, spatie/activitylog, PHPUnit feature tests on in-memory SQLite, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-10-11-auto-renewal-design.md` (Arabic). Builds on `docs/decisions/service-providers.md` and `docs/decisions/office-leases.md`.

## Global Constraints

- Run PHP as `php` from the repo root (Git Bash: `cd "/c/Users/User/Desktop/Aleppo Digital District/ADDCore"`). Tests: `php artisan test <path>`; style: `./vendor/bin/pint --test <files>` on every touched PHP file (Pint only the files you touched).
- The full suite has **17 pre-existing failures** (AuditLoggingTest ×2, ExchangeRateSuggestionControllerTest dismiss, SettingControllerTest audit, BookingReceptionControllerTest check-in, SiteInquiryTest ×2, ResourceControllerTest status logged, MemberOperationsIsolationTest recovery, AdminNotificationControllerTest broadcast, MemberDeviceTokenControllerTest ×3, MemberNotificationControllerTest ×3, EnumColumnsHaveBackedEnumCastsTest's EventAttendee violation). A task is green when it adds none.
- The working tree carries unrelated uncommitted edits (`app/Http/Controllers/Api/V1/Auth/MemberAuthController.php`, `app/Http/Resources/CurrencyResource.php`, `tests/Feature/Identity/MemberLoginTest.php`, `tests/Feature/Public/CurrencyControllerTest.php`) and an untracked `.claude/launch.json` — never stage, commit, revert or reformat them. Stage files by explicit path.
- Money stays bcmath strings; money columns `decimal`. Enum-shaped columns are `string` + backed-enum cast, registered in `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`.
- "Today" for leases is `now()->toDateString()` (as `LeaseDailyRunner`); membership times are timestamps compared with `now()`.
- Update/action endpoints answer `{"message": __('api.<group>.<key>')}`. Copy goes in `lang/en/api.php` **and** `lang/ar/api.php` in a new top-level `'renewal' => [...]` group added once at the end of each file (grep first: no `'renewal' =>` top-level key exists).
- Never create a membership outside `MembershipActivationService::activate()`, never touch lease periods outside `App\Domain\Leasing`/`App\Domain\Renewals` services, never issue an invoice outside `InvoiceIssuer`.
- Code, comments and docs in English. Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Never read or search `vendor/`, `storage/logs`, `storage/framework`, `bootstrap/cache`. Never run migrations against the MySQL DB in `.env`.

**Refinements of the spec (Task 9 writes them into the decision record):**

| Spec says | Plan does | Why |
|---|---|---|
| A renewal invoice for a zero-price plan | No invoice is issued (as Phase 2 does for zero-point plans); the membership still renews | An invoice of 0 is noise and Phase 2 already refuses it. |
| (silent) Where membership renewal invoices point | `invoices.membership_id` on the **renewal** membership; the chain's source invoice is the renewal invoice, else the plan-assignment request's invoice (`provider_requests.result_id` = membership id) | One FK per source (Phase 2 rule); the first link already exists through the request. |
| (silent) Turning auto-renew on for a lease that is not `active` | Refused 409 `api.renewal.lease_not_active` | Only a running lease has a next cycle. |
| (silent) Provider toggle when the company has no renewable subscription | 404 | The provider learns nothing about a subscription it did not assign. |

## Review Focus

1. **The renewal subscription exists a week before it starts** → it must not be "the company's subscription" until it starts: not `live`, not returned by `MembershipResolver::activeForOwner()`, its plan balances not yet credited (Task 2 pins it).
2. **The hourly job runs twice, or two runs overlap** → exactly one renewal membership and one invoice per subscription (unique `renewed_from_id` + row lock + "already renewed" check) (Task 3 and Task 5 pin it).
3. **The payer settles the overdue invoice mid-window** → the next run renews and clears the stop reason; after the end date no automatic attempt happens (Task 5 pins it).
4. **A lease is extended while its door grant runs to the old end** → the old grant is revoked and a new one runs to the new end, so the tenant is never locked out at the old end (Task 4 pins it).
5. **The company moved to another provider before renewal** → the renewal invoice belongs to the current provider; payer and currency still follow the previous invoice (Task 3 pins it).

## File Map

**Create**
- `database/migrations/2026_10_12_100000_add_auto_renewal_columns.php`
- `app/Domain/Renewals/Enums/RenewalStopReason.php`
- `app/Domain/Renewals/Exceptions/{RenewalRefused,RenewalException}.php`
- `app/Domain/Renewals/Services/{PayerStanding,MembershipRenewalService,LeaseRenewalService,RenewalSettings,RenewalRunner}.php`
- `app/Console/Commands/RunRenewals.php`
- `app/Http/Controllers/Api/V1/Admin/RenewalController.php`, `app/Http/Controllers/Api/V1/Provider/ProviderRenewalController.php`
- `app/Http/Requests/Admin/{UpdateAutoRenewRequest,ListStoppedRenewalsRequest}.php`
- `tests/Feature/Renewals/*`
- `docs/decisions/auto-renewal.md`

**Modify**
- `app/Domain/Membership/Models/Membership.php`, `app/Domain/Membership/Services/MembershipActivationService.php`
- `app/Domain/Leasing/Models/OfficeLease.php`, `app/Domain/Leasing/Data/LeaseTerms.php`, `app/Domain/Leasing/Services/OfficeLeaseService.php`
- `app/Domain/Invoicing/Models/Invoice.php`, `app/Domain/Invoicing/Services/InvoiceIssuer.php`
- `app/Domain/Providers/Requests/Handlers/PlanAssignmentHandler.php`, `app/Http/Requests/Provider/StoreProviderRequestRequest.php`
- `app/Http/Requests/Admin/Concerns/ValidatesLeaseTerms.php`
- `app/Http/Resources/OfficeLeaseResource.php`, `app/Http/Resources/Provider/ProviderCompanyResource.php`, `app/Http/Controllers/Api/V1/Provider/ProviderCompanyController.php`
- `app/Services/Permissions/PermissionSyncService.php`, `database/seeders/SettingSeeder.php`
- `routes/api/v1/{admin,provider}.php`, `routes/console.php`, `lang/{en,ar}/api.php`
- `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
- `CLAUDE.md`, `postman/ADD-OS.postman_collection.json`, `docs/superpowers/specs/2026-10-11-auto-renewal-design.md`

---

### Task 1: Schema, enum, setting, copy

**Files:**
- Create: `database/migrations/2026_10_12_100000_add_auto_renewal_columns.php`, `app/Domain/Renewals/Enums/RenewalStopReason.php`, `tests/Feature/Renewals/RenewalSchemaTest.php`
- Modify: `app/Domain/Membership/Models/Membership.php`, `app/Domain/Leasing/Models/OfficeLease.php`, `app/Domain/Invoicing/Models/Invoice.php`, `database/seeders/SettingSeeder.php`, `lang/en/api.php`, `lang/ar/api.php`, `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`

**Interfaces:**
- Produces: `memberships.auto_renew|renewed_from_id|renewal_stop_reason|balances_granted_at`; `office_leases.auto_renew|renewal_stop_reason|renewals_count`; `invoices.membership_id` (unique).
- Produces: `RenewalStopReason` cases `OverdueInvoice='overdue_invoice'`, `NoSeats='no_seats'`, `PlanInactive='plan_inactive'`, `CompanyInactive='company_inactive'`, `OfficeUnavailable='office_unavailable'`, `OfficeTaken='office_taken'`, with `label(): string`.
- Produces: `Membership::renewedFrom(): BelongsTo`, `Membership::renewal(): HasOne`; `Invoice::membership(): BelongsTo`; setting `renewals.advance_days` = 7; lang group `api.renewal.*`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Renewals/RenewalSchemaTest.php`:

```php
<?php

namespace Tests\Feature\Renewals;

use App\Domain\Identity\Models\Company;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenewalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_membership_carries_its_renewal_fields_and_chain(): void
    {
        $company = Company::factory()->create();
        $first = Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id, 'auto_renew' => true]);
        $next = Membership::factory()->create([
            'owner_type' => OwnerType::Company, 'owner_id' => $company->id,
            'renewed_from_id' => $first->id,
            'current_period_start' => $first->current_period_end,
            'current_period_end' => $first->current_period_end->copy()->addDays(30),
        ]);
        $first->update(['renewal_stop_reason' => RenewalStopReason::NoSeats]);

        $this->assertTrue($first->fresh()->auto_renew);
        $this->assertSame(RenewalStopReason::NoSeats, $first->fresh()->renewal_stop_reason);
        $this->assertTrue($first->renewal->is($next));
        $this->assertTrue($next->renewedFrom->is($first));
        $this->assertFalse($next->fresh()->auto_renew);
    }

    public function test_a_lease_carries_its_renewal_fields(): void
    {
        $this->seed(CurrencySeeder::class);
        $lease = OfficeLease::factory()->create(['auto_renew' => true]);

        $this->assertTrue($lease->fresh()->auto_renew);
        $this->assertSame(0, $lease->fresh()->renewals_count);
        $this->assertNull($lease->fresh()->renewal_stop_reason);
    }

    public function test_every_reason_has_a_label_in_both_languages(): void
    {
        foreach (RenewalStopReason::cases() as $reason) {
            app()->setLocale('en');
            $this->assertNotSame('api.renewal.reason.'.$reason->value, $reason->label());
            app()->setLocale('ar');
            $this->assertNotSame('api.renewal.reason.'.$reason->value, $reason->label());
        }
    }

    public function test_the_advance_days_setting_defaults_to_seven(): void
    {
        $this->seed(SettingSeeder::class);

        $this->assertSame(7, (int) app(SettingService::class)->get('renewals.advance_days'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Renewals/RenewalSchemaTest.php`
Expected: FAIL — `Class "App\Domain\Renewals\Enums\RenewalStopReason" not found`.

- [ ] **Step 3: Migration**

`database/migrations/2026_10_12_100000_add_auto_renewal_columns.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auto-renewal (docs/superpowers/specs/2026-10-11-auto-renewal-design.md).
     * A renewed subscription is a new membership pointing at the one it
     * renews (unique: one renewal each); a renewed lease is the same lease
     * with more periods. Balances are granted when a membership starts, so
     * every existing membership — all started at creation — is marked
     * granted at its creation time.
     */
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false)->after('status');
            $table->foreignId('renewed_from_id')->nullable()->unique()->after('auto_renew')
                ->constrained('memberships')->nullOnDelete();
            $table->string('renewal_stop_reason', 30)->nullable()->after('renewed_from_id');
            $table->timestamp('balances_granted_at')->nullable()->after('current_period_end');
        });

        DB::table('memberships')->update(['balances_granted_at' => DB::raw('created_at')]);

        Schema::table('office_leases', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false)->after('status');
            $table->string('renewal_stop_reason', 30)->nullable()->after('auto_renew');
            $table->unsignedSmallInteger('renewals_count')->default(0)->after('renewal_stop_reason');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('membership_id')->nullable()->unique()->after('office_lease_period_id')
                ->constrained('memberships')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropConstrainedForeignId('membership_id'));
        Schema::table('office_leases', fn (Blueprint $table) => $table->dropColumn(['auto_renew', 'renewal_stop_reason', 'renewals_count']));
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('renewed_from_id');
            $table->dropColumn(['auto_renew', 'renewal_stop_reason', 'balances_granted_at']);
        });
    }
};
```

- [ ] **Step 4: Enum**

`app/Domain/Renewals/Enums/RenewalStopReason.php`:

```php
<?php

namespace App\Domain\Renewals\Enums;

/**
 * Why an automatic renewal did not happen (stored on the subscription or
 * lease as `renewal_stop_reason`). Cleared when a renewal succeeds or
 * auto-renew is turned off.
 */
enum RenewalStopReason: string
{
    case OverdueInvoice = 'overdue_invoice';
    case NoSeats = 'no_seats';
    case PlanInactive = 'plan_inactive';
    case CompanyInactive = 'company_inactive';
    case OfficeUnavailable = 'office_unavailable';
    case OfficeTaken = 'office_taken';

    public function label(): string
    {
        return __('api.renewal.reason.'.$this->value);
    }
}
```

- [ ] **Step 5: Models**

`app/Domain/Membership/Models/Membership.php` — add to `$fillable`: `'auto_renew'`, `'renewed_from_id'`, `'renewal_stop_reason'`, `'balances_granted_at'`; to `casts()`: `'auto_renew' => 'boolean'`, `'renewal_stop_reason' => RenewalStopReason::class`, `'balances_granted_at' => 'datetime'` (import `App\Domain\Renewals\Enums\RenewalStopReason`, `Illuminate\Database\Eloquent\Relations\HasOne`); add:

```php
    /** The subscription this one renews. */
    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }

    /** The subscription that renews this one, once created. */
    public function renewal(): HasOne
    {
        return $this->hasOne(self::class, 'renewed_from_id');
    }
```

`app/Domain/Leasing/Models/OfficeLease.php` — add to `$fillable`: `'auto_renew'`, `'renewal_stop_reason'`, `'renewals_count'`; to `casts()`: `'auto_renew' => 'boolean'`, `'renewal_stop_reason' => RenewalStopReason::class`, `'renewals_count' => 'integer'`.

`app/Domain/Invoicing/Models/Invoice.php` — add `'membership_id'` to `$fillable` (after `'office_lease_period_id'`) and:

```php
    /** The renewal subscription this invoice bills (a renewal's own source). */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
```

(import `App\Domain\Membership\Models\Membership`).

- [ ] **Step 6: Guard, setting, copy**

`tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` — import `App\Domain\Renewals\Enums\RenewalStopReason`; add `'renewal_stop_reason' => RenewalStopReason::class,` to the `OfficeLease::class` entry, and to the `Membership::class` entry if one exists (else add `Membership::class => ['renewal_stop_reason' => RenewalStopReason::class],`, importing `App\Domain\Membership\Models\Membership` if missing).

`database/seeders/SettingSeeder.php` — after the `invoices.default_due_days` line:

```php

        // Auto-renewal: how many days before a subscription or lease ends
        // its renewal is made (docs/superpowers/specs/2026-10-11-auto-renewal-design.md).
        $settings->setDefault('renewals.advance_days', 7, SettingValueType::Int);
```

At the end of `lang/en/api.php`, before the closing `];`:

```php
    'renewal' => [
        'reason' => [
            'overdue_invoice' => 'The payer has an overdue invoice.',
            'no_seats' => 'The branch has no seats left for the next period.',
            'plan_inactive' => 'The plan is no longer active.',
            'company_inactive' => 'The company is not active.',
            'office_unavailable' => 'The office is under maintenance or out of service.',
            'office_taken' => 'The office is leased or booked during the next period.',
        ],
        'refused' => 'Cannot renew: :reason',
        'renewed' => 'Renewed.',
        'auto_renew_updated' => 'Auto-renewal updated.',
        'not_renewable' => 'This subscription does not renew automatically.',
        'already_renewed' => 'This has already been renewed.',
        'lease_not_active' => 'Only an active lease can be renewed.',
    ],
```

At the end of `lang/ar/api.php`, before the closing `];`:

```php
    'renewal' => [
        'reason' => [
            'overdue_invoice' => 'على الدافع فاتورة متأخرة.',
            'no_seats' => 'لم تعد في الفرع مقاعد كافية للفترة القادمة.',
            'plan_inactive' => 'الخطة لم تعد مفعّلة.',
            'company_inactive' => 'الشركة غير نشطة.',
            'office_unavailable' => 'المكتب في الصيانة أو خارج الخدمة.',
            'office_taken' => 'المكتب مؤجَّر أو محجوز خلال الفترة القادمة.',
        ],
        'refused' => 'تعذّر التجديد: :reason',
        'renewed' => 'تم التجديد.',
        'auto_renew_updated' => 'تم تحديث التجديد التلقائي.',
        'not_renewable' => 'هذا الاشتراك لا يتجدد تلقائياً.',
        'already_renewed' => 'تم تجديده من قبل.',
        'lease_not_active' => 'لا يمكن تجديد إلا عقد سارٍ.',
    ],
```

- [ ] **Step 7: Run tests**

Run: `php artisan test tests/Feature/Renewals/RenewalSchemaTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php tests/Feature/Leasing tests/Feature/Invoicing`
Expected: PASS (the guard's pre-existing EventAttendee violation aside).

- [ ] **Step 8: Pint and commit**

```bash
./vendor/bin/pint database/migrations/2026_10_12_100000_add_auto_renewal_columns.php app/Domain/Renewals/Enums/RenewalStopReason.php app/Domain/Membership/Models/Membership.php app/Domain/Leasing/Models/OfficeLease.php app/Domain/Invoicing/Models/Invoice.php database/seeders/SettingSeeder.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php tests/Feature/Renewals/RenewalSchemaTest.php
git add database/migrations/2026_10_12_100000_add_auto_renewal_columns.php app/Domain/Renewals/Enums/RenewalStopReason.php app/Domain/Membership/Models/Membership.php app/Domain/Leasing/Models/OfficeLease.php app/Domain/Invoicing/Models/Invoice.php database/seeders/SettingSeeder.php lang/en/api.php lang/ar/api.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php tests/Feature/Renewals/RenewalSchemaTest.php
git commit -m "feat(renewals): renewal columns, stop reasons, advance-days setting

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: "Live" means started; activation in the future; balances at start

**Files:**
- Modify: `app/Domain/Membership/Models/Membership.php`, `app/Domain/Membership/Services/MembershipActivationService.php`
- Create: `tests/Feature/Renewals/FutureMembershipTest.php`

**Interfaces:**
- Consumes: Task 1 columns.
- Produces: `Membership::scopeLive()` / `isLive()` require `current_period_start <= now`. `MembershipActivationService::activate(..., ?CarbonInterface $startsAt = null, array $attributes = [])` — new trailing named params: the period starts at `$startsAt ?? now()`; `$attributes` (e.g. `auto_renew`, `renewed_from_id`) are merged into the created row; balances are granted (and `balances_granted_at` set) only when the period has started. New `MembershipActivationService::grantDueBalances(Membership $membership): bool` — grants once, under the row lock, when started and not yet granted.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Renewals/FutureMembershipTest.php`:

```php
<?php

namespace Tests\Feature\Renewals;

use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanBalance;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\MembershipActivationService;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\WalletService;
use Tests\Feature\Providers\ProviderTestCase;

class FutureMembershipTest extends ProviderTestCase
{
    private function company(): Company
    {
        $branch = $this->branchWithSeats(10);
        $company = Company::factory()->create(['branch_id' => $branch->id]);
        Wallet::firstOrCreate(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        return $company;
    }

    private function planWithBalance(int $points = 100): Plan
    {
        $plan = Plan::factory()->create(['price_points' => 500, 'duration_days' => 30]);
        PlanBalance::create(['plan_id' => $plan->id, 'category' => WalletTransactionCategory::General, 'amount_points' => $points]);

        return $plan->fresh('balances');
    }

    private function balance(Company $company): int
    {
        $wallets = app(WalletService::class);

        return $wallets->generalBalance($wallets->walletFor(OwnerType::Company, $company->id));
    }

    public function test_a_membership_that_has_not_started_is_not_live(): void
    {
        $company = $this->company();
        $current = Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        $next = Membership::factory()->create([
            'owner_type' => OwnerType::Company, 'owner_id' => $company->id,
            'current_period_start' => now()->addDays(30),
            'current_period_end' => now()->addDays(60),
        ]);

        $this->assertFalse($next->isLive());
        $this->assertFalse(Membership::query()->live()->whereKey($next->id)->exists());
        $this->assertTrue(app(MembershipResolver::class)->activeForOwner(OwnerType::Company, $company->id)->is($current));

        $this->travel(31)->days();
        $this->assertTrue($next->fresh()->isLive());
        $this->assertTrue(app(MembershipResolver::class)->activeForOwner(OwnerType::Company, $company->id)->is($next));
    }

    public function test_a_future_activation_grants_its_balances_only_when_it_starts(): void
    {
        $company = $this->company();
        $plan = $this->planWithBalance(100);
        $activations = app(MembershipActivationService::class);

        $membership = $activations->activate(
            $plan, OwnerType::Company, $company->id,
            charge: false,
            startsAt: now()->addDays(5),
            attributes: ['auto_renew' => true],
        );

        $this->assertTrue($membership->auto_renew);
        $this->assertSame(now()->addDays(5)->timestamp, $membership->current_period_start->timestamp);
        $this->assertSame(now()->addDays(35)->timestamp, $membership->current_period_end->timestamp);
        $this->assertNull($membership->balances_granted_at);
        $this->assertSame(0, $this->balance($company));

        $this->assertFalse($activations->grantDueBalances($membership), 'Not started yet.');

        $this->travel(5)->days();
        $this->assertTrue($activations->grantDueBalances($membership->fresh()));
        $this->assertFalse($activations->grantDueBalances($membership->fresh()), 'Granted once.');
        $this->assertSame(100, $this->balance($company));
        $this->assertNotNull($membership->fresh()->balances_granted_at);
    }

    public function test_an_immediate_activation_grants_at_once_as_before(): void
    {
        $company = $this->company();

        $membership = app(MembershipActivationService::class)->activate($this->planWithBalance(100), OwnerType::Company, $company->id, charge: false);

        $this->assertNotNull($membership->balances_granted_at);
        $this->assertSame(100, $this->balance($company));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Renewals/FutureMembershipTest.php`
Expected: FAIL — the future membership is reported live / unknown named parameter `startsAt`.

- [ ] **Step 3: Live means started**

`app/Domain/Membership/Models/Membership.php`:

```php
    /**
     * Started, active and not yet ended. Nothing writes an `expired` status:
     * a subscription past `current_period_end` is over by its date alone, and
     * a renewal made in advance is not live until its period starts.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', MembershipStatus::Active)
            ->where('current_period_start', '<=', now())
            ->where('current_period_end', '>=', now());
    }
```

and `isLive()`:

```php
    /** The same rule as scopeLive(), for a loaded row. */
    public function isLive(): bool
    {
        return $this->status === MembershipStatus::Active
            && ! $this->current_period_start->isFuture()
            && ! $this->current_period_end->isPast();
    }
```

Leave `scopeEnded()` as it is (a not-yet-started membership is neither live nor ended).

- [ ] **Step 4: Future activation and deferred balances**

`app/Domain/Membership/Services/MembershipActivationService.php`:

1. Signature — append two named parameters after `$auditProperties`:

```php
        array $auditProperties = [],
        ?CarbonInterface $startsAt = null,
        array $attributes = [],
    ): Membership {
```

(import `Carbon\CarbonInterface`), add both to the closure's `use (...)` list, and document them in the docblock: `$startsAt` starts the period later (a renewal made in advance); `$attributes` are extra columns for the new row (`auto_renew`, `renewed_from_id`).

2. Period:

```php
            $periodStart = $startsAt !== null ? $startsAt->copy() : now();
            $periodEnd = $periodStart->copy()->addDays($plan->duration_days);
```

3. Row: `Membership::create([...])` becomes `Membership::create([ ...existing keys..., ...$attributes ])` — keep the existing keys first so `$attributes` can only add.

4. Balances — replace `$this->grantPlanBalances($plan, $membership, $wallet);` with:

```php
            // A renewal made in advance holds its allowance until it starts
            // (grantDueBalances, from the renewal run), or the company would
            // spend next period's allowance in this one.
            if (! $periodStart->isFuture()) {
                $this->grantPlanBalances($plan, $membership, $wallet);
                $membership->forceFill(['balances_granted_at' => now()])->save();
            }
```

5. New public method, before `grantPlanBalances()`:

```php
    /**
     * Grants a started membership's plan balances once (renewals made in
     * advance). False when it has not started or was already granted.
     */
    public function grantDueBalances(Membership $membership): bool
    {
        return DB::transaction(function () use ($membership) {
            $locked = Membership::query()->with('plan.balances')->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->balances_granted_at !== null || $locked->current_period_start->isFuture()) {
                return false;
            }

            $this->grantPlanBalances($locked->plan, $locked, $this->wallets->walletFor($locked->owner_type, (int) $locked->owner_id));
            $locked->forceFill(['balances_granted_at' => now()])->save();

            return true;
        });
    }
```

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/Renewals tests/Feature/Membership tests/Feature/Providers tests/Feature/Booking`
Expected: PASS except pre-existing failures. Any newly failing test that built a membership with `current_period_start` in the future and expected it live is a real behaviour change — report it instead of editing the test.

- [ ] **Step 6: Pint and commit**

```bash
./vendor/bin/pint app/Domain/Membership/Models/Membership.php app/Domain/Membership/Services/MembershipActivationService.php tests/Feature/Renewals/FutureMembershipTest.php
git add app/Domain/Membership/Models/Membership.php app/Domain/Membership/Services/MembershipActivationService.php tests/Feature/Renewals/FutureMembershipTest.php
git commit -m "feat(membership): live means started; activate ahead; grant balances at start

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Renewing a subscription

**Files:**
- Create: `app/Domain/Renewals/Exceptions/RenewalRefused.php`, `app/Domain/Renewals/Exceptions/RenewalException.php`, `app/Domain/Renewals/Services/PayerStanding.php`, `app/Domain/Renewals/Services/MembershipRenewalService.php`, `tests/Feature/Renewals/RenewalsTestCase.php`, `tests/Feature/Renewals/MembershipRenewalTest.php`
- Modify: `app/Domain/Invoicing/Services/InvoiceIssuer.php`

**Interfaces:**
- Consumes: Task 1 columns/enum; Task 2 `activate(..., startsAt:, attributes:)`; `ProviderRequestService::approve(ProviderRequest, User, array $overrides = [], ?InvoiceTerms $terms = null)`; `Invoice::scopeOverdue()`; `provider_requests.result_id` (= membership id for an approved plan assignment).
- Produces: `RenewalRefused(RenewalStopReason $reason)` (public readonly `$reason`); `RenewalException(string $messageKey, int $status = 422, array $params = [])`; `PayerStanding::hasOverdue(Payer $payer, ?int $providerId, int $companyId): bool`; `MembershipRenewalService::origin(Membership): ?ProviderRequest`, `sourceInvoice(Membership): ?Invoice`, `isRenewable(Membership): bool`, `renew(Membership $membership, ?User $actor, bool $checkOverdue = true): Membership`; `InvoiceIssuer::issueForMembershipRenewal(Membership $renewal, Payer $billedTo, ?string $currency, ?User $issuer): ?Invoice`.
- Produces (tests): `Tests\Feature\Renewals\RenewalsTestCase` (extends `Tests\Feature\Leasing\LeasingTestCase`) with `reviewer(): User` and `assignedMembership(Provider $provider, array $payload = [], ?Company $company = null, ?InvoiceTerms $terms = null): Membership`.

- [ ] **Step 1: Test base**

`tests/Feature/Renewals/RenewalsTestCase.php`:

```php
<?php

namespace Tests\Feature\Renewals;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Data\InvoiceTerms;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\ProviderRequestService;
use Tests\Feature\Leasing\LeasingTestCase;

/** Providers, invoices, leases (LeasingTestCase) plus a subscription assigned through a real approval. */
abstract class RenewalsTestCase extends LeasingTestCase
{
    private ?User $reviewer = null;

    /** An admin account that decides requests, without signing in as it. */
    protected function reviewer(): User
    {
        if ($this->reviewer === null) {
            $this->reviewer = User::factory()->create();
            $this->reviewer->assignRole('admin');
        }

        return $this->reviewer;
    }

    /**
     * A 2-seat, 30-day, 500-point plan assigned to a client company of
     * $provider through an approved plan-assignment request (payer: provider,
     * auto_renew: true, invoice in USD unless $terms says otherwise). The
     * company sits in a default branch with 10 co-working seats.
     */
    protected function assignedMembership(Provider $provider, array $payload = [], ?Company $company = null, ?InvoiceTerms $terms = null): Membership
    {
        if ($company === null) {
            $company = $this->clientCompany($provider, ['branch_id' => $this->branchWithSeats(10)->id]);
        }

        $plan = Plan::factory()->create(['price_points' => 500, 'duration_days' => 30]);
        $request = ProviderRequest::factory()->create([
            'provider_id' => $provider->id,
            'company_id' => $company->id,
            'type' => ProviderRequestType::PlanAssignment,
            'payload' => ['plan_id' => $plan->id, 'seats' => 2, 'payer' => 'provider', 'auto_renew' => true, ...$payload],
        ]);

        app(ProviderRequestService::class)->approve($request, $this->reviewer(), [], $terms ?? new InvoiceTerms(currency: 'USD'));

        return Membership::query()->findOrFail($request->fresh()->result_id);
    }
}
```

Note: until Task 6 lands, `PlanAssignmentHandler` ignores `payload.auto_renew`; tests here set `auto_renew` on the membership directly where it matters.

- [ ] **Step 2: Write the failing test**

`tests/Feature/Renewals/MembershipRenewalTest.php`:

```php
<?php

namespace Tests\Feature\Renewals;

use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Invoicing\Data\InvoiceTerms;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Exceptions\RenewalRefused;
use App\Domain\Renewals\Services\MembershipRenewalService;

class MembershipRenewalTest extends RenewalsTestCase
{
    private function service(): MembershipRenewalService
    {
        return app(MembershipRenewalService::class);
    }

    private function assertRefused(callable $action, RenewalStopReason $reason): void
    {
        try {
            $action();
            $this->fail("Expected the renewal to be refused with {$reason->value}.");
        } catch (RenewalRefused $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_the_next_subscription_starts_where_this_one_ends_and_is_invoiced(): void
    {
        $provider = Provider::factory()->create();
        $current = $this->assignedMembership($provider);
        $current->update(['renewal_stop_reason' => RenewalStopReason::NoSeats]);

        $renewal = $this->service()->renew($current, null);

        $this->assertSame($current->current_period_end->timestamp, $renewal->current_period_start->timestamp);
        $this->assertSame($current->current_period_end->copy()->addDays(30)->timestamp, $renewal->current_period_end->timestamp);
        $this->assertSame([$current->plan_id, 2, $current->branch_id], [$renewal->plan_id, $renewal->seats, $renewal->branch_id]);
        $this->assertTrue($renewal->auto_renew);
        $this->assertSame($current->id, $renewal->renewed_from_id);
        $this->assertNull($current->fresh()->renewal_stop_reason);
        $this->assertFalse($renewal->isLive());

        $invoice = Invoice::query()->where('membership_id', $renewal->id)->sole();
        $this->assertSame(1000, $invoice->points);
        $this->assertSame('USD', $invoice->currency);
        $this->assertSame(Payer::Provider, $invoice->billed_to);
        $this->assertSame($provider->id, $invoice->provider_id);
    }

    public function test_the_renewal_charges_the_current_plan_price_in_the_first_invoices_currency(): void
    {
        $this->syp();
        $current = $this->assignedMembership(Provider::factory()->create(), terms: new InvoiceTerms(currency: 'SYP'));
        $current->plan->update(['price_points' => 700]);

        $renewal = $this->service()->renew($current, null);

        $invoice = Invoice::query()->where('membership_id', $renewal->id)->sole();
        $this->assertSame(1400, $invoice->points);
        $this->assertSame('SYP', $invoice->currency);
    }

    public function test_the_chain_keeps_renewing_from_the_last_link(): void
    {
        $current = $this->assignedMembership(Provider::factory()->create());

        $second = $this->service()->renew($current, null);
        $third = $this->service()->renew($second, null);

        $this->assertSame($second->id, $third->renewed_from_id);
        $this->assertSame($second->current_period_end->timestamp, $third->current_period_start->timestamp);
        $this->assertTrue($this->service()->isRenewable($third));
    }

    public function test_a_subscription_is_renewed_once(): void
    {
        $current = $this->assignedMembership(Provider::factory()->create());
        $this->service()->renew($current, null);

        try {
            $this->service()->renew($current->fresh(), null);
            $this->fail('Expected already_renewed.');
        } catch (RenewalException $e) {
            $this->assertSame('api.renewal.already_renewed', $e->messageKey);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(1, Membership::query()->where('renewed_from_id', $current->id)->count());
        $this->assertSame(1, Invoice::query()->whereNotNull('membership_id')->count());
    }

    public function test_an_overdue_invoice_of_the_payer_stops_the_renewal_unless_staff_override(): void
    {
        $provider = Provider::factory()->create();
        $current = $this->assignedMembership($provider);
        Invoice::factory()->create(['provider_id' => $provider->id, 'billed_to' => Payer::Provider, 'due_at' => now()->subDay()->toDateString()]);

        $this->assertRefused(fn () => $this->service()->renew($current, null), RenewalStopReason::OverdueInvoice);
        $this->assertSame(0, Membership::query()->where('renewed_from_id', $current->id)->count());

        $this->assertTrue($this->service()->renew($current, $this->reviewer(), checkOverdue: false)->exists);
    }

    public function test_an_overdue_invoice_of_another_payer_does_not_stop_it(): void
    {
        $provider = Provider::factory()->create();
        $current = $this->assignedMembership($provider);
        $company = Company::query()->findOrFail($current->owner_id);
        Invoice::factory()->create(['provider_id' => $provider->id, 'company_id' => $company->id, 'billed_to' => Payer::Company, 'due_at' => now()->subDay()->toDateString()]);

        $this->assertTrue($this->service()->renew($current, null)->exists);
    }

    public function test_refusals(): void
    {
        $provider = Provider::factory()->create();

        $inactiveCompany = $this->assignedMembership($provider);
        Company::query()->whereKey($inactiveCompany->owner_id)->update(['status' => CompanyStatus::Inactive]);
        $this->assertRefused(fn () => $this->service()->renew($inactiveCompany, null), RenewalStopReason::CompanyInactive);

        $inactivePlan = $this->assignedMembership($provider, company: $this->clientCompany($provider, ['branch_id' => $inactiveCompany->branch_id]));
        $inactivePlan->plan->update(['is_active' => false]);
        $this->assertRefused(fn () => $this->service()->renew($inactivePlan, null), RenewalStopReason::PlanInactive);

        $noSeats = $this->assignedMembership($provider, company: $this->clientCompany($provider, ['branch_id' => $inactiveCompany->branch_id]));
        Space::query()->whereHas('building', fn ($q) => $q->where('branch_id', $noSeats->branch_id))->update(['capacity' => 1]);
        $this->assertRefused(fn () => $this->service()->renew($noSeats, null), RenewalStopReason::NoSeats);
    }

    public function test_a_subscription_not_assigned_by_a_provider_is_not_renewable(): void
    {
        $company = Company::factory()->create();
        $membership = Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $this->assertFalse($this->service()->isRenewable($membership));
        $this->expectException(RenewalException::class);

        $this->service()->renew($membership, null);
    }

    public function test_after_a_transfer_the_renewal_belongs_to_the_new_provider(): void
    {
        $old = Provider::factory()->create();
        $new = Provider::factory()->create();
        $current = $this->assignedMembership($old);
        Company::query()->whereKey($current->owner_id)->update(['provider_id' => $new->id]);

        $renewal = $this->service()->renew($current, null);

        $invoice = Invoice::query()->where('membership_id', $renewal->id)->sole();
        $this->assertSame($new->id, $invoice->provider_id);
        $this->assertSame(Payer::Provider, $invoice->billed_to);
    }

    public function test_a_free_plan_renews_without_an_invoice(): void
    {
        $current = $this->assignedMembership(Provider::factory()->create());
        $current->plan->update(['price_points' => 0]);

        $renewal = $this->service()->renew($current, null);

        $this->assertTrue($renewal->exists);
        $this->assertSame(0, Invoice::query()->where('membership_id', $renewal->id)->count());
    }

    public function test_a_subscription_that_already_ended_renews_from_now(): void
    {
        $current = $this->assignedMembership(Provider::factory()->create());
        $this->travel(40)->days();

        $renewal = $this->service()->renew($current->fresh(), $this->reviewer(), checkOverdue: false);

        $this->assertEqualsWithDelta(now()->timestamp, $renewal->current_period_start->timestamp, 2);
        $this->assertTrue($renewal->isLive());
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Renewals/MembershipRenewalTest.php`
Expected: FAIL — `Class "App\Domain\Renewals\Services\MembershipRenewalService" not found`.

- [ ] **Step 4: Exceptions and payer standing**

`app/Domain/Renewals/Exceptions/RenewalRefused.php`:

```php
<?php

namespace App\Domain\Renewals\Exceptions;

use App\Domain\Renewals\Enums\RenewalStopReason;
use RuntimeException;

/** A renewal that cannot happen now, for a reason stored on the subject. */
class RenewalRefused extends RuntimeException
{
    public function __construct(public readonly RenewalStopReason $reason)
    {
        parent::__construct($reason->value);
    }
}
```

`app/Domain/Renewals/Exceptions/RenewalException.php`:

```php
<?php

namespace App\Domain\Renewals\Exceptions;

use RuntimeException;

/** A renewal action refused outright (not renewable, already renewed, …), with its message and status. */
class RenewalException extends RuntimeException
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

`app/Domain/Renewals/Services/PayerStanding.php`:

```php
<?php

namespace App\Domain\Renewals\Services;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Providers\Enums\Payer;

/**
 * Whether a payer is behind: any open invoice past its due date billed to
 * it — the provider's own invoices (every company of it), or the company's.
 */
class PayerStanding
{
    public function hasOverdue(Payer $payer, ?int $providerId, int $companyId): bool
    {
        $query = Invoice::query()->overdue()->where('billed_to', $payer);

        return ($payer === Payer::Provider
            ? $query->where('provider_id', $providerId)
            : $query->where('company_id', $companyId)
        )->exists();
    }
}
```

- [ ] **Step 5: The renewal invoice**

`app/Domain/Invoicing/Services/InvoiceIssuer.php` — imports `App\Domain\Identity\Models\Company`, `App\Domain\Membership\Models\Membership`; add after `issueForLeasePeriod()`:

```php
    /**
     * Bills a renewal subscription: its plan's price today × its seats, in
     * $currency (the previous invoice's; null = base), to $billedTo, for the
     * company's current provider. Null for a free plan. The caller owns
     * the transaction (MembershipRenewalService::renew()).
     */
    public function issueForMembershipRenewal(Membership $renewal, Payer $billedTo, ?string $currency, ?User $issuer): ?Invoice
    {
        $renewal->loadMissing('plan');
        $points = (int) $renewal->plan->price_points * $renewal->seats;

        if ($points <= 0) {
            return null;
        }

        $company = Company::query()->findOrFail($renewal->owner_id);

        return $this->issue(new InvoiceDraft(
            companyId: $company->id,
            providerId: $company->provider_id,
            billedTo: $billedTo,
            points: $points,
            description: sprintf(
                'Plan renewal: %s × %d seats, %d days',
                $renewal->plan->translate('name', 'en'),
                $renewal->seats,
                (int) $renewal->plan->duration_days,
            ),
            source: ['membership_id' => $renewal->id],
        ), new InvoiceTerms(currency: $currency), $issuer);
    }
```

and extend the class docblock's wrapper list with `issueForMembershipRenewal`.

- [ ] **Step 6: The renewal service**

`app/Domain/Renewals/Services/MembershipRenewalService.php`:

```php
<?php

namespace App\Domain\Renewals\Services;

use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoiceIssuer;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Exceptions\SeatPoolExhaustedException;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Services\MembershipActivationService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Exceptions\RenewalRefused;
use Illuminate\Support\Facades\DB;

/**
 * Renews a provider company's subscription
 * (docs/superpowers/specs/2026-10-11-auto-renewal-design.md): the next
 * membership starts where this one ends (or now, if it already ended),
 * same plan, seats and branch, invoiced at the plan's price today in the
 * previous invoice's currency to the payer the provider chose at
 * assignment. Only a subscription that came from a plan-assignment
 * request (or a renewal of one) is renewable.
 */
class MembershipRenewalService
{
    private const MAX_CHAIN = 1000;

    public function __construct(
        private readonly MembershipActivationService $activations,
        private readonly InvoiceIssuer $invoices,
        private readonly PayerStanding $standing,
    ) {}

    /** The plan-assignment request at the root of this subscription's chain, if any. */
    public function origin(Membership $membership): ?ProviderRequest
    {
        $root = $membership;

        for ($i = 0; $root->renewed_from_id !== null && $i < self::MAX_CHAIN; $i++) {
            $root = Membership::query()->findOrFail($root->renewed_from_id);
        }

        return ProviderRequest::query()
            ->where('type', ProviderRequestType::PlanAssignment)
            ->where('result_id', $root->id)
            ->first();
    }

    /** The invoice this subscription was billed with: its renewal invoice, else its request's. */
    public function sourceInvoice(Membership $membership): ?Invoice
    {
        return Invoice::query()->where('membership_id', $membership->id)->first()
            ?? Invoice::query()->whereHas('providerRequest', fn ($q) => $q
                ->where('type', ProviderRequestType::PlanAssignment)
                ->where('result_id', $membership->id))->first();
    }

    public function isRenewable(Membership $membership): bool
    {
        return $membership->owner_type === OwnerType::Company && $this->origin($membership) !== null;
    }

    /**
     * @throws RenewalRefused when a check fails (the caller stores the reason)
     * @throws RenewalException when not renewable or already renewed
     */
    public function renew(Membership $membership, ?User $actor, bool $checkOverdue = true): Membership
    {
        return DB::transaction(function () use ($membership, $actor, $checkOverdue) {
            $locked = Membership::query()->with('plan')->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();

            if (Membership::query()->where('renewed_from_id', $locked->id)->exists()) {
                throw new RenewalException('api.renewal.already_renewed', 409);
            }

            $origin = $locked->owner_type === OwnerType::Company ? $this->origin($locked) : null;

            if ($origin === null) {
                throw new RenewalException('api.renewal.not_renewable');
            }

            $company = Company::query()->findOrFail($locked->owner_id);
            // A company that has since become direct pays its own way.
            $payer = $company->provider_id === null ? Payer::Company : Payer::from($origin->payload['payer']);

            if ($checkOverdue && $this->standing->hasOverdue($payer, $company->provider_id, $company->id)) {
                throw new RenewalRefused(RenewalStopReason::OverdueInvoice);
            }

            if ($company->status !== CompanyStatus::Active) {
                throw new RenewalRefused(RenewalStopReason::CompanyInactive);
            }

            if (! $locked->plan->is_active) {
                throw new RenewalRefused(RenewalStopReason::PlanInactive);
            }

            $startsAt = $locked->current_period_end->isFuture() ? $locked->current_period_end : now();

            try {
                $renewal = $this->activations->activate(
                    $locked->plan,
                    OwnerType::Company,
                    $company->id,
                    charge: false,
                    auditAction: 'membership_renewed',
                    branchId: $locked->branch_id,
                    seats: $locked->seats,
                    auditProperties: ['renewed_from_id' => $locked->id, 'automatic' => $actor === null],
                    startsAt: $startsAt,
                    attributes: ['auto_renew' => true, 'renewed_from_id' => $locked->id],
                );
            } catch (SeatPoolExhaustedException) {
                throw new RenewalRefused(RenewalStopReason::NoSeats);
            }

            $this->invoices->issueForMembershipRenewal($renewal, $payer, $this->sourceInvoice($locked)?->currency, $actor);

            $locked->update(['renewal_stop_reason' => null]);

            return $renewal;
        });
    }
}
```

- [ ] **Step 7: Run tests**

Run: `php artisan test tests/Feature/Renewals tests/Feature/Invoicing tests/Feature/Providers`
Expected: PASS.

- [ ] **Step 8: Pint and commit**

```bash
./vendor/bin/pint app/Domain/Renewals app/Domain/Invoicing/Services/InvoiceIssuer.php tests/Feature/Renewals/RenewalsTestCase.php tests/Feature/Renewals/MembershipRenewalTest.php
git add app/Domain/Renewals/Exceptions app/Domain/Renewals/Services/PayerStanding.php app/Domain/Renewals/Services/MembershipRenewalService.php app/Domain/Invoicing/Services/InvoiceIssuer.php tests/Feature/Renewals/RenewalsTestCase.php tests/Feature/Renewals/MembershipRenewalTest.php
git commit -m "feat(renewals): renew a provider company's subscription with its invoice

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Extending a lease

**Files:**
- Create: `app/Domain/Renewals/Services/LeaseRenewalService.php`, `tests/Feature/Renewals/LeaseRenewalTest.php`

**Interfaces:**
- Consumes: `RenewalRefused`, `RenewalException`, `PayerStanding` (Task 3); `LeaseScheduleBuilder::endsOn()|build()`, `OfficeLease::scopeOverlapping()`, `OfficeLeaseService::closeDoor()|openDoor()` (Phase 3); `AvailabilityService::hasOpenBookingsOn(Space $space, string $fromDate, string $toDate): bool`.
- Produces: `LeaseRenewalService::extend(OfficeLease $lease, ?User $actor, bool $checkOverdue = true): OfficeLease` — appends one more term (`duration_months`) of periods to the same lease, moves `ends_on`, increments `renewals_count`, clears `renewal_stop_reason`, then re-issues the door grant to the new end.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Renewals/LeaseRenewalTest.php`:

```php
<?php

namespace Tests\Feature\Renewals;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Exceptions\RenewalRefused;
use App\Domain\Renewals\Services\LeaseRenewalService;

class LeaseRenewalTest extends RenewalsTestCase
{
    private function lease(Space $office, array $overrides = []): OfficeLease
    {
        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]),
            LeaseTerms::fromArray([...$this->leasePayload($office, ['starts_on' => now()->toDateString()]), 'contract_ref' => 'C', ...$overrides]),
            $this->reviewer(),
        );
    }

    private function assertRefused(callable $action, RenewalStopReason $reason): void
    {
        try {
            $action();
            $this->fail("Expected {$reason->value}.");
        } catch (RenewalRefused $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_the_lease_gains_another_term_of_periods_and_its_door_runs_to_the_new_end(): void
    {
        $this->fakeTtlock();
        $lease = $this->lease($this->leasableOffice());
        $oldEnd = $lease->ends_on;
        $oldGrant = $lease->accessGrants()->sole();
        $lease->update(['renewal_stop_reason' => RenewalStopReason::OfficeTaken]);

        $extended = app(LeaseRenewalService::class)->extend($lease, null);

        $this->assertSame($oldEnd->addDay()->addMonthsNoOverflow(12)->subDay()->toDateString(), $extended->ends_on->toDateString());
        $this->assertSame(1, $extended->renewals_count);
        $this->assertNull($extended->renewal_stop_reason);
        $periods = $extended->periods()->get();
        $this->assertCount(8, $periods);
        $this->assertSame([5, 6, 7, 8], $periods->where('status', LeasePeriodStatus::Upcoming)->where('sequence', '>', 4)->pluck('sequence')->values()->all());
        $this->assertSame($oldEnd->addDay()->toDateString(), $periods->firstWhere('sequence', 5)->starts_on->toDateString());
        $this->assertSame('1500.00', (string) $periods->firstWhere('sequence', 8)->amount);

        $this->assertSame(AccessGrantStatus::Revoked, $oldGrant->fresh()->status);
        $live = $extended->accessGrants()->where('status', AccessGrantStatus::Activated)->sole();
        $this->assertSame($extended->ends_on->endOfDay()->timestamp, $live->expires_at->timestamp);
    }

    public function test_a_lease_from_the_31st_keeps_its_day(): void
    {
        $lease = $this->lease($this->leasableOffice(withLock: false), ['starts_on' => '2026-01-31', 'duration_months' => 2, 'billing_cycle_months' => 1]);

        $extended = app(LeaseRenewalService::class)->extend($lease, null);

        $this->assertSame(['2026-03-31', '2026-04-30'], $extended->periods()->where('sequence', '>', 2)->get()->map(fn ($p) => $p->starts_on->toDateString())->all());
        $this->assertSame('2026-05-30', $extended->ends_on->toDateString());
    }

    public function test_an_office_leased_or_booked_in_the_next_term_stops_the_extension(): void
    {
        $office = $this->leasableOffice(withLock: false);
        $lease = $this->lease($office, ['duration_months' => 3, 'billing_cycle_months' => 3]);

        $booking = Booking::factory()->create([
            'space_id' => $office->id,
            'status' => BookingStatus::Confirmed,
            'start_at' => $lease->ends_on->addDays(10)->setTime(10, 0),
            'end_at' => $lease->ends_on->addDays(10)->setTime(11, 0),
        ]);
        $this->assertRefused(fn () => app(LeaseRenewalService::class)->extend($lease, null), RenewalStopReason::OfficeTaken);

        $booking->update(['status' => BookingStatus::Cancelled]);
        $this->assertSame(1, app(LeaseRenewalService::class)->extend($lease, null)->renewals_count);
    }

    public function test_another_lease_after_this_one_stops_the_extension(): void
    {
        $office = $this->leasableOffice(withLock: false);
        $lease = $this->lease($office, ['duration_months' => 3, 'billing_cycle_months' => 3]);
        $this->lease($office, ['starts_on' => $lease->ends_on->addMonthsNoOverflow(1)->toDateString(), 'duration_months' => 3, 'billing_cycle_months' => 3]);

        $this->assertRefused(fn () => app(LeaseRenewalService::class)->extend($lease, null), RenewalStopReason::OfficeTaken);
        $this->assertSame(0, $lease->fresh()->renewals_count);
    }

    public function test_maintenance_overdue_and_inactive_lease(): void
    {
        $office = $this->leasableOffice(withLock: false);
        $lease = $this->lease($office);

        $office->update(['status' => OperationalStatus::Maintenance]);
        $this->assertRefused(fn () => app(LeaseRenewalService::class)->extend($lease, null), RenewalStopReason::OfficeUnavailable);
        $office->update(['status' => OperationalStatus::Active]);

        Invoice::factory()->create(['provider_id' => null, 'company_id' => $lease->company_id, 'billed_to' => Payer::Company, 'due_at' => now()->subDay()->toDateString()]);
        $this->assertRefused(fn () => app(LeaseRenewalService::class)->extend($lease, null), RenewalStopReason::OverdueInvoice);
        $this->assertSame(1, app(LeaseRenewalService::class)->extend($lease, $this->reviewer(), checkOverdue: false)->renewals_count);

        app(OfficeLeaseService::class)->terminate($lease->fresh(), $lease->starts_on->toDateString(), 'x', $this->reviewer());
        try {
            app(LeaseRenewalService::class)->extend($lease->fresh(), $this->reviewer(), checkOverdue: false);
            $this->fail('Expected lease_not_active.');
        } catch (RenewalException $e) {
            $this->assertSame('api.renewal.lease_not_active', $e->messageKey);
        }
    }
}
```

Check `BookingFactory` for its required columns (user, times) before running and set any it needs.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Renewals/LeaseRenewalTest.php`
Expected: FAIL — `Class "App\Domain\Renewals\Services\LeaseRenewalService" not found`.

- [ ] **Step 3: The service**

`app/Domain/Renewals/Services/LeaseRenewalService.php`:

```php
<?php

namespace App\Domain\Renewals\Services;

use App\Domain\Booking\Services\AvailabilityService;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use App\Domain\Leasing\Services\LeaseScheduleBuilder;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Exceptions\RenewalRefused;
use Illuminate\Support\Facades\DB;

/**
 * Extends an active lease by one more term — same price, length and
 * billing cycle — on the same lease row: periods are appended and
 * `ends_on` moves (docs/superpowers/specs/2026-10-11-auto-renewal-design.md).
 * The door grant ran to the old end, so it is re-issued after commit.
 */
class LeaseRenewalService
{
    public function __construct(
        private readonly LeaseScheduleBuilder $schedule,
        private readonly PayerStanding $standing,
        private readonly AvailabilityService $availability,
        private readonly OfficeLeaseService $leases,
    ) {}

    /**
     * @throws RenewalRefused when a check fails (the caller stores the reason)
     * @throws RenewalException when the lease is not active
     */
    public function extend(OfficeLease $lease, ?User $actor, bool $checkOverdue = true): OfficeLease
    {
        $extended = DB::transaction(function () use ($lease, $checkOverdue) {
            $locked = OfficeLease::query()->whereKey($lease->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OfficeLeaseStatus::Active) {
                throw new RenewalException('api.renewal.lease_not_active', 409);
            }

            $space = Space::query()->whereKey($locked->space_id)->lockForUpdate()->firstOrFail();
            $from = $locked->ends_on->addDay()->toDateString();
            $to = $this->schedule->endsOn($from, $locked->duration_months);

            if ($checkOverdue && $this->standing->hasOverdue($locked->billed_to, $locked->provider_id, $locked->company_id)) {
                throw new RenewalRefused(RenewalStopReason::OverdueInvoice);
            }

            if (Company::query()->whereKey($locked->company_id)->value('status') !== CompanyStatus::Active->value) {
                throw new RenewalRefused(RenewalStopReason::CompanyInactive);
            }

            if ($space->status !== OperationalStatus::Active) {
                throw new RenewalRefused(RenewalStopReason::OfficeUnavailable);
            }

            if (OfficeLease::query()->overlapping($space->id, $from, $to)->whereKeyNot($locked->id)->exists()
                || $this->availability->hasOpenBookingsOn($space, $from, $to)) {
                throw new RenewalRefused(RenewalStopReason::OfficeTaken);
            }

            $last = (int) OfficeLeasePeriod::query()->where('office_lease_id', $locked->id)->max('sequence');

            foreach ($this->schedule->build($from, $locked->duration_months, $locked->billing_cycle_months, (string) $locked->monthly_amount) as $period) {
                OfficeLeasePeriod::create([
                    ...$period,
                    'office_lease_id' => $locked->id,
                    'sequence' => $last + $period['sequence'],
                    'status' => LeasePeriodStatus::Upcoming,
                ]);
            }

            $locked->update([
                'ends_on' => $to,
                'renewals_count' => $locked->renewals_count + 1,
                'renewal_stop_reason' => null,
            ]);

            return $locked;
        });

        activity()->causedBy($actor)->performedOn($extended)->withProperties([
            'ends_on' => $extended->ends_on->toDateString(),
            'automatic' => $actor === null,
        ])->log('lease_renewed');

        $this->leases->closeDoor($extended);
        $this->leases->openDoor($extended->fresh());

        return $extended->fresh();
    }
}
```

If `Company::status` is cast and `value('status')` returns the raw string, the comparison against `CompanyStatus::Active->value` is right; if the codebase's other services compare via a loaded model, load the model instead (`Company::query()->findOrFail(...)->status !== CompanyStatus::Active`) — either is fine, keep one.

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/Renewals tests/Feature/Leasing`
Expected: PASS.

- [ ] **Step 5: Pint and commit**

```bash
./vendor/bin/pint app/Domain/Renewals/Services/LeaseRenewalService.php tests/Feature/Renewals/LeaseRenewalTest.php
git add app/Domain/Renewals/Services/LeaseRenewalService.php tests/Feature/Renewals/LeaseRenewalTest.php
git commit -m "feat(renewals): extend a lease by another term and re-issue its door

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: The hourly renewal run

**Files:**
- Create: `app/Domain/Renewals/Services/RenewalRunner.php`, `app/Console/Commands/RunRenewals.php`, `tests/Feature/Renewals/RenewalRunnerTest.php`
- Modify: `routes/console.php`

**Interfaces:**
- Consumes: `MembershipRenewalService::renew()`, `LeaseRenewalService::extend()`, `MembershipActivationService::grantDueBalances()`, `SettingService::get('renewals.advance_days', 7)`.
- Produces: `RenewalRunner::run(): array{memberships_renewed: int, memberships_stopped: int, leases_extended: int, leases_stopped: int, balances_granted: int}`; command `renewals:run`, scheduled hourly `withoutOverlapping`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Renewals/RenewalRunnerTest.php`:

```php
<?php

namespace Tests\Feature\Renewals;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\PlanBalance;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Services\RenewalRunner;

class RenewalRunnerTest extends RenewalsTestCase
{
    private function runRenewals(): array
    {
        return app(RenewalRunner::class)->run();
    }

    private function renewable(Provider $provider): Membership
    {
        $membership = $this->assignedMembership($provider);
        $membership->update(['auto_renew' => true]);

        return $membership->fresh();
    }

    public function test_a_subscription_renews_inside_the_window_and_only_once(): void
    {
        $current = $this->renewable(Provider::factory()->create());

        $this->travelTo($current->current_period_end->copy()->subDays(8));
        $this->assertSame(0, $this->runRenewals()['memberships_renewed']);

        $this->travelTo($current->current_period_end->copy()->subDays(7));
        $this->assertSame(1, $this->runRenewals()['memberships_renewed']);
        $this->assertSame(0, $this->runRenewals()['memberships_renewed']);
        $this->assertSame(1, Membership::query()->where('renewed_from_id', $current->id)->count());
    }

    public function test_a_subscription_without_auto_renew_is_left_alone(): void
    {
        $current = $this->renewable(Provider::factory()->create());
        $current->update(['auto_renew' => false]);
        $this->travelTo($current->current_period_end->copy()->subDay());

        $this->assertSame(0, $this->runRenewals()['memberships_renewed']);
    }

    public function test_a_stopped_renewal_retries_until_the_end_and_clears_once_it_succeeds(): void
    {
        $provider = Provider::factory()->create();
        $current = $this->renewable($provider);
        $overdue = Invoice::factory()->create(['provider_id' => $provider->id, 'billed_to' => Payer::Provider, 'due_at' => now()->subDay()->toDateString()]);
        $this->travelTo($current->current_period_end->copy()->subDays(5));

        $this->assertSame(1, $this->runRenewals()['memberships_stopped']);
        $this->assertSame(RenewalStopReason::OverdueInvoice, $current->fresh()->renewal_stop_reason);

        $overdue->update(['status' => InvoiceStatus::Paid, 'paid_amount' => $overdue->amount]);
        $this->travel(1)->hours();
        $this->assertSame(1, $this->runRenewals()['memberships_renewed']);
        $this->assertNull($current->fresh()->renewal_stop_reason);
    }

    public function test_no_automatic_attempt_after_the_end(): void
    {
        $provider = Provider::factory()->create();
        $current = $this->renewable($provider);
        Invoice::factory()->create(['provider_id' => $provider->id, 'billed_to' => Payer::Provider, 'due_at' => now()->subDay()->toDateString()]);
        $this->travelTo($current->current_period_end->copy()->subDay());
        $this->runRenewals();

        $this->travelTo($current->current_period_end->copy()->addDay());
        $result = $this->runRenewals();

        $this->assertSame(0, $result['memberships_renewed'] + $result['memberships_stopped']);
        $this->assertSame(RenewalStopReason::OverdueInvoice, $current->fresh()->renewal_stop_reason);
    }

    public function test_the_renewal_gets_its_balances_when_it_starts(): void
    {
        $current = $this->renewable(Provider::factory()->create());
        PlanBalance::create(['plan_id' => $current->plan_id, 'category' => WalletTransactionCategory::General, 'amount_points' => 100]);
        $wallets = app(WalletService::class);
        $wallet = $wallets->walletFor(OwnerType::Company, (int) $current->owner_id);
        $before = $wallets->generalBalance($wallet);

        $this->travelTo($current->current_period_end->copy()->subDays(3));
        $this->runRenewals();
        $this->assertSame($before, $wallets->generalBalance($wallet));

        $this->travelTo($current->current_period_end->copy()->addMinute());
        $this->assertSame(1, $this->runRenewals()['balances_granted']);
        $this->assertSame(0, $this->runRenewals()['balances_granted']);
        $this->assertSame($before + 100, $wallets->generalBalance($wallet));
    }

    public function test_a_lease_extends_inside_the_window(): void
    {
        $lease = app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice(withLock: false), ['starts_on' => now()->toDateString()]), 'contract_ref' => 'C']),
            $this->reviewer(),
        );
        $lease->update(['auto_renew' => true]);

        $this->travelTo($lease->ends_on->subDays(8));
        $this->assertSame(0, $this->runRenewals()['leases_extended']);

        $this->travelTo($lease->ends_on->subDays(7));
        $this->assertSame(1, $this->runRenewals()['leases_extended']);
        $this->assertSame(0, $this->runRenewals()['leases_extended']);
        $this->assertSame(1, $lease->fresh()->renewals_count);
    }

    public function test_the_command_is_scheduled_hourly(): void
    {
        $this->artisan('renewals:run')->assertSuccessful();

        $this->artisan('schedule:list')->expectsOutputToContain('renewals:run');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Renewals/RenewalRunnerTest.php`
Expected: FAIL — `Class "App\Domain\Renewals\Services\RenewalRunner" not found`.

- [ ] **Step 3: The runner**

`app/Domain/Renewals/Services/RenewalRunner.php`:

```php
<?php

namespace App\Domain\Renewals\Services;

use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Services\MembershipActivationService;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Exceptions\RenewalRefused;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The hourly renewal run (renewals:run). Within `renewals.advance_days` of
 * the end it renews subscriptions and extends leases that auto-renew,
 * storing why when it cannot; a stopped one is retried every run until it
 * ends. It also grants the balances of renewals that have started. One
 * failing subject never stops the rest.
 */
class RenewalRunner
{
    private const DEFAULT_ADVANCE_DAYS = 7;

    public function __construct(
        private readonly MembershipRenewalService $memberships,
        private readonly LeaseRenewalService $leases,
        private readonly MembershipActivationService $activations,
        private readonly SettingService $settings,
    ) {}

    /** @return array{memberships_renewed: int, memberships_stopped: int, leases_extended: int, leases_stopped: int, balances_granted: int} */
    public function run(): array
    {
        [$renewed, $stoppedMemberships] = $this->renewMemberships();
        [$extended, $stoppedLeases] = $this->extendLeases();

        return [
            'memberships_renewed' => $renewed,
            'memberships_stopped' => $stoppedMemberships,
            'leases_extended' => $extended,
            'leases_stopped' => $stoppedLeases,
            'balances_granted' => $this->grantStartedBalances(),
        ];
    }

    /** @return array{0: int, 1: int} */
    private function renewMemberships(): array
    {
        $renewed = $stopped = 0;

        Membership::query()
            ->live()
            ->where('auto_renew', true)
            ->where('owner_type', OwnerType::Company)
            ->where('current_period_end', '<=', now()->addDays($this->advanceDays()))
            ->whereDoesntHave('renewal')
            ->chunkById(100, function ($memberships) use (&$renewed, &$stopped) {
                foreach ($memberships as $membership) {
                    try {
                        $this->memberships->renew($membership, null);
                        $renewed++;
                    } catch (RenewalRefused $e) {
                        $this->stop($membership, $e->reason);
                        $stopped++;
                    } catch (RenewalException) {
                        // Not renewable (not a provider assignment) or renewed meanwhile.
                    } catch (\Throwable $e) {
                        Log::error('Failed to renew a subscription', ['membership_id' => $membership->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return [$renewed, $stopped];
    }

    /** @return array{0: int, 1: int} */
    private function extendLeases(): array
    {
        $extended = $stopped = 0;
        $today = now()->toDateString();

        OfficeLease::query()
            ->where('status', OfficeLeaseStatus::Active)
            ->where('auto_renew', true)
            ->whereDate('ends_on', '>=', $today)
            ->whereDate('ends_on', '<=', now()->addDays($this->advanceDays())->toDateString())
            ->chunkById(100, function ($leases) use (&$extended, &$stopped) {
                foreach ($leases as $lease) {
                    try {
                        $this->leases->extend($lease, null);
                        $extended++;
                    } catch (RenewalRefused $e) {
                        $this->stop($lease, $e->reason);
                        $stopped++;
                    } catch (RenewalException) {
                        // Ended or terminated meanwhile.
                    } catch (\Throwable $e) {
                        Log::error('Failed to extend a lease', ['office_lease_id' => $lease->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return [$extended, $stopped];
    }

    private function grantStartedBalances(): int
    {
        $granted = 0;

        Membership::query()
            ->whereNull('balances_granted_at')
            ->where('current_period_start', '<=', now())
            ->chunkById(100, function ($memberships) use (&$granted) {
                foreach ($memberships as $membership) {
                    try {
                        if ($this->activations->grantDueBalances($membership)) {
                            $granted++;
                        }
                    } catch (\Throwable $e) {
                        Log::error('Failed to grant a renewal its plan balances', ['membership_id' => $membership->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $granted;
    }

    /** Stores the reason (logged only when it changes, not every hour). */
    private function stop(Model $subject, RenewalStopReason $reason): void
    {
        if ($subject->renewal_stop_reason === $reason) {
            return;
        }

        $subject->update(['renewal_stop_reason' => $reason]);
        activity()->performedOn($subject)->withProperties(['reason' => $reason->value])->log('renewal_stopped');
    }

    private function advanceDays(): int
    {
        return (int) $this->settings->get('renewals.advance_days', self::DEFAULT_ADVANCE_DAYS);
    }
}
```

- [ ] **Step 4: Command and schedule**

`app/Console/Commands/RunRenewals.php`:

```php
<?php

namespace App\Console\Commands;

use App\Domain\Renewals\Services\RenewalRunner;
use Illuminate\Console\Command;

class RunRenewals extends Command
{
    protected $signature = 'renewals:run';

    protected $description = 'Renew subscriptions and extend leases that auto-renew, and grant started renewals their balances.';

    public function handle(RenewalRunner $runner): int
    {
        $result = $runner->run();

        $this->info(sprintf(
            'Subscriptions renewed: %d (stopped %d), leases extended: %d (stopped %d), balances granted: %d',
            $result['memberships_renewed'],
            $result['memberships_stopped'],
            $result['leases_extended'],
            $result['leases_stopped'],
            $result['balances_granted'],
        ));

        return self::SUCCESS;
    }
}
```

`routes/console.php` — append:

```php

/*
 * Auto-renewal (docs/superpowers/specs/2026-10-11-auto-renewal-design.md):
 * renew subscriptions and extend leases within renewals.advance_days of
 * their end, retrying stopped ones every hour until they end, and grant
 * renewals their plan balances once they start.
 */
Schedule::command('renewals:run')->hourly()->withoutOverlapping();
```

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/Renewals`
Expected: PASS.

- [ ] **Step 6: Pint and commit**

```bash
./vendor/bin/pint app/Domain/Renewals/Services/RenewalRunner.php app/Console/Commands/RunRenewals.php routes/console.php tests/Feature/Renewals/RenewalRunnerTest.php
git add app/Domain/Renewals/Services/RenewalRunner.php app/Console/Commands/RunRenewals.php routes/console.php tests/Feature/Renewals/RenewalRunnerTest.php
git commit -m "feat(renewals): hourly renewal run

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Choosing auto-renew at creation

**Files:**
- Modify: `app/Http/Requests/Provider/StoreProviderRequestRequest.php`, `app/Domain/Providers/Requests/Handlers/PlanAssignmentHandler.php`, `app/Http/Requests/Admin/Concerns/ValidatesLeaseTerms.php`, `app/Domain/Leasing/Data/LeaseTerms.php`, `app/Domain/Leasing/Services/OfficeLeaseService.php`
- Create: `tests/Feature/Renewals/AutoRenewAtCreationTest.php`

**Interfaces:**
- Consumes: `activate(..., attributes:)` (Task 2).
- Produces: `plan_assignment` payload key `auto_renew` (optional boolean, default false) → `memberships.auto_renew`; lease body key `auto_renew` (optional boolean) → `LeaseTerms::$autoRenew` (trailing `bool $autoRenew = false`) → `office_leases.auto_renew`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Renewals/AutoRenewAtCreationTest.php`:

```php
<?php

namespace Tests\Feature\Renewals;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;

class AutoRenewAtCreationTest extends RenewalsTestCase
{
    public function test_the_provider_chooses_auto_renew_when_asking_for_a_plan(): void
    {
        $provider = Provider::factory()->create();
        $renewing = $this->assignedMembership($provider, ['auto_renew' => true]);
        $branch = $renewing->branch_id;
        $plain = $this->assignedMembership($provider, ['auto_renew' => false], $this->clientCompany($provider, ['branch_id' => $branch]));

        $this->assertTrue($renewing->auto_renew);
        $this->assertFalse($plain->auto_renew);
    }

    public function test_the_provider_request_accepts_auto_renew(): void
    {
        $provider = Provider::factory()->create();
        $company = $this->clientCompany($provider);
        $plan = Plan::factory()->create();
        $this->providerAccount($provider);

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'plan_assignment', 'company_id' => $company->id, 'plan_id' => $plan->id,
            'seats' => 2, 'payer' => 'provider', 'auto_renew' => true,
        ])->assertCreated();

        $this->assertTrue(ProviderRequest::query()->latest('id')->first()->payload['auto_renew']);

        $this->postJson('/api/v1/provider/requests', [
            'type' => 'plan_assignment', 'company_id' => $company->id, 'plan_id' => $plan->id,
            'seats' => 2, 'payer' => 'provider', 'auto_renew' => 'sometimes',
        ])->assertUnprocessable()->assertJsonValidationErrors('auto_renew');
    }

    public function test_staff_choose_auto_renew_when_making_a_lease(): void
    {
        $this->actingAsAdmin();
        $request = PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]);

        $this->postJson("/api/v1/admin/private-office-requests/{$request->id}/lease", [
            'contract_ref' => 'C', ...$this->leasePayload($this->leasableOffice()), 'auto_renew' => true,
        ])->assertCreated()->assertJsonPath('data.auto_renew', true);

        $this->assertTrue(OfficeLease::query()->sole()->auto_renew);
    }
}
```

`data.auto_renew` in the lease response depends on Task 7's resource field; until then assert only the database row. If Task 7 has not landed when you run this, drop the `->assertJsonPath(...)` call and keep the DB assertion.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Renewals/AutoRenewAtCreationTest.php`
Expected: FAIL — `auto_renew` false on the renewing membership / missing from the payload.

- [ ] **Step 3: Implement**

`StoreProviderRequestRequest::rules()` — the `PlanAssignment` arm gains `'auto_renew' => ['sometimes', 'boolean'],`.

`PlanAssignmentHandler::handle()` — the `activate()` call gains:

```php
                attributes: ['auto_renew' => (bool) ($payload['auto_renew'] ?? false)],
```

`ValidatesLeaseTerms::leaseRules()` — add `'auto_renew' => ['sometimes', 'boolean'],`.

`LeaseTerms` — add a trailing constructor parameter `public readonly bool $autoRenew = false,` and in `fromArray()` `autoRenew: (bool) ($validated['auto_renew'] ?? false),`.

`OfficeLeaseService::contract()` — `OfficeLease::create([...])` gains `'auto_renew' => $terms->autoRenew,`.

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/Renewals tests/Feature/Leasing tests/Feature/Providers`
Expected: PASS.

- [ ] **Step 5: Pint and commit**

```bash
./vendor/bin/pint app/Http/Requests/Provider/StoreProviderRequestRequest.php app/Domain/Providers/Requests/Handlers/PlanAssignmentHandler.php app/Http/Requests/Admin/Concerns/ValidatesLeaseTerms.php app/Domain/Leasing/Data/LeaseTerms.php app/Domain/Leasing/Services/OfficeLeaseService.php tests/Feature/Renewals/AutoRenewAtCreationTest.php
git add app/Http/Requests/Provider/StoreProviderRequestRequest.php app/Domain/Providers/Requests/Handlers/PlanAssignmentHandler.php app/Http/Requests/Admin/Concerns/ValidatesLeaseTerms.php app/Domain/Leasing/Data/LeaseTerms.php app/Domain/Leasing/Services/OfficeLeaseService.php tests/Feature/Renewals/AutoRenewAtCreationTest.php
git commit -m "feat(renewals): choose auto-renew on plan assignment and lease creation

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Admin — renew by hand, toggle, stopped list

**Files:**
- Create: `app/Domain/Renewals/Services/RenewalSettings.php`, `app/Http/Controllers/Api/V1/Admin/RenewalController.php`, `app/Http/Requests/Admin/UpdateAutoRenewRequest.php`, `app/Http/Requests/Admin/ListStoppedRenewalsRequest.php`, `tests/Feature/Renewals/AdminRenewalTest.php`
- Modify: `app/Http/Resources/OfficeLeaseResource.php`, `app/Services/Permissions/PermissionSyncService.php`, `routes/api/v1/admin.php`

**Interfaces:**
- Consumes: Tasks 3–4 services and exceptions.
- Produces: `RenewalSettings::setForMembership(Membership, bool $on, ?User $actor): void` (RenewalException `not_renewable` 422), `setForLease(OfficeLease, bool $on, ?User $actor): void` (RenewalException `lease_not_active` 409); turning off clears `renewal_stop_reason`. Routes (all `permission:renewals.manage`, inside the `withoutMiddleware('role:admin|operations')` block): `POST admin/memberships/{membership}/renew`, `POST admin/office-leases/{officeLease}/renew`, `PATCH admin/memberships/{membership}/auto-renew`, `PATCH admin/office-leases/{officeLease}/auto-renew`, `GET admin/renewals/stopped`. `OfficeLeaseResource` gains `auto_renew`, `renewal_stop_reason`, `renewal_stop_reason_label`, `renewals_count` (shown to providers too).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Renewals/AdminRenewalTest.php`:

```php
<?php

namespace Tests\Feature\Renewals;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Domain\Renewals\Enums\RenewalStopReason;
use Laravel\Sanctum\Sanctum;

class AdminRenewalTest extends RenewalsTestCase
{
    private function lease(): OfficeLease
    {
        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => Company::factory()->create()->id]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice(withLock: false), ['starts_on' => now()->toDateString()]), 'contract_ref' => 'C']),
            $this->reviewer(),
        );
    }

    public function test_staff_renew_a_stopped_subscription_past_an_overdue_invoice(): void
    {
        $provider = Provider::factory()->create();
        $membership = $this->assignedMembership($provider);
        $membership->update(['renewal_stop_reason' => RenewalStopReason::OverdueInvoice]);
        Invoice::factory()->create(['provider_id' => $provider->id, 'billed_to' => Payer::Provider, 'due_at' => now()->subDay()->toDateString()]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/memberships/{$membership->id}/renew")
            ->assertOk()->assertJsonPath('message', __('api.renewal.renewed'));

        $this->assertSame(1, Membership::query()->where('renewed_from_id', $membership->id)->count());
        $this->postJson("/api/v1/admin/memberships/{$membership->id}/renew")->assertStatus(409);
    }

    public function test_a_manual_renewal_still_needs_seats(): void
    {
        $membership = $this->assignedMembership(Provider::factory()->create());
        \App\Domain\Foundation\Models\Space::query()->whereHas('building', fn ($q) => $q->where('branch_id', $membership->branch_id))->update(['capacity' => 1]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/memberships/{$membership->id}/renew")
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.renewal.refused', ['reason' => RenewalStopReason::NoSeats->label()]));
    }

    public function test_staff_extend_a_lease_and_toggle_auto_renew(): void
    {
        $lease = $this->lease();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/office-leases/{$lease->id}/renew")->assertOk();
        $this->assertSame(1, $lease->fresh()->renewals_count);

        $lease->update(['auto_renew' => true, 'renewal_stop_reason' => RenewalStopReason::OfficeTaken]);
        $this->patchJson("/api/v1/admin/office-leases/{$lease->id}/auto-renew", ['auto_renew' => false])
            ->assertOk()->assertJsonPath('message', __('api.renewal.auto_renew_updated'));
        $this->assertFalse($lease->fresh()->auto_renew);
        $this->assertNull($lease->fresh()->renewal_stop_reason);

        $this->getJson("/api/v1/admin/office-leases/{$lease->id}")
            ->assertJsonPath('data.auto_renew', false)
            ->assertJsonPath('data.renewals_count', 1);
    }

    public function test_toggling_a_subscription_not_from_a_provider_is_refused(): void
    {
        $membership = Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => Company::factory()->create()->id]);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/memberships/{$membership->id}/auto-renew", ['auto_renew' => true])
            ->assertUnprocessable()->assertJsonPath('message', __('api.renewal.not_renewable'));
    }

    public function test_the_stopped_list_filters_by_type_reason_and_provider(): void
    {
        $provider = Provider::factory()->create();
        $membership = $this->assignedMembership($provider);
        $membership->update(['renewal_stop_reason' => RenewalStopReason::OverdueInvoice]);
        $lease = $this->lease();
        $lease->update(['renewal_stop_reason' => RenewalStopReason::OfficeTaken]);
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/renewals/stopped')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/admin/renewals/stopped?type=lease')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'lease')
            ->assertJsonPath('data.0.reason', 'office_taken')
            ->assertJsonPath('data.0.reason_label', RenewalStopReason::OfficeTaken->label());
        $this->getJson('/api/v1/admin/renewals/stopped?reason=overdue_invoice')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'membership')
            ->assertJsonPath('data.0.provider.id', $provider->id)
            ->assertJsonPath('data.0.has_ended', false);
        $this->getJson('/api/v1/admin/renewals/stopped?provider_id='.$provider->id)->assertJsonCount(1, 'data');
    }

    public function test_it_all_needs_the_permission(): void
    {
        $membership = $this->assignedMembership(Provider::factory()->create());
        $operations = User::factory()->create();
        $operations->assignRole('operations');
        Sanctum::actingAs($operations, ['*']);

        $this->getJson('/api/v1/admin/renewals/stopped')->assertForbidden();
        $this->postJson("/api/v1/admin/memberships/{$membership->id}/renew")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Renewals/AdminRenewalTest.php`
Expected: FAIL — 404 on the new routes.

- [ ] **Step 3: Settings service**

`app/Domain/Renewals/Services/RenewalSettings.php`:

```php
<?php

namespace App\Domain\Renewals\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Models\Membership;
use App\Domain\Renewals\Exceptions\RenewalException;
use Illuminate\Database\Eloquent\Model;

/** Turns auto-renewal on or off; turning it off clears a stop reason. */
class RenewalSettings
{
    public function __construct(private readonly MembershipRenewalService $memberships) {}

    public function setForMembership(Membership $membership, bool $on, ?User $actor): void
    {
        if (! $this->memberships->isRenewable($membership)) {
            throw new RenewalException('api.renewal.not_renewable');
        }

        $this->set($membership, $on, $actor);
    }

    public function setForLease(OfficeLease $lease, bool $on, ?User $actor): void
    {
        if ($lease->status !== OfficeLeaseStatus::Active) {
            throw new RenewalException('api.renewal.lease_not_active', 409);
        }

        $this->set($lease, $on, $actor);
    }

    private function set(Model $subject, bool $on, ?User $actor): void
    {
        $subject->update(['auto_renew' => $on] + ($on ? [] : ['renewal_stop_reason' => null]));

        activity()->causedBy($actor)->performedOn($subject)->withProperties(['auto_renew' => $on])->log('auto_renew_changed');
    }
}
```

- [ ] **Step 4: Requests**

`app/Http/Requests/Admin/UpdateAutoRenewRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Body of every auto-renew toggle (admin and provider). */
class UpdateAutoRenewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['auto_renew' => ['required', 'boolean']];
    }
}
```

`app/Http/Requests/Admin/ListStoppedRenewalsRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Domain\Renewals\Enums\RenewalStopReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListStoppedRenewalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['membership', 'lease'])],
            'provider_id' => ['nullable', 'integer'],
            'reason' => ['nullable', Rule::enum(RenewalStopReason::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

- [ ] **Step 5: Controller**

`app/Http/Controllers/Api/V1/Admin/RenewalController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\Company;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Exceptions\RenewalRefused;
use App\Domain\Renewals\Services\LeaseRenewalService;
use App\Domain\Renewals\Services\MembershipRenewalService;
use App\Domain\Renewals\Services\RenewalSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListStoppedRenewalsRequest;
use App\Http\Requests\Admin\UpdateAutoRenewRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Auto-renewal for staff (docs/superpowers/specs/2026-10-11-auto-renewal-design.md):
 * renew by hand (past an overdue invoice, nothing else), turn auto-renew
 * on or off, and list what stopped. All `renewals.manage`.
 */
class RenewalController extends Controller
{
    public function renewMembership(Request $request, Membership $membership, MembershipRenewalService $renewals): JsonResponse
    {
        return $this->attempt(fn () => $renewals->renew($membership, $request->user(), checkOverdue: false));
    }

    public function renewLease(Request $request, OfficeLease $officeLease, LeaseRenewalService $renewals): JsonResponse
    {
        return $this->attempt(fn () => $renewals->extend($officeLease, $request->user(), checkOverdue: false));
    }

    public function membershipAutoRenew(UpdateAutoRenewRequest $request, Membership $membership, RenewalSettings $settings): JsonResponse
    {
        return $this->toggle(fn () => $settings->setForMembership($membership, $request->boolean('auto_renew'), $request->user()));
    }

    public function leaseAutoRenew(UpdateAutoRenewRequest $request, OfficeLease $officeLease, RenewalSettings $settings): JsonResponse
    {
        return $this->toggle(fn () => $settings->setForLease($officeLease, $request->boolean('auto_renew'), $request->user()));
    }

    /** Stopped subscriptions and leases, soonest end first. */
    public function stopped(ListStoppedRenewalsRequest $request): JsonResponse
    {
        $type = $request->input('type');
        $rows = collect()
            ->concat($type === 'lease' ? [] : $this->stoppedMemberships($request))
            ->concat($type === 'membership' ? [] : $this->stoppedLeases($request))
            ->when($request->filled('provider_id'), fn (Collection $c) => $c->filter(fn ($row) => ($row['provider']['id'] ?? null) === $request->integer('provider_id')))
            ->sortBy('ends_at')
            ->values();

        $perPage = $request->integer('per_page', 15);
        $page = max(1, $request->integer('page', 1));

        return response()->json([
            'data' => $rows->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $rows->count(),
                'last_page' => max(1, (int) ceil($rows->count() / $perPage)),
            ],
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function stoppedMemberships(ListStoppedRenewalsRequest $request): Collection
    {
        $memberships = Membership::query()
            ->whereNotNull('renewal_stop_reason')
            ->where('owner_type', OwnerType::Company)
            ->when($request->filled('reason'), fn ($q) => $q->where('renewal_stop_reason', $request->input('reason')))
            ->get();
        $companies = Company::query()->with('provider')->whereIn('id', $memberships->pluck('owner_id'))->get()->keyBy('id');

        return $memberships->map(fn (Membership $m) => $this->row('membership', $m->id, $companies->get($m->owner_id), $m->renewal_stop_reason, $m->current_period_end->toIso8601String(), $m->current_period_end->isPast()));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function stoppedLeases(ListStoppedRenewalsRequest $request): Collection
    {
        return OfficeLease::query()
            ->with('company.provider')
            ->whereNotNull('renewal_stop_reason')
            ->when($request->filled('reason'), fn ($q) => $q->where('renewal_stop_reason', $request->input('reason')))
            ->get()
            ->map(fn (OfficeLease $l) => $this->row('lease', $l->id, $l->company, $l->renewal_stop_reason, $l->ends_on->toDateString(), $l->ends_on->toDateString() < now()->toDateString()));
    }

    /** @return array<string, mixed> */
    private function row(string $type, int $id, ?Company $company, $reason, string $endsAt, bool $hasEnded): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'company' => $company === null ? null : ['id' => $company->id, 'legal_name' => $company->legal_name],
            'provider' => $company?->provider === null ? null : ['id' => $company->provider->id, 'name' => $company->provider->name],
            'reason' => $reason->value,
            'reason_label' => $reason->label(),
            'ends_at' => $endsAt,
            'has_ended' => $hasEnded,
        ];
    }

    private function attempt(callable $renew): JsonResponse
    {
        try {
            $renew();
        } catch (RenewalRefused $e) {
            return response()->json(['message' => __('api.renewal.refused', ['reason' => $e->reason->label()])], 422);
        } catch (RenewalException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.renewal.renewed')]);
    }

    private function toggle(callable $set): JsonResponse
    {
        try {
            $set();
        } catch (RenewalException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.renewal.auto_renew_updated')]);
    }
}
```

- [ ] **Step 6: Resource, permission, routes**

`OfficeLeaseResource::toArray()` — after `'terminated_on' => ...` add:

```php
            'auto_renew' => $this->auto_renew,
            'renewal_stop_reason' => $this->renewal_stop_reason?->value,
            'renewal_stop_reason_label' => $this->renewal_stop_reason?->label(),
            'renewals_count' => $this->renewals_count,
```

`PermissionSyncService::MANUAL_REGISTRATIONS` — import the controller and add:

```php
        // Auto-renewal (docs/superpowers/specs/2026-10-11-auto-renewal-design.md).
        RenewalController::class => [
            'renewals' => ['manage'],
        ],
```

`routes/api/v1/admin.php` — import `App\Http\Controllers\Api\V1\Admin\RenewalController`; inside the `withoutMiddleware('role:admin|operations')` block holding the office-lease routes:

```php

    // Auto-renewal (docs/superpowers/specs/2026-10-11-auto-renewal-design.md).
    Route::get('renewals/stopped', [RenewalController::class, 'stopped'])->middleware('permission:renewals.manage');
    Route::post('memberships/{membership}/renew', [RenewalController::class, 'renewMembership'])->middleware('permission:renewals.manage');
    Route::post('office-leases/{officeLease}/renew', [RenewalController::class, 'renewLease'])->middleware('permission:renewals.manage');
    Route::patch('memberships/{membership}/auto-renew', [RenewalController::class, 'membershipAutoRenew'])->middleware('permission:renewals.manage');
    Route::patch('office-leases/{officeLease}/auto-renew', [RenewalController::class, 'leaseAutoRenew'])->middleware('permission:renewals.manage');
```

Check with `php artisan route:list --path=admin/memberships` that no existing route with the same method and path shadows these.

- [ ] **Step 7: Run tests**

Run: `php artisan test tests/Feature/Renewals tests/Feature/Leasing tests/Unit/Services/Permissions 2>/dev/null || php artisan test tests/Feature/Renewals tests/Feature/Leasing`
Expected: PASS. Then re-run Task 6's test with its `data.auto_renew` assertion restored if it was dropped.

- [ ] **Step 8: Pint and commit**

```bash
./vendor/bin/pint app/Domain/Renewals/Services/RenewalSettings.php app/Http/Controllers/Api/V1/Admin/RenewalController.php app/Http/Requests/Admin/UpdateAutoRenewRequest.php app/Http/Requests/Admin/ListStoppedRenewalsRequest.php app/Http/Resources/OfficeLeaseResource.php app/Services/Permissions/PermissionSyncService.php routes/api/v1/admin.php tests/Feature/Renewals/AdminRenewalTest.php
git add app/Domain/Renewals/Services/RenewalSettings.php app/Http/Controllers/Api/V1/Admin/RenewalController.php app/Http/Requests/Admin/UpdateAutoRenewRequest.php app/Http/Requests/Admin/ListStoppedRenewalsRequest.php app/Http/Resources/OfficeLeaseResource.php app/Services/Permissions/PermissionSyncService.php routes/api/v1/admin.php tests/Feature/Renewals/AdminRenewalTest.php tests/Feature/Renewals/AutoRenewAtCreationTest.php
git commit -m "feat(renewals): staff renew by hand, toggle auto-renew, list stopped renewals

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Provider — toggle and see renewal state

**Files:**
- Create: `app/Http/Controllers/Api/V1/Provider/ProviderRenewalController.php`, `tests/Feature/Renewals/ProviderRenewalTest.php`
- Modify: `app/Http/Controllers/Api/V1/Provider/ProviderCompanyController.php`, `app/Http/Resources/Provider/ProviderCompanyResource.php`, `routes/api/v1/provider.php`

**Interfaces:**
- Consumes: `RenewalSettings`, `MembershipRenewalService::isRenewable()`, `UpdateAutoRenewRequest` (Task 7), `ActsForProvider::authorizeCompany()|authorizeLease()`, `MembershipResolver::activeForOwner()`.
- Produces: `PATCH provider/companies/{company}/membership/auto-renew` (404 for another provider's company or no renewable current subscription), `PATCH provider/office-leases/{officeLease}/auto-renew` (404 for another provider's lease). `ProviderCompanyResource.membership` gains `auto_renew`, `renewal_stop_reason`, `renewal_stop_reason_label`, `next_membership` (`{current_period_start, current_period_end}` or null).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Renewals/ProviderRenewalTest.php`:

```php
<?php

namespace Tests\Feature\Renewals;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Services\MembershipRenewalService;

class ProviderRenewalTest extends RenewalsTestCase
{
    public function test_a_provider_turns_a_companys_renewal_off_and_sees_its_state(): void
    {
        $provider = Provider::factory()->create();
        $membership = $this->assignedMembership($provider);
        $membership->update(['auto_renew' => true, 'renewal_stop_reason' => RenewalStopReason::OverdueInvoice]);
        $company = Company::query()->findOrFail($membership->owner_id);
        $this->providerAccount($provider);

        $this->getJson("/api/v1/provider/companies/{$company->id}")
            ->assertJsonPath('data.membership.auto_renew', true)
            ->assertJsonPath('data.membership.renewal_stop_reason', 'overdue_invoice')
            ->assertJsonPath('data.membership.next_membership', null);

        $this->patchJson("/api/v1/provider/companies/{$company->id}/membership/auto-renew", ['auto_renew' => false])
            ->assertOk()->assertJsonPath('message', __('api.renewal.auto_renew_updated'));
        $this->assertFalse($membership->fresh()->auto_renew);
        $this->assertNull($membership->fresh()->renewal_stop_reason);
    }

    public function test_a_provider_sees_the_next_subscription_once_made(): void
    {
        $provider = Provider::factory()->create();
        $membership = $this->assignedMembership($provider);
        $next = app(MembershipRenewalService::class)->renew($membership, null);
        $this->providerAccount($provider);

        $this->getJson("/api/v1/provider/companies/{$membership->owner_id}")
            ->assertJsonPath('data.membership.id', $membership->id)
            ->assertJsonPath('data.membership.next_membership.current_period_start', $next->current_period_start->toIso8601String());
    }

    public function test_another_providers_company_or_lease_is_not_found(): void
    {
        $theirs = $this->assignedMembership(Provider::factory()->create());
        $lease = app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $theirs->owner_id, 'provider_id' => Company::query()->find($theirs->owner_id)->provider_id, 'payer' => Payer::Provider]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice(withLock: false)), 'contract_ref' => 'C']),
            $this->reviewer(),
        );
        $this->providerAccount(Provider::factory()->create());

        $this->patchJson("/api/v1/provider/companies/{$theirs->owner_id}/membership/auto-renew", ['auto_renew' => false])->assertNotFound();
        $this->patchJson("/api/v1/provider/office-leases/{$lease->id}/auto-renew", ['auto_renew' => false])->assertNotFound();
    }

    public function test_a_provider_toggles_its_companys_lease(): void
    {
        $provider = Provider::factory()->create();
        $company = $this->clientCompany($provider);
        $lease = app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id, 'provider_id' => $provider->id, 'payer' => Payer::Provider]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice(withLock: false)), 'contract_ref' => 'C']),
            $this->reviewer(),
        );
        $this->providerAccount($provider);

        $this->patchJson("/api/v1/provider/office-leases/{$lease->id}/auto-renew", ['auto_renew' => true])->assertOk();
        $this->assertTrue($lease->fresh()->auto_renew);
        $this->getJson("/api/v1/provider/office-leases/{$lease->id}")->assertJsonPath('data.auto_renew', true);
    }

    public function test_a_company_with_no_renewable_subscription_is_not_found(): void
    {
        $provider = Provider::factory()->create();
        $company = $this->clientCompany($provider);
        $this->providerAccount($provider);

        $this->patchJson("/api/v1/provider/companies/{$company->id}/membership/auto-renew", ['auto_renew' => true])->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Renewals/ProviderRenewalTest.php`
Expected: FAIL — missing `auto_renew` in the company view / 404 routes.

- [ ] **Step 3: Controller and routes**

`app/Http/Controllers/Api/V1/Provider/ProviderRenewalController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Identity\Models\Company;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Services\MembershipRenewalService;
use App\Domain\Renewals\Services\RenewalSettings;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAutoRenewRequest;
use Illuminate\Http\JsonResponse;

/** The provider turns auto-renewal on or off for its companies' subscription and leases. */
class ProviderRenewalController extends Controller
{
    use ActsForProvider;

    public function membershipAutoRenew(UpdateAutoRenewRequest $request, Company $company, MembershipResolver $resolver, MembershipRenewalService $renewals, RenewalSettings $settings): JsonResponse
    {
        $this->authorizeCompany($request, $company);

        $membership = $resolver->activeForOwner(OwnerType::Company, $company->id);
        abort_if($membership === null || ! $renewals->isRenewable($membership), 404);

        $settings->setForMembership($membership, $request->boolean('auto_renew'), $request->user());

        return response()->json(['message' => __('api.renewal.auto_renew_updated')]);
    }

    public function leaseAutoRenew(UpdateAutoRenewRequest $request, OfficeLease $officeLease, RenewalSettings $settings): JsonResponse
    {
        $this->authorizeLease($request, $officeLease);

        try {
            $settings->setForLease($officeLease, $request->boolean('auto_renew'), $request->user());
        } catch (RenewalException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.renewal.auto_renew_updated')]);
    }
}
```

`routes/api/v1/provider.php` — import the controller and add:

```php
Route::patch('companies/{company}/membership/auto-renew', [ProviderRenewalController::class, 'membershipAutoRenew']);
Route::patch('office-leases/{officeLease}/auto-renew', [ProviderRenewalController::class, 'leaseAutoRenew']);
```

- [ ] **Step 4: Company view**

`ProviderCompanyController::attachMembership()` becomes:

```php
    private function attachMembership(Company $company): void
    {
        $current = $this->memberships->activeForOwner(OwnerType::Company, $company->id);

        $company->setAttribute('current_membership', $current);
        $company->setAttribute('next_membership', $current === null ? null : Membership::query()->where('renewed_from_id', $current->id)->first());
    }
```

(import `App\Domain\Membership\Models\Membership`).

`ProviderCompanyResource` — the `membership` array gains:

```php
                'auto_renew' => $membership->auto_renew,
                'renewal_stop_reason' => $membership->renewal_stop_reason?->value,
                'renewal_stop_reason_label' => $membership->renewal_stop_reason?->label(),
                'next_membership' => $this->next_membership === null ? null : [
                    'current_period_start' => $this->next_membership->current_period_start->toIso8601String(),
                    'current_period_end' => $this->next_membership->current_period_end->toIso8601String(),
                ],
```

and the docblock mentions `next_membership` (the renewal made in advance, or null).

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/Renewals tests/Feature/Providers tests/Guards/ProviderRoutesStayIsolatedTest.php`
Expected: PASS.

- [ ] **Step 6: Pint and commit**

```bash
./vendor/bin/pint app/Http/Controllers/Api/V1/Provider/ProviderRenewalController.php app/Http/Controllers/Api/V1/Provider/ProviderCompanyController.php app/Http/Resources/Provider/ProviderCompanyResource.php routes/api/v1/provider.php tests/Feature/Renewals/ProviderRenewalTest.php
git add app/Http/Controllers/Api/V1/Provider/ProviderRenewalController.php app/Http/Controllers/Api/V1/Provider/ProviderCompanyController.php app/Http/Resources/Provider/ProviderCompanyResource.php routes/api/v1/provider.php tests/Feature/Renewals/ProviderRenewalTest.php
git commit -m "feat(renewals): providers toggle auto-renew and see renewal state

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Decision record, CLAUDE.md, Postman, full suite

**Files:**
- Create: `docs/decisions/auto-renewal.md`
- Modify: `CLAUDE.md`, `postman/ADD-OS.postman_collection.json`, `docs/superpowers/specs/2026-10-11-auto-renewal-design.md`

- [ ] **Step 1: Spec status**

In the spec, set the status line to `**الحالة:** معتمد ومنفّذ (الخطة docs/superpowers/plans/2026-10-11-auto-renewal.md).`

- [ ] **Step 2: Decision record**

`docs/decisions/auto-renewal.md`:

```markdown
# Auto-renewal (Phase 4)

**Status:** shipped 2026-10-12. **Owner:** Maryam Asha.
**Spec:** docs/superpowers/specs/2026-10-11-auto-renewal-design.md (Arabic). **Plan:** docs/superpowers/plans/2026-10-11-auto-renewal.md.

## What this adds

`App\Domain\Renewals`. A provider company's plan subscription (one that came from a plan-assignment request, or a renewal of one) and any office lease can renew automatically, chosen at creation (`auto_renew` on the plan-assignment payload and the lease body) and changed later by the provider (its companies) or staff (`renewals.manage`).

- **When.** `renewals:run` (hourly) acts within `renewals.advance_days` (setting, default 7) of the end and retries a stopped one every run until it ends.
- **Subscription.** `MembershipRenewalService::renew()` creates the next `Membership` through `MembershipActivationService::activate(startsAt:, attributes:)` — starting at the current end (or now, if it already ended), same plan, seats and branch, `renewed_from_id` (unique) — and `InvoiceIssuer::issueForMembershipRenewal()` bills the plan's price today × seats in the previous invoice's currency (`invoices.membership_id`) to the payer chosen at assignment (the company itself once it is direct), for the company's current provider. A free plan renews without an invoice.
- **Lease.** `LeaseRenewalService::extend()` appends one more term of periods to the same lease (`ends_on` moves, `renewals_count`++) and re-issues the door grant to the new end.
- **Stops.** A failed check stores `renewal_stop_reason` (`overdue_invoice`, `no_seats`, `plan_inactive`, `company_inactive`, `office_unavailable`, `office_taken`) on the subject; success or turning auto-renew off clears it. `GET admin/renewals/stopped` lists them; `POST admin/memberships/{id}/renew` / `admin/office-leases/{id}/renew` renew by hand past an overdue invoice (nothing else). "Overdue on the payer" = any open overdue invoice billed to the provider (all its companies) or to the company.
- **Live means started.** `Membership::scopeLive()`/`isLive()` require `current_period_start <= now`; a renewal made in advance is not the company's subscription until it starts. Plan balances are granted when a membership starts (`balances_granted_at`), by the run for renewals.
- **Who sees what.** Staff: lease detail carries `auto_renew`, the stop reason and `renewals_count`; the stopped list. Provider: the company's subscription carries `auto_renew`, its stop reason and `next_membership`; leases the same fields as staff. Members: nothing new.

## Rulings made while implementing

(Copy the plan's refinements table here, plus any ruling made during execution.)

## Explicitly not done here

- Renewing direct companies' or individual members' subscriptions (automatic point debits).
- Notifications before a renewal or when it stops.
- Changing plan or seats at renewal (a new plan-assignment request after the end).

## Deploy

`php artisan migrate` (backfills `memberships.balances_granted_at` from `created_at`), `php artisan permissions:sync` (`renewals.manage`), `php artisan db:seed --class=SettingSeeder` (`renewals.advance_days`). The scheduler must run `renewals:run`.
```

Replace the "(Copy the plan's refinements table …)" line with the actual table from this plan's header and any rulings the execution ledger recorded.

- [ ] **Step 3: CLAUDE.md**

After the **Office leases** paragraph add:

```markdown
**Auto-renewal** (`docs/decisions/auto-renewal.md`). A provider company's subscription (from a plan-assignment request) and any office lease may carry `auto_renew`; `renewals:run` (hourly) renews them within `renewals.advance_days` (default 7) of their end — `MembershipRenewalService::renew()` (a new membership starting at the old end, `renewed_from_id`, invoiced through `InvoiceIssuer::issueForMembershipRenewal()`, `invoices.membership_id`) and `LeaseRenewalService::extend()` (more periods on the same lease, door re-issued). A failed check stores `renewal_stop_reason`; staff list and renew by hand (`renewals.manage`). A membership is live only once started (`scopeLive()` checks `current_period_start`), and its plan balances are granted when it starts (`balances_granted_at`) — never assume a membership row is the company's current subscription without `live()`.
```

In the `routes/api/v1/admin.php` bullet's "Documented exceptions" sentence add `renewals` to the list of permission-gated route groups and `permission:renewals.*` to the middleware list.

- [ ] **Step 4: Postman**

With a Python script that loads and dumps `postman/ADD-OS.postman_collection.json` (`indent=4`, `ensure_ascii=True`, no trailing newline), add:
- **Admin (Dashboard) → Renewals** (new folder): `List Stopped Renewals` (`GET admin/renewals/stopped`, disabled query params `type`, `provider_id`, `reason`, `per_page`), `Renew Membership` (`POST admin/memberships/{{membership_id}}/renew`), `Renew Lease` (`POST admin/office-leases/{{office_lease_id}}/renew`), `Membership Auto-Renew` (`PATCH admin/memberships/{{membership_id}}/auto-renew`, body `{"auto_renew": false}`), `Lease Auto-Renew` (`PATCH admin/office-leases/{{office_lease_id}}/auto-renew`, body `{"auto_renew": true}`). Descriptions name `renewals.manage`; the renew ones say "past an overdue invoice, nothing else".
- **Provider (Dashboard)**: `Membership Auto-Renew` (`PATCH provider/companies/{{provider_company_id}}/membership/auto-renew`), `Lease Auto-Renew` (`PATCH provider/office-leases/{{office_lease_id}}/auto-renew`); the `Create Request - Plan Assignment` body gains `"auto_renew": true`.
- **Admin → Office Leases → Create Lease from Request** and **Admin → Companies → Create Company** bodies gain `"auto_renew": true`.
- Add a collection variable `membership_id` if missing.

- [ ] **Step 5: Full suite**

Run: `php artisan test` (10+ minutes — run in the background and wait). Expected: exactly the 17 pre-existing failures. `./vendor/bin/pint --test` on every PHP file changed on this branch (`git diff --name-only feat/add-point-system..HEAD -- '*.php'`): clean.

- [ ] **Step 6: Commit**

```bash
git add docs/decisions/auto-renewal.md CLAUDE.md postman/ADD-OS.postman_collection.json docs/superpowers/specs/2026-10-11-auto-renewal-design.md
git commit -m "docs(renewals): decision record, CLAUDE.md and Postman

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
