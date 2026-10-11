# Provider and admin reports (phase 5)

Spec: `docs/superpowers/specs/2026-10-11-provider-reports-design.md`. Code: `App\Domain\Reports`.

## Reports

| Route | Who | Returns |
|---|---|---|
| `GET provider/reports/companies` | the provider's accounts | one row per client company: `members_count`, live `membership` (plan, seats, period end, `auto_renew`, stop reason + label) or null, `active_leases_count`, `outstanding` per currency (`remaining`, `overdue`) |
| `GET provider/reports/invoices?from&to` | the provider's accounts | `totals` per currency (`billed`, `paid`, `remaining`, `overdue`, plus `by_payer` provider / company), `aging`, `by_company` |
| `GET admin/reports/revenue?from&to&provider_id` | `reports.view` | `totals`, `by_source` (`wallet_top_up`, `plan_assignment`, `membership_renewal`, `office_lease`), `aging`, `top_overdue` (10 payers, provider or company) |
| `GET admin/reports/providers` | `reports.view` | every provider: `active_companies`, `seats`, `branch_share` (`seats`, pool `capacity`, `share` = seats / capacity as a 2-decimal string, null when the branch has no capacity), `active_leases`, `outstanding` |

## Rules

- Every amount is per currency; currencies are never summed together. Money is a SQL `SUM` formatted once, never PHP arithmetic.
- `InvoiceAggregates` is the one place invoice money is summed for reports.
- Cancelled invoices are excluded from every figure.
- Overdue = unpaid or partially paid with `due_at` before today, counted on `amount - paid_amount`.
- Aging buckets by days past due: `0_30` (1-30), `31_60`, `60_plus` (61+), on the remainder.
- The optional period filters `invoices.issued_at`, inclusive.
- A provider only ever sees its own invoices (`invoices.provider_id`); after a company transfer the old invoices stay with the provider that was billed.
- Pool capacity comes from `CoworkingPoolService` only (`SeatPoolIsCentralizedTest`).

## Permission and deploy

Admin reports are gated by `reports.view` (registered manually in `PermissionSyncService`), not by `role:admin|operations`. Run `php artisan permissions:sync` on deploy; `admin` gets it automatically, any other role must be granted it.

## Deferred

A usage report (seat occupancy, attendance hours) is a later phase.
