# Notifications Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every notice that is faked today (a `notification_logs` row nobody receives) reaches its reader, plus the immediate booking, door, company, payment and service-request notices of the approved catalog.

**Architecture:** One small notifier per domain builds the params (translatable names kept whole, local date/time) and calls `NotificationService`. Members get `sendToUser`; staff get `sendToHolders(permission)`; provider accounts get `sendToUser(…, Provider)`. A guard then forbids writing `notification_logs` anywhere but `NotificationService`.

**Tech Stack:** Laravel 12, PHPUnit on SQLite.

**Spec:** `docs/superpowers/specs/2026-11-02-notifications-design.md` (catalog + phase 2), built on phase 1 (`8eecbb33`).

## Global Constraints

- PHP: `PHP="/c/laragon/bin/php/php-8.3.33-nts-Win32-vs16-x64/php.exe"`.
- Arabic wording is the approved catalog text, verbatim. English is written alongside it. Both files keep the same keys (guarded).
- Translatable names (`space.name`, `plan.name`, `event.title`, `service_type.name`) are passed whole. Lang lines used as params go through `NotificationText::translations()`.
- Dates and times are in the `app.timezone` setting (default `Asia/Damascus`): `Y-m-d` and `H:i`.
- Notices about someone's own action are not sent: a member cancelling their own booking, or a company admin changing their own flags.
- Where money may or may not come back, a `_no_refund` variant of the key is used, so the text never says "0 points were returned".
- Only notification files are staged. The working tree holds unrelated uncommitted work.

## Review Focus

- A booking with no refund must not read "returned 0 points". → Task 2 `test_rejection_without_a_refund_says_nothing_about_points`.
- A provider request with no company (plan design) must not print an empty company. → Task 1 `test_a_request_without_a_company_names_only_its_type`.
- A lease door re-issued on renewal must not tell members "your office is ready" again. → Task 3 `test_a_renewed_lease_does_not_announce_the_office_again`.
- A company admin changing their own flags is not told. → Task 4 `test_changing_your_own_flags_tells_nobody`.
- A cardholder who cancelled on the gateway page is not told "payment failed". → Task 5 `test_a_payment_the_member_cancelled_is_not_announced`.

---

### Task 1: Fake senders become real

**Files:**
- Create: `app/Domain/Booking/Services/BookingNotifier.php`, `tests/Feature/Notification/FakeSendersAreRealTest.php`
- Modify: `BookingApprovalService`, `PendingBookingNotice`, `BookingBatchCreationService::notifyOperations`, `CancelExpiredPrepayments`, `EventApprovalService`, `ExpirePendingEventRegistrations`, `ProviderRequestService::notify`, `NotificationText` (+`localDate`, `localTime`), both `lang/*/notifications.php`
- Update tests asserting the fake rows: `BookingApprovalServiceTest`, `BookingCreationServiceTest`, `BookingOutcomesTest`, `CancelExpiredPrepaymentsTest`, `PrepaymentHoldTest`, `ApproveProviderRequestTest`, so they read the inbox instead.

**Interfaces:**
- `NotificationText::localDate(CarbonInterface $at): string`, `NotificationText::localTime(CarbonInterface $at): string`.
- `BookingNotifier` methods: `approved(Booking)`, `rejected(Booking, int $refundedPoints)`, `lapsed(Booking, int)`, `cancelled(Booking, int)`, `paymentExpired(Booking)`, `noShow(Booking)`, `pendingApproval(Booking)` (staff, `reception.approve`), `affectedByMaintenance(Booking)`. Params are `space`, `date`, `time`, `points`, and `member` for staff.
- Keys added/rewritten: `booking.{approved, rejected, rejected_no_refund, lapsed, lapsed_no_refund, cancelled, cancelled_no_refund, payment_expired, no_show, pending_approval, affected_by_maintenance}`, `event.{registration_approved, registration_rejected, registration_expired}`, `provider.{request_approved, request_rejected, request_types.*, subject_with_company, subject}`.
- Provider requests: the provider's accounts get the `Provider` audience. A submitter outside them (a company admin) gets the `Member` audience. Params are `subject` and `reason`.

