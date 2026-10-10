# Provider Invoicing (Phase 2) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Approving a plan assignment or a wallet top-up for a provider's company issues an invoice to the chosen payer; staff record (partial, any-currency) payments against it, void wrong payments, cancel unpaid invoices; the provider sees its invoices read-only.

**Architecture:** A new `App\Domain\Invoicing` domain owns `invoices` and `invoice_payments`. `InvoiceIssuer` builds an invoice from a source-agnostic `InvoiceDraft` (company, payer, points, description, source FK) priced by `ReceivedMoneyConverter::pointsToAmount()` and frozen; `ProviderRequestService::approve()` calls it inside the approval transaction, so a pricing failure rolls the whole approval back. `InvoicePaymentService` records/voids payments and cancels invoices under a row lock, converting received money to the invoice currency through `InvoiceMoney`. Admin routes are permission-gated (`invoices.*`); provider routes are read-only and isolated by `invoices.provider_id`.

**Tech Stack:** Laravel 12, PHP 8.3, bcmath, spatie/laravel-permission, PHPUnit feature tests on in-memory SQLite, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-10-10-provider-invoicing-design.md` (Arabic). Phase 1 context: `docs/decisions/service-providers.md`.

## Global Constraints

- Run PHP as `/c/laragon/bin/php/php-8.3.33-nts-Win32-vs16-x64/php.exe` (the `php` on PATH is 8.2). Below, `PHP` means that path.
- Tests: `PHP artisan test <path>`; style: `PHP vendor/bin/pint --test <files>` on every touched PHP file (Pint only the files you touched, never whole directories).
- The full suite has **17 pre-existing failures** (audit-log `'1'` vs `1`, the EventAttendee enum guard, notifications on SQLite, MemberOperationsIsolationTest password recovery). A task is green when it adds none.
- The working tree carries unrelated uncommitted edits (`MemberAuthController`, `CurrencyResource`, `MemberLoginTest`, `CurrencyControllerTest`) and an untracked `.claude/launch.json` — never stage, commit, revert or reformat them. Stage files by explicit path.
- Money columns are `decimal` (guarded by `MoneyIsDecimalOnlyTest`); amounts are bcmath strings end to end — never cast money to float.
- Only `PointConversionService` reads the point rate (`PointConversionIsCentralizedTest`); invoices price points through `ReceivedMoneyConverter::pointsToAmount()`, which uses it.
- Update/action endpoints answer `{"message": __('api.<group>.<key>')}`; `store`-style creation returns the resource (201).
- Copy goes in `lang/en/api.php` **and** `lang/ar/api.php`. The new top-level `invoice` group is added once at the end of each file — grep first that no `'invoice' =>` top-level key exists.
- Code, comments and docs in the repo are English. Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Never read or search `vendor/`, `storage/logs`, `storage/framework`, `bootstrap/cache`.

**Refinements of the spec (apply these):**

| Spec says | Plan does | Why |
|---|---|---|
| amount via `PointConversionService::pointsToCurrency()` | `ReceivedMoneyConverter::pointsToAmount($points, $currency)` | Same rate source, but bcmath end to end; `pointsToCurrency()` goes through floats. |
| payment conversion "rounded up" | rounded **down** (truncated) to the invoice currency's decimals; a shortfall under one minor unit settles the remainder exactly | Money received is credited conservatively everywhere else (points floor); rounding up would credit more than was paid. The one-unit rule keeps an exact equivalent payment from leaving `0.01` open. |
| (spec silent) | `ReceivedMoneyConverter` throws `PaymentPricingException` (rendered globally as 503 + Paymera log); the issuer catches it and throws `InvoicingException('api.invoice.no_exchange_rate')` (422) | A missing invoice-currency rate is the reviewer's choice, not a gateway outage. |

## Review Focus

1. **Approving with a currency that has no exchange rate** → 422 `api.invoice.no_exchange_rate`, the request stays pending, no membership, no wallet credit, no invoice (Task 3 pins it).
2. **Two payments recorded at once that together exceed the remainder** → the second sees the first under the row lock and is refused 422 `api.invoice.overpayment` (Task 4 pins the sequential case; the lock is what makes it hold).
3. **A payment in SYP whose exact USD equivalent is the remainder** → the invoice becomes `paid`, not `partially_paid` with `0.01` left (Task 4 pins it).
4. **A provider opening another provider's invoice by id** → 404, never the invoice (Task 6 pins it).
5. **Voiding the only payment of a `paid` invoice** → back to `unpaid` with `paid_amount` 0, and the invoice becomes cancellable (Task 4 pins it).

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_10_10_100000_create_invoices_tables.php` | `invoices`, `invoice_payments`, `providers.payment_terms_days` |
| `app/Domain/Invoicing/Enums/{InvoiceStatus,InvoicePaymentMethod}.php` | enums |
| `app/Domain/Invoicing/Models/{Invoice,InvoicePayment}.php` | models; `Invoice::refreshStatus()`, `remaining()`, `scopeOverdue()`, `isOverdue()` |
| `app/Domain/Invoicing/Exceptions/InvoicingException.php` | refusal with message key + status + params |
| `app/Domain/Invoicing/Data/{InvoiceDraft,InvoiceTerms,InvoiceQuote}.php` | value objects |
| `app/Domain/Invoicing/Services/InvoiceIssuer.php` | draft from a provider request, quote, issue |
| `app/Domain/Invoicing/Services/InvoiceMoney.php` | received money → invoice currency |
| `app/Domain/Invoicing/Services/InvoicePaymentService.php` | record, void, cancel |
| `app/Http/Controllers/Api/V1/Admin/{InvoiceController,InvoicePaymentController}.php` | admin surface |
| `app/Http/Controllers/Api/V1/Provider/ProviderInvoiceController.php` | provider surface (read-only) |
| `app/Http/Resources/{InvoiceResource,ProviderInvoiceResource,InvoicePaymentResource}.php` | output |
| `tests/Feature/Invoicing/*` | feature tests; `InvoicingTestCase` shared setup |

---

### Task 1: Schema, models, enums, the default due period

**Files:**
- Create: `database/migrations/2026_10_10_100000_create_invoices_tables.php`
- Create: `app/Domain/Invoicing/Enums/InvoiceStatus.php`, `InvoicePaymentMethod.php`
- Create: `app/Domain/Invoicing/Models/Invoice.php`, `InvoicePayment.php`
- Create: `database/factories/InvoiceFactory.php`, `database/factories/InvoicePaymentFactory.php`
- Modify: `app/Domain/Providers/Models/Provider.php` (`payment_terms_days` fillable + cast), `app/Domain/Providers/Models/ProviderRequest.php` (`invoice()` hasOne)
- Modify: `database/seeders/SettingSeeder.php` (`invoices.default_due_days` = 15)
- Modify: `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
- Test: `tests/Feature/Invoicing/InvoiceModelTest.php`

**Interfaces:**
- Produces: `InvoiceStatus{Unpaid,PartiallyPaid,Paid,Cancelled}` (`unpaid`, `partially_paid`, `paid`, `cancelled`); `InvoicePaymentMethod{BankTransfer,Cash,Cheque,Other}` (`bank_transfer`, `cash`, `cheque`, `other`); `Invoice` (relations `provider()`, `company()`, `providerRequest()`, `payments()`, `issuer()`; `remaining(): string`; `refreshStatus(): void`; `isOverdue(): bool`; `scopeOverdue()`; reference prefix `INV`); `InvoicePayment` (`invoice()`, `recorder()`, `isVoided(): bool`, `scopeActive()`); `ProviderRequest::invoice()`; `Provider::$payment_terms_days`; factories.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_invoice_gets_a_yearly_reference(): void
    {
        $invoice = Invoice::factory()->create(['issued_at' => '2026-10-10']);

        $this->assertMatchesRegularExpression('/^INV-2026-\d{5}$/', $invoice->reference);
    }

    public function test_status_follows_the_active_payments(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '100.00', 'paid_amount' => '0.00']);

        $invoice->paid_amount = '40.00';
        $invoice->refreshStatus();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertSame('60.00', $invoice->remaining());

        $invoice->paid_amount = '100.00';
        $invoice->refreshStatus();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);

        $invoice->paid_amount = '0.00';
        $invoice->refreshStatus();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
    }

    public function test_a_cancelled_invoice_keeps_its_status(): void
    {
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Cancelled, 'paid_amount' => '0.00']);

        $invoice->refreshStatus();

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->status);
    }

    public function test_overdue_is_open_and_past_its_due_date(): void
    {
        Carbon::setTestNow('2026-10-20');
        $late = Invoice::factory()->create(['due_at' => '2026-10-19']);
        Invoice::factory()->create(['due_at' => '2026-10-20']);
        Invoice::factory()->create(['due_at' => '2026-10-01', 'status' => InvoiceStatus::Paid, 'paid_amount' => '100.00', 'amount' => '100.00']);
        Invoice::factory()->create(['due_at' => '2026-10-01', 'status' => InvoiceStatus::Cancelled]);

        $this->assertSame([$late->id], Invoice::query()->overdue()->pluck('id')->all());
        $this->assertTrue($late->fresh()->isOverdue());
    }

    public function test_a_voided_payment_is_not_active(): void
    {
        $payment = InvoicePayment::factory()->create(['voided_at' => now()]);

        $this->assertTrue($payment->isVoided());
        $this->assertSame(0, InvoicePayment::query()->active()->count());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `PHP artisan test tests/Feature/Invoicing/InvoiceModelTest.php`
Expected: FAIL — `Class "App\Domain\Invoicing\Models\Invoice" not found`.

- [ ] **Step 3: The migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider invoicing (docs/superpowers/specs/2026-10-10-provider-invoicing-design.md):
 * a debt in money, outside the points ledger and the cash drawer. Each
 * source of an invoice is its own nullable FK — today only
 * provider_request_id — so a later source (office leases) adds a column
 * rather than a morph. `paid_amount` is the sum of active payments, kept
 * under the invoice's row lock; "overdue" is derived, never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('contract_ref');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('billed_to', 20);
            $table->foreignId('provider_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->unsignedInteger('points');
            $table->string('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->decimal('computed_amount', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->decimal('exchange_rate_to_base', 20, 10);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->string('status', 20)->default('unpaid');
            $table->date('issued_at');
            $table->date('due_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('received_amount', 14, 2);
            $table->string('received_currency', 3);
            $table->foreign('received_currency')->references('code')->on('currencies');
            $table->decimal('exchange_rate', 20, 10);
            $table->decimal('amount', 14, 2);
            $table->string('method', 20);
            $table->string('receipt_number')->nullable();
            $table->date('paid_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoices');
        Schema::table('providers', fn (Blueprint $table) => $table->dropColumn('payment_terms_days'));
    }
};
```

