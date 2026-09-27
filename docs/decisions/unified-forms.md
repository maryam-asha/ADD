# Unified forms: one polymorphic form engine any model can own

**Status:** resolved 2026-09-26. **Owner:** Maryam Asha.
**Type:** design doc, and a deliberate reversal of one line of an earlier decision.
**Supersedes:** "Separate tables per domain, one logic layer" in [event-approval-and-custom-forms.md](event-approval-and-custom-forms.md), and that doc's cascade-vs-restrict divergence note.

## What changed

Custom forms — the questions a member answers when registering for an event or filing a service request — were stored per domain: `service_type_fields` + `service_request_values`, and a column-for-column copy in `event_registration_fields` + `event_registration_field_values`. The *logic* was already shared (`App\Support\Forms`), but every owner that wanted a form would have added two more tables, two more models, two Form Requests, a resource and a controller.

The two copies had already drifted, and the drift was the bugs: service file answers were validated and then dropped (`input()` instead of `validated()`); deleting an answered service field was a 500 (restrict FK) while deleting an event field silently erased its answers (cascade FK); renaming a service field onto a sibling's key was a DB error; a label-only PATCH of an event select demanded `options`; `is_required: null` hit a NOT NULL column; the admin service-type detail returned no fields at all.

They now share four tables, one domain, one controller and one pair of requests. The earlier decision kept the tables apart only to avoid migrating live Services data — there was no behavioural reason — and that migration is now written, reconciled and tested. The four per-domain tables are dropped in the same release, straight after the copy.

## The schema

| Table | Holds | Keys |
|---|---|---|
| `forms` | one form per (owner, purpose) | `formable_type`/`formable_id` morph + `purpose`, unique together |
| `form_fields` | the questions | `form_id` FK **cascade**; `(form_id, key)` unique |
| `form_submissions` | one filled-in form, tied to the record it created | `form_id` FK **cascade**; `subject_type`/`subject_id` morph; `user_id` FK **null on delete**; `(subject_type, subject_id, form_id)` unique |
| `form_answers` | one answer | `form_submission_id` FK **cascade**; `form_field_id` FK **null on delete**; snapshot `field_key`/`field_type`/`field_label`; `value_text`/`value_json`/`value_file` |

