<?php

namespace Database\Seeders;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Data\InvoiceTerms;
use App\Domain\Invoicing\Enums\InvoicePaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Services\InvoicePaymentService;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\LeaseDailyRunner;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Enums\ProviderStatus;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Renewals\Exceptions\RenewalException;
use App\Domain\Renewals\Exceptions\RenewalRefused;
use App\Domain\Renewals\Services\MembershipRenewalService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Demo data for the service-providers module (docs/decisions/service-providers.md,
 * invoicing, office-leases.md, auto-renewal.md, provider-reports.md), so every
 * state shows in the dashboard.
 *
 * Providers: "Demo Provider Alpha" (active, large, USD and SYP), "Demo
 * Provider Beta" (active, small), "Demo Provider Gamma" (inactive).
 *
 * Companies: five of Alpha's (one inactive, one transferred to Beta with its
 * subscription's auto-renew switched off), two of Beta's, one of Gamma's and
 * one direct company, each with employees; one company admin.
 *
 * Provider requests: every type (company_member, company_admin, plan_design,
 * plan_assignment, wallet_top_up) in every status (pending, approved,
 * rejected, cancelled), all decided through ProviderRequestService.
 *
 * Invoices: unpaid, partially_paid, paid and cancelled; overdue by about 10,
 * 45 and 90 days; in USD and SYP; billed to a provider and to a company;
 * payments by bank_transfer, cash, cheque and other, plus one voided payment;
 * from plan assignments, wallet top-ups, office leases and membership renewals.
 *
 * Office leases: active with invoiced and upcoming periods; starting in the
 * future; ended; terminated early; auto-renew on; renewal stopped.
 *
 * Subscriptions: a live one, a renewed one waiting to start (upcoming), and
 * live ones whose renewal stopped (overdue_invoice, no_seats, plan_inactive).
 *
 * Demo logins (password `password`): alpha@providers.demo,
 * beta@providers.demo, gamma@providers.demo. A reviewer account
 * (reviewer@providers.demo, admin role) is created only when no admin exists.
 *
 * Local development only, and re-runnable: every record is found by a stable
 * key (provider name, account email, company legal name, office name) and a
 * scenario already there is left as it is. Money, requests, leases and
 * renewals go through the real domain services; only the age of an invoice
 * (its issued and due dates, to make it overdue) and a stored renewal-stop
 * reason are written directly, since they describe history the present
 * cannot produce. Needs what the other seeders make: roles, a branch, a
 * business office (the demo offices are copies of it), plans and currencies;
 * a case whose prerequisite is missing is skipped with a note.
 *
 * Run manually: php artisan db:seed --class=ProvidersDemoSeeder
 */
class ProvidersDemoSeeder extends Seeder
{
    private const PROVIDERS = [
        'alpha' => ['name' => 'Demo Provider Alpha', 'email' => 'alpha@providers.demo', 'phone' => '+963944100001', 'terms' => 30],
        'beta' => ['name' => 'Demo Provider Beta', 'email' => 'beta@providers.demo', 'phone' => '+963944100002', 'terms' => 15],
        'gamma' => ['name' => 'Demo Provider Gamma', 'email' => 'gamma@providers.demo', 'phone' => '+963944100003', 'terms' => null],
    ];

    /** key => [legal name, provider key or null, status] */
    private const COMPANIES = [
        'orchard' => ['Demo Alpha - Orchard Tech', 'alpha', CompanyStatus::Active],
        'cedar' => ['Demo Alpha - Cedar Labs', 'alpha', CompanyStatus::Active],
        'olive' => ['Demo Alpha - Olive Studio', 'alpha', CompanyStatus::Active],
        'jasmine' => ['Demo Alpha - Jasmine Works (transferred to Beta)', 'alpha', CompanyStatus::Active],
        'souq' => ['Demo Alpha - Old Souq Trading', 'alpha', CompanyStatus::Inactive],
        'bluebird' => ['Demo Beta - Bluebird Design', 'beta', CompanyStatus::Active],
        'citadel' => ['Demo Beta - Citadel Foods', 'beta', CompanyStatus::Active],
        'legacy' => ['Demo Gamma - Legacy Co', 'gamma', CompanyStatus::Active],
        'khan' => ['Demo Direct - Khan Consulting', null, CompanyStatus::Active],
    ];