- [ ] **Step 4: Enums**

`app/Domain/Invoicing/Enums/InvoiceStatus.php`:

```php
<?php

namespace App\Domain\Invoicing\Enums;

/**
 * Stored states only. "Overdue" is not one: it is an open invoice past its
 * due date (Invoice::scopeOverdue()).
 */
enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Unpaid || $this === self::PartiallyPaid;
    }
}
```

`app/Domain/Invoicing/Enums/InvoicePaymentMethod.php`:

```php
<?php

namespace App\Domain\Invoicing\Enums;

enum InvoicePaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Cheque = 'cheque';
    case Other = 'other';
}
```

- [ ] **Step 5: Models**

`app/Domain/Invoicing/Models/Invoice.php`:

```php
<?php

namespace App\Domain\Invoicing\Models;

use App\Concerns\HasReference;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A debt in money owed by a provider or a client company
 * (docs/superpowers/specs/2026-10-10-provider-invoicing-design.md). Its
 * amount is frozen at issue; `paid_amount` is the sum of active payments,
 * changed only by InvoicePaymentService under the row lock.
 */
class Invoice extends Model
{
    use HasFactory, HasReference;

    protected $fillable = [
        'provider_id',
        'company_id',
        'billed_to',
        'provider_request_id',
        'description',
        'points',
        'currency',
        'computed_amount',
        'amount',
        'exchange_rate_to_base',
        'paid_amount',
        'status',
        'issued_at',
        'due_at',
        'issued_by',
    ];

    protected function casts(): array
    {
        return [
            'billed_to' => Payer::class,
            'status' => InvoiceStatus::class,
            'points' => 'integer',
            'computed_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'issued_at' => 'date',
            'due_at' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    protected function referencePrefix(): string
    {
        return 'INV';
    }

    /** The series restarts each calendar year of `issued_at`. */
    protected function referenceDate(): CarbonInterface
    {
        return $this->issued_at ??= now();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function providerRequest(): BelongsTo
    {
        return $this->belongsTo(ProviderRequest::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('id');
    }

    /** What is still owed, in the invoice currency (never below zero). */
    public function remaining(): string
    {
        $left = bcsub((string) $this->amount, (string) $this->paid_amount, 2);

        return bccomp($left, '0', 2) < 0 ? '0.00' : $left;
    }

    /** Status from `paid_amount`, in one place. A cancelled invoice stays cancelled. */
    public function refreshStatus(): void
    {
        if ($this->status === InvoiceStatus::Cancelled) {
            return;
        }

        $paid = (string) $this->paid_amount;

        $this->status = match (true) {
            bccomp($paid, '0', 2) <= 0 => InvoiceStatus::Unpaid,
            bccomp($paid, (string) $this->amount, 2) < 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Paid,
        };
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])
            ->whereDate('due_at', '<', now()->toDateString());
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->due_at->lt(now()->startOfDay());
    }
}
```

`app/Domain/Invoicing/Models/InvoicePayment.php`:

```php
<?php

namespace App\Domain\Invoicing\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoicePaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received against an invoice. A wrong one is voided, never deleted:
 * a voided payment stays visible and no longer counts.
 */
class InvoicePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'received_amount',
        'received_currency',
        'exchange_rate',
        'amount',
        'method',
        'receipt_number',
        'paid_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'method' => InvoicePaymentMethod::class,
            'received_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'paid_at' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
```

`ProviderRequest` — add (import `HasOne`, `App\Domain\Invoicing\Models\Invoice`):

```php
    /** The invoice approval issued, for an assignment or a top-up. */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
```

`Provider` — add `'payment_terms_days'` to `$fillable` and `'payment_terms_days' => 'integer'` to `casts()`.

- [ ] **Step 6: Factories**

`database/factories/InvoiceFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Domain\Identity\Models\Company;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /** An unpaid USD invoice of a company of the same provider. */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'company_id' => fn (array $attributes) => Company::factory()->create(['provider_id' => $attributes['provider_id']])->id,
            'billed_to' => Payer::Company,
            'provider_request_id' => null,
            'description' => 'Wallet top-up: 1000 points',
            'points' => 1000,
            'currency' => 'USD',
            'computed_amount' => '100.00',
            'amount' => '100.00',
            'exchange_rate_to_base' => '1',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::Unpaid,
            'issued_at' => now()->toDateString(),
            'due_at' => now()->addDays(15)->toDateString(),
        ];
    }
}
```

`database/factories/InvoicePaymentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Domain\Invoicing\Enums\InvoicePaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoicePayment>
 */
class InvoicePaymentFactory extends Factory
{
    protected $model = InvoicePayment::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'received_amount' => '10.00',
            'received_currency' => 'USD',
            'exchange_rate' => '1',
            'amount' => '10.00',
            'method' => InvoicePaymentMethod::BankTransfer,
            'receipt_number' => null,
            'paid_at' => now()->toDateString(),
        ];
    }
}
```

(The currencies table seeds USD and SYP in its own migration, so `currency` FKs hold under `RefreshDatabase`; if a test fails on the FK, seed `CurrencySeeder` in it.)

- [ ] **Step 7: Setting default and guard**

`SettingSeeder::run()` — next to the other defaults:

```php
        // Provider invoicing: days from issue to due when the provider has
        // no payment terms of its own (docs/superpowers/specs/2026-10-10-provider-invoicing-design.md).
        $settings->setDefault('invoices.default_due_days', 15, SettingValueType::Int);
```

`EnumColumnsHaveBackedEnumCastsTest::EXPECTED_CASTS` — add (with imports):

```php
        Invoice::class => [
            'status' => InvoiceStatus::class,
            'billed_to' => Payer::class,
        ],
        InvoicePayment::class => [
            'method' => InvoicePaymentMethod::class,
        ],
```

- [ ] **Step 8: Run**

Run: `PHP artisan test tests/Feature/Invoicing/InvoiceModelTest.php tests/Guards tests/Feature/Providers`
Expected: PASS (Guards: only the pre-existing EventAttendee violation).

- [ ] **Step 9: Commit**

```bash
PHP vendor/bin/pint database/migrations/2026_10_10_100000_create_invoices_tables.php app/Domain/Invoicing app/Domain/Providers/Models/Provider.php app/Domain/Providers/Models/ProviderRequest.php database/factories/InvoiceFactory.php database/factories/InvoicePaymentFactory.php database/seeders/SettingSeeder.php tests/Feature/Invoicing/InvoiceModelTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php
git add database/migrations/2026_10_10_100000_create_invoices_tables.php app/Domain/Invoicing app/Domain/Providers/Models/Provider.php app/Domain/Providers/Models/ProviderRequest.php database/factories/InvoiceFactory.php database/factories/InvoicePaymentFactory.php database/seeders/SettingSeeder.php tests/Feature/Invoicing/InvoiceModelTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php
git commit -m "feat(invoicing): invoices and their payments

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `InvoiceIssuer` — draft, quote, issue

**Files:**
- Create: `app/Domain/Invoicing/Exceptions/InvoicingException.php`
- Create: `app/Domain/Invoicing/Data/InvoiceDraft.php`, `InvoiceTerms.php`, `InvoiceQuote.php`
- Create: `app/Domain/Invoicing/Services/InvoiceIssuer.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php` (new `invoice` group)
- Test: `tests/Feature/Invoicing/InvoicingTestCase.php`, `tests/Feature/Invoicing/InvoiceIssuerTest.php`

**Interfaces:**
- Consumes: Task 1 models; `ReceivedMoneyConverter::pointsToAmount(int $points, string $currencyCode): string` (throws `App\Domain\Finance\Exceptions\PaymentPricingException`); `CurrencyConversionService::baseCurrencyCode(): string`, `rateToBase(string): ?string`; `SettingService::get(string $key, mixed $default)`.
- Produces:
  - `InvoicingException(string $messageKey, int $status = 422, array $params = [])`
  - `final class InvoiceDraft(int $companyId, ?int $providerId, Payer $billedTo, int $points, string $description, array $source)` — `$source` is the source FK, e.g. `['provider_request_id' => 7]`
  - `final class InvoiceTerms(?string $currency = null, ?string $amount = null, ?string $dueAt = null)` + `static fromArray(array $validated): self` reading `invoice_currency`, `invoice_amount`, `invoice_due_at`
  - `final class InvoiceQuote(string $currency, string $computedAmount, string $amount, string $exchangeRateToBase, string $issuedAt, string $dueAt)` + `toArray(): array`
  - `InvoiceIssuer::draftFor(ProviderRequest $request): ?InvoiceDraft` (null for types that bill nothing)
  - `InvoiceIssuer::quote(InvoiceDraft $draft, InvoiceTerms $terms): InvoiceQuote`
  - `InvoiceIssuer::issue(InvoiceDraft $draft, InvoiceTerms $terms, User $reviewer): Invoice`
  - `InvoiceIssuer::issueForProviderRequest(ProviderRequest $request, User $reviewer, InvoiceTerms $terms): ?Invoice`
  - test base `InvoicingTestCase` (extends `Tests\Feature\Providers\ProviderTestCase`; seeds `CurrencySeeder` + `SettingSeeder`; `syp(string $rateToBase = '0.0001'): void` creates an SYP exchange rate)

- [ ] **Step 1: The shared test base**

`tests/Feature/Invoicing/InvoicingTestCase.php`:

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Finance\Models\ExchangeRate;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Providers\ProviderTestCase;

abstract class InvoicingTestCase extends ProviderTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CurrencySeeder::class);
        $this->seed(SettingSeeder::class);
    }

    /** An SYP rate effective now: one pound is worth `$rateToBase` dollars. */
    protected function syp(string $rateToBase = '0.0001'): void
    {
        ExchangeRate::factory()->create([
            'currency_code' => 'SYP',
            'rate_to_base' => $rateToBase,
            'effective_from' => now()->subMinute(),
        ]);
        Cache::forget('exchange_rate_current_SYP');
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Invoicing/InvoiceIssuerTest.php`:

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Data\InvoiceTerms;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Services\InvoiceIssuer;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Carbon\Carbon;

