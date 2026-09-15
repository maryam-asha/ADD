# Event approval and per-event registration forms: debit-on-approve, implicit identity, and one shared form engine

**Status:** resolved 2026-09-15. **Owner:** Maryam Asha.
**Type:** design doc for a new capability, plus a bug-fix note on an existing one.

## What this adds

An event can require review before it admits a member (`events.requires_approval`), and can ask its own questions at registration (`event_registration_fields` → `event_registration_field_values`). Registrations carry a decision (`event_attendees.status`), and operations approve or reject them through `App\Services\Experience\EventApprovalService`.

The form engine is not new code. Services already had one; the reusable half of it moved to `App\Support\Forms` and now serves both domains.

## Decision

- **Points are debited on approval, never on submission.** A pending registration costs the member nothing, so a rejection has nothing to refund and there is deliberately **no refund path for events** — the thing most likely to be got wrong simply does not exist. `EventRegistrationService` charges only when it admits the registration outright; `EventApprovalService::approve()` charges at the moment of the decision. If the member has spent their points while waiting, `debitGeneral()` throws, the `EventActionException` propagates out of the transaction closure, and the whole approval rolls back: the row stays `pending` and the wallet is untouched. That behaviour comes from the transaction boundary, not from manual compensation. Tested by `EventApprovalTest::test_approval_fails_and_changes_nothing_when_the_balance_has_since_dropped`.

- **`pending` holds a seat; `rejected` does not.** Same shape as `BookingCreationService`'s `whereIn('status', [Confirmed, Pending])` overlap rule: the pending row *is* the hold, and a rejection frees the seat with no separate release step. Because a pending registration is free, the alternative — counting only approved rows — would let a paid event be flooded past capacity by people who never pay. Every capacity read goes through `Event::seatHoldingAttendees()` or `EventRegistrationStatus::seatHoldingValues()`, covering the four `withCount` sites, both raw-SQL status subqueries in `applyStatusFilter`, and the registration capacity check. Tested by `EventSeatCountingTest`.

- **Capacity is re-checked at approval, not trusted from submission.** Other pendings may have been approved in between. `assertSeatAvailable()` counts **approved rows only** and excludes the row being decided — the pending row is itself seat-holding, so counting seat-holders would always be one over.

- **`name`, `phone` and `email` are never form fields.** They already exist on the member and are copied from the token onto the attendee row; a form field for them would give two sources for one fact and let a client overwrite the identity the server just established. `App\Support\Forms\ReservedFieldKeys` enforces this in three places: the admin field Form Requests reject the key with a 422, `RegisterForEventRequest` rejects a reserved key in the submitted payload rather than dropping it silently, and an `EventRegistrationField::saving` guard throws for seeders and tinker sessions that have no request layer in front of them. The form endpoint returns identity in a separate read-only `identity` block.

- **Separate tables per domain, one logic layer.** `event_registration_fields` mirrors `service_type_fields` column for column rather than both becoming one polymorphic `form_fields` table. A polymorphic table would have required migrating live Services data for no behavioural gain; matching the column shape gets the shared engine anyway.

- **`App\Support\Forms` and `App\Support\Enums` sit outside `app/Domain/` on purpose.** `DomainLayerBoundaryTest` bans Experience and Ecosystem from importing each other or Core, and its regex scans `app/Domain/` only. `EventAttendee` lives in Ecosystem while `EventRegistrationField` lives in Experience, so their shared status enum and form types have to live outside that tree to be legal from both. This is the same reason `EventRegistrationService` — and now `EventApprovalService`, which needs `WalletService` — live under `App\Services\Experience`.

- **`EventRegistrationFieldValue` lives in Experience, not Ecosystem.** It belongs to an `EventAttendee` (Ecosystem) but references an `EventRegistrationField` (Experience). Only the `hasMany` direction is defined, from the allowlisted `EventAttendee`; there is no inverse `belongsTo`, because nothing needs one and the alternative — an inline FQCN that evades the guard's `use`-statement regex — would be a technicality rather than a design.

