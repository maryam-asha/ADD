# Auto-renewal (Phase 4)

**Status:** shipped 2026-10-12. **Owner:** Maryam Asha.
**Spec:** docs/superpowers/specs/2026-10-11-auto-renewal-design.md (Arabic). **Plan:** docs/superpowers/plans/2026-10-11-auto-renewal.md.

## What this adds

`App\Domain\Renewals`. A provider company's plan subscription (one that came from a plan-assignment request, or a renewal of one) and any office lease can renew automatically, chosen at creation (`auto_renew` on the plan-assignment payload and the lease body) and changed later by the provider (its companies) or staff (`renewals.manage`).

- **When.** `renewals:run` (hourly) acts within `renewals.advance_days` (setting, default 7) of the end and retries a stopped one every run until it ends.
- **Subscription.** `MembershipRenewalService::renew()` creates the next `Membership` through `MembershipActivationService::activate(startsAt:, attributes:)` — starting at the current end (or now, if it already ended), same plan, seats and branch, `renewed_from_id` (unique) — and `InvoiceIssuer::issueForMembershipRenewal()` bills the plan's price today × seats in the previous invoice's currency (`invoices.membership_id`) to the payer chosen at assignment (the company itself once it is direct — a manual renewal, since a transfer turns auto-renew off), for the company's current provider. The renewal keeps the subscription's `auto_renew` and, on a Dedicated Desk plan, its desks. A free plan renews without an invoice.
- **Lease.** `LeaseRenewalService::extend()` appends one more term of periods to the same lease (`ends_on` moves, `renewals_count`++) and re-issues the door grant to the new end.
- **Stops.** A failed check stores `renewal_stop_reason` (`overdue_invoice`, `no_seats`, `plan_inactive`, `company_inactive`, `office_unavailable`, `office_taken`, `no_exchange_rate`) on the subject; success or turning auto-renew off clears it. `GET admin/renewals/stopped` lists them; `POST admin/memberships/{id}/renew` / `admin/office-leases/{id}/renew` renew by hand past an overdue invoice (nothing else). "Overdue on the payer" = any open overdue invoice billed to the provider (all its companies) or to the company.
- **Live means started.** `Membership::scopeLive()`/`isLive()` require `current_period_start <= now`; a renewal made in advance is not the company's subscription until it starts. Plan balances are granted when a membership starts (`balances_granted_at`), by the run for renewals.
- **Who sees what.** Staff: lease detail carries `auto_renew`, the stop reason and `renewals_count`; the stopped list. Provider: the company's subscription carries `auto_renew`, its stop reason and `next_membership`; leases the same fields as staff. Members: nothing new beyond the `upcoming` status below.

## Rulings made while implementing

| Spec says | Done | Why |
|---|---|---|
| A renewal invoice for a zero-price plan | No invoice; the subscription still renews | Phase 2 already refuses zero-point invoices. |
| (silent) Where membership renewal invoices point | `invoices.membership_id` on the renewal; the first link's invoice is found through the plan-assignment request (`result_id`) | One FK per source. |
| (silent) Auto-renew on a lease that is not active | Refused 409 `api.renewal.lease_not_active` | Only a running lease has a next cycle. |
| (silent) Provider toggle with no renewable subscription | 404 | The provider learns nothing about a subscription it did not assign. |
| (silent) A renewal made ahead in the member app | Status `upcoming` (filter `?status=upcoming`), not `expired` | "Live means started" would otherwise label it expired. |
| (silent) Payer once the company became direct | The company itself, on a manual renewal (a transfer turns auto-renew off, below) | No provider is left to bill. |
| (silent) Renewal currency | The previous link's invoice currency; after a free-plan link it falls back to the base currency | Accepted edge. |
| (silent) Two renewals racing (double click, run and staff at once) | One extension per read: `extend()` refuses 409 `already_renewed` when the locked lease's `ends_on` moved since the caller read it; the run (`automatic: true`) also re-checks under the lock that `auto_renew` is still on (and, for a lease, that the end is still inside the window), else it skips | A stale read must not add a second term or renew something just turned off. |
| (silent) Renewing by hand a stopped subscription after the company took a new one | Refused 409 `api.renewal.superseded` when the company holds another active subscription still running and ending after this one; the stopped list drops an ended stopped subscription once its company holds a live or upcoming one | Two overlapping subscriptions would double the seats and the bill. |
| (silent) Turning auto-renew off after the renewal was made in advance | The toggle also reaches the pending renewal (and clears its stop reason); a renewal copies the subscription's `auto_renew` | Otherwise the chain continues one more cycle against the user's choice. |
| (silent) Dedicated Desk plans | The renewal carries the subscription's desks; when another subscription holds them in the next period it renews without desks and staff get `membership.desk_needed`, as for a member's purchase | A renewal must not silently drop the tenant's desk. |
| (silent) A door grant shorter than the lease | `OfficeLeaseService::openDoor()` revokes a live grant expiring before the end of the lease's last access day and issues a fresh one | Self-heals an extension whose revoke failed, so the tenant is not locked out at the old end. |
| (silent) Provider transfer | `PATCH admin/companies/{company}/provider` turns `auto_renew` off (and clears the stop reason) on the company's live and upcoming subscriptions (`memberships_auto_renew_off` in the audit); leases keep theirs | The choice was the old payer's; the new provider, or the company now direct, opts in again. Leases move with their billing (Phase 3). |
| (silent) No exchange rate for the invoice currency at renewal | The run stops it with `no_exchange_rate` | Otherwise it would fail silently every hour. |
| (silent) Lease renewal term start | Each term chains from the day after `ends_on`, so a 1-month term starting on the 29th–31st drifts after February | Accepted edge; documented. |

## Explicitly not done here

- Renewing direct companies' or individual members' subscriptions (automatic point debits).
- Notifications before a renewal or when it stops.
- Changing plan or seats at renewal (a new plan-assignment request after the end).
- A guard against turning auto-renew on for an already-ended subscription: it is a no-op, since the run only looks at live ones.

## Deploy

`php artisan migrate` (backfills `memberships.balances_granted_at` from `created_at`, else `current_period_start`), `php artisan permissions:sync` (`renewals.manage`), `php artisan db:seed --class=SettingSeeder` (`renewals.advance_days`). The scheduler must run `renewals:run`.
