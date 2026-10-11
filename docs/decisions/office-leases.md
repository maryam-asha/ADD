# Office leases (Phase 3)

**Status:** shipped 2026-10-11. **Owner:** Maryam Asha.
**Spec:** docs/superpowers/specs/2026-10-10-office-leases-design.md (Arabic). **Plan:** docs/superpowers/plans/2026-10-11-office-leases.md.

## What this adds

A company (direct or a provider's) leases one private office (`spaces.space_type = business`) per lease, for whole months, at a fixed money price per month in a currency staff choose. `App\Domain\Leasing` owns `office_leases` and `office_lease_periods`.

- **Every lease comes from a private-office request.** A new company's request is closed by `POST admin/companies`, which now takes the first lease's fields and derives the company's branch from the office; an existing company files another request (`private_office_requests.company_id`) closed by `POST admin/private-office-requests/{id}/lease` (`office-leases.create`). Providers file requests for their companies with a `payer`. Both paths run `OfficeLeaseService::contract()` in one transaction, under the request's and the office's row locks.
- **Billing.** All periods are created with the lease (`LeaseScheduleBuilder`: 1, 3 or 6 months, or the whole term; each period counted from the lease start, so a 31st start returns to the 31st). Period 1, and every period already started, is invoiced at contract; `leases:run-daily` (scheduled hourly; every step is idempotent) invoices each later period on its first day. A rent invoice has `points = null` and its fixed amount; `invoices.office_lease_period_id` (unique) links it and prevents a second invoice. Cancelling a rent invoice leaves the period and the lease as they are.
- **Payer.** A direct company pays itself. A provider's company pays as staff chose at contract, else as the request said (`billed_to`).
- **Door.** On the first day the company gets an activated grant on the office lock (`PasscodeIssuanceService::issueForLease()`, replacing the never-called `issueForTenancy()`), expiring at the end of the last access day; members with `door_access_enabled` open it through the existing QR unlock. No keypad code reaches the member app. An office without a lock leases fine and shows `access.warning = no_lock` to staff. Under maintenance the grant is revoked like any other and re-issued on the first run after.
- **States.** `active`, `ended` (the run, after `ends_on`), `terminated`. `terminated_on` is the first day without access; termination (`office-leases.terminate`, reason required) cancels periods starting on or after it and closes the door now or on that date.
- **Who sees what.** Staff: `GET admin/office-leases` (+ filters) and `/{id}` with periods, invoices and access state. Provider: its current companies' leases with prices and invoices, no staff fields (404 for others). Company admin: `GET member/companies/{company}/office-leases`, office and dates only.
- **Browse.** An office held by a lease today is not listed by `SpaceBrowseService`.
- **No booking on a lease day.** `AvailabilityService::isHeldByLease()` is the one rule (a lease's dates read in the district's timezone): single and batch booking creation re-check it under the space lock (`api.booking.office_leased`), and the booking preview, the slot grid (`SlotState::Leased`, shown to members as `booked`), the reception calendar (day `state = leased`) and walk-ins consult it. The reverse holds too: `contract()` refuses with 409 `api.lease.office_booked` while the office has a booking still to happen (`awaiting_payment`, `pending`, `confirmed`) in the lease window; final bookings do not block.
- **Permissions on the companies routes.** `POST admin/companies` contracts the first lease, so it needs `office-leases.create` on top of the role gate; a company's `office_leases` (on `show` and on the create response) are shown only to who holds `office-leases.view`.
- **Provider transfer.** `PATCH admin/companies/{company}/provider` moves the company's leases not yet `ended` to the new provider in the same transaction (`billed_to = company` when it moves to ADD directly), so the next period invoices follow; issued invoices stay where they were billed. A provider's lease view shows only the period invoices billed to that provider.

## Rulings made while implementing

| Spec says | Done | Why |
|---|---|---|
| Company admin sees the keypad code | No passcode on the member surface | Locked guard `LockCredentialsNeverReachMemberTest` (qr-lock-unlock.md §4). |
| Termination date semantics unstated | `terminated_on` = first day without access | One rule makes "terminate today closes the door now" and "a period starting before the date still bills" both hold. |
| First invoice at contract | Period 1 plus every started period | A back-dated lease's past periods are already due. |
| (silent) Which provider sees a lease | The company's current provider | A transferred company takes its leases along, as it takes its invoices' company. |
| `leases:run-daily` at 06:00 Damascus | Hourly | A failed lock call, or a door that becomes due after the morning run, would otherwise wait a day. |

## Explicitly not done here

- Auto-renewal (Phase 4), reports (Phase 5).
- Deposits, early-termination fees, changing a running lease's price or term, moving a lease to another office — terminate and contract again.
- Suspending the door for unpaid rent (overdue invoices are shown, staff act).
- A failed TTLock delete is logged and the grant marked revoked, as for every other revocation; the vendor passcode then lapses at the end date it was created with.

## Deploy

`php artisan migrate`, then `php artisan permissions:sync` (creates `office-leases.view|create|terminate`; admin gets them — operations needs them granted to create companies or see their leases). The scheduler must run (`leases:run-daily`, hourly). The migration makes `invoices.points` nullable.

- `POST admin/companies` now requires the lease fields and no longer accepts `branch_id` — ship AddDashboard's company form in lockstep.
- Rolling back the migration cannot restore `invoices.points NOT NULL` once rent invoices exist — take a backup before deploying; it is the rollback source.
- The reception calendar's day `state` gains the value `leased`, and the slot engine the state `leased` (members still see `booked`); AddDashboard should render a leased day as not bookable.