- **Answers carry a snapshot of the field they answered.** An answer is a record of what was asked, so deleting or renaming a question never fails and never erases what members said: the field FK goes null and the answer keeps its key, type and label. This replaces *both* old behaviours (restrict and cascade) with one that is right for both owners.
- **`purpose` lets one owner hold several forms** — an event's registration form and, later, a post-event feedback survey — without a schema change. `App\Domain\Forms\Enums\FormPurpose` has `Registration` and `Request` today.
- **No FK on a morph.** An owner's `deleted` hook removes its forms; subjects are usually removed by database cascades (`users → service_requests`, `events → event_attendees`) that fire no model event, so `Form` and `FormSubmission` are `Prunable` and the hourly `model:prune` sweeps orphans, taking their stored files with them.
- Every composite index and FK is named explicitly (MySQL's 64-character limit; SQLite in CI accepts anything).

## Decisions

- **`App\Domain\Forms` is a shared kernel.** Every domain may import it; it imports nothing but `Identity` (for the submitting `User`). `DomainLayerBoundaryTest::test_forms_depends_on_no_domain_but_identity` enforces it as an allowlist, so a domain created later is banned automatically. Owners and subjects are reached through morphs and the `HasForms` contract, never by class.
- **`App\Support\Forms` stays where it is.** It is the stateless half — `FieldType`, `FieldRuleBuilder`, `FieldConstraints`, `FieldValueRouter`, `ReservedFieldKeys` — and nothing about it changed. `App\Domain\Forms` is the persistence half.
- **Owner-specific rules live on the owner, as two hooks** on `HasForms`: `formFieldsChanged(Form)` and `formFieldCreationBlockedReason(FormPurpose)`. `Event` uses the first to keep `has_registration_form` in step (from `FormField`'s own model events, so factories and tinker keep it right too — but not `db:seed`: `DatabaseSeeder` runs `WithoutModelEvents`, so `EventRegistrationFormSeeder` sets the flag itself, which `EventRegistrationFormSeederTest` checks with events muted) and the second to refuse questions on an event that registers externally. Nothing in the engine knows events exist.
- **`has_registration_form` is display-only now.** It is still stored, because the browse listings read it without a join, but validation and storage are decided by whether the registration form *exists*. A stale flag can no longer switch validation off.
- **One controller plus a route registrar, not an abstract controller per owner.** `AdminFormFieldController` is deliberately not an `AdminResourceController` subclass: the model, requests and resource are identical for every owner, so the only thing that varies — which owner, which purpose — is configuration. `App\Http\Routing\FormFieldRoutes::register($uri, $ownerClass, $purpose)` puts it on the route action (strings only, `route:cache`-safe), and is called *inside* each owner's existing middleware group, so guards are unchanged: operations may edit event forms, only admins may edit service forms. `FormRouteContext` resolves the owner through `resolveRouteBinding()` (same localized 404) and scopes every field lookup to that owner's form for that purpose — which is what stops a field being reached through another owner, including an owner of another type sharing its numeric id.
- **The HTTP contract did not move.** Paths, verbs, guards and response shapes of both admin editors and the three `registration-form` endpoints are unchanged, so ADDOS and the mobile app need nothing. Two deliberate differences: the admin `ServiceRequestResource.values[]` gains `label` (additive), and a reserved identity key in *service* answers is now a 422 — which is what `docs/api/form-field-types.md` already promised the mobile app.
- **A non-enforced morph map.** `MorphMapServiceProvider` aliases `event`, `event_attendee`, `service_type`, `service_request`. `enforceMorphMap()` would stop existing Sanctum token, notification and spatie role rows — which store `User` by class name — from resolving, so the map is not enforced and `User` is never aliased. `FormModelsAreMorphMappedTest` stands in for enforcement on the form engine: every class using `InteractsWithForms` or `HasFormSubmissions` must have an alias. The map also changes what spatie's `performedOn()` writes to `activity_log.subject_type` for events and attendees; `2026_09_27_100005` rewrites the older rows so one subject is not stored two ways.
- **Option labels are optional per language.** `options.*.label.*` is nullable: the Services requests used to require both, the Events ones neither, and ADDOS's builder sends blank `{ar, en}` labels for a new option. Requiring them would have started refusing edits the dashboard makes today. A blank label renders the option's value.

## Attaching a form to a new model — no migration

Say `Space` gets a booking form and `Booking` holds the answers:

1. `Space implements HasForms` and `use InteractsWithForms`; add `bookingForm(): MorphOne { return $this->form(FormPurpose::Booking); }` and a `Booking` case to `FormPurpose`. Override the two hooks only if the owner has rules of its own.
2. `Booking` uses `HasFormSubmissions`.
3. Add `space` and `booking` to `MorphMapServiceProvider` (the guard fails otherwise).
4. `FormFieldRoutes::register('spaces/{space}/booking-fields', Space::class, FormPurpose::Booking);` inside the middleware group that should guard it.
5. The submitting Form Request uses `ValidatesFormSubmission` and implements `resolveSubmittedForm()`.
6. Inside the transaction that creates the booking: `app(FormSubmissionService::class)->record($form, $booking, $request->formFieldValues(), $user)`.
7. Render with `FormFieldResource` / `FormAnswerResource`, eager-loading `bookingForm.activeFields` and `formAnswers.field`.

## Migrating the data — copy, then drop, in one release

- **`2026_09_27_100004_backfill_forms_from_legacy_tables`** copies everything: query builder only, aliases and field types written as literals, one transaction. It refuses non-empty targets; aborts *before writing* on an unknown field type, a key over 80 characters, or an answer filed against another owner's field; copies value columns as raw text with file paths untouched (no files move); and rolls back if the field and answer counts do not reconcile.
- **`2026_09_27_100006_drop_legacy_form_tables`** then drops the four per-domain tables. The first plan kept them for a release as a rollback source; that was reversed because the data is small (tens of fields, a handful of answers) and a database backup does the same job. Its `down()` recreates them empty, exactly as they were.
- **So the deploy is:** take a database backup → `php artisan down` → deploy → `php artisan migrate --force` → `php artisan up`. Rolling back means restoring that backup plus the previous code; after new submissions arrive, fix forward instead.
- `LegacyFormTablesAreUnreferencedTest` asserts the tables are gone and that no code in `app/`, `routes/`, seeders or factories names them or their old models. `LegacyFormBackfillTest` recreates them through the drop's `down()` to replay the copy; keep it until the backfill has run on every environment.

## In passing

`model:prune` was scheduled bare. Discovery only looks in `app/Models`, which does not exist here, so the hourly job threw on every run and `pending_registrations` had never been pruned. It now names its models, and `PrunableModelsAreScheduledTest` fails if a `Prunable` model is left off.

## Known gaps, deliberately not addressed

- **File answers are returned as a raw storage path** on the `public` disk, not a URL, as before. Changing that changes the response contract, so it is a follow-up of its own.
- **ADDOS's field editor fills both label languages from the single localized label** the admin list returns, so editing a field overwrites its other language. Keeping the response shape unchanged keeps that bug as it was; it is a dashboard fix, not an API one.
- **The field editor is not on the `permission:` pilot.** Like every admin resource except Branches, it runs on the `role:` middleware of the group it is registered in. `AdminFormFieldController` shows up in `permissions:sync`'s uncovered list; its URIs already name the right module per owner (`events`, `service-types`) for when it converts.