    /** [email local part, name, phone, company key or null, is company admin] */
    private const EMPLOYEES = [
        ['orchard.layla', 'Layla Orchard', '+963944200001', null, false],
        ['orchard.omar', 'Omar Orchard', '+963944200002', null, false],
        ['orchard.nour', 'Nour Orchard', '+963944200003', null, false],
        ['cedar.sami', 'Sami Cedar', '+963944200004', 'cedar', true],
        ['cedar.dana', 'Dana Cedar', '+963944200005', 'cedar', false],
        ['olive.rima', 'Rima Olive', '+963944200006', 'olive', true],
        ['jasmine.yara', 'Yara Jasmine', '+963944200007', 'jasmine', true],
        ['souq.adel', 'Adel Souq', '+963944200008', 'souq', true],
        ['bluebird.hana', 'Hana Bluebird', '+963944200009', 'bluebird', true],
        ['bluebird.zaid', 'Zaid Bluebird', '+963944200010', 'bluebird', false],
        ['citadel.maher', 'Maher Citadel', '+963944200011', 'citadel', true],
        ['legacy.salma', 'Salma Legacy', '+963944200012', 'legacy', true],
        ['khan.karim', 'Karim Khan', '+963944200013', 'khan', true],
        ['khan.lina', 'Lina Khan', '+963944200014', 'khan', false],
        ['khan.tariq', 'Tariq Khan', '+963944200015', 'khan', false],
    ];

    private User $reviewer;

    private Branch $branch;

    private ?Plan $monthly = null;

    private ?Plan $quarterly = null;

    /** The currency of the "foreign" cases: SYP when it has a rate, else USD. */
    private string $syp = 'USD';

    /** @var array<int, Space> demo offices by number 1..6 */
    private array $offices = [];

    private ProviderRequestService $requests;

