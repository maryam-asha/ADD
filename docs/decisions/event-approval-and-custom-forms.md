# Event approval and per-event registration forms: debit-on-approve, implicit identity, and one shared form engine

**Status:** resolved 2026-09-15. **Owner:** Maryam Asha.
**Type:** design doc for a new capability, plus a bug-fix note on an existing one.

> **Partly superseded 2026-09-26 by [unified-forms.md](unified-forms.md).** The per-domain form tables and models named below (`event_registration_fields`, `EventRegistrationField`, `EventRegistrationFieldValue`, `service_type_fields`, `service_request_values`) were replaced by one polymorphic set of form tables in `App\Domain\Forms`. The event-approval, seat-holding, identity and timezone decisions are unaffected. Sections that no longer hold are marked inline.

## What this adds

An event can require review before it admits a member (`events.requires_approval`), and can ask its own questions at registration (`event_registration_fields` → `event_registration_field_values`). Registrations carry a decision (`event_attendees.status`), and operations approve or reject them through `App\Services\Experience\EventApprovalService`.

The form engine is not new code. Services already had one; the reusable half of it moved to `App\Support\Forms` and now serves both domains.

## Decision

- **Points are debited on approval, never on submission.** A pending registration costs the member nothing, so a rejection has nothing to refund and there is deliberately **no refund path for events** — the thing most likely to be got wrong simply does not exist. `EventRegistrationService` charges only when it admits the registration outright; `EventApprovalService::approve()` charges at the moment of the decision. If the member has spent their points while waiting, `debitGeneral()` throws, the `EventActionException` propagates out of the transaction closure, and the whole approval rolls back: the row stays `pending` and the wallet is untouched. That behaviour comes from the transaction boundary, not from manual compensation. Tested by `EventApprovalTest::test_approval_fails_and_changes_nothing_when_the_balance_has_since_dropped`.

- **`pending` holds a seat; `rejected` does not.** Same shape as `BookingCreationService`'s `whereIn('status', [Confirmed, Pending])` overlap rule: the pending row *is* the hold, and a rejection frees the seat with no separate release step. Because a pending registration is free, the alternative — counting only approved rows — would let a paid event be flooded past capacity by people who never pay. Every capacity read goes through `Event::seatHoldingAttendees()` or `EventRegistrationStatus::seatHoldingValues()`, covering the four `withCount` sites, both raw-SQL status subqueries in `applyStatusFilter`, and the registration capacity check. Tested by `EventSeatCountingTest`.

- **Capacity is re-checked at approval, not trusted from submission.** Other pendings may have been approved in between. `assertSeatAvailable()` counts **approved rows only** and excludes the row being decided — the pending row is itself seat-holding, so counting seat-holders would always be one over.

- **`name`, `phone` and `email` are never form fields.** They already exist on the member and are copied from the token onto the attendee row; a form field for them would give two sources for one fact and let a client overwrite the identity the server just established. `App\Support\Forms\ReservedFieldKeys` enforces this in three places: the admin field Form Requests reject the key with a 422, `RegisterForEventRequest` rejects a reserved key in the submitted payload rather than dropping it silently, and an `EventRegistrationField::saving` guard throws for seeders and tinker sessions that have no request layer in front of them. The form endpoint returns identity in a separate read-only `identity` block.

- ~~**Separate tables per domain, one logic layer.**~~ *Superseded by [unified-forms.md](unified-forms.md): one set of polymorphic form tables now serves every owner.* `event_registration_fields` mirrors `service_type_fields` column for column rather than both becoming one polymorphic `form_fields` table. A polymorphic table would have required migrating live Services data for no behavioural gain; matching the column shape gets the shared engine anyway.

- **`App\Support\Forms` and `App\Support\Enums` sit outside `app/Domain/` on purpose.** `DomainLayerBoundaryTest` bans Experience and Ecosystem from importing each other or Core, and its regex scans `app/Domain/` only. `EventAttendee` lives in Ecosystem while `EventRegistrationField` lives in Experience, so their shared status enum and form types have to live outside that tree to be legal from both. This is the same reason `EventRegistrationService` — and now `EventApprovalService`, which needs `WalletService` — live under `App\Services\Experience`.