- [ ] Tests (red first): the booking approve/reject/lapse rows carry the space/date/time; `test_rejection_without_a_refund_says_nothing_about_points`; pending approval reaches the holders of `reception.approve` (staff audience), including from batch creation and a released prepayment hold; an expired booking hold → `booking.payment_expired`, an expired event hold → `event.registration_expired`; event approve/reject/rejectAll/expire-pending → member rows; provider approve/reject → provider-audience rows for its accounts; `test_a_request_without_a_company_names_only_its_type`.
- [ ] Implement, then run `tests/Feature/Notification tests/Feature/Booking tests/Feature/Finance tests/Feature/Providers tests/Feature/Experience` → only known failures.

### Task 2: Booking cancelled by reception, no-show

- Modify: `BookingCancellationService::cancel(): int` (returns the points refunded), `BookingReceptionController::cancel` (the notice comes from here, so a member's own cancel tells nobody), `CloseElapsedBookings` (select the ids, then update and notify).
- [ ] Tests: reception cancel → `booking.cancelled` / `_no_refund`; a member's own cancel → nothing; an elapsed confirmed booking → `booking.no_show`.

### Task 3: Doors and maintenance

- Create: `app/Domain/Access/Services/AccessNotifier.php` — `passcodeReady(Booking)`, `revoked(AccessGrant)` (booking grants only), `officeOpened(OfficeLease)` (company members with door access).
- Modify: `IssueAccessGrantsOnCancellationWindowClose` (after a successful issue), `PasscodeIssuanceService::revokeForSpace` (tells each booking grantee), `OfficeLeaseService` door opening (only when no live grant existed), `SpaceController::updateStatus` (into `maintenance` → `booking.affected_by_maintenance` for the space's upcoming open bookings).
- [ ] Tests: code issued → `access.passcode_ready` with `from`/`to` and no code in the text; space to maintenance → affected bookings told, revocation → `access.revoked`; first door open → members with door access told, others not; `test_a_renewed_lease_does_not_announce_the_office_again`.

### Task 4: Company membership

- Create: `app/Domain/Identity/Services/CompanyNotifier.php` — `joined(Company, User)`, `removed(Company, User)`, `doorAccessChanged(Company, User, bool, ?User $actor)`, `adminChanged(Company, User, bool, ?User $actor)`. Self-changes are skipped.
- Callers: Admin + Member `CompanyMemberController`, `ProviderCompanyController::destroyMember`, `CompanyMemberHandler`, `CompanyAdminHandler`, `CompanyInvitationAcceptor`.
- Keys: `company.{member_joined, member_removed, door_access_granted, door_access_revoked, admin_assigned, admin_removed}`. Door access becomes two keys instead of `:status`, so each language reads naturally.
- [ ] Tests: each caller's path produces its row; `test_changing_your_own_flags_tells_nobody`.

### Task 5: Payment failed / reversed; service request status

- Modify: `PaymentNotifier` (+`failed`, `reversed`), `SyncPaymentStatus::settleUnsuccessful` (only `Failed`) and `expire()`, `ReversePayment`, `ServiceRequestService::updateStatus` (only when the status changes; `status` param from `notifications.service_request.statuses.*`).
- [ ] Tests: failed card → `payment.failed`; `test_a_payment_the_member_cancelled_is_not_announced`; reversal → `payment.reversed` with its points; status change → `service_request.status_updated` with the service name, and a note-only edit → nothing.

### Task 6: Guard and finish

- Create: `tests/Guards/NotificationLogsAreWrittenOnlyBySendingTest.php` — no `NotificationLog::create` / `->create(` on the log outside `app/Services/Notification/NotificationService.php`.
- [ ] Pint, focused runs, the full suite once, a self-review, then commit only these files.