    private InvoicePaymentService $payments;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('ProvidersDemoSeeder creates demo accounts with a known password; it never runs in production.');
        }

        if (! $this->prepare()) {
            return;
        }

        $this->providers();
        $this->companies();

        $this->orchardScenario();
        $this->cedarScenario();
        $this->oliveScenario();
        $this->jasmineScenario();
        $this->bluebirdScenario();
        $this->citadelScenario();

        $this->leases();
        $this->transferJasmine();
        $this->gammaScenario();

        $this->command?->info('Providers demo data is in place. Demo logins: alpha@providers.demo, beta@providers.demo, gamma@providers.demo (password: password).');
    }

    // ---------------------------------------------------------------- setup

    /** Loads what the other seeders made; false when nothing useful can be built. */
    private function prepare(): bool
    {
        $this->requests = app(ProviderRequestService::class);
        $this->payments = app(InvoicePaymentService::class);

        foreach (['provider', 'member', 'admin'] as $role) {
            if (! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
                $this->command?->warn("Skipped everything: the {$role} role is missing; run RoleSeeder first.");

                return false;
            }
        }

        if (! Currency::query()->where('code', 'USD')->exists()) {
            $this->command?->warn('Skipped everything: the USD currency is missing; run CurrencySeeder first.');

            return false;
        }

        $branch = Branch::query()->where('is_default', true)->first() ?? Branch::query()->orderBy('id')->first();

        if ($branch === null) {
            $this->command?->warn('Skipped everything: there is no branch; run SpaceSeeder first.');

            return false;
        }

        $this->branch = $branch;
        $this->reviewer = $this->reviewer();
        $this->syp = $this->sypRate() ? 'SYP' : 'USD';
        $this->prepareOffices();

        $this->monthly = $this->plan(1250, 30);
        $this->quarterly = $this->plan(3000, 90) ?? $this->monthly;

        if ($this->monthly === null) {
            $this->command?->warn('No Hot Desk monthly plan: plan assignments, renewals and their invoices are skipped; run PlanSeeder.');
        }

        return true;
    }

    private function reviewer(): User
    {
        $admin = User::role('admin')->orderBy('id')->first();

        return $admin ?? $this->user('reviewer@providers.demo', 'Demo Reviewer', '+963944100099', 'admin');
    }

    /** SYP needs a rate to price in it; one is added only when none exists. */
    private function sypRate(): bool
    {
        if (! Currency::query()->where('code', 'SYP')->exists()) {
            $this->command?->warn('No SYP currency: the SYP cases are billed in USD instead; run CurrencySeeder.');

            return false;
        }

        if (! ExchangeRate::query()->where('currency_code', 'SYP')->exists()) {
            ExchangeRate::create([
                'currency_code' => 'SYP',
                'rate_to_base' => '0.0000680272',
                'effective_from' => now()->subYear(),
            ]);
        }

        return true;
    }

    private function plan(int $points, int $days): ?Plan
    {
        return Plan::query()
            ->where('is_subscription', true)->where('is_active', true)->where('is_public', true)
            ->where('price_points', $points)->where('duration_days', $days)
            ->orderBy('id')->first();
    }

    /** Six demo offices, copies of the first business office (a lease needs an office of its own each). */
    private function prepareOffices(): void
    {
        $template = Space::query()->where('space_type', SpaceType::Business)->orderBy('id')->first();

        if ($template === null) {
            $this->command?->warn('No business office: the office-lease cases are skipped; run SpaceSeeder.');

            return;
        }

        for ($n = 1; $n <= 6; $n++) {
            $name = "Demo Office {$n}";

            $this->offices[$n] = Space::query()->where('building_id', $template->building_id)->where('name->en', $name)->first()
                ?? Space::create([
                    'building_id' => $template->building_id,
                    'zone_id' => $template->zone_id,
                    'name' => ['en' => $name, 'ar' => "مكتب تجريبي {$n}"],
                    'space_type' => SpaceType::Business,
                    'allocation_model' => AllocationModel::BookingHourly,
                    'is_lockable' => false,
                    'capacity' => 2,
                    'hourly_rate_points' => 180,
                    'cancellation_window_minutes' => 120,
                    'slot_granularity_minutes' => 60,
                    'buffer_minutes' => 0,
                    'requires_approval' => false,
                    'status' => OperationalStatus::Active,
                ]);
        }
    }

    // ------------------------------------------------------ providers, people

    private function providers(): void
    {
        foreach (self::PROVIDERS as $spec) {
            $provider = Provider::query()->firstOrCreate(
                ['name' => $spec['name']],
                [
                    'contract_ref' => 'DEMO-'.strtoupper(substr($spec['email'], 0, strpos($spec['email'], '@'))),
                    'payment_terms_days' => $spec['terms'],
                    'status' => ProviderStatus::Active,
                ],
            );

            $account = $this->user($spec['email'], $spec['name'].' Account', $spec['phone'], 'provider');
            $provider->users()->syncWithoutDetaching([$account->id]);
        }
    }

    /** Companies with their wallets, then employees. Alpha's Orchard keeps its people unlinked: its requests link them. */
    private function companies(): void
    {
        foreach (self::COMPANIES as $key => [$name, $providerKey, $status]) {
            $company = Company::query()->where('legal_name', $name)->first();

            if ($company === null) {
                $company = Company::create([
                    'legal_name' => $name,
                    'contract_ref' => 'DEMO-'.strtoupper($key),
                    'branch_id' => $this->branch->id,
                    'status' => CompanyStatus::Active,
                    'provider_id' => $providerKey === null ? null : $this->provider($providerKey)->id,
                ]);
            }

            // A wallet needs an active owner, so an inactive company is switched off after it.
            Wallet::query()->firstOrCreate(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

            if ($status !== CompanyStatus::Active && $company->status !== $status) {
                $company->update(['status' => $status]);
            }
        }

        foreach (self::EMPLOYEES as [$local, $name, $phone, $companyKey, $isAdmin]) {
            $user = $this->user("{$local}@providers.demo", $name, $phone, 'member');
            Wallet::query()->firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

            if ($companyKey !== null) {
                $this->company($companyKey)->members()->syncWithoutDetaching([
                    $user->id => ['door_access_enabled' => true, 'is_admin' => $isAdmin],
                ]);
            }
        }
    }

    // ------------------------------------------------- requests and invoices

    /**
     * Orchard Tech: the full request matrix (5 types x 4 statuses), the live
     * subscription and its renewal waiting to start, a paid top-up.
     */
    private function orchardScenario(): void
    {
        $alpha = $this->provider('alpha');
        $company = $this->company('orchard');

        if (ProviderRequest::query()->where('company_id', $company->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($alpha, $company) {
            $layla = $this->userByEmail('orchard.layla@providers.demo');
            $omar = $this->userByEmail('orchard.omar@providers.demo');
            $nour = $this->userByEmail('orchard.nour@providers.demo');

            // Employees: two linked at once, one parked as an invitation.
            $this->ask($alpha, $company, ProviderRequestType::CompanyMember, $this->member($layla->phone, $layla->name, true), 'approved');
            $this->ask($alpha, $company, ProviderRequestType::CompanyMember, $this->member($omar->phone, $omar->name, false), 'approved');
            $this->ask($alpha, $company, ProviderRequestType::CompanyMember, $this->member('+963944990001', 'Invited Employee', true), 'approved');
            $this->ask($alpha, $company, ProviderRequestType::CompanyMember, $this->member('+963944990002', 'Waiting Employee', false), 'pending');
            $this->ask($alpha, $company, ProviderRequestType::CompanyMember, $this->member('+963944990003', 'Refused Employee', false), 'rejected');
            $this->ask($alpha, $company, ProviderRequestType::CompanyMember, $this->member('+963944990004', 'Withdrawn Employee', false), 'cancelled');

            // Company admin.
            $this->ask($alpha, $company, ProviderRequestType::CompanyAdmin, ['user_id' => $layla->id], 'approved');
            $this->ask($alpha, $company, ProviderRequestType::CompanyAdmin, ['user_id' => $omar->id], 'pending');
            $this->ask($alpha, $company, ProviderRequestType::CompanyAdmin, ['user_id' => $nour->id], 'rejected');
            $this->ask($alpha, $company, ProviderRequestType::CompanyAdmin, ['user_id' => $omar->id], 'cancelled');

            // Top-ups: the approved one is billed to the company and paid in cash.
            $topUp = $this->ask($alpha, $company, ProviderRequestType::WalletTopUp, ['points' => 500, 'payer' => 'company'], 'approved');
            $this->pay($this->invoiceOf($topUp), InvoicePaymentMethod::Cash);
            $this->ask($alpha, $company, ProviderRequestType::WalletTopUp, ['points' => 300, 'payer' => 'company'], 'pending');
            $this->ask($alpha, $company, ProviderRequestType::WalletTopUp, ['points' => 200, 'payer' => 'provider'], 'rejected');
            $this->ask($alpha, $company, ProviderRequestType::WalletTopUp, ['points' => 100, 'payer' => 'company'], 'cancelled');

            if ($this->monthly !== null) {
                $this->orchardSubscription($alpha, $company);
            }
        });

        $this->designs($alpha);
    }

    private function orchardSubscription(Provider $alpha, Company $company): void
    {
        $assignment = $this->ask($alpha, $company, ProviderRequestType::PlanAssignment, $this->assignment($this->monthly, 5, Payer::Provider, true), 'approved', overrides: ['override_capacity' => true]);
        $this->pay($this->invoiceOf($assignment), InvoicePaymentMethod::BankTransfer);

        foreach (['pending', 'rejected', 'cancelled'] as $outcome) {
            $this->ask($alpha, $company, ProviderRequestType::PlanAssignment, $this->assignment($this->monthly, 1, Payer::Company, false), $outcome);
        }

        // The renewal is made ahead: it starts when the live subscription ends (upcoming).
        $live = Membership::query()->findOrFail($assignment->result_id);

        try {
            $renewal = app(MembershipRenewalService::class)->renew($live, $this->reviewer);
            $invoice = Invoice::query()->where('membership_id', $renewal->id)->first();
            $this->pay($invoice, InvoicePaymentMethod::Cheque, '0.3');
            $this->age($invoice, 10);
        } catch (RenewalRefused|RenewalException $e) {
            $this->command?->warn('Orchard renewal not made: '.$e->getMessage());
        }
    }

    /** Provider-level plan designs in every status (no company). */
    private function designs(Provider $alpha): void
    {
        if (ProviderRequest::query()->where('provider_id', $alpha->id)->where('type', ProviderRequestType::PlanDesign)->exists()) {
            return;
        }

        DB::transaction(function () use ($alpha) {
            foreach (['approved', 'pending', 'rejected', 'cancelled'] as $i => $outcome) {
                $this->ask($alpha, null, ProviderRequestType::PlanDesign, [
                    'name' => ['en' => "Demo Alpha Team Plan {$outcome}", 'ar' => "باقة فريق ألفا {$outcome}"],
                    'is_subscription' => true,
                    'price_points' => 900 + $i * 100,
                    'duration_days' => 30,
                    'features' => [['name' => ['en' => 'Shared desk', 'ar' => 'مكتب مشترك']]],
                ], $outcome);
            }
        });
    }

    /** Cedar Labs: SYP subscription billed to the company, 45 days overdue, renewal stopped (overdue_invoice); a SYP top-up billed to Alpha. */
    private function cedarScenario(): void
    {
        $alpha = $this->provider('alpha');
        $company = $this->company('cedar');

        if ($this->monthly === null || ProviderRequest::query()->where('company_id', $company->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($alpha, $company) {
            $assignment = $this->ask($alpha, $company, ProviderRequestType::PlanAssignment, $this->assignment($this->quarterly, 3, Payer::Company, true), 'approved',
                new InvoiceTerms(currency: $this->syp), ['override_capacity' => true]);
            $this->age($this->invoiceOf($assignment), 45);
            $this->stopRenewal($assignment, RenewalStopReason::OverdueInvoice);

            $topUp = $this->ask($alpha, $company, ProviderRequestType::WalletTopUp, ['points' => 200, 'payer' => 'provider'], 'approved', new InvoiceTerms(currency: $this->syp));
            $invoice = $this->invoiceOf($topUp);
            $this->pay($invoice, InvoicePaymentMethod::BankTransfer, '0.4');
            $this->age($invoice, 10);
        });
    }

    /** Olive Studio: 90 days overdue and partly paid, a voided payment, a cancelled invoice, renewal stopped (no_seats). */
    private function oliveScenario(): void
    {
        $alpha = $this->provider('alpha');
        $company = $this->company('olive');

        if ($this->monthly === null || ProviderRequest::query()->where('company_id', $company->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($alpha, $company) {
            $assignment = $this->ask($alpha, $company, ProviderRequestType::PlanAssignment, $this->assignment($this->monthly, 2, Payer::Provider, true), 'approved', overrides: ['override_capacity' => true]);
            $invoice = $this->invoiceOf($assignment);
            $this->pay($invoice, InvoicePaymentMethod::Other, '0.2');
            $this->age($invoice, 90);
            $this->stopRenewal($assignment, RenewalStopReason::NoSeats);

            // Paid in cash, then the payment is voided: the invoice is open again.
            $voided = $this->ask($alpha, $company, ProviderRequestType::WalletTopUp, ['points' => 1000, 'payer' => 'company'], 'approved');
            $payment = $this->pay($this->invoiceOf($voided), InvoicePaymentMethod::Cash);
            if ($payment !== null) {
                $this->payments->void($payment, 'Demo: cash was counted twice', $this->reviewer);
            }

            $cancelled = $this->ask($alpha, $company, ProviderRequestType::WalletTopUp, ['points' => 300, 'payer' => 'provider'], 'approved');
            $this->payments->cancel($this->invoiceOf($cancelled), 'Demo: issued by mistake', $this->reviewer);
        });
    }

    /** Jasmine Works: an overdue subscription with auto-renew on and a pending request; both change when it is transferred. */
    private function jasmineScenario(): void
    {
        $alpha = $this->provider('alpha');
        $company = $this->company('jasmine');

        if ($this->monthly === null || ProviderRequest::query()->where('company_id', $company->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($alpha, $company) {
            $assignment = $this->ask($alpha, $company, ProviderRequestType::PlanAssignment, $this->assignment($this->monthly, 1, Payer::Provider, true), 'approved', overrides: ['override_capacity' => true]);
            $this->age($this->invoiceOf($assignment), 10);
            $this->ask($alpha, $company, ProviderRequestType::WalletTopUp, ['points' => 150, 'payer' => 'provider'], 'pending');
        });
    }

    /** Bluebird Design: 45 days overdue in USD, billed to the company; renewal stopped (plan_inactive). */
    private function bluebirdScenario(): void
    {
        $beta = $this->provider('beta');
        $company = $this->company('bluebird');

        if ($this->monthly === null || ProviderRequest::query()->where('company_id', $company->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($beta, $company) {
            $assignment = $this->ask($beta, $company, ProviderRequestType::PlanAssignment, $this->assignment($this->monthly, 1, Payer::Company, true), 'approved', overrides: ['override_capacity' => true]);
            $this->age($this->invoiceOf($assignment), 45);
            $this->stopRenewal($assignment, RenewalStopReason::PlanInactive);
        });
    }

    /** Citadel Foods: a SYP top-up billed to the company 90 days overdue; a current USD top-up billed to Beta. */
    private function citadelScenario(): void
    {
        $beta = $this->provider('beta');
        $company = $this->company('citadel');

        if (ProviderRequest::query()->where('company_id', $company->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($beta, $company) {
            $old = $this->ask($beta, $company, ProviderRequestType::WalletTopUp, ['points' => 500, 'payer' => 'company'], 'approved', new InvoiceTerms(currency: $this->syp));
            $this->age($this->invoiceOf($old), 90);

            $this->ask($beta, $company, ProviderRequestType::WalletTopUp, ['points' => 300, 'payer' => 'provider'], 'approved');
        });
    }

    // ---------------------------------------------------------------- leases

    private function leases(): void
    {
        if ($this->offices === []) {
            return;
        }

        $today = CarbonImmutable::today();

        // Direct company: active, quarterly, first period part-paid, the rest upcoming.
        $this->lease('khan', 1, $today->subMonths(2), 12, 3, '450.00', 'USD', null, false, function (OfficeLease $lease) {
            $this->pay($this->periodInvoice($lease, 1), InvoicePaymentMethod::Cash, '0.5');
        });

        // Alpha, auto-renew on, ending in four days: first period paid, second invoiced.
        $this->lease('olive', 2, $today->subMonths(6)->addDays(5), 6, 3, '300.00', 'USD', Payer::Provider, true, function (OfficeLease $lease) {
            $this->pay($this->periodInvoice($lease, 1), InvoicePaymentMethod::BankTransfer);
        });

        // Alpha, auto-renew stopped by an overdue invoice: the second period is 10 days overdue.
        $this->lease('orchard', 3, $today->subMonths(6)->addDays(3), 6, 3, '350.00', 'USD', Payer::Provider, true, function (OfficeLease $lease) {
            $this->pay($this->periodInvoice($lease, 1), InvoicePaymentMethod::Cheque);
            $this->age($this->periodInvoice($lease, 2), 10);
            $lease->update(['renewal_stop_reason' => RenewalStopReason::OverdueInvoice]);
        });

        // Starts in 20 days (SYP, billed to the company): only period 1 is invoiced.
        $this->lease('cedar', 4, $today->addDays(20), 6, 1, $this->syp === 'SYP' ? '4000000' : '280.00', $this->syp, Payer::Company, false);

        // Terminated early: the periods from the termination date are cancelled.
        $this->lease('bluebird', 5, $today->subMonths(3), 12, 3, '400.00', 'USD', Payer::Company, false, function (OfficeLease $lease) {
            $this->pay($this->periodInvoice($lease, 1), InvoicePaymentMethod::Other);
            app(OfficeLeaseService::class)->terminate($lease, CarbonImmutable::today()->addDays(20)->toDateString(), 'Demo: tenant relocated', $this->reviewer);
        });

        // Ended: a three-month lease long over, paid.
        $this->lease('citadel', 6, $today->subMonths(8), 3, 3, '500.00', 'USD', Payer::Company, false, function (OfficeLease $lease) {
            $this->pay($this->periodInvoice($lease, 1), InvoicePaymentMethod::BankTransfer);
            app(LeaseDailyRunner::class)->endExpired();
        });
    }

    /**
     * Contracts one lease from a quoted private-office request, then runs
     * $after on it, all in one transaction. Skipped when its office already
     * carries a lease (the re-run guard) or a prerequisite is missing.
     */
    private function lease(string $companyKey, int $officeNo, CarbonImmutable $startsOn, int $months, int $cycle, string $monthly, string $currency, ?Payer $billedTo, bool $autoRenew, ?\Closure $after = null): void
    {
        $office = $this->offices[$officeNo] ?? null;

        if ($office === null || OfficeLease::query()->where('space_id', $office->id)->exists()) {
            return;
        }

        $company = $this->company($companyKey);

        DB::transaction(function () use ($company, $office, $startsOn, $months, $cycle, $monthly, $currency, $billedTo, $autoRenew, $after) {
            $request = PrivateOfficeRequest::create([
                'prospect_name' => $company->legal_name,
                'contact' => '+963944300000',
                'status' => PrivateOfficeRequestStatus::Quoted,
                'quote_ref' => 'Q-DEMO-'.$office->id,
                'company_id' => $company->id,
                'provider_id' => $company->provider_id,
                'payer' => $billedTo,
            ]);

            $lease = app(OfficeLeaseService::class)->contract($request, new LeaseTerms(
                spaceId: $office->id,
                startsOn: $startsOn->toDateString(),
                durationMonths: $months,
                billingCycleMonths: $cycle,
                monthlyAmount: $monthly,
                currency: $currency,
                contractRef: 'LSE-DEMO-'.$office->id,
                billedTo: $billedTo,
                autoRenew: $autoRenew,
            ), $this->reviewer);

            if ($after !== null) {
                $after($lease);
            }
        });
    }

    // ----------------------------------------------------- transfer, deactivation

    /**
     * Moves Jasmine Works from Alpha to Beta the way CompanyProviderController
     * does (that logic lives in the controller, so it is mirrored here): the
     * old provider's pending requests are cancelled, its leases follow, and
     * its subscription stops auto-renewing.
     */
    private function transferJasmine(): void
    {
        $company = $this->company('jasmine');
        $alpha = $this->provider('alpha');
        $beta = $this->provider('beta');

        if ($company->provider_id !== $alpha->id) {
            return;
        }

        DB::transaction(function () use ($company, $alpha, $beta) {
            $this->requests->cancelPending(ProviderRequest::query()->where('provider_id', $alpha->id)->where('company_id', $company->id), 'company_transferred');
            $company->update(['provider_id' => $beta->id]);

            OfficeLease::query()->where('company_id', $company->id)->where('status', '!=', 'ended')->update(['provider_id' => $beta->id]);

            Membership::query()
                ->where('owner_type', OwnerType::Company)->where('owner_id', $company->id)
                ->where('status', MembershipStatus::Active)->where('current_period_end', '>=', now())->where('auto_renew', true)
                ->update(['auto_renew' => false, 'renewal_stop_reason' => null]);
        });
    }

    /** Gamma: one paid top-up and a pending request, then the provider is deactivated (pending requests cancelled). */
    private function gammaScenario(): void
    {
        $gamma = $this->provider('gamma');
        $company = $this->company('legacy');

        if (ProviderRequest::query()->where('provider_id', $gamma->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($gamma, $company) {
            $topUp = $this->ask($gamma, $company, ProviderRequestType::WalletTopUp, ['points' => 300, 'payer' => 'provider'], 'approved');
            $this->pay($this->invoiceOf($topUp), InvoicePaymentMethod::Other);
            $this->ask($gamma, $company, ProviderRequestType::WalletTopUp, ['points' => 100, 'payer' => 'provider'], 'pending');

            $this->requests->cancelPending(ProviderRequest::query()->where('provider_id', $gamma->id), 'provider_deactivated');
            $gamma->update(['status' => ProviderStatus::Inactive]);
        });
    }

    // --------------------------------------------------------------- helpers

    /**
     * Files a request as the provider's account and takes it to $outcome
     * (approved | rejected | cancelled | pending) through the real service.
     */
    private function ask(Provider $provider, ?Company $company, ProviderRequestType $type, array $payload, string $outcome, ?InvoiceTerms $terms = null, array $overrides = []): ProviderRequest
    {
        $submitter = $provider->users()->orderBy('users.id')->firstOrFail();
        $request = $this->requests->submit($provider, $submitter, $type, $company?->id, $payload);

        return match ($outcome) {
            'approved' => $this->requests->approve($request, $this->reviewer, $overrides, $terms),
            'rejected' => $this->requests->reject($request, $this->reviewer, 'Demo: does not match the contract'),
            'cancelled' => tap($request, fn (ProviderRequest $r) => $this->requests->cancel($r))->refresh(),
            default => $request,
        };
    }

    /** @return array<string, mixed> */
    private function member(string $phone, string $name, bool $door): array
    {
        return ['phone' => $phone, 'name' => $name, 'door_access_enabled' => $door];
    }

    /** @return array<string, mixed> */
    private function assignment(Plan $plan, int $seats, Payer $payer, bool $autoRenew): array
    {
        return [
            'plan_id' => $plan->id,
            'branch_id' => $this->branch->id,
            'seats' => $seats,
            'payer' => $payer->value,
            'auto_renew' => $autoRenew,
        ];
    }

    private function invoiceOf(ProviderRequest $request): ?Invoice
    {
        return Invoice::query()->where('provider_request_id', $request->id)->first();
    }

    private function periodInvoice(OfficeLease $lease, int $sequence): ?Invoice
    {
        return Invoice::query()
            ->whereHas('leasePeriod', fn ($q) => $q->where('office_lease_id', $lease->id)->where('sequence', $sequence))
            ->first();
    }

    /** Records a payment of $fraction of what is left (all of it when null), in the invoice's own currency. */
    private function pay(?Invoice $invoice, InvoicePaymentMethod $method, ?string $fraction = null): ?InvoicePayment
    {
        if ($invoice === null) {
            return null;
        }

        $invoice->refresh();
        $decimals = $invoice->currency === 'SYP' ? 0 : 2;
        $amount = $fraction === null ? $invoice->remaining() : bcmul($invoice->remaining(), $fraction, $decimals);

        return $this->payments->record($invoice, [
            'amount' => $amount,
            'currency' => $invoice->currency,
            'method' => $method->value,
            'receipt_number' => 'DEMO-'.strtoupper($method->value).'-'.$invoice->id,
            'paid_at' => now()->toDateString(),
        ], $this->reviewer);
    }

    /** Makes an invoice $daysOverdue days past due: history the present cannot produce. */
    private function age(?Invoice $invoice, int $daysOverdue): void
    {
        if ($invoice === null) {
            return;
        }

        $due = CarbonImmutable::today()->subDays($daysOverdue);

        Invoice::query()->whereKey($invoice->id)->update([
            'issued_at' => $due->subDays(15)->toDateString(),
            'due_at' => $due->toDateString(),
        ]);
    }

    /** Stores why a live subscription's automatic renewal stopped, as RenewalRunner would. */
    private function stopRenewal(ProviderRequest $assignment, RenewalStopReason $reason): void
    {
        Membership::query()->whereKey($assignment->result_id)->update(['auto_renew' => true, 'renewal_stop_reason' => $reason->value]);
    }

    private function provider(string $key): Provider
    {
        return Provider::query()->where('name', self::PROVIDERS[$key]['name'])->firstOrFail();
    }

    private function company(string $key): Company
    {
        return Company::query()->where('legal_name', self::COMPANIES[$key][0])->firstOrFail();
    }

    private function userByEmail(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function user(string $email, string $name, string $phone, string $role): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = new User([
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'password' => Hash::make('password'),
                'status' => 'active',
                'preferred_currency' => 'USD',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        return $user;
    }
}
