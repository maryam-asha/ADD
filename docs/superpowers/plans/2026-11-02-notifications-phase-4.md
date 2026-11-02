# Notifications Phase 4 (Staff and Providers) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** The dashboard hears of what waits for staff, by permission; a provider's portal hears of what concerns its companies.

**Spec:** `docs/superpowers/specs/2026-11-02-notifications-design.md` (staff and provider catalog). Staff notices go through `sendToHolders(permission)`. Provider notices go to the provider's active accounts, in the `provider` audience.

## Staff notices

| Key | Permission | Sent from |
|---|---|---|
| `service_request.submitted` | `service-requests.update` | `ServiceRequestService::submit` |
| `provider_request.submitted` | `provider-requests.approve` | `ProviderRequestService::submit` |
| `private_office.requested` | `private-office-requests.view` | `ProviderOfficeRequestController::store` |
| `event.registration_pending` | `events.manage_attendees` | `EventRegistrationService::register` when the registration lands pending |
| `payment.needs_review` | `payments.reverse` | `SyncPaymentStatus::quarantine` |
| `renewal.stopped` | `renewals.manage` | `RenewalRunner::stop` when the reason changes |
| `exchange_rate.suggested` | `exchange-rates.create` | `ExchangeRateSuggestionIngestor` on a new suggestion |
| `invoice.overdue_digest` | `invoices.view` | ReminderRunner: once a day, from 09:00 local, when any invoice is overdue |
| `reception.forgotten_session_alert` | `reception.view` | `CloseOverdueReceptionSessions` |

## Provider notices

- `invoice.payment_recorded`: `InvoicePaymentService::record`, to the payer. The company's admins get it too when the company pays.
- `provider.employee_joined`: `CompanyInvitationAcceptor`, for a provider's company.
- `renewal.done`: `MembershipRenewalService::renew`, `LeaseRenewalService::extend`.
- `renewal.stopped`: with the staff one.
- `lease.ending_soon`: also to a provider-managed company's provider.

## Rulings

- `private_office.requested` names the provider and the prospect. Requests have no seat count, so the text drops `:seats`.
- `payment.needs_review` names the payment by its reference and its value in points. `charged_amount` is in the gateway's own units.
- The staff session alert reuses the old unused key `reception.forgotten_session_alert`, rewritten. A member and a staff notice cannot share one template.
- A payment recorded on a company-paid invoice also tells the company's admins: the same notice, the same payer rule as `invoice.issued`.

## Tasks

1. Staff request notices: service, provider, office, event registration.
2. Staff money/ops notices: needs review, renewal stopped, exchange rate, overdue digest, session alert.
3. Provider notices.
4. Pint, the full suite once, a self-review, then a commit of only these files.
