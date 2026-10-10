# Service providers: an intermediary that manages client companies, with ADD as arbiter

**Status:** resolved 2026-10-08. **Owner:** Maryam Asha.
**Type:** design doc for a new capability. Design:
`docs/superpowers/specs/2026-10-08-service-providers-design.md` (Arabic,
user-approved); plan: `docs/superpowers/plans/2026-10-08-service-providers.md`.
This file describes the code as shipped, including the rulings made while
implementing it. Phase 1 of 5 (core and approvals); Phase 2 (invoicing) is
described in **Invoicing (Phase 2)** below.

## What this adds

A **provider** (`App\Domain\Providers\Models\Provider`) is an intermediary
with no seats and no employees of its own. It requests offices for client
companies, assigns them a plan (an existing one, or one it designs), adds their
employees and names their admin. ADD is the arbiter of everything that grants
access or seats or moves money.

| Decision | Outcome |
|---|---|
| Entity | Its own `providers` table; not a company with a type, not only a role. |
| Accounts | Fortify accounts (email + password + 2FA) on the dashboard, holding the protected `provider` role, linked by `provider_user` (one provider per account, all of a provider's accounts equal). Admin creates them. |
| Companies | `companies.provider_id`; `null` = ADD deals with the company directly. |
| Approval needed | New company, employee, company admin, plan design, plan assignment (even of an existing plan), wallet top-up. |
| No approval | Doors, balance distribution, removing an employee. Principle: what grants goes through ADD; what withdraws or manages within the granted is direct. |
| Scope | Approvals apply to provider companies only; direct companies behave as before. |
| Who decides | `provider-requests.approve` / `.reject` are permissions, assignable to any role, not admin-only. |
| New company | A private-office request carrying `provider_id`, on the existing `requested -> quoted -> contracted` pipeline. |
| Deactivation | Cuts off the provider's accounts and cancels its pending requests; companies carry on; admin moves them to another provider or to ADD direct. |
| Payment | Phase 1 activated with no charge; Phase 2 issues an invoice on approval (payer = provider or company), see **Invoicing (Phase 2)**. |

## Decisions this revises

1. **"Company accounts are created by operations only"** becomes "by operations,
   or on a provider's office request that operations takes through the same
   private-office pipeline". `CompanyController::store` carries `provider_id`
   from the request to the company.
2. **D.8 (`docs/decisions/rbac-scoping.md`)**: `ProviderPolicy` is a second
   exception beside `CompanyPolicy`. Which companies a provider may act on is
   `companies.provider_id`, a relationship, not a role. `scope_type`/`scope_id`
   stay banned (`RbacStaysFlatTest` names both policies and still forbids a
   third).

## The model

Tables (migration `2026_10_08_110000_create_providers_tables`):

- `providers` (`name`, `contract_ref`, `status` = `ProviderStatus`).
- `provider_user` (`user_id` unique).
- `provider_requests`: the approval inbox. `type` (`ProviderRequestType`),
  `status` (`pending|approved|rejected|cancelled`), `company_id` (null for
  `plan_design`), `payload` json, `submitted_by`, `reviewed_by/at`,
  `rejection_reason`, `result_id`.
- `company_invitations`: an approved employee with no account yet; unique
  (`company_id`, `phone`), stamped `accepted_*` when the phone registers.
- Columns: `companies.provider_id`, `private_office_requests.provider_id` +
  `submitted_by`, `plans.provider_id` (designed plans are `is_public = false`).

Payloads: `company_member` `{phone, name, door_access_enabled}` ·
`company_admin` `{user_id}` · `plan_design` the `StorePlanRequest` fields ·
`plan_assignment` `{plan_id, branch_id, seats, payer}` ·
`wallet_top_up` `{points, payer}`. `payer` is stored, unused until Phase 2.

**`result_id` by type:** `plan_design` the new plan's id · `plan_assignment` the
membership's id · `wallet_top_up` the wallet transaction's id · `company_member`
the user's id when an account was linked, `null` when an invitation was parked ·
`company_admin` the user's id.

**Flow.** Each type has a Form Request that validates fully at submission and a
`ProviderRequestHandler` (`Domain/Providers/Requests/Handlers`, registered in
`ProviderRequestHandlers`). `ProviderRequestService::approve()` / `reject()`
lock the request row, require `pending` (a repeat is a 409), recheck that the
provider and company are active and still linked, then hand over to the
handler; a failed recheck is a 422 and the request stays `pending`. Inventory
oversell on a plan assignment is available to the reviewer only
(`override_capacity` on the approve body) and is logged. Providers cancel their own `pending`
requests. Review "notifications" are `NotificationLog` rows only (no push, no
inbox yet) — log-only until a dashboard inbox or push exists. An invited phone is linked inside the `register/verify` transaction.

**`provider.active`** (`EnsureActiveProvider`): the account's provider must
exist and be active, else 403 `api.provider.inactive`; it also puts the
provider on the request for the controllers.

**The 404 rule.** Every provider route with a `{company}` calls
`authorizeCompany()` (`ProviderPolicy`, gate `provider.manage-company`);
another provider's company and a direct company are both a 404, so existence is
not revealed. `ProviderRoutesStayIsolatedTest` pins this and that
`routes/api/v1/provider.php` only uses `Provider\*` controllers.

## Rulings made while implementing

Deliberate refinements of the spec:

| Spec says | Done | Why |
|---|---|---|
| `result_type` + `result_id` (morph) | `result_id` only; meaning follows `type` | A morph needs `Plan`/`Membership`/`WalletTransaction` in the morph map, changing how existing activity-log rows for them are written. |
| morph aliases `provider`, `provider_request` | none | Nothing stores them once `result_type` is gone. |
| permissions `provider_requests.*` | `provider-requests.*` | Module names are the kebab-case URI segment elsewhere (`error-logs.view`). |
| "`provider` never combines with another role" -> 422 | no new check; a test pins it | `UserController::store` assigns one role and `assignRole` uses `syncRoles([...])`, so a second role is already impossible through the API. |

Other rulings:

- Phone numbers on employee requests use the existing `App\Rules\PhoneNumber`
  (E.164), like every other phone field in the app; the plan's
  `SyrianPhoneNumber` / `09XXXXXXXX` does not exist.
- `ProviderController::store` reloads with `refresh()` so the response stays 201.
- A member-app grant of company admin on a provider's company answers 202 and
  files a `company_admin` request (deduplicated); a revoke stays direct.

## Invoicing (Phase 2)

Spec: `docs/superpowers/specs/2026-10-10-provider-invoicing-design.md`
(Arabic). Approvals now leave a recorded receivable, so the Phase 1 warning
against shipping without invoices no longer applies.

**What an approval issues.** `ProviderRequestService::approve()` calls
`InvoiceIssuer` inside the approval transaction, for the two types that carry a
charge: a `plan_assignment` (plan price x seats) and a `wallet_top_up` (the
points). `billed_to` is the request's `payer` (provider or company). The amount
is priced with `ReceivedMoneyConverter::pointsToAmount()` in the reviewer's
currency and frozen on the invoice together with the rate used. The due date is
the provider's `payment_terms_days`, else the setting `invoices.default_due_days`
(15). A currency without an exchange rate refuses the whole approval
(`api.invoice.no_exchange_rate`, 422): nothing is activated or credited
without its invoice.

**Approve body and admin view.** The approve body gains three optional fields:
`invoice_currency`, `invoice_amount` (overrides the points-priced amount) and
`invoice_due_at`. The admin request view returns an `invoice_preview` block
while the request is pending and an `invoice` block once it is approved.

**Payments.** Recorded from the dashboard only (`invoices.record_payment`),
outside the points ledger and the cash drawer: an invoice payment moves no
wallet and no drawer balance. A payment may be in any currency; it is converted
at the day's rate and truncated to the invoice currency's decimals, and a
shortfall under one minor unit settles the remainder. Partial payments are
allowed; overpayment is refused. A payment is voided, never deleted
(`invoices.void_payment`), which reopens the balance. An invoice is cancelled
(`invoices.cancel`) only when it has no active payment; cancelling does not
touch the plan or the credit that was granted.

**Provider view.** `GET provider/invoices` and `provider/invoices/{invoice}`,
read only, without staff fields. Another provider's invoice is a 404.

**Sources.** An invoice points at what produced it through separate nullable
foreign keys, today only `provider_request_id`. Office leases (Phase 3) add
their own column rather than a morph.

**Permissions.** `invoices.view`, `invoices.record_payment`,
`invoices.void_payment`, `invoices.cancel`; run `permissions:sync` on deploy.

### Rulings made while implementing

| Spec says | Done | Why |
|---|---|---|
| amount via `PointConversionService::pointsToCurrency()` | `ReceivedMoneyConverter::pointsToAmount($points, $currency)` | Same rate source, but bcmath end to end; `pointsToCurrency()` goes through floats. |
| payment conversion "rounded up" | rounded **down** (truncated) to the invoice currency's decimals; a shortfall under one minor unit settles the remainder exactly | Money received is credited conservatively everywhere else (points floor); rounding up would credit more than was paid. The one-unit rule keeps an exact equivalent payment from leaving `0.01` open. |
| (spec silent) | `ReceivedMoneyConverter` throws `PaymentPricingException` (a 503 plus a Paymera log); the issuer catches it and throws `InvoicingException('api.invoice.no_exchange_rate')` (422) | A missing invoice-currency rate is the reviewer's choice, not a gateway outage. |

Also:

- The one-minor-unit settle rule applies only when the payment currency differs
  from the invoice currency (it absorbs conversion truncation); a same-currency
  payment one unit short stays partially paid.
- A payment whose amount is not positive, or converts to zero, is refused
  (`api.invoice.amount_too_small`). Cancelling is refused while any active
  (non-voided) payment exists.

## Phase order

1 core and approvals (shipped) -> 2 invoicing (this section) -> 3 office
leases -> 4 auto-renewal -> 5 reports.
## Explicitly not done here

- **Office leases (Phase 3)**, **auto-renewal (Phase 4)**, **reports (Phase 5)**.
- No per-provider contractual discount (deferred until it recurs).

## Deploy

Run `php artisan migrate`, then `php artisan permissions:sync` (nobody, admin
included, reaches the provider admin routes before it). `php artisan db:seed
--class=RoleSeeder` creates the `provider` role.
