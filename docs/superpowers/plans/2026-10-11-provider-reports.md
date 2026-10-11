# Provider & Admin Reports (Phase 5) — Implementation Plan

> **For agentic workers:** one implementer executes all tasks in order (user asked for lean execution), then one whole-branch review. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Four JSON reports — provider: companies summary, invoice statement; admin: providers summary, revenue & aging — all amounts per currency.

**Architecture:** `App\Domain\Reports` with one shared `InvoiceAggregates` (per-currency SUMs, aging buckets, period filter on a given invoice query) and one service per report. Thin controllers; admin routes permission-gated `reports.view`; provider routes in `provider.php`.

**Tech Stack:** Laravel 12, PHP 8.3, bcmath, PHPUnit on SQLite.

**Spec:** `docs/superpowers/specs/2026-10-11-provider-reports-design.md` (Arabic).

## Global Constraints
- Bash: `cd "/c/Users/User/Desktop/Aleppo Digital District/ADDCore"`; if PATH php is 8.2 use the Laragon 8.3 binary. Tests `php artisan test <path>`; Pint only touched files.
- 17 pre-existing suite failures (AuditLoggingTest ×2, ExchangeRateSuggestionControllerTest dismiss, SettingControllerTest audit, BookingReceptionControllerTest check-in, SiteInquiryTest ×2, ResourceControllerTest status logged, MemberOperationsIsolationTest recovery, AdminNotificationControllerTest broadcast, MemberDeviceTokenControllerTest ×3, MemberNotificationControllerTest ×3, EnumColumnsHaveBackedEnumCastsTest). Add none.
- Never stage the user's unrelated edits (MemberAuthController, CurrencyResource, MemberLoginTest, CurrencyControllerTest, .claude/launch.json). Stage by path. Never read vendor/.
- Money: SUM in SQL, format with `number_format((float) $x, 2, '.', '')` only for output of a single DB aggregate (as `ProviderSummaryController` does) or bcmath — never add money in PHP floats. Never sum across currencies.
- Cancelled invoices excluded everywhere. Overdue = `Invoice::scopeOverdue()`. Aging days = today − `due_at` (date diff); buckets `0_30` (1–30), `31_60` (31–60), `60_plus` (61+), on `amount - paid_amount`.
- Period: optional `from`/`to` (`date_format:Y-m-d`, `to` ≥ `from`) on `issued_at` via `whereDate`.
- Copy in `lang/{en,ar}/api.php` only if a new message is needed (none expected). Commits end `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus
1. Two currencies never merged in any total.
2. A partially paid overdue invoice counts only its remaining in aging/overdue.
3. A cancelled invoice appears nowhere.
4. A provider never sees another provider's companies or invoices (and after a company transfer, the company's old invoices stay with `invoices.provider_id`).
5. Branch share with zero capacity → `share: null`, no division by zero.

---

### Task 1: `InvoiceAggregates` + provider invoice statement

**Files:** Create `app/Domain/Reports/Services/InvoiceAggregates.php`, `app/Domain/Reports/Services/ProviderInvoiceReport.php`, `app/Http/Requests/Reports/ReportPeriodRequest.php`, `app/Http/Controllers/Api/V1/Provider/ProviderReportController.php`, `tests/Feature/Reports/ProviderInvoiceReportTest.php`; modify `routes/api/v1/provider.php`.

**Interfaces (produced):**
- `InvoiceAggregates::totals(Builder $invoices): list<array{currency:string, billed:string, paid:string, remaining:string, overdue:string}>` — excludes cancelled; `billed = SUM(amount)`, `paid = SUM(paid_amount)`, `remaining = SUM(amount - paid_amount)` over open+paid (non-cancelled), `overdue` = SUM(amount - paid_amount) where open and `due_at < today`; ordered by currency.
- `InvoiceAggregates::aging(Builder $invoices): list<array{currency:string, '0_30':string, '31_60':string, '60_plus':string}>` — over overdue invoices; compute days in PHP per row is NOT allowed for money; use three conditional SUMs with date thresholds: `due_at >= today-30` → 0_30, `due_at between today-60 and today-31` → 31_60, `due_at < today-60` → 60_plus (all also `due_at < today`, open).
- `InvoiceAggregates::period(Builder $q, ?string $from, ?string $to): Builder`.
- `ProviderInvoiceReport::for(Provider $provider, ?string $from, ?string $to): array{totals, aging, by_company}` — `totals` rows add `by_payer` => ['provider' => row, 'company' => row] per currency (same fields); `by_company` => list of `['company' => ['id','legal_name'], 'totals' => list per currency]` for companies having invoices in scope.
- Route `GET provider/reports/invoices` → `ProviderReportController::invoices` (query `from`, `to`).

**Implementation sketch (InvoiceAggregates):**

```php
final class InvoiceAggregates
{
    public function period(Builder $q, ?string $from, ?string $to): Builder
    {
        return $q->when($from, fn ($q) => $q->whereDate('issued_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('issued_at', '<=', $to));
    }

    /** @return list<array<string, string>> */
    public function totals(Builder $invoices): array
    {
        $today = now()->toDateString();
        $open = [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value];

        return (clone $invoices)
            ->where('status', '!=', InvoiceStatus::Cancelled)
            ->selectRaw('currency')
            ->selectRaw('SUM(amount) AS billed, SUM(paid_amount) AS paid, SUM(amount - paid_amount) AS remaining')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) AND due_at < ? THEN amount - paid_amount ELSE 0 END) AS overdue', [...$open, $today])
            ->groupBy('currency')->orderBy('currency')->toBase()->get()
            ->map(fn ($r) => ['currency' => $r->currency] + $this->money($r, ['billed', 'paid', 'remaining', 'overdue']))
            ->values()->all();
    }
    // aging(): same shape with three CASE sums on due_at thresholds (today-30, today-60), restricted to open & due_at < today.
    // money(): number_format((float) $row->$k, 2, '.', '') per key — each value is one DB SUM, not PHP arithmetic.
}
```

Note: `due_at` is stored as a date (or datetime on SQLite); compare with `whereDate`-safe raw: use `DATE(due_at) < ?` in raw SQL so SQLite and MySQL agree.

- [ ] Step 1: Test first — `ProviderInvoiceReportTest` (extends `Tests\Feature\Invoicing\InvoicingTestCase`): provider A with company C1, C2; invoices via `Invoice::factory()`: USD 100 unpaid due in future (C1, billed_to company), USD 200 partially paid 50 due 10 days ago (C1, provider), USD 300 unpaid due 45 days ago (C2, provider), USD 400 unpaid due 90 days ago (C2, company), SYP 500000 paid (C2), USD 999 cancelled (C1); provider B's invoice USD 777. Assert `totals` USD: billed 1000.00, paid 50.00, remaining 950.00, overdue 850.00; SYP billed 500000.00, remaining 0.00; `by_payer.provider` USD remaining 450.00; `aging` USD 0_30 150.00, 31_60 300.00, 60_plus 400.00; `by_company` has C1 and C2 with correct USD rows; nothing of B's. `?from=` excluding the 90-days-old one (set its `issued_at` 100 days ago and filter from 95 days ago) drops it. Run → fail.
- [ ] Step 2: implement; Step 3: green; Step 4: Pint, commit `feat(reports): invoice aggregates and the provider's invoice statement`.