- ~~**`EventRegistrationFieldValue` lives in Experience, not Ecosystem.**~~ *Superseded: answers are `App\Domain\Forms\Models\FormAnswer`, and `EventAttendee` reaches them through `HasFormSubmissions`.* It belongs to an `EventAttendee` (Ecosystem) but references an `EventRegistrationField` (Experience). Only the `hasMany` direction is defined, from the allowlisted `EventAttendee`; there is no inverse `belongsTo`, because nothing needs one and the alternative — an inline FQCN that evades the guard's `use`-statement regex — would be a technicality rather than a design.

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

**Shipping note, since resolved:** turning on validation that has never run could in principle reject payloads the app sends successfully today. The production configuration was inspected before shipping: four field rows in total, one of them required (`technical.issue_type`, a select with options), and zero rows in `service_requests`. There were no live submissions to break.

## Time is stored as an instant, displayed as a wall clock

Events stored whatever the admin typed, and rendered it back without conversion. That looked right only because both halves were wrong in the same direction: `config('app.timezone')` is `UTC` while the district runs on the `app.timezone` Setting, `Asia/Damascus` — three hours apart. The moment anything interpreted a stored value as a real instant, it moved.

The damage is a wrong **day**, not a wrong hour. An event entered as 23:30 on 1 October is 20:30 UTC; read back naively it becomes 02:30 on **2 October**, taking the weekday label, the upcoming/past ordering, the `open`/`closed` filters and `pending_expires_at` with it. An 01:30 event moves the other way, to the day before.

- **`App\Support\Time\LocalTime` is the only thing that knows what "local" means.** It reads the same global `app.timezone` Setting that `BusinessHoursService` and the Booking services already use, and deliberately not `branches.timezone` — a pre-existing column nothing reads (`docs/decisions/business-hours.md`). Events were the one area ignoring that convention.
- **Input is interpreted, not trusted.** `StoreEventRequest`/`UpdateEventRequest` convert `start_at`/`end_at` in `prepareForValidation()`, so a bare `2026-10-01 19:00` means 19:00 *here*. A value that states its own offset (`+03:00`, `Z`) is taken at its word, because that client has already been explicit and re-interpreting it would shift the event again.
- **Output is converted back.** Every `format()` in `EventBrowseService` now works from a converted instance, so the five parse sites fix every downstream label at once. `EventResource` gives the admin a true ISO instant — so a round-trip through the editor is lossless — plus `start_time`/`end_time`/`timezone` for the wall clock they actually typed.
- **Comparisons were already right and stay untouched.** "Is this event past", capacity and the pending deadline compare instants to instants; they never needed a timezone and would have been broken by adding one.

**The trap worth knowing:** Eloquent's `datetime` cast *relabels* rather than converts. Handing it a `19:00 +03:00` Carbon stores `19:00 UTC` and silently moves the event three hours. Anything writing an event time outside the Form Requests — `EventSeeder` did — has to convert through `LocalTime::toUtc()` first, not merely build the Carbon in the right zone.

The twenty existing rows were disposable test data and were dropped rather than back-filled, so no correction migration exists. Any real data written before this change would need one.

### The same pattern elsewhere

A scan of every datetime column, Form Request accepting a date, and `Carbon::parse` on request input found the bug was not confined to events.

**Bookings had it, and worse — it refused legitimate work.** `BookingController` parsed `start_at` with no timezone, and `BookingCreationService` then converted it to local to compare against business hours, so a 19:00 booking in a space open until 20:00 was read as 22:00 and rejected as outside business hours. The existing booking tests never caught it because every one of them sends an explicit `+03:00`, which `Carbon::parse` handles correctly; the gap only opened for a client sending a bare time. Session check-out (`BookingReceptionController`, `WalkInSessionController`) had the same shape, comparing a wall clock against closing time.

Interpretation now happens once at the edge through `App\Http\Requests\Concerns\InterpretsLocalTimes`, which the event requests also moved onto, so one rule lives in one place. The remaining parses state `'UTC'` explicitly rather than inheriting whatever `config('app.timezone')` happens to be.

Announcements were given the same treatment pre-emptively — not broken today, because nothing compares those times, but the same fragile shape.