class InvoiceIssuerTest extends InvoicingTestCase
{
    private function issuer(): InvoiceIssuer
    {
        return app(InvoiceIssuer::class);
    }

    private function assignment(Provider $provider, Plan $plan, int $seats, string $payer = 'provider'): ProviderRequest
    {
        return ProviderRequest::factory()->create([
            'provider_id' => $provider->id,
            'company_id' => $this->clientCompany($provider)->id,
            'type' => ProviderRequestType::PlanAssignment,
            'payload' => ['plan_id' => $plan->id, 'seats' => $seats, 'payer' => $payer],
        ]);
    }

    public function test_an_assignment_bills_the_plan_price_times_the_seats(): void
    {
        $provider = Provider::factory()->create();
        $plan = Plan::factory()->create(['price_points' => 900, 'duration_days' => 30]);

        $draft = $this->issuer()->draftFor($this->assignment($provider, $plan, 5));

        $this->assertSame(4500, $draft->points);
        $this->assertSame(Payer::Provider, $draft->billedTo);
        $this->assertSame($provider->id, $draft->providerId);
        $this->assertStringContainsString('× 5 seats', $draft->description);
    }

    public function test_a_top_up_bills_its_points_and_other_types_bill_nothing(): void
    {
        $provider = Provider::factory()->create();
        $topUp = ProviderRequest::factory()->create([
            'provider_id' => $provider->id,
            'company_id' => $this->clientCompany($provider)->id,
            'payload' => ['points' => 500, 'payer' => 'company'],
        ]);
        $member = ProviderRequest::factory()->create([
            'provider_id' => $provider->id,
            'type' => ProviderRequestType::CompanyMember,
            'payload' => ['phone' => '+963911111111', 'name' => 'A'],
        ]);

        $this->assertSame(500, $this->issuer()->draftFor($topUp)->points);
        $this->assertSame(Payer::Company, $this->issuer()->draftFor($topUp)->billedTo);
        $this->assertNull($this->issuer()->draftFor($member));
    }

    public function test_the_quote_defaults_to_the_base_currency_and_the_providers_terms(): void
    {
        Carbon::setTestNow('2026-10-10');
        $provider = Provider::factory()->create(['payment_terms_days' => 30]);
        $draft = $this->issuer()->draftFor($this->assignment($provider, Plan::factory()->create(['price_points' => 1000]), 1));

        $quote = $this->issuer()->quote($draft, new InvoiceTerms);

        $this->assertSame('USD', $quote->currency);
        $this->assertSame('100.00', $quote->computedAmount); // point.rate_usd = 0.10
        $this->assertSame('100.00', $quote->amount);
        $this->assertSame('2026-11-09', $quote->dueAt);
    }

    public function test_a_provider_without_terms_falls_back_to_the_default_days(): void
    {
        Carbon::setTestNow('2026-10-10');
        $draft = $this->issuer()->draftFor($this->assignment(Provider::factory()->create(), Plan::factory()->create(), 1));

        $this->assertSame('2026-10-25', $this->issuer()->quote($draft, new InvoiceTerms)->dueAt);
    }

    public function test_the_reviewer_may_change_currency_amount_and_due_date(): void
    {
        $this->syp('0.0001');
        $draft = $this->issuer()->draftFor($this->assignment(Provider::factory()->create(), Plan::factory()->create(['price_points' => 1000]), 1));

        $quote = $this->issuer()->quote($draft, new InvoiceTerms('SYP', '900000', '2026-12-31'));

        $this->assertSame('SYP', $quote->currency);
        $this->assertSame('1000000', $quote->computedAmount); // 100 USD / 0.0001
        $this->assertSame('900000', $quote->amount);
        $this->assertSame('2026-12-31', $quote->dueAt);
    }

    public function test_a_currency_with_no_rate_is_refused(): void
    {
        $draft = $this->issuer()->draftFor($this->assignment(Provider::factory()->create(), Plan::factory()->create(), 1));

        $this->expectException(InvoicingException::class);
        $this->issuer()->quote($draft, new InvoiceTerms('SYP'));
    }

    public function test_issuing_freezes_the_quote_on_an_invoice(): void
    {
        $provider = Provider::factory()->create();
        $request = $this->assignment($provider, Plan::factory()->create(['price_points' => 900]), 5, 'company');
        $reviewer = $this->actingAsAdmin();

        $invoice = $this->issuer()->issueForProviderRequest($request, $reviewer, new InvoiceTerms(amount: '400.00'));

        $this->assertSame($request->id, $invoice->provider_request_id);
        $this->assertSame($request->company_id, $invoice->company_id);
        $this->assertSame(Payer::Company, $invoice->billed_to);
        $this->assertSame('450.00', (string) $invoice->computed_amount);
        $this->assertSame('400.00', (string) $invoice->amount);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame($reviewer->id, $invoice->issued_by);
    }
}
```

(`point.rate_usd` is `0.10` in this project's config; if a test's expected money differs, read `config('point.rate_usd')` once in the test and assert against `PointConversionService` rather than hardcoding — but keep the 4500-point assertion literal.)

- [ ] **Step 3: Run to verify they fail**

Run: `PHP artisan test tests/Feature/Invoicing/InvoiceIssuerTest.php`
Expected: FAIL — `Class "App\Domain\Invoicing\Services\InvoiceIssuer" not found`.

- [ ] **Step 4: Exception and value objects**

`app/Domain/Invoicing/Exceptions/InvoicingException.php`:

```php
<?php

namespace App\Domain\Invoicing\Exceptions;

use RuntimeException;

/** An invoicing action refused, with the message to show and its HTTP status. */
class InvoicingException extends RuntimeException
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

`app/Domain/Invoicing/Data/InvoiceDraft.php`:

```php
<?php

namespace App\Domain\Invoicing\Data;

use App\Domain\Providers\Enums\Payer;

/**
 * What an invoice is for, whatever produced it. `$source` is the source's
 * own FK column — ['provider_request_id' => 7] today, an office lease later.
 */
final class InvoiceDraft
{
    /** @param  array<string, int>  $source */
    public function __construct(
        public readonly int $companyId,
        public readonly ?int $providerId,
        public readonly Payer $billedTo,
        public readonly int $points,
        public readonly string $description,
        public readonly array $source,
    ) {}
}
```

`app/Domain/Invoicing/Data/InvoiceTerms.php`:

```php
<?php

namespace App\Domain\Invoicing\Data;

/** The reviewer's optional choices at approval; null means "use the default". */
final class InvoiceTerms
{
    public function __construct(
        public readonly ?string $currency = null,
        public readonly ?string $amount = null,
        public readonly ?string $dueAt = null,
    ) {}

    /** @param  array<string, mixed>  $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            $validated['invoice_currency'] ?? null,
            isset($validated['invoice_amount']) ? (string) $validated['invoice_amount'] : null,
            $validated['invoice_due_at'] ?? null,
        );
    }
}
```

`app/Domain/Invoicing/Data/InvoiceQuote.php`:

```php
<?php

namespace App\Domain\Invoicing\Data;

/** A priced draft: what issuing would store. Dates are Y-m-d. */
final class InvoiceQuote
{
    public function __construct(
        public readonly string $currency,
        public readonly string $computedAmount,
        public readonly string $amount,
        public readonly string $exchangeRateToBase,
        public readonly string $issuedAt,
        public readonly string $dueAt,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'computed_amount' => $this->computedAmount,
            'amount' => $this->amount,
            'issued_at' => $this->issuedAt,
            'due_at' => $this->dueAt,
        ];
    }
}
```

- [ ] **Step 5: The issuer**

`app/Domain/Invoicing/Services/InvoiceIssuer.php`:

```php
<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Finance\Services\ReceivedMoneyConverter;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Data\InvoiceDraft;
use App\Domain\Invoicing\Data\InvoiceQuote;
use App\Domain\Invoicing\Data\InvoiceTerms;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Settings\Services\SettingService;

/**
 * Turns what was granted into a frozen invoice
 * (docs/superpowers/specs/2026-10-10-provider-invoicing-design.md):
 * points priced in the reviewer's currency at today's rate, due after the
 * provider's terms or the default. The draft is source-agnostic; each
 * source gets a thin wrapper (issueForProviderRequest today).
 */
class InvoiceIssuer
{
    private const DEFAULT_DUE_DAYS = 15;

    public function __construct(
        private readonly ReceivedMoneyConverter $money,
        private readonly CurrencyConversionService $currencies,
        private readonly SettingService $settings,
    ) {}

    /** Null for request types that bill nothing. */
    public function draftFor(ProviderRequest $request): ?InvoiceDraft
    {
        $payload = $request->payload;

        [$points, $description] = match ($request->type) {
            ProviderRequestType::PlanAssignment => $this->assignmentLine($payload),
            ProviderRequestType::WalletTopUp => [(int) $payload['points'], "Wallet top-up: {$payload['points']} points"],
            default => [null, null],
        };

        if ($points === null) {
            return null;
        }

        return new InvoiceDraft(
            companyId: $request->company_id,
            providerId: $request->provider_id,
            billedTo: Payer::from($payload['payer']),
            points: $points,
            description: $description,
            source: ['provider_request_id' => $request->id],
        );
    }

    public function quote(InvoiceDraft $draft, InvoiceTerms $terms): InvoiceQuote
    {
        $currency = $terms->currency ?? $this->currencies->baseCurrencyCode();

        try {
            $computed = $this->money->pointsToAmount($draft->points, $currency);
        } catch (PaymentPricingException) {
            throw new InvoicingException('api.invoice.no_exchange_rate', 422, ['currency' => $currency]);
        }

        $rate = $currency === $this->currencies->baseCurrencyCode()
            ? '1'
            : $this->currencies->rateToBase($currency);

        $issuedAt = now()->toDateString();

        return new InvoiceQuote(
            currency: $currency,
            computedAmount: $computed,
            amount: $terms->amount ?? $computed,
            exchangeRateToBase: (string) $rate,
            issuedAt: $issuedAt,
            dueAt: $terms->dueAt ?? now()->addDays($this->dueDays($draft->providerId))->toDateString(),
        );
    }

    public function issue(InvoiceDraft $draft, InvoiceTerms $terms, User $reviewer): Invoice
    {
        $quote = $this->quote($draft, $terms);

        $invoice = Invoice::create([
            ...$draft->source,
            'provider_id' => $draft->providerId,
            'company_id' => $draft->companyId,
            'billed_to' => $draft->billedTo,
            'description' => $draft->description,
            'points' => $draft->points,
            'currency' => $quote->currency,
            'computed_amount' => $quote->computedAmount,
            'amount' => $quote->amount,
            'exchange_rate_to_base' => $quote->exchangeRateToBase,
            'paid_amount' => '0',
            'status' => InvoiceStatus::Unpaid,
            'issued_at' => $quote->issuedAt,
            'due_at' => $quote->dueAt,
            'issued_by' => $reviewer->id,
        ]);

        activity()->causedBy($reviewer)->performedOn($invoice)->withProperties([
            'amount' => $quote->amount,
            'currency' => $quote->currency,
            'amount_overridden' => $terms->amount !== null,
            'due_overridden' => $terms->dueAt !== null,
        ])->log('invoice_issued');

        return $invoice;
    }

    public function issueForProviderRequest(ProviderRequest $request, User $reviewer, InvoiceTerms $terms): ?Invoice
    {
        $draft = $this->draftFor($request);

        return $draft === null ? null : $this->issue($draft, $terms, $reviewer);
    }

    /** @return array{0: int, 1: string} */
    private function assignmentLine(array $payload): array
    {
        $plan = Plan::query()->findOrFail($payload['plan_id']);
        $seats = (int) $payload['seats'];

        return [
            (int) $plan->price_points * $seats,
            sprintf('Plan: %s × %d seats, %d days', $plan->translate('name', 'en'), $seats, (int) $plan->duration_days),
        ];
    }

    private function dueDays(?int $providerId): int
    {
        $terms = $providerId === null ? null : Provider::query()->whereKey($providerId)->value('payment_terms_days');

        return (int) ($terms ?? $this->settings->get('invoices.default_due_days', self::DEFAULT_DUE_DAYS));
    }
}
```


- [ ] **Step 6: The `invoice` lang group**

End of `lang/en/api.php` (grep first that no top-level `'invoice' =>` exists):

```php
    // Provider invoicing (docs/superpowers/specs/2026-10-10-provider-invoicing-design.md).
    'invoice' => [
        'no_exchange_rate' => 'There is no exchange rate for :currency yet.',
    ],
```

`lang/ar/api.php`:

```php
    'invoice' => [
        'no_exchange_rate' => 'لا يوجد سعر صرف لـ :currency بعد.',
    ],
```

Later tasks add keys inside this group.

- [ ] **Step 7: Run**

Run: `PHP artisan test tests/Feature/Invoicing tests/Guards/PointConversionIsCentralizedTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
PHP vendor/bin/pint app/Domain/Invoicing tests/Feature/Invoicing
git add app/Domain/Invoicing tests/Feature/Invoicing lang/en/api.php lang/ar/api.php
git commit -m "feat(invoicing): price and issue an invoice for what was granted

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Issue on approval, preview before it, provider payment terms

**Files:**
- Modify: `app/Domain/Providers/Services/ProviderRequestService.php` (`approve` signature + issuer call)
- Modify: `app/Http/Requests/Admin/ApproveProviderRequestRequest.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/ProviderRequestController.php` (`show` preview/invoice, `approve` terms)
- Modify: `app/Http/Resources/ProviderRequestResource.php`
- Modify: `app/Http/Requests/Admin/UpdateProviderRequest.php`, `app/Http/Resources/ProviderResource.php`
- Test: `tests/Feature/Invoicing/ApproveIssuesInvoiceTest.php`

**Interfaces:**
- Consumes: Task 2 (`InvoiceIssuer`, `InvoiceTerms::fromArray`, `InvoicingException`).
- Produces: `ProviderRequestService::approve(ProviderRequest $request, User $reviewer, array $overrides = [], ?InvoiceTerms $terms = null): ProviderRequest`; approve body fields `invoice_currency`, `invoice_amount`, `invoice_due_at`; `GET admin/provider-requests/{id}` carries `invoice_preview` (pending, billable) or `invoice` (`{id, reference, status, amount, currency}`, once issued); `payment_terms_days` on provider update and resource.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Carbon\Carbon;

class ApproveIssuesInvoiceTest extends InvoicingTestCase
{
    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 09:00');
        $this->provider = Provider::factory()->create(['payment_terms_days' => 30]);
    }

    private function assignment(int $seats = 5, string $payer = 'provider'): ProviderRequest
    {
        $branch = $this->branchWithSeats(50);

        return ProviderRequest::factory()->create([
            'provider_id' => $this->provider->id,
            'company_id' => $this->clientCompany($this->provider, ['branch_id' => $branch->id])->id,
            'type' => ProviderRequestType::PlanAssignment,
            'payload' => ['plan_id' => Plan::factory()->create(['price_points' => 900])->id, 'seats' => $seats, 'payer' => $payer],
        ]);
    }

    public function test_approving_an_assignment_issues_an_invoice_to_the_payer(): void
    {
        $request = $this->assignment();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $invoice = Invoice::sole();
        $this->assertSame($request->id, $invoice->provider_request_id);
        $this->assertSame(4500, $invoice->points);
        $this->assertSame('450.00', (string) $invoice->amount);
        $this->assertSame('USD', $invoice->currency);
        $this->assertSame('provider', $invoice->billed_to->value);
        $this->assertSame('2026-11-09', $invoice->due_at->toDateString());
    }

    public function test_the_reviewer_sets_currency_amount_and_due_date_at_approval(): void
    {
        $this->syp('0.0001');
        $request = $this->assignment(1);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve", [
            'invoice_currency' => 'SYP',
            'invoice_amount' => '850000',
            'invoice_due_at' => '2026-12-01',
        ])->assertOk();

        $invoice = Invoice::sole();
        $this->assertSame('SYP', $invoice->currency);
        $this->assertSame('900000.00', (string) $invoice->computed_amount);
        $this->assertSame('850000.00', (string) $invoice->amount);
        $this->assertSame('2026-12-01', $invoice->due_at->toDateString());
    }

    public function test_a_currency_with_no_rate_refuses_the_whole_approval(): void
    {
        $request = $this->assignment();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve", ['invoice_currency' => 'SYP'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.invoice.no_exchange_rate', ['currency' => 'SYP']));

        $this->assertTrue($request->fresh()->isPending());
        $this->assertSame(0, Membership::query()->where('owner_type', OwnerType::Company)->count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_top_up_is_invoiced_and_an_employee_is_not(): void
    {
        $company = $this->clientCompany($this->provider);
        $topUp = ProviderRequest::factory()->create([
            'provider_id' => $this->provider->id, 'company_id' => $company->id,
            'payload' => ['points' => 500, 'payer' => 'company'],
        ]);
        $member = ProviderRequest::factory()->create([
            'provider_id' => $this->provider->id, 'company_id' => $company->id,
            'type' => ProviderRequestType::CompanyMember,
            'payload' => ['phone' => '+963944444444', 'name' => 'B'],
        ]);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/provider-requests/{$topUp->id}/approve")->assertOk();
        $this->postJson("/api/v1/admin/provider-requests/{$member->id}/approve")->assertOk();

        $this->assertSame([$topUp->id], Invoice::query()->pluck('provider_request_id')->all());
        $this->assertSame('company', Invoice::sole()->billed_to->value);
    }

    public function test_the_request_shows_a_preview_before_and_the_invoice_after(): void
    {
        $request = $this->assignment();
        $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/provider-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.invoice_preview.amount', '450.00')
            ->assertJsonPath('data.invoice_preview.currency', 'USD')
            ->assertJsonPath('data.invoice_preview.due_at', '2026-11-09')
            ->assertJsonPath('data.invoice', null);

        $this->postJson("/api/v1/admin/provider-requests/{$request->id}/approve")->assertOk();

        $this->getJson("/api/v1/admin/provider-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.invoice_preview', null)
            ->assertJsonPath('data.invoice.reference', Invoice::sole()->reference);
    }

    public function test_the_admin_sets_a_providers_payment_terms(): void
    {
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/providers/{$this->provider->id}", ['payment_terms_days' => 45])->assertOk();
        $this->getJson("/api/v1/admin/providers/{$this->provider->id}")->assertJsonPath('data.payment_terms_days', 45);

        $this->patchJson("/api/v1/admin/providers/{$this->provider->id}", ['payment_terms_days' => null])->assertOk();
        $this->assertNull($this->provider->fresh()->payment_terms_days);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `PHP artisan test tests/Feature/Invoicing/ApproveIssuesInvoiceTest.php`
Expected: FAIL.

- [ ] **Step 3: Issue inside the approval transaction**

`ProviderRequestService` — constructor gains the issuer, `approve()` gains the terms (imports `InvoiceIssuer`, `InvoiceTerms`, `InvoicingException`):

```php
    public function __construct(
        private readonly ProviderRequestHandlers $handlers,
        private readonly InvoiceIssuer $invoices,
    ) {}