### Task 2: Provider companies report

**Files:** Create `app/Domain/Reports/Services/ProviderCompaniesReport.php`, `tests/Feature/Reports/ProviderCompaniesReportTest.php`; modify `ProviderReportController` (+`companies`), `routes/api/v1/provider.php` (`GET provider/reports/companies`).

`ProviderCompaniesReport::for(Provider $provider): list<array>` — companies where `provider_id = provider` ordered by legal_name; each: `id, legal_name, status, members_count` (withCount members), `membership` from `MembershipResolver::activeForOwner(Company, id)` → `['plan_name', 'seats', 'current_period_end' (ISO), 'auto_renew', 'renewal_stop_reason', 'renewal_stop_reason_label']` or null, `active_leases_count` (`office_leases` status active), `outstanding` = `InvoiceAggregates::totals(Invoice::where('provider_id', p)->where('company_id', c))` mapped to `currency, remaining, overdue`.

- [ ] Test: two companies, one with a live membership (auto_renew + stop reason) and one active lease (OfficeLease factory needs CurrencySeeder), invoices on both; another provider's company absent; counts and outstanding right. Implement, green, Pint, commit `feat(reports): the provider's companies summary`.

### Task 3: Admin revenue & aging

**Files:** Create `app/Domain/Reports/Services/RevenueReport.php`, `app/Http/Requests/Reports/RevenueReportRequest.php` (period + `provider_id` nullable integer), `app/Http/Controllers/Api/V1/Admin/ReportController.php` (`revenue`), `tests/Feature/Reports/RevenueReportTest.php`; modify `routes/api/v1/admin.php`, `app/Services/Permissions/PermissionSyncService.php` (`ReportController::class => ['reports' => ['view']]`).

`RevenueReport::for(?string $from, ?string $to, ?int $providerId): array{totals, by_source, aging, top_overdue}`:
- `totals`: `InvoiceAggregates::totals()` without `overdue`? keep all four fields (spec lists billed/paid/remaining; extra `overdue` is fine).
- `by_source`: per currency and source — source expression: `CASE WHEN office_lease_period_id IS NOT NULL THEN 'office_lease' WHEN membership_id IS NOT NULL THEN 'membership_renewal' WHEN provider_requests.type = 'wallet_top_up' THEN 'wallet_top_up' ELSE 'plan_assignment' END` via LEFT JOIN provider_requests; rows `currency, source, billed, paid, remaining`. Qualify `invoices.` columns after the join.
- `aging`: `InvoiceAggregates::aging()`.
- `top_overdue`: overdue invoices grouped by payer key (`billed_to='provider'` → provider_id, else company_id) and currency, `SUM(amount - paid_amount)` desc, limit 10; each row `payer: {type: provider|company, id, name}`, `currency`, `overdue`.
- Route: `GET admin/reports/revenue` → `ReportController::revenue`, `->middleware('permission:reports.view')`, inside the `withoutMiddleware('role:admin|operations')` block.

- [ ] Test: direct-company invoices + provider invoices from each source (create `ProviderRequest` factory rows of both types and link `provider_request_id`; `OfficeLeasePeriod` factory for a lease invoice; a renewal membership for `membership_id`); assert per-source sums, `provider_id` filter, period filter, top_overdue ordering; 403 for operations without the permission. Implement, green, Pint, commit `feat(reports): admin revenue and debt aging`.

### Task 4: Admin providers summary

**Files:** Create `app/Domain/Reports/Services/ProvidersReport.php`, `tests/Feature/Reports/ProvidersReportTest.php`; modify `ReportController` (+`providers`), `routes/api/v1/admin.php` (`GET admin/reports/providers`, `permission:reports.view`).

`ProvidersReport::all(): list<array>` — every provider: `id, name, status, active_companies` (companies status active), `seats` (SUM seats of live memberships of its companies — `Membership::live()->where('owner_type', Company)->whereIn('owner_id', companyIds)`), `branch_share` (group those memberships by `branch_id`: `seats`; `capacity` = open co-working seats of that branch now — obtain from `CoworkingPoolService` (do NOT build a PoolTimeline yourself; `SeatPoolIsCentralizedTest`): e.g. `$pool->timeline($branch, now(), now()->addMinute())` and its capacity accessor, or `placesAt($branch, now())` summing `seats`; read the service to pick the existing public method; `share` = bcdiv(seats, capacity, 4) formatted as a 2-decimal ratio string, or null when capacity is 0), `active_leases` (office_leases active of its companies), `outstanding` (per currency remaining/overdue of `invoices.provider_id`).

- [ ] Test: two providers; provider A's two companies with live memberships 3 and 2 seats in a branch with 10 co-working seats → seats 5, share "0.50"; a zero-capacity branch → share null; outstanding per currency; 403 without permission. Implement, green, Pint, commit `feat(reports): admin providers summary`.

### Task 5: Docs, Postman, focused suite

- `docs/decisions/provider-reports.md` (what each report returns, rules: per currency, cancelled excluded, aging buckets, period on issued_at; permission `reports.view`; usage report deferred; deploy: `permissions:sync`).
- CLAUDE.md: one paragraph after Auto-renewal (`App\Domain\Reports`, `InvoiceAggregates` is the one place invoice money is summed for reports; `reports.view`); add `reports` to the admin.php permission-gated list.
- Postman (Python load/dump, indent 4, ensure_ascii): Admin → Reports gains `Providers Report`, `Revenue Report` (query from, to, provider_id disabled); Provider gains `Report - Companies`, `Report - Invoices` (from, to disabled).
- Spec status → `معتمد ومنفّذ`.
- Run `php artisan test tests/Feature/Reports tests/Feature/Providers tests/Feature/Invoicing tests/Guards` (no full suite needed; guards only pre-existing EventAttendee failure). Pint --test on touched PHP files. Commit `docs(reports): decision record, CLAUDE.md and Postman`.