Most datetime columns needed nothing: `expires_at`, `paid_at`, `checked_in_at`, `approved_at` and the rest are machine timestamps written from `now()`, already instants. The risk is only where a person types a wall clock that is later compared as one.

`tests/Guards/UserSuppliedTimesAreInterpretedLocallyTest` now fails if a controller parses a request time without a timezone, or a Form Request takes a typed date without interpreting it. Both assertions were checked by reintroducing the bug and confirming they fail — a guard that has only ever passed proves nothing. Fields that are genuinely not wall clocks (business hours, stored as `H:i` against a weekday; an exchange-rate filter bound; unread maintenance windows) are listed with reasons rather than silently skipped.

## Schema note: the 64-character trap

`event_registration_field_values` is 31 characters, so Laravel's generated FK name `event_registration_field_values_event_registration_field_id_foreign` is **67** — past MySQL's 64-character identifier limit. The test suite runs on in-memory SQLite (`phpunit.xml`), which accepts long names silently, while production is MySQL: an auto-named constraint would have passed CI and failed on deploy. Every index and FK on that table is named explicitly, and the DDL was verified by creating both tables against the real MySQL database before committing.

`service_request_values` hit the same wall once already (`2026_09_13_160005_add_unique_to_service_request_values.php`, named `srv_req_values_unique`). Name composite constraints on long table names explicitly, always.

*Superseded by [unified-forms.md](unified-forms.md): answers now keep a snapshot of their field and the field FK is null-on-delete for every owner, so neither restrict nor cascade applies.* One deliberate divergence from that table: `event_registration_field_values.event_registration_field_id` **cascades** on delete where `service_request_values` restricts. A service field outlives its requests; an event's fields are owned by the event, and restricting would make deleting an event fail.

## Closing the seat-holding gap

A pending registration is free and holds a seat, so abandoned applications can fill a paid event — and several of them, cheaply, are a way to fill one without paying at all. That is closer to an abuse surface than a missing feature, and it is addressed two ways rather than left open.

- **A deadline, counted back from the event's start.** `event_attendees.pending_expires_at` is set at registration to `start_at` minus `config('events.pending_expiry_hours_before_start')`, default 24. Measuring back from the event rather than forward from the submission means an application for a distant event stays alive as long as it is useful, while one for tomorrow resolves quickly — a fixed "7 days from submission" would kill legitimate early applications for no reason. An event already inside the window mints no deadline rather than one born expired. `events:expire-pending-registrations` runs hourly and closes out anything past its time, writing `approved_at` but deliberately **no `approved_by`**: nobody decided it, the deadline did.
- **A bulk reject.** `POST admin/events/{event}/attendees/reject-pending` turns down every still-undecided registration on one event under one reason. Deciding a queue one row at a time is the reason queues get abandoned in the first place, so the way out has to be one action. Rows are locked while being decided, so a concurrent single approval either lands first or finds its row no longer pending.

Together these also resolve what was previously a second gap: an approval that fails because the member's balance dropped no longer strands the registration forever — the deadline or a bulk reject will close it out.

Reserved identity keys are now enforced on **both** domains. The Services field API was left alone initially because its production configuration was unknown and might already have contained a field keyed `name`; inspection showed four fields, none reserved, so the same rule and the same key-format guard now apply there. The message moved from `api.event.reserved_field_key` to `api.validation.reserved_field_key`, since it is no longer specific to events.

## Known gaps, deliberately not addressed

- **Events still run on `role:` middleware, not the `permission:` pilot.** Consistent with every other resource except Branches; converting them belongs with the rest of that rollout, not here.
- **Notifications are `NotificationLog` rows, not real delivery** — inherited unchanged from `BookingApprovalService`, and `docs/decisions/booking-creation-approval-extension.md` covers why. A member whose registration expires or is bulk-rejected therefore learns of it by opening the app, not by being told.
- **The expiry sweep does not notify differently from a manual rejection.** Both write `event.registration_rejected`, so a member cannot tell from the notification alone whether a person decided or a deadline passed. The `rejection_reason` text does distinguish them.

## Per-field-type validation constraints

A field could declare its type but not its limits: a text question accepted
10,000 characters, a date question any date in any century. The `validation`
JSON column already existed on both tables and a few branches of
`FieldRuleBuilder` already read it, but nothing specified what it could hold,
so the admin API stored whatever it was handed.

