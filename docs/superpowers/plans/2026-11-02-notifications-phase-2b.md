# Notifications Phase 2b Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** The immediate member notices the phases left unassigned: plans, a company's wallet allocation, invoices, leases, coworking expiries.

**Spec:** `docs/superpowers/specs/2026-11-02-notifications-design.md` (catalog). Builds on phase 2 (`d4f8fad8`) and follows its constraints: approved Arabic wording, translatable names passed whole, local dates, no notice for one's own action, and a `_no_charge` variant instead of "0 points".

**Recipients of a company's notices:** its active members holding `is_admin`. A provider-paid invoice goes to the provider's accounts, in the `provider` audience.

## Tasks

1. **Plans** — new `App\Domain\Membership\Services\MembershipNotifier`:
   - `assigned`: admin `MembershipController::store` and `PlanAssignmentHandler`.
   - `started`: `RenewalRunner` when `grantDueBalances()` grants.
   - `renewed`: `MembershipRenewalService::renew`.
   - `deskAssigned`: admin `MembershipController::assignDesks`.
   - Owner user → them; owner company → its admins.
   - Tests: `MembershipNoticesTest`.
2. **Money**:
   - `wallet.company_allocated`: the allocation's members, except the admin who made it.
   - `invoice.issued`: from `InvoiceIssuer::issue` to the payer.
   - Tests: `MoneyNoticesTest`.
3. **Leases and coworking**:
   - `lease.contracted`: `OfficeLeaseService::contract`, to the admins.
   - `lease.ended`: `terminate()` and `LeaseDailyRunner` ending a lease, to the admins and the members with door access.
   - `coworking.arrival_expired`: `ExpireStaleArrivalRequests`.
   - `coworking.closed_by_system` / `_no_charge`: `CloseOverdueReceptionSessions`.
   - Tests: `LeaseAndSessionNoticesTest`.
4. **Finish** — Pint, the full suite once, a self-review, then a commit of only these files.

## Rulings

- `account.password_changed` moves to phase 5 (WhatsApp). A password change revokes every other session and its devices first, so a push could only reach the device that made the change.
- One `lease.ended` key serves both a natural end and an early termination: the text is the same.
- `membership.subscribed` (no sender, replaced by `assigned`) is removed.
- Invoices billed to a provider are announced now rather than in phase 4: same trigger, same code.