- **`event_attendees.status` defaults to `approved`.** Every row that existed was created under debit-on-registration: already paid, already holding a seat. Defaulting to `pending` would have retroactively un-seated every past attendee and thrown off every capacity count. The migration is a no-op for existing data.

- **`can_register` now accounts for the caller's own registration.** It previously returned `true` for a member who already held a seat, who then got a 422 on the attempt. A rejected registration is not seat-holding, so a rejected member reads as not registered and may apply again.

## The bug this fixes in passing

`ServiceRequestService::buildValidationRules()` was **dead code**. It was referenced only by its own definition and by a comment in `StoreServiceRequestRequest` claiming the controller re-validated through it — nothing did. Every service request submitted since that feature shipped was persisted without field validation: required fields unenforced, lengths unenforced, select values unconstrained, uploads accepted with no type or size check.

Wiring it up required resolving the `ServiceType` from the route slug inside the Form Request. Six further defects surfaced while moving the rule builder, each fixed once in `FieldRuleBuilder` for both domains:

| Defect | Effect before |
|---|---|
| Checkbox `in:` applied to the array, not its elements | Element values were never validated |
| Select emitted no `string` rule | An array could slip past `in:` |
| Optionless select/checkbox emitted a bare `in:` | Matched nothing, or nothing at all |
| No format guard on a field `key` | A dot addressed a nested path instead of the field |
| Rating bounds used unparsed from JSON | A string bound produced a malformed rule |
| Unmapped `accept` passed through as `mimes:` | e.g. `mimes:application/pdf` rejected every upload |

Separately, `ServiceType::fields()` was not filtered to active, so inactive fields were rendered to members and then silently dropped by `persistValues()`. `activeFields()` is now the single source for what a member is shown and what is persisted; `fields()` still returns everything for the admin editor.

**Shipping note:** turning on validation that has never run can reject payloads the mobile app currently sends successfully. Audit production for active `service_type_fields` with `is_required = true` before deploying, and confirm the client sends them.

## Schema note: the 64-character trap

`event_registration_field_values` is 31 characters, so Laravel's generated FK name `event_registration_field_values_event_registration_field_id_foreign` is **67** — past MySQL's 64-character identifier limit. The test suite runs on in-memory SQLite (`phpunit.xml`), which accepts long names silently, while production is MySQL: an auto-named constraint would have passed CI and failed on deploy. Every index and FK on that table is named explicitly, and the DDL was verified by creating both tables against the real MySQL database before committing.

`service_request_values` hit the same wall once already (`2026_09_13_160005_add_unique_to_service_request_values.php`, named `srv_req_values_unique`). Name composite constraints on long table names explicitly, always.

One deliberate divergence from that table: `event_registration_field_values.event_registration_field_id` **cascades** on delete where `service_request_values` restricts. A service field outlives its requests; an event's fields are owned by the event, and restricting would make deleting an event fail.

## Known gaps, deliberately not addressed

- **A pending registration holds a seat indefinitely, for free.** There is no TTL and no bulk-reject, so abandoned pendings can silently block a paid event. An admin bulk-reject is the natural follow-up; a pending expiry needs a product decision about how long is too long.
- **An approval that fails on a dropped balance leaves the registration pending forever** unless staff retry or reject it. Auto-rejecting was considered and rejected: it makes a decision on the admin's behalf and would surprise a member who was about to top up.
- **Reserved keys are enforced on Events only.** The Services field API is unchanged, because its production configuration is unknown and may already contain a field keyed `name` — a hard 422 there could break a live form. Tighten it once that configuration is confirmed clean.
- **Events still run on `role:` middleware, not the `permission:` pilot.** Consistent with every other resource except Branches; converting them belongs with the rest of that rollout.
- **Notifications are `NotificationLog` rows, not real delivery** — inherited unchanged from `BookingApprovalService`, and `docs/decisions/booking-creation-approval-extension.md` covers why.