`App\Support\Forms\FieldConstraints` is that specification now, and both
halves of the engine read it — the rule builder turning stored keys into
rules, `ValidationVocabularyRule` refusing to store a key the chosen type does
not accept. One `match()`, so they cannot drift.

| Type | Keys | Meaning |
|---|---|---|
| `text`, `textarea` | `min`, `max`, `regex` | characters, named pattern |
| `number` | `min`, `max` | value, fractions allowed |
| `date` | `min_date`, `max_date` | absolute `YYYY-MM-DD` |
| `checkbox` | `min`, `max` | how many boxes ticked |
| `rating` | `min`, `max` | stars, 1..5 by default |
| `file` | `accept`, `max_kb` | unchanged |
| `select` | — | its options are the constraint |
| *all* | `required` | OR'd with the `is_required` column |

### Why dates do not reuse `min`/`max`

Every other type stores a number there. A date bound is a string, and
`EventRegistrationFieldResource` ships `validation` raw to a member app that
is a separate codebase we cannot inspect or revise. A client doing the obvious
thing — `input.maxLength = field.validation.max` — would be handed
`"2026-12-31"`. A new key an old client ignores is safe in a way a redefined
one is not, and that asymmetry is the whole argument: forward compatibility by
omission is the only compatibility direction available.

### Free-form regex was removed, not guarded

`"regex:{$v['regex']}"` was built by concatenation from admin input. Two holes
at once. Laravel splits a rule string on `|`, so a stored
`/^a/|exists:users,email` appended two rules to a **member-facing** validator —
an existence oracle handed to anyone who can edit a field. And a nested
quantifier like `(a+)+$` hangs the worker on a long input, which needs no
attacker at all; that shape is ordinary copy-paste.

Only the names in `FieldConstraints::PATTERNS` are honoured: `alpha`,
`alphanumeric`, `digits`, `email`, `url`, `syrian_phone`, `no_urls`. The two
with a `null` expression emit Laravel's own rule, which is already translated
in both locales and words the failure far better than "format is invalid".

This was checked against the live database before shipping: four rows carried
constraints, none carried a `regex`. So dropping it cost nothing — and that
window does not stay open. **It is still a contract change for the member app**,
which will now receive a short name where the field's type says PCRE. Nothing
in flight breaks because nothing in flight uses it, but the owner of that app
should be told.

Reads stay lenient and only writes are strict, the same posture
`acceptToMimes()` already took: an unrecognised legacy value is dropped and
logged rather than rejected, so a live configuration cannot start refusing
every submission on deploy.

### The timezone guard needed no exemption

`UserSuppliedTimesAreInterpretedLocallyTest` was expected to be the awkward
part, and it was not — but only because the design avoids it deliberately
rather than by luck, so the reasoning is worth keeping.

Its Form Request half matches `/=>\s*\[[^\]]*'date'/`, which a line like
`'validation.min_date' => ['nullable', 'date']` **would** trip. There is no
such line: the vocabulary is enforced by a rule object, so no admin Form
Request declares a literal `date` rule. Its controller half bans
`Carbon::parse($request->…)`. There is no such call either — a bound is
validated with `preg_match` plus `checkdate()` and passed to
`after_or_equal:` as a raw string.

Both are correct on the merits, not just convenient. `max_date: 2026-12-31`
means that date wherever the reader is; converting it to an instant would be
the bug. The submission side is safe for the same reason and additionally
because its `date` rules are built at runtime from the database, so the
literal never appears in source.

If a future field type accepts a date**time** rather than a date, this changes:
it would need `interpretLocalTimes` over a dynamically-built field list, which
the trait cannot do today.

### Relative bounds are deferred, and rejected meanwhile

`today` and `+30 days` are the more useful version of a date range and will
likely be asked for. They are refused rather than partially supported, because
"today" is a different calendar day in `Asia/Damascus` than in UTC for three
hours out of every 24 — the bug class that has already bitten this project
twice — and because they make the generated rule depend on the day it was
built, which every `assertSame` test in `FieldRuleBuilderTest` would have to
freeze time to tolerate.

### Failures now name the question