```

In `approve()`, signature `approve(ProviderRequest $request, User $reviewer, array $overrides = [], ?InvoiceTerms $terms = null): ProviderRequest`; inside the transaction, right after `$resultId = $this->handlers->for(...)->handle(...)`:

```php
            // Phase 2: an assignment or a top-up is invoiced in the same
            // transaction — a pricing refusal undoes the grant with it.
            try {
                $this->invoices->issueForProviderRequest($locked, $reviewer, $terms ?? new InvoiceTerms);
            } catch (InvoicingException $e) {
                throw new ProviderRequestException($e->messageKey, $e->status, $e->params);
            }
```

Update the class docblock's approve paragraph to mention the invoice.

- [ ] **Step 4: Request fields and controller**

`ApproveProviderRequestRequest::rules()`:

```php
        return [
            // Sell past a full pool, by arrangement — recorded in the audit log.
            'override_capacity' => ['sometimes', 'boolean'],
            // The invoice an assignment or a top-up issues (provider invoicing):
            // each optional, the default used when absent.
            'invoice_currency' => ['sometimes', 'string', 'size:3', 'exists:currencies,code'],
            'invoice_amount' => ['sometimes', 'decimal:0,2', 'gt:0'],
            'invoice_due_at' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
```

`Admin\ProviderRequestController`:

```php
    public function show(ProviderRequest $providerRequest, InvoiceIssuer $invoices): ProviderRequestResource
    {
        $providerRequest->load(['provider', 'company', 'submitter', 'invoice']);
        $providerRequest->setAttribute('invoice_preview', $this->preview($providerRequest, $invoices));

        return new ProviderRequestResource($providerRequest);
    }

    public function approve(ApproveProviderRequestRequest $request, ProviderRequest $providerRequest): JsonResponse
    {
        try {
            $this->requests->approve(
                $providerRequest,
                $request->user(),
                $request->safe()->only(['override_capacity']),
                InvoiceTerms::fromArray($request->validated()),
            );
        } catch (ProviderRequestException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.admin.provider_request_approved')]);
    }

    /**
     * What approving would invoice, for a pending billable request; null
     * otherwise. A currency with no rate is not an error here — the
     * preview is in the base currency.
     */
    private function preview(ProviderRequest $providerRequest, InvoiceIssuer $invoices): ?array
    {
        if (! $providerRequest->isPending()) {
            return null;
        }

        $draft = $invoices->draftFor($providerRequest);

        return $draft === null ? null : $invoices->quote($draft, new InvoiceTerms)->toArray();
    }
```

`ProviderRequestResource::toArray` — add:

```php
            'invoice_preview' => $this->invoice_preview,
            'invoice' => $this->relationLoaded('invoice') && $this->invoice !== null ? [
                'id' => $this->invoice->id,
                'reference' => $this->invoice->reference,
                'status' => $this->invoice->status,
                'amount' => (string) $this->invoice->amount,
                'currency' => $this->invoice->currency,
            ] : null,
```

(The provider-side controllers never set `invoice_preview` nor load `invoice`, so for them both stay null — the provider reads invoices on its own endpoints, Task 6.)

- [ ] **Step 5: Provider payment terms**

`UpdateProviderRequest::rules()` — add `'payment_terms_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],`. `ProviderResource::toArray` — add `'payment_terms_days' => $this->payment_terms_days,` after `contract_ref`.

- [ ] **Step 6: Run**

Run: `PHP artisan test tests/Feature/Invoicing tests/Feature/Providers`
Expected: PASS — the Phase 1 approval tests stay green (their approvals now also issue invoices; none asserts there are none).

- [ ] **Step 7: Commit**

```bash
PHP vendor/bin/pint app/Domain/Providers/Services/ProviderRequestService.php app/Http/Requests/Admin/ApproveProviderRequestRequest.php app/Http/Controllers/Api/V1/Admin/ProviderRequestController.php app/Http/Resources/ProviderRequestResource.php app/Http/Requests/Admin/UpdateProviderRequest.php app/Http/Resources/ProviderResource.php tests/Feature/Invoicing/ApproveIssuesInvoiceTest.php
git add app/Domain/Providers/Services/ProviderRequestService.php app/Http/Requests/Admin/ApproveProviderRequestRequest.php app/Http/Controllers/Api/V1/Admin/ProviderRequestController.php app/Http/Resources/ProviderRequestResource.php app/Http/Requests/Admin/UpdateProviderRequest.php app/Http/Resources/ProviderResource.php tests/Feature/Invoicing/ApproveIssuesInvoiceTest.php
git commit -m "feat(invoicing): approving an assignment or a top-up issues its invoice

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Recording payments, voiding them, cancelling invoices

**Files:**
- Create: `app/Domain/Invoicing/Services/InvoiceMoney.php`
- Create: `app/Domain/Invoicing/Services/InvoicePaymentService.php`
- Modify: `lang/{en,ar}/api.php` (`invoice` group)
- Test: `tests/Feature/Invoicing/InvoicePaymentServiceTest.php`

**Interfaces:**
- Consumes: Task 1 models, Task 2 exception; `CurrencyConversionService::baseCurrencyCode()`, `rateToBase()`; `Currency` model (`decimal_places`).
- Produces:
  - `InvoiceMoney::toInvoiceCurrency(string $amount, string $from, string $to): array{amount: string, rate: string}` — truncated to the target currency's decimals; throws `InvoicingException('api.invoice.no_exchange_rate')`.
  - `InvoicePaymentService::record(Invoice $invoice, array $data, User $by): InvoicePayment` — `$data`: `amount`, `currency`, `method`, `receipt_number?`, `paid_at`.
  - `InvoicePaymentService::void(InvoicePayment $payment, string $reason, User $by): void`
  - `InvoicePaymentService::cancel(Invoice $invoice, string $reason, User $by): void`

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Services\InvoicePaymentService;
use App\Domain\Identity\Models\User;

class InvoicePaymentServiceTest extends InvoicingTestCase
{
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create();
    }

    private function service(): InvoicePaymentService
    {
        return app(InvoicePaymentService::class);
    }

    private function pay(Invoice $invoice, string $amount, string $currency = 'USD'): InvoicePayment
    {
        return $this->service()->record($invoice, [
            'amount' => $amount, 'currency' => $currency, 'method' => 'bank_transfer',
            'receipt_number' => 'R-1', 'paid_at' => now()->toDateString(),
        ], $this->staff);
    }

    public function test_partial_then_final_payment(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '100.00']);

        $this->pay($invoice, '40');
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
        $this->assertSame('60.00', $invoice->fresh()->remaining());

        $this->pay($invoice, '60');
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_a_payment_in_another_currency_is_converted_at_todays_rate(): void
    {
        $this->syp('0.0001');
        $invoice = Invoice::factory()->create(['amount' => '100.00']);

        $payment = $this->pay($invoice, '300000', 'SYP');

        $this->assertSame('30.00', (string) $payment->amount);
        $this->assertSame('300000.00', (string) $payment->received_amount);
        $this->assertSame('SYP', $payment->received_currency);
        $this->assertSame('30.00', (string) $invoice->fresh()->paid_amount);
    }

    public function test_an_exact_equivalent_settles_the_invoice_despite_truncation(): void
    {
        $this->syp('0.0000680272');
        $invoice = Invoice::factory()->create(['amount' => '10.00']);

        $this->pay($invoice, '147000', 'SYP'); // 9.9999984 USD, truncated 9.99

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('10.00', (string) $invoice->fresh()->paid_amount);
    }

    public function test_more_than_what_is_left_is_refused(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '100.00']);
        $this->pay($invoice, '70');

        try {
            $this->pay($invoice, '31');
            $this->fail('An overpayment was accepted.');
        } catch (InvoicingException $e) {
            $this->assertSame('api.invoice.overpayment', $e->messageKey);
            $this->assertSame('30.00', $e->params['remaining']);
        }
    }

    public function test_a_paid_or_cancelled_invoice_takes_no_payment(): void
    {
        $paid = Invoice::factory()->create(['amount' => '10.00', 'paid_amount' => '10.00', 'status' => InvoiceStatus::Paid]);
        $cancelled = Invoice::factory()->create(['status' => InvoiceStatus::Cancelled]);

        foreach ([$paid, $cancelled] as $invoice) {
            try {
                $this->pay($invoice, '1');
                $this->fail('A payment was accepted.');
            } catch (InvoicingException $e) {
                $this->assertSame(409, $e->status);
            }
        }
    }

    public function test_voiding_the_only_payment_reopens_the_invoice_and_allows_cancelling(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '50.00']);
        $payment = $this->pay($invoice, '50');

        try {
            $this->service()->cancel($invoice->fresh(), 'Wrong', $this->staff);
            $this->fail('An invoice with a payment was cancelled.');
        } catch (InvoicingException $e) {
            $this->assertSame('api.invoice.has_payments', $e->messageKey);
        }

        $this->service()->void($payment, 'Typed the wrong invoice', $this->staff);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertSame('0.00', (string) $invoice->fresh()->paid_amount);

        $this->service()->cancel($invoice->fresh(), 'Waived', $this->staff);
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame('Waived', $invoice->fresh()->cancellation_reason);
    }

    public function test_a_voided_payment_cannot_be_voided_again(): void
    {
        $payment = $this->pay(Invoice::factory()->create(), '10');
        $this->service()->void($payment, 'x', $this->staff);

        $this->expectException(InvoicingException::class);
        $this->service()->void($payment->fresh(), 'y', $this->staff);
    }

    public function test_a_currency_with_no_rate_is_refused(): void
    {
        $this->expectException(InvoicingException::class);
        $this->pay(Invoice::factory()->create(), '1000', 'SYP');
    }
}
```

(Check the third test's arithmetic against `InvoiceMoney`: 147000 × 0.0000680272 = 9.9999984 → truncated 9.99 USD; remaining 10.00 − 9.99 = 0.01, one minor unit → settles to 10.00. If the rate factory or seeder stores a different SYP rate, `syp()` overrides it.)

- [ ] **Step 2: Run to verify they fail**

Run: `PHP artisan test tests/Feature/Invoicing/InvoicePaymentServiceTest.php`
Expected: FAIL.

- [ ] **Step 3: `InvoiceMoney`**

```php
<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Invoicing\Exceptions\InvoicingException;

/**
 * Money received in one currency, counted in the invoice's currency at
 * today's rates. bcmath end to end; truncated to the invoice currency's
 * decimals — money received is credited conservatively, as everywhere
 * else in the app (points floor).
 */
class InvoiceMoney
{
    private const SCALE = 16;

    public function __construct(private readonly CurrencyConversionService $currencies) {}

    /** @return array{amount: string, rate: string} */
    public function toInvoiceCurrency(string $amount, string $from, string $to): array
    {
        if ($from === $to) {
            return ['amount' => bcadd($amount, '0', $this->decimals($to)), 'rate' => '1'];
        }

        $rate = bcdiv($this->rateToBase($from), $this->rateToBase($to), self::SCALE);

        return [
            'amount' => bcadd(bcmul($amount, $rate, self::SCALE), '0', $this->decimals($to)),
            'rate' => bcadd($rate, '0', 10),
        ];
    }

    /** The smallest amount of `$code`: 0.01 for two decimals, 1 for none. */
    public function minorUnit(string $code): string
    {
        $decimals = $this->decimals($code);

        return $decimals === 0 ? '1' : '0.'.str_repeat('0', $decimals - 1).'1';
    }

    private function rateToBase(string $code): string
    {
        if ($code === $this->currencies->baseCurrencyCode()) {
            return '1';
        }

        return $this->currencies->rateToBase($code)
            ?? throw new InvoicingException('api.invoice.no_exchange_rate', 422, ['currency' => $code]);
    }

    private function decimals(string $code): int
    {
        return (int) (Currency::query()->whereKey($code)->value('decimal_places') ?? 2);
    }
}
```

(`bcadd($x, '0', $n)` truncates positive values to `$n` places — that is the intended rounding down.)

- [ ] **Step 4: `InvoicePaymentService`**

```php
<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use Illuminate\Support\Facades\DB;

/**
 * Every change to what an invoice has been paid, under its row lock, so
 * two payments recorded at once can never together exceed it.
 */
class InvoicePaymentService
{
    public function __construct(private readonly InvoiceMoney $money) {}

    /** @param  array{amount: string, currency: string, method: string, receipt_number?: ?string, paid_at: string}  $data */
    public function record(Invoice $invoice, array $data, User $by): InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $data, $by) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $locked->status->isOpen()) {
                throw new InvoicingException('api.invoice.not_payable', 409);
            }

            $converted = $this->money->toInvoiceCurrency((string) $data['amount'], $data['currency'], $locked->currency);
            $amount = $converted['amount'];
            $remaining = $locked->remaining();
            $shortfall = bcsub($remaining, $amount, 2);

            if (bccomp($shortfall, '0', 2) < 0) {
                throw new InvoicingException('api.invoice.overpayment', 422, ['remaining' => $remaining, 'currency' => $locked->currency]);
            }

            // Truncation can leave less than one minor unit open on a payment
            // worth exactly the remainder: that payment settles it.
            if (bccomp($shortfall, '0', 2) > 0 && bccomp($shortfall, $this->money->minorUnit($locked->currency), 2) <= 0) {
                $amount = $remaining;
            }

            $payment = $locked->payments()->create([
                'received_amount' => $data['amount'],
                'received_currency' => $data['currency'],
                'exchange_rate' => $converted['rate'],
                'amount' => $amount,
                'method' => $data['method'],
                'receipt_number' => $data['receipt_number'] ?? null,
                'paid_at' => $data['paid_at'],
                'recorded_by' => $by->id,
            ]);

            $locked->paid_amount = bcadd((string) $locked->paid_amount, $amount, 2);
            $locked->refreshStatus();
            $locked->save();

            activity()->causedBy($by)->performedOn($locked)
                ->withProperties(['payment_id' => $payment->id, 'amount' => $amount, 'received' => $data['amount'].' '.$data['currency']])
                ->log('invoice_payment_recorded');

            return $payment;
        });
    }

    public function void(InvoicePayment $payment, string $reason, User $by): void
    {
        DB::transaction(function () use ($payment, $reason, $by) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            $locked = InvoicePayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->isVoided()) {
                throw new InvoicingException('api.invoice.payment_already_voided', 409);
            }

            $locked->forceFill(['voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => $reason])->save();

            $invoice->paid_amount = bcsub((string) $invoice->paid_amount, (string) $locked->amount, 2);
            $invoice->refreshStatus();
            $invoice->save();

            activity()->causedBy($by)->performedOn($invoice)
                ->withProperties(['payment_id' => $locked->id, 'reason' => $reason])
                ->log('invoice_payment_voided');
        });
    }

    public function cancel(Invoice $invoice, string $reason, User $by): void
    {
        DB::transaction(function () use ($invoice, $reason, $by) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === InvoiceStatus::Cancelled) {
                throw new InvoicingException('api.invoice.already_cancelled', 409);
            }

            if (bccomp((string) $locked->paid_amount, '0', 2) > 0) {
                throw new InvoicingException('api.invoice.has_payments');
            }

            $locked->forceFill([
                'status' => InvoiceStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancellation_reason' => $reason,
            ])->save();

            activity()->causedBy($by)->performedOn($locked)
                ->withProperties(['reason' => $reason])
                ->log('invoice_cancelled');
        });
    }
}
```

- [ ] **Step 5: Lang keys** (inside `invoice`, both files)

en:
```php
        'not_payable' => 'This invoice is paid or cancelled; it takes no payment.',
        'overpayment' => 'That is more than what is left on this invoice (:remaining :currency).',
        'has_payments' => 'This invoice has payments; void them before cancelling it.',
        'already_cancelled' => 'This invoice is already cancelled.',
        'payment_already_voided' => 'This payment is already voided.',
```
ar:
```php
        'not_payable' => 'هذه الفاتورة مدفوعة أو ملغاة؛ لا تقبل دفعة.',
        'overpayment' => 'المبلغ أكبر من المتبقي على الفاتورة (:remaining :currency).',
        'has_payments' => 'على هذه الفاتورة دفعات؛ أبطلها قبل الإلغاء.',
        'already_cancelled' => 'هذه الفاتورة ملغاة بالفعل.',
        'payment_already_voided' => 'هذه الدفعة مُبطلة بالفعل.',
```

- [ ] **Step 6: Run**

Run: `PHP artisan test tests/Feature/Invoicing`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
PHP vendor/bin/pint app/Domain/Invoicing tests/Feature/Invoicing
git add app/Domain/Invoicing tests/Feature/Invoicing lang/en/api.php lang/ar/api.php
git commit -m "feat(invoicing): record payments in any currency, void them, cancel unpaid invoices

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Admin invoice endpoints

**Files:**
- Create: `app/Http/Controllers/Api/V1/Admin/InvoiceController.php` (index, show, cancel)
- Create: `app/Http/Controllers/Api/V1/Admin/InvoicePaymentController.php` (store, void)
- Create: `app/Http/Requests/Admin/ListInvoicesRequest.php`, `StoreInvoicePaymentRequest.php`, `ReasonRequest.php`
- Create: `app/Http/Resources/InvoiceResource.php`, `app/Http/Resources/InvoicePaymentResource.php`
- Modify: `routes/api/v1/admin.php`, `app/Services/Permissions/PermissionSyncService.php`, `lang/{en,ar}/api.php`
- Test: `tests/Feature/Invoicing/AdminInvoiceTest.php`

**Interfaces:**
- Consumes: Task 4 service; Task 1 models (`scopeOverdue`, `isOverdue`, `remaining`).
- Produces: routes `GET admin/invoices`, `GET admin/invoices/{invoice}`, `POST admin/invoices/{invoice}/payments` (201 payment), `POST admin/invoice-payments/{invoicePayment}/void`, `POST admin/invoices/{invoice}/cancel`; permissions `invoices.view|record_payment|void_payment|cancel`; `InvoiceResource` (with a protected `staffFields()` hook) and `InvoicePaymentResource` (with a protected `staffFields()` hook) — Task 6 extends both.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Providers\Models\Provider;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class AdminInvoiceTest extends InvoicingTestCase
{
    public function test_the_list_filters_by_status_overdue_provider_and_payer(): void
    {
        Carbon::setTestNow('2026-10-20');
        $provider = Provider::factory()->create();
        $late = Invoice::factory()->create(['provider_id' => $provider->id, 'company_id' => $this->clientCompany($provider)->id, 'due_at' => '2026-10-01']);
        Invoice::factory()->create(['due_at' => '2026-11-01', 'billed_to' => 'provider']);
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/invoices')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/invoices?overdue=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $late->id)
            ->assertJsonPath('data.0.is_overdue', true);
        $this->getJson("/api/v1/admin/invoices?provider_id={$provider->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/invoices?billed_to=provider')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/invoices?status=paid')->assertJsonCount(0, 'data');
    }

    public function test_a_payment_is_recorded_and_the_invoice_shows_it(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '100.00']);
        $staff = $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", [
            'amount' => '25', 'currency' => 'USD', 'method' => 'cash', 'receipt_number' => 'R-77', 'paid_at' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.amount', '25.00');

        $this->getJson("/api/v1/admin/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.remaining', '75.00')
            ->assertJsonPath('data.payments.0.receipt_number', 'R-77')
            ->assertJsonPath('data.payments.0.recorded_by.id', $staff->id);
    }

    public function test_an_overpayment_answers_422_with_the_remainder(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '10.00']);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", [
            'amount' => '11', 'currency' => 'USD', 'method' => 'cash', 'paid_at' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('message', __('api.invoice.overpayment', ['remaining' => '10.00', 'currency' => 'USD']));
    }

    public function test_void_and_cancel_need_a_reason(): void
    {
        $payment = InvoicePayment::factory()->create();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/invoice-payments/{$payment->id}/void")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/invoices/{$payment->invoice_id}/cancel")->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_cancelling_an_unpaid_invoice(): void
    {
        $invoice = Invoice::factory()->create();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/cancel", ['reason' => 'Waived'])
            ->assertOk()->assertJsonPath('message', __('api.invoice.cancelled'));

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
    }

    public function test_each_action_follows_its_permission(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '100.00']);
        $role = Role::findOrCreate('accounts', 'web');
        $role->syncPermissions(['invoices.view']);
        $user = User::factory()->create();
        $user->assignRole('accounts');
        Sanctum::actingAs($user, ['*']);

        $body = ['amount' => '5', 'currency' => 'USD', 'method' => 'cash', 'paid_at' => now()->toDateString()];

        $this->getJson('/api/v1/admin/invoices')->assertOk();
        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", $body)->assertForbidden();

        $role->givePermissionTo('invoices.record_payment');
        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", $body)->assertCreated();
    }

    public function test_operations_without_permissions_and_providers_are_refused(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
        $this->getJson('/api/v1/admin/invoices')->assertForbidden();

        $this->providerAccount();
        $this->getJson('/api/v1/admin/invoices')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `PHP artisan test tests/Feature/Invoicing/AdminInvoiceTest.php`
Expected: FAIL — 404s.

- [ ] **Step 3: Resources**

`app/Http/Resources/InvoicePaymentResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoicePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (string) $this->amount,
            'received_amount' => (string) $this->received_amount,
            'received_currency' => $this->received_currency,
            'exchange_rate' => (string) $this->exchange_rate,
            'method' => $this->method,
            'receipt_number' => $this->receipt_number,
            'paid_at' => $this->paid_at?->toDateString(),
            'voided' => $this->voided_at !== null,
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            ...$this->staffFields(),
        ];
    }

    /** Who at ADD recorded and voided it — the provider view leaves these out. */
    protected function staffFields(): array
    {
        return [
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder === null ? null : ['id' => $this->recorder->id, 'name' => $this->recorder->name]),
            'voided_by' => $this->voided_by,
        ];
    }
}
```

`app/Http/Resources/InvoiceResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /** The payment resource class this view renders payments with. */
    protected string $paymentResource = InvoicePaymentResource::class;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'is_overdue' => $this->isOverdue(),
            'billed_to' => $this->billed_to,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider === null ? null : ['id' => $this->provider->id, 'name' => $this->provider->name]),
            'company' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'legal_name' => $this->company->legal_name]),
            'provider_request_id' => $this->provider_request_id,
            'description' => $this->description,
            'points' => $this->points,
            'currency' => $this->currency,
            'amount' => (string) $this->amount,
            'paid_amount' => (string) $this->paid_amount,
            'remaining' => $this->remaining(),
            'issued_at' => $this->issued_at?->toDateString(),
            'due_at' => $this->due_at?->toDateString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'payments' => $this->whenLoaded('payments', fn () => $this->paymentResource::collection($this->payments)),
            ...$this->staffFields(),
        ];
    }

    /** ADD-internal fields — the provider view leaves these out. */
    protected function staffFields(): array
    {
        return [
            'computed_amount' => (string) $this->computed_amount,
            'exchange_rate_to_base' => (string) $this->exchange_rate_to_base,
            'issued_by' => $this->whenLoaded('issuer', fn () => $this->issuer === null ? null : ['id' => $this->issuer->id, 'name' => $this->issuer->name]),
            'cancelled_by' => $this->cancelled_by,
        ];
    }
}
```

- [ ] **Step 4: Requests**

`ListInvoicesRequest`:

```php
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(InvoiceStatus::class)],
            'overdue' => ['sometimes', 'boolean'],
            'provider_id' => ['sometimes', 'integer'],
            'company_id' => ['sometimes', 'integer'],
            'billed_to' => ['sometimes', Rule::enum(Payer::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
```

`StoreInvoicePaymentRequest`:

```php
    public function rules(): array
    {
        return [
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'currency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'method' => ['required', Rule::enum(InvoicePaymentMethod::class)],
            'receipt_number' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
```

`ReasonRequest`: `'reason' => ['required', 'string', 'max:1000']`. All three `authorize()` → `true`.

- [ ] **Step 5: Controllers**

`app/Http/Controllers/Api/V1/Admin/InvoiceController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoicePaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListInvoicesRequest;
use App\Http\Requests\Admin\ReasonRequest;
use App\Http\Resources\InvoiceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Provider invoices (docs/superpowers/specs/2026-10-10-provider-invoicing-design.md).
 * Does not extend AdminResourceController: paginated and filtered, and an
 * invoice is cancelled, never deleted.
 */
class InvoiceController extends Controller
{
    public function index(ListInvoicesRequest $request): AnonymousResourceCollection
    {
        return InvoiceResource::collection(self::filtered($request)->latest('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->load(['provider', 'company', 'issuer', 'payments.recorder']));
    }

    public function cancel(ReasonRequest $request, Invoice $invoice, InvoicePaymentService $payments): JsonResponse
    {
        try {
            $payments->cancel($invoice, $request->validated('reason'), $request->user());
        } catch (InvoicingException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.invoice.cancelled')]);
    }

    /** The list filters, shared with the provider's list (which scopes the provider first). */
    public static function filtered(ListInvoicesRequest $request, ?Builder $query = null): Builder
    {
        return ($query ?? Invoice::query())
            ->with(['provider', 'company'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('overdue'), fn (Builder $q) => $q->overdue())
            ->when($request->filled('provider_id'), fn (Builder $q) => $q->where('provider_id', $request->integer('provider_id')))
            ->when($request->filled('company_id'), fn (Builder $q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('billed_to'), fn (Builder $q) => $q->where('billed_to', $request->string('billed_to')));
    }
}
```

`app/Http/Controllers/Api/V1/Admin/InvoicePaymentController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Services\InvoicePaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReasonRequest;
use App\Http\Requests\Admin\StoreInvoicePaymentRequest;
use App\Http\Resources\InvoicePaymentResource;
use Illuminate\Http\JsonResponse;

/** Recording money against an invoice, and voiding a wrong record. Never the cash drawer. */
class InvoicePaymentController extends Controller
{
    public function __construct(private readonly InvoicePaymentService $payments) {}

    public function store(StoreInvoicePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        try {
            $payment = $this->payments->record($invoice, $request->validated(), $request->user());
        } catch (InvoicingException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return (new InvoicePaymentResource($payment->load('recorder')))->response()->setStatusCode(201);
    }

    public function void(ReasonRequest $request, InvoicePayment $invoicePayment): JsonResponse
    {
        try {
            $this->payments->void($invoicePayment, $request->validated('reason'), $request->user());
        } catch (InvoicingException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        return response()->json(['message' => __('api.invoice.payment_voided')]);
    }
}
```

- [ ] **Step 6: Routes, permissions, lang**

`routes/api/v1/admin.php` — inside the providers `Route::withoutMiddleware('role:admin|operations')->group(...)` block:

```php
    // Provider invoicing (docs/superpowers/specs/2026-10-10-provider-invoicing-design.md).
    Route::get('invoices', [InvoiceController::class, 'index'])->middleware('permission:invoices.view');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:invoices.view');
    Route::post('invoices/{invoice}/payments', [InvoicePaymentController::class, 'store'])->middleware('permission:invoices.record_payment');
    Route::post('invoice-payments/{invoicePayment}/void', [InvoicePaymentController::class, 'void'])->middleware('permission:invoices.void_payment');
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->middleware('permission:invoices.cancel');
```

`PermissionSyncService::MANUAL_REGISTRATIONS`:

```php
        // Provider invoicing — each action its own grant, so recording money
        // can be given to accounts staff without the right to void or cancel.
        InvoiceController::class => [
            'invoices' => ['view', 'cancel'],
        ],
        InvoicePaymentController::class => [
            'invoices' => ['record_payment', 'void_payment'],
        ],
```

Lang (`invoice` group) — en: `'cancelled' => 'Invoice cancelled.'`, `'payment_voided' => 'Payment voided.'`; ar: `'cancelled' => 'أُلغيت الفاتورة.'`, `'payment_voided' => 'أُبطلت الدفعة.'`.

- [ ] **Step 7: Run**

Run: `PHP artisan test tests/Feature/Invoicing tests/Feature/Providers tests/Feature/Foundation/BranchPermissionEnforcementTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
PHP vendor/bin/pint app/Http/Controllers/Api/V1/Admin/InvoiceController.php app/Http/Controllers/Api/V1/Admin/InvoicePaymentController.php app/Http/Requests/Admin/ListInvoicesRequest.php app/Http/Requests/Admin/StoreInvoicePaymentRequest.php app/Http/Requests/Admin/ReasonRequest.php app/Http/Resources/InvoiceResource.php app/Http/Resources/InvoicePaymentResource.php app/Services/Permissions/PermissionSyncService.php routes/api/v1/admin.php tests/Feature/Invoicing/AdminInvoiceTest.php
git add app/Http/Controllers/Api/V1/Admin/InvoiceController.php app/Http/Controllers/Api/V1/Admin/InvoicePaymentController.php app/Http/Requests/Admin/ListInvoicesRequest.php app/Http/Requests/Admin/StoreInvoicePaymentRequest.php app/Http/Requests/Admin/ReasonRequest.php app/Http/Resources/InvoiceResource.php app/Http/Resources/InvoicePaymentResource.php app/Services/Permissions/PermissionSyncService.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Invoicing/AdminInvoiceTest.php
git commit -m "feat(invoicing): admin lists invoices, records and voids payments, cancels

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: The provider's invoices (read-only)

**Files:**
- Create: `app/Http/Controllers/Api/V1/Provider/ProviderInvoiceController.php`
- Create: `app/Http/Resources/ProviderInvoiceResource.php`, `app/Http/Resources/ProviderInvoicePaymentResource.php`
- Modify: `app/Http/Controllers/Api/V1/Provider/Concerns/ActsForProvider.php` (`authorizeInvoice`)
- Modify: `routes/api/v1/provider.php`
- Modify: `tests/Guards/ProviderRoutesStayIsolatedTest.php`
- Test: `tests/Feature/Invoicing/ProviderInvoiceTest.php`

**Interfaces:**
- Consumes: Task 5 (`InvoiceController::filtered()`, `ListInvoicesRequest`, `InvoiceResource`/`InvoicePaymentResource` hooks).
- Produces: `GET provider/invoices`, `GET provider/invoices/{invoice}`; `ActsForProvider::authorizeInvoice(Request $request, Invoice $invoice): void` (404 when `invoice.provider_id` is not this provider's).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Providers\Models\Provider;

class ProviderInvoiceTest extends InvoicingTestCase
{
    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = Provider::factory()->create();
        $this->providerAccount($this->provider);
    }

    private function mine(array $attributes = []): Invoice
    {
        return Invoice::factory()->create([
            'provider_id' => $this->provider->id,
            'company_id' => $this->clientCompany($this->provider)->id,
            ...$attributes,
        ]);
    }

    public function test_a_provider_lists_only_its_invoices(): void
    {
        $mine = $this->mine();
        Invoice::factory()->create();

        $this->getJson('/api/v1/provider/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_another_providers_invoice_is_not_found(): void
    {
        $theirs = Invoice::factory()->create();

        $this->getJson("/api/v1/provider/invoices/{$theirs->id}")->assertNotFound();
    }

    public function test_the_provider_view_leaves_out_who_at_add_did_what(): void
    {
        $invoice = $this->mine();
        InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'recorded_by' => $this->actingAsAdmin()->id]);
        $this->providerAccount($this->provider);

        $this->getJson("/api/v1/provider/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.payments.0.amount', '10.00')
            ->assertJsonMissingPath('data.issued_by')
            ->assertJsonMissingPath('data.computed_amount')
            ->assertJsonMissingPath('data.payments.0.recorded_by');
    }

    public function test_a_provider_cannot_record_a_payment(): void
    {
        $invoice = $this->mine();

        $this->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", [
            'amount' => '5', 'currency' => 'USD', 'method' => 'cash', 'paid_at' => now()->toDateString(),
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `PHP artisan test tests/Feature/Invoicing/ProviderInvoiceTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`ActsForProvider` — add (import `App\Domain\Invoicing\Models\Invoice`):

```php
    /** 404, as for companies: a provider must not learn another provider's invoice exists. */
    protected function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        abort_unless($invoice->provider_id === $this->provider($request)->id, 404);
    }
```

`app/Http/Resources/ProviderInvoicePaymentResource.php`:

```php
<?php

namespace App\Http\Resources;

/** A payment as the provider sees it: no ADD staff fields. */
class ProviderInvoicePaymentResource extends InvoicePaymentResource
{
    protected function staffFields(): array
    {
        return [];
    }
}
```

`app/Http/Resources/ProviderInvoiceResource.php`:

```php
<?php

namespace App\Http\Resources;

/** An invoice as the provider sees it: no ADD staff fields, no computed amount or rate. */
class ProviderInvoiceResource extends InvoiceResource
{
    protected string $paymentResource = ProviderInvoicePaymentResource::class;

    protected function staffFields(): array
    {
        return [];
    }
}
```

`app/Http/Controllers/Api/V1/Provider/ProviderInvoiceController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Invoicing\Models\Invoice;
use App\Http\Controllers\Api\V1\Admin\InvoiceController;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListInvoicesRequest;
use App\Http\Resources\ProviderInvoiceResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The provider's invoices — read only (docs/superpowers/specs/2026-10-10-provider-invoicing-design.md):
 * those billed to it and those billed to its companies.
 */
class ProviderInvoiceController extends Controller
{
    use ActsForProvider;

    public function index(ListInvoicesRequest $request): AnonymousResourceCollection
    {
        $query = Invoice::query()->where('provider_id', $this->provider($request)->id);

        return ProviderInvoiceResource::collection(
            InvoiceController::filtered($request, $query)->latest('id')->paginate($request->integer('per_page', 15))
        );
    }

    public function show(Request $request, Invoice $invoice): ProviderInvoiceResource
    {
        $this->authorizeInvoice($request, $invoice);

        return new ProviderInvoiceResource($invoice->load(['company', 'payments']));
    }
}
```

The `provider_id` filter inside `filtered()` can only narrow within the provider's own rows (the query is scoped first), so it needs no special handling. The controller importing `Admin\InvoiceController` for `filtered()` is fine: `ProviderRoutesStayIsolatedTest` checks only the `use` lines of `routes/api/v1/provider.php`, which must keep importing `Provider\*` controllers only.

`routes/api/v1/provider.php` — add (import `ProviderInvoiceController`):

```php
Route::get('invoices', [ProviderInvoiceController::class, 'index']);
Route::get('invoices/{invoice}', [ProviderInvoiceController::class, 'show']);
```

`tests/Guards/ProviderRoutesStayIsolatedTest::test_every_provider_controller_taking_a_company_authorizes_it` — extend to invoices:

```php
            if (str_contains($source, 'Company $company') && ! str_contains($source, 'authorizeCompany(')) {
                $violations[] = basename($file).' (company)';
            }

            if (str_contains($source, 'Invoice $invoice') && ! str_contains($source, 'authorizeInvoice(')) {
                $violations[] = basename($file).' (invoice)';
            }
```

(rename the test to `test_every_provider_controller_taking_a_company_or_invoice_authorizes_it` and update its message).

- [ ] **Step 4: Run**

Run: `PHP artisan test tests/Feature/Invoicing tests/Guards/ProviderRoutesStayIsolatedTest.php tests/Feature/Providers`
Expected: PASS. Sanity-check the guard bites: temporarily remove the `authorizeInvoice` call, see FAIL, restore.

- [ ] **Step 5: Commit**

```bash
PHP vendor/bin/pint app/Http/Controllers/Api/V1/Provider app/Http/Resources/ProviderInvoiceResource.php app/Http/Resources/ProviderInvoicePaymentResource.php routes/api/v1/provider.php tests/Guards/ProviderRoutesStayIsolatedTest.php tests/Feature/Invoicing/ProviderInvoiceTest.php
git add app/Http/Controllers/Api/V1/Provider app/Http/Resources/ProviderInvoiceResource.php app/Http/Resources/ProviderInvoicePaymentResource.php routes/api/v1/provider.php tests/Guards/ProviderRoutesStayIsolatedTest.php tests/Feature/Invoicing/ProviderInvoiceTest.php
git commit -m "feat(invoicing): providers see their invoices, read only

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Decision record, CLAUDE.md, Postman

**Files:**
- Modify: `docs/decisions/service-providers.md`, `CLAUDE.md`, `postman/ADD-OS.postman_collection.json`

- [ ] **Step 1: Decision record**

In `docs/decisions/service-providers.md` add a section **Invoicing (Phase 2)** — follow the record's existing tone:
- What an approval now issues (assignment: plan price × seats; top-up: points), to whom (`billed_to` = the request's payer), priced how (`ReceivedMoneyConverter::pointsToAmount()` in the reviewer's currency, frozen with its rate), due when (provider `payment_terms_days`, else setting `invoices.default_due_days` = 15), and that a missing rate refuses the whole approval.
- The approve body's new optional fields `invoice_currency`, `invoice_amount`, `invoice_due_at`; the `invoice_preview` / `invoice` blocks on the admin request view.
- Payments: dashboard only (`invoices.record_payment`), outside the points ledger and the cash drawer; any currency, converted at the day's rate and truncated, a sub-unit shortfall settles; partial allowed, overpayment refused; voided not deleted (`invoices.void_payment`); cancel only with no active payment (`invoices.cancel`), plan unaffected.
- Provider view: read-only, no staff fields; 404 for another provider's invoice.
- Invoice sources are separate nullable FKs (only `provider_request_id` now); office leases (Phase 3) add theirs.
- Rulings: the three refinements in the plan's table.
- Remove or rewrite the "do not ship to production without Phase 2" warning: approvals now leave a recorded receivable.
- Updated phase order: 3 office leases → 4 auto-renewal → 5 reports.

- [ ] **Step 2: CLAUDE.md**

At the end of the **Service providers** paragraph, replace the sentence "Phase 1 activates assigned plans with no charge — invoicing is Phase 2." with:

```markdown
Approving an assignment or a top-up also issues an `Invoice` (`App\Domain\Invoicing`, `InvoiceIssuer`) in the same transaction — a debt in money billed to the request's payer, outside the points ledger and the cash drawer; payments are recorded, voided and invoices cancelled only through `InvoicePaymentService` under the invoice's row lock (`invoices.*` permissions), and providers read their invoices on `provider/invoices`.
```

Edit only that sentence; check `git diff CLAUDE.md`.

- [ ] **Step 3: Postman**

Add to the existing **Admin → Providers** folder: List Invoices (with `overdue=1` example), Show Invoice, Record Payment, Void Payment, Cancel Invoice; extend the Approve Provider Request body example with the three `invoice_*` fields. Add to **Provider (Dashboard)**: List Invoices, Show Invoice. Do not read the collection whole: grep for the folder names, read only those regions, insert with a small decode/append/encode script matching the file's existing formatting, and validate it still decodes.

- [ ] **Step 4: Full suite**

Run: `PHP artisan test`
Expected: only the 17 pre-existing failures.

- [ ] **Step 5: Commit**

```bash
git add docs/decisions/service-providers.md CLAUDE.md postman/ADD-OS.postman_collection.json
git commit -m "docs(invoicing): decision record, CLAUDE.md and Postman

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