`'attributes' => []` was empty in both locales and no Form Request implemented
`attributes()`, so a failed dynamic rule rendered `:attribute` as the dotted
path it was keyed by. An Arabic member reading "يجب ألا يكون طول حقل
fields.dietary_needs أكبر من 50 حرفاً" has been told which key failed, not
which question. The label was already stored bilingually and already sent to
clients; `FieldRuleBuilder::attributesFor()` now carries it into the validator,
including the `.*` path so a checkbox element error does not read as a bare
index.

### Two unrelated defects found on the way

`AdminServiceTypeController::store()` and `storeField()` declared a Resource
return type but returned the `JsonResponse` that `->response()` produces, so
both threw a `TypeError` on every success. Creating a service type, or a field
on one, was broken outright. Neither had a test; the vocabulary tests, which
run against both domains precisely because these four requests are
near-duplicates, are what surfaced it.

### No migration

The column was already `json`, nullable, cast and fillable on both tables, and
all four constrained rows in production remain valid under the vocabulary on
their own type. No backfill, and no new enum column, so
`NoNewMysqlEnumColumnsTest` and the 64-character identifier trap are both moot.

### The vocabulary is duplicated, unavoidably

`FieldConstraints::for()` in PHP, `VALIDATION_KEYS_BY_TYPE` in
`ADDOS/src/add-os/components/resource/form-fields.ts`. There is no shared
schema mechanism between the repos. `FieldConstraintsTest` snapshots the
backend's list and names that file in its failure message, so the two cannot
drift silently. Generating the TS from the PHP at build time is the real fix if
this grows.

## An event runs on a schedule, not one unbroken stretch

A three-day exhibition that opens 09:00–17:00, then 10:00–14:00, then
16:00–21:00 could not be expressed. `events.start_at`/`end_at` is a single
interval, so the admin had to either state hours that were wrong on two days
out of three, or create three events and split the registrations between them.

`event_sessions` carries the real hours, one row per sitting.

### The envelope is derived, not replaced

`start_at`/`end_at` stay on `events` and stay authoritative — they drive
ordering, the upcoming/past filters, the registration deadline, the card, and
the member app's whole idea of when an event is. Replacing them would have
meant rewriting every one of those and changing a public API shape consumed by
an app we do not control.

Instead they became *derived*: the earliest session's start and the latest
session's end, recomputed by `EventSchedule` whenever the schedule changes.
Every existing query kept working untouched, which is the entire argument for
this shape. `EventSchedule::syncEnvelope()` is therefore the only place those
two columns may be written once an event has sessions; anything else setting
them by hand is overwritten on the next schedule edit, deliberately — a
mismatch would show one set of hours on the card and another on the detail
page.

An event with no sessions keeps whatever envelope it has rather than having it
blanked, or clearing a schedule would drop the event out of every listing.

### Registration stays one act

A member registers for the event, not for a sitting, and attendance is not
tracked per sitting — hence no pivot. Per-session seats would make capacity,
approval, points and the expiry sweep all per-session, which is a much larger
change than what was asked for. If it is ever wanted, `event_sessions` is the
table to hang it off.

### Existing events were backfilled

Every event with times became a single-session event, so nothing has to
special-case "an event with no schedule" in the read path. Verified against
MySQL rather than SQLite: 20 events, 20 sessions, none stranded.

### Two bugs the tests caught, worth recording

- **`EventSessionFactory` wrote its wall clock straight to the column.** That
  stored 09:00 as 09:00 UTC and read it back as 12:00 local — the same
  three-hour drift this application has now shipped twice. It interprets
  through `LocalTime` like the Form Request does. Left alone it would have
  baked the drift into every future test using it, which is worse than a bug
  in production code: it would have made the wrong behaviour look verified.
- **Returning `$event->fresh()` from `store()` turned the 201 into a 200**,
  because the refetched instance is no longer "recently created" as far as
  Laravel's resource response is concerned. Caught by the nine existing
  timezone tests. `refresh()` keeps the flag.

Session hours are interpreted at the edge like every other wall clock here:
`interpretLocalTimesIn()` extends the existing trait to nested rows rather
than reimplementing the timezone rule beside it, so
`UserSuppliedTimesAreInterpretedLocallyTest` still describes the whole
application.
