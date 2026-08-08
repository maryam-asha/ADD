# ADD OS — Backend Build Plan (ADD Core)

Version 1.1 · 2026-08-08 · **Approved — the six blocking conflicts in §D are resolved; implementation is underway.**
See [docs/decisions/](../decisions/README.md) for the resolution of each one and the guard test enforcing it.

Sources of truth, in precedence order:

1. `ADD_PRD_v0.7.1` — behaviour, product decisions, decision register (§7)
2. `ADD_Master_ERD_v2.0` — structure (diff over the ERD the current code was built from)
3. `ADD_Document4_ERD_v1.0` — per-table documentation and decision-number traceability

Where a document conflicts with an assumption baked into today's code, the documents win.
Where documents 2 and 3 conflict **with each other**, nothing is decided here — see §D.

---

## A · Architecture

### A.1 Layer organisation — domain-namespaced single application

**Recommendation: domain-oriented namespaces inside one Laravel app. Not a module package, not a flat `app/Models`.**

Sizing argument. The target is ~50 tables across 10 technical domains, built by a small team. A module package (`nwidart/laravel-modules` or equivalent) buys hard boundaries at the cost of per-module migration timelines — and the Master ERD closes *every one of its ten domain sections* with an explicit "Cross-domain FKs" list. Boundaries that must be punctured on that scale are ceremony, not safety. Conversely, a flat `app/Models` with 50 classes makes the Experience/Ecosystem separation (decision #17) invisible in the code, when the PRD's whole argument (§3.1) is that the separation is *structural* rather than editorial.

```
app/
  Domain/
    Foundation/     districts, branches, buildings, floors, zones, spaces, resources, seats_desks
    Identity/       users, roles, companies, guests, consents, audit
    Membership/     plans, subscriptions, wallets
    Booking/        bookings, walkin_sessions, capacity, affected_bookings
    Access/         devices/locks, passcodes, access_events, TTLock client
    Qr/             qr_points, service_tickets
    Finance/        exchange_rates, transactions, payment methods, Money
    Experience/     events, event_registrations, business_cafe_orders
    Ecosystem/      site_content, partners, community_members, add_members, places
    Incubation/     programs, cohorts, applications, mentors, milestones
```

Each domain carries `Models/`, `Enums/`, `Actions/`, `Services/`, `Policies/`, `Data/` — created only when a domain actually needs them. A domain gets its own `ServiceProvider` **only** when it has bindings to register (today that is Otp, Access/TTLock, Finance/Money); the rest need none.

What deliberately stays where it is:

- **`database/migrations/` remains one ordered timeline.** With this many cross-domain FKs, per-module migration folders create ordering bugs that surface only on a fresh database.
- **`app/Http/` stays organised by audience, not by domain** — `Api/V1/{Public,Member,Admin,Kiosk}`. The API surface is versioned and role-shaped; a request's authorisation profile is what varies, not its domain.

### A.2 Isolating the three pillars

Core / Experience / Ecosystem are a *product* taxonomy, not a technical one — Core spans Foundation, Booking, Access, Membership and Incubation. Two things follow:

1. **Only the axis the PRD calls structural gets structure.** Decision #17 separates Experience from Ecosystem. That becomes two namespaces plus a guard test: no class under `Domain\Ecosystem` may reference `Domain\Experience` or vice versa, and **neither may reference `Domain\Booking`, `Domain\Access`, or `Domain\Membership`**. That test is the executable form of "belonging without a contract" (PRD §1, decision #18) — today it is only prose, and prose does not fail a build.
2. **Service names never appear in code.** PRD §3: service text is read from the CMS, never written into code. So there is no `ServiceName` enum. The only enum is `Pillar { core, experience, ecosystem }`, and the nine services live as CMS rows. This also keeps the unresolved `Event`/`Events` naming (7.3 #13) out of every class name.

### A.3 API layer

- Keep the `/api/v1` prefix and the split route files. Add **`routes/api/v1/member.php`** (`auth:sanctum` + `role:member`) — bookings, wallet, check-in, tickets and QR scanning have no home in today's public/admin split, and the Flutter app is their only consumer.
- Add **`routes/api/v1/kiosk.php`** last, and behind a placeholder guard: kiosk device trust is open decision 7.3 #4, and the kiosk is the one client that touches lock credentials.
- **Keep** the `AdminResourceController` / `PublicResourceController` pair for content resources (partners, community members, events, founders). It fits CRUD-over-a-list, which is what those are.
- **Do not extend it to transactional resources.** Bookings, passcodes, walk-in sessions and tickets are command-shaped (`confirm`, `cancel`, `check-in`, `issue`, `revoke`, `resolve`) and get single-action invokable controllers delegating to `Actions/`. `UserController` already establishes the precedent for opting out when the shape genuinely differs.
- Form Requests per write action, API Resources per read shape — unchanged from today.

### A.4 Two conventions to settle before any table is written

- **Enum columns.** Recommendation: `string` columns cast to PHP 8.2 backed enums for all new tables, rather than MySQL `ENUM(...)` as used today. Three enums in the ERD are explicitly unresolved (`partners.partner_type`, `community_members.category`, QR `scope_type`), and every change to a MySQL ENUM is a locking `ALTER`. A guard test asserts each such column has a matching cast. Existing tables are not rewritten — this is recorded as a documented deviation with a migration path.
- **Money.** A `Domain\Finance\Money` value object, `decimal:N` casts everywhere, and a guard test that greps every migration for `float(`/`double(`. Decision #15 is otherwise unenforceable.

---

## B · Phases

Ordering follows real dependencies, and maps to PRD §8 Sprints with **one deliberate deviation**, flagged in Phase 4.

### Phase 0 — Reconciliation and the guard harness

No new domain tables. This phase makes the existing code agree with the new decisions and installs the mechanism that keeps it that way.

| Work | Reason |
|---|---|
| Delete `app/Services/Access/AccessHoursPolicy.php` and `config/access.php` | Decision #11 abolishes the 08:00–23:00 window "from every location". The file hard-codes it. |
| Create the `app/Domain/**` skeleton; move existing models in | A.1 |
| Create `tests/Guards/` and the first invariants | PRD §9 |
| Create `docs/decisions/` — PRD decision # → the guard test that enforces it | Traceability |

First guard tests: no `float`/`double` in any migration (#15) · no external host in code, config or seeds (#20) · no reference anywhere to an access-hours window (#11) · Ecosystem↛Experience↛Core namespace dependency ban (#17, #18) · every enum column has a PHP enum cast · no `ADD Club` table, column or enum exists (§7.2 explicitly forbids building on it).

Acceptance: `php artisan test` green, `pint --test` clean, every guard test failing loudly if its invariant is removed. **DONE** — `tests/Guards/` has 7 checks, `php artisan test` is green (19 tests), `pint --test` is clean apart from 6 files that already failed before this work started (confirmed via `git stash` against `HEAD`).

### Phase 1 — Foundation: the eight-level spatial hierarchy · **DONE**

**Tables:** `districts` (new) · `branches` (+`district_id` nullable) · `buildings` · `floors` (new) · `zones` (new) · `spaces` (+`zone_id`, `event_hall`, `allocation_model`, `pricing_currency`, `status_reason`, `status_from`, `status_until`) · `resources` (new, +operational status per D.11) · `seats_desks` (new; `qr_point_id` nullable until Phase 7).

**Locked decisions:** #1 eight levels, `Branch` keeps its name · #2 Floor/Zone are classification only · #3 Resource is metadata, never booked · #4 Seat/Desk is an address, not a seat map · #8 operational status at Space level, no hierarchical escalation · #13 `is_lockable` true only for room / business / event_hall.

**Non-blocking items resolved on arrival** (per [space-type-and-resource-status.md](../decisions/space-type-and-resource-status.md)): D.7 `space_type` follows ERD v2.0 naming (`co_space|room|business|event_hall`) · D.11 `resources` gets the same four operational-status columns as `spaces`, since PRD §5.6/#8 name "the space and the resource" literally.

**Placeholders:** `districts` gets exactly one row and `branches.district_id` stays nullable — it becomes meaningful at the second branch, and must not grow logic before then · `spaces.allocation_model` is nullable — the space_type → allocation_model mapping is Phase 5 business logic, not this structural phase.

**Acceptance:** the full hierarchy is creatable; maintenance can be set on one space without touching its floor; a space whose `status != active` disappears from availability results regardless of calendar availability. All three verified in [tests/Feature/Foundation/SpatialHierarchyTest.php](../../tests/Feature/Foundation/SpatialHierarchyTest.php) (6 tests) against both SQLite (test suite) and the real dev MySQL database (manual `tinker` walkthrough + `migrate`/`migrate:rollback`/`migrate:fresh` all verified clean).

**Guards:** [`SpatialHierarchyGuardTest`](../../tests/Guards/SpatialHierarchyGuardTest.php) — `floors`/`zones` carry no `status` column and no device/lock/booking FK, ever. (Every spatial table resolving to a `branch_id` is verified behaviourally in the acceptance test above rather than as a schema guard — the chain is fixed and known today, not a generic rule new domains must satisfy.)

**Implementation notes for later phases:**
- `space_type` moved off a MySQL `ENUM` onto `string` + PHP backed enum cast in the *same* migration that needed to add `event_hall` — the "documented deviation with a migration path" from §A.4, exercised on the one column that actually needed to change. `spaces.status` was left as the legacy `ENUM` (untouched, so [`NoNewMysqlEnumColumnsTest`](../../tests/Guards/NoNewMysqlEnumColumnsTest.php) doesn't apply) but still gets a PHP enum cast on the model — a DB column can be `ENUM` and still be cast to a backed enum in Eloquent; the two are independent.
- Confirmed Laravel 12 can `->change()` a column (enum→string) on MySQL without `doctrine/dbal` installed — tested against the real dev DB, not just SQLite.
- `SeatDesk` needs an explicit `protected $table = 'seats_desks'` — Eloquent's naming convention guesses `seat_desks` from the class name.

### Phase 2 — Identity, RBAC, companies, guests, audit · **DONE**

**Tables:** `users` · spatie role tables (`member`/`operations`/`admin` — `staff` renamed, see [staff-operations-rename.md](../decisions/staff-operations-rename.md)) · `user_branch_memberships` · `otp_verifications` · `audit_logs` (spatie/activitylog's `activity_log` table, already installed and migrated — no new table) · `notification_logs` (kept as-is; ERD v2.0's `notifications` naming not adopted) · `private_office_requests` · `companies` (+`branch_id`) · `company_user` (+`door_access_enabled`, +its own `id`) · `guests` · `consents`.

**Locked decisions:** OTP is the verified identity channel, WhatsApp-only (§5.1, D.3) · company accounts are created by operations only, never self-service · #9 a guest holds no account and acts only through a host · every sensitive action is audit-logged · [D.8](../decisions/rbac-scoping.md) — flat spatie roles stay flat; the one scoped capability (company door access) is `company_user.door_access_enabled` + `CompanyPolicy::useDoorAccess()`, not a scope system.

**Placeholders:** `private_office_requests.converted_company_id` nullable — the request precedes the company by design · `consents.subject_type = community_member` has no write path yet (Phase 9) · `mentor` role deliberately not seeded — its account structure is still open (§7.3 #10), and seeding an unusable role would presume an answer to it.

**Acceptance:** a real member logs in by OTP and gets the `member` role, not `operations` (regression-tested after the rename) · operations creates a company from a signed (quoted) request and the source request closes to `contracted` in the same transaction · a request that isn't yet quoted is rejected · a company member with `door_access_enabled` is allowed by `CompanyPolicy`, one without the flag or without membership is denied, `admin` bypasses via the existing `Gate::before` · a member creates a guest and a `guest_data_on_behalf` consent is recorded automatically · a member cannot see or delete another member's guest · every one of: role change, status change, company creation, door-access toggle, member added/removed writes an `activity_log` row with the actor and a before/after payload.

**Guards:** [`RbacStaysFlatTest`](../../tests/Guards/RbacStaysFlatTest.php) — no `scope_type`/`scope_id` column anywhere, `CompanyPolicy` is the only Policy in the app · [`CommunityMembersNoUserLinkTest`](../../tests/Guards/CommunityMembersNoUserLinkTest.php) — extends decision #18 now that `users`/`companies` are real · [`EnumColumnsHaveBackedEnumCastsTest`](../../tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php) — every enum-shaped column (Phase 1 and 2) actually casts to its PHP backed enum, not just avoids MySQL `ENUM`.

**Bugs found and fixed while building this phase (pre-existing, not introduced here):** `AdminResourceController::destroy()` — and by inheritance every existing admin resource's delete endpoint (Founder, Partner, CommunityMember) — declared `: JsonResponse` but `response()->noContent()` returns `Illuminate\Http\Response`, a sibling class, not a subtype; this was a live 500 on every one of those DELETE routes, unnoticed because no test had ever exercised them. Fixed by correcting the return type. Also fixed in this session's own new code: the same mismatch in `CompanyMemberController`/`GuestController`, and two places (`CompanyController::store`, `PrivateOfficeRequestController::store`) that relied on a migration-level column default instead of setting the enum explicitly, which left the field `null` in the very API response returned from creating the row (confirmed by first writing this as a fact — Eloquent does not re-fetch DB-side defaults into an unrefreshed model).

### Phase 3 — Membership, plans, wallet

**Tables:** plans · `memberships` (ERD v2.0 naming) · `wallets` · `wallet_transactions`.

**Locked decisions:** hybrid pricing — a duration permit plus included room hours plus an overage discount (§5.2) · **no rollover of unused balance between cycles** · Dedicated Desk sells as subscription or package only, never as a session · `is_subscription` distinguishes a true subscription from a single-use Hot Desk package that creates a booking directly · [wallet/subscription ownership](../decisions/wallet-subscription-ownership.md) — `wallets` and `memberships` carry `owner_type[user|company]` + `owner_id`, extending ERD v2.0 rather than switching to Document 4's table shape, so a private-office company can hold a shared wallet/package its `company_members` draw from alongside any individual's personal one.

**Placeholders:** proration on plan change (7.3 #8) is unmodelled — no field, no logic, no partial implementation.

**Acceptance:** buying a plan debits the wallet and creates the right record; a single-use Hot Desk package produces a `booking`, not a membership; a company-owned wallet is debited by a `company_members` purchase, not by the individual member's own wallet.

**Guards:** unused included hours do not survive a cycle boundary · no money column is float · a `is_subscription=false` plan can never create a membership row · every `wallets`/`memberships` row has a non-null `owner_type`+`owner_id`, and `owner_type='company'` requires an `active` company.

### Phase 4 — Finance primitives · **deviates from Sprint order**

**Tables:** `exchange_rates` · `payment_methods` · `transactions` · the `Money` value object.

**Why earlier than PRD Sprint 6.** Decision #15 requires *every* transaction to carry an exchange-rate snapshot. Both Phase 3 (wallet top-ups) and Phase 5 (booking payment, walk-in settlement) create transactions. Building them before the transaction primitive exists means writing payment logic twice and back-filling snapshots — exactly the "redesign the data model mid-sprint" that PRD §8 forbids. Only the *primitives* move earlier; reporting and reconciliation stay at their original position (Phase 11).

**Locked decisions:** #15 DECIMAL exclusively; exchange rate is admin-managed; a snapshot is stored per transaction · SYP is formatted without `Intl` (§5.7) — relevant here because the API returns the formatted string the clients display · [the Money Model](../decisions/money-model.md) — currency is set per price point (`pricing_currency` on `plans`/`spaces`/`business_cafe_orders`), never forced to a single USD base; `transactions.amount`+`currency` is authoritative, `usd_equivalent` is derived and reporting-only and must never be read back for a refund; no `*_snapshot_usd`-style single-base column is introduced anywhere, including on `bookings` in Phase 5.

**Guards:** no transaction persists without a rate snapshot · no float anywhere in the finance domain · no `Intl`-equivalent locale formatting on SYP · no migration introduces a single-USD-base money column.

### Phase 5 — Booking, walk-in sessions, capacity, affected bookings

**Tables:** `bookings` · `walkin_sessions` · `space_capacity_slots` · `affected_bookings`.

**Locked decisions:** #5 the unified rule — a booking record means prepaid and cancellable; a session without a booking means postpaid and not cancellable · #6 choosing a Hot Desk package creates a real booking with a start time and free cancellation up to 24 hours · #7 meeting rooms auto-confirm, only the large event hall is manual · #11 no maximum duration, access is 24/7 · no overbooking in Co-Space — the capacity ceiling is strict · #8 activating maintenance over a confirmed booking succeeds immediately and generates `affected_bookings` for manual handling.

**Placeholders:** none — this domain is fully decided.

**Acceptance:** a confirmed prepaid booking; a walk-in session settled at checkout **even when the wallet balance was zero at entry**; maintenance activated on an occupied space produces a pending `affected_bookings` row.

**Guards:** `capacity_booked` counts bookings *and* active walk-ins against one ceiling · no booking is creatable on a non-active space · `cancellation_allowed_until = null` makes cancellation unreachable, not merely hidden · walk-in sessions expose no cancellation path at all · no duration ceiling exists in any validator.

### Phase 6 — Access control (TTLock)

**Tables:** `devices` + `device_capabilities` (ERD v2.0 shape confirmed, see [access-control-tables.md](../decisions/access-control-tables.md) — no separate `gateways`/`locks` tables) · passcodes · `access_events`.

**Locked decisions:** #12 gateway with custom codes is primary; offline algorithmic is a documented degraded mode that must be flagged in the ops dashboard when used · #13 locks only on bookable spaces; the main door has no lock and no code · #14 `lock_mac` is the natural key, never the provider's `lockId`; lock deletion always requires physical proximity · Period passcodes only · a code is issued **when the cancellation window closes**, not at confirmation · activation at reception is mandatory, and TTLock's 24-hour rule kills an unactivated code permanently · one rotatable company-level code with a per-member usage flag · the SDK ships in the staff app and reception kiosk only — never in the member app, because `lockData` is an owner-level credential.

**Placeholders:** reception-scan → auto-activation (7.3 #2, recommended but not adopted) · kiosk device trust (7.3 #4) · Force Unlock (7.3 #11). None may be partially implemented.

**Guards:** no member-role endpoint can reach `lockData`, SDK material or any passcode value · `hardware_mac` is required and unique for every lock and gateway · no passcode is issuable before its booking's cancellation window closes · remote lock deletion is unreachable without an explicit proximity flag · using the offline mode raises the operational flag.

### Phase 7 — QR layer and service tickets

**Tables:** `qr_points` · `service_tickets`; `seats_desks.qr_point_id` is wired here.

**Locked decisions:** #10 every ticket carries the identity of the member who scanned · #9 a guest's request goes through the host — the alternative (an anonymous scan auto-linked to the active booking) is explicitly rejected · QR values are random, never sequential · each point is independently disableable and replaceable.

**Placeholders:** the service/technical split for a Co-Space desk code (7.3 #6).

**Guards:** no ticket exists without an authenticated opener · a guest ticket requires `hosting_user_id` · QR codes are drawn from a CSPRNG and are non-sequential (assert distribution, not one sample) · disabling one point does not affect any other.

### Phase 8 — Experience layer

**Tables:** `events` (reconciled with the existing table) · `event_registrations` · `business_cafe_orders` (neutral skeleton only).

**Locked decisions:** #17 structural separation from Ecosystem · an internal member-facing event is distinct from a commercial event-hall booking, which goes through `bookings` · registration requires a verified identity, unlike the public RSVP that stays in Ecosystem.

**Placeholders:** `business_cafe_orders.billing_scope` stays an untyped neutral field — billing scope is 7.3 #7 · **ADD Club is not modelled at all** (§7.2).

**Guards:** `Domain\Experience` imports nothing from `Domain\Ecosystem` · no ADD Club table, column or enum value exists.

### Phase 9 — Ecosystem layer and the public site

**Tables:** `site_content` · `partners` · `community_members` · `add_members` · `ecosystem_places` · `event_attendees` · `founders` (existing — see §D.10).

**Locked decisions:** #18 `community_members` has no foreign key to `users`, deliberately · #20 network isolation — an external link is a functional defect · public directory listing requires explicit opt-in, revocable at any time (§5.11).

**Placeholders:** `partner_type` is seeded from the proposed list and marked provisional (7.3 #5) · the community category 4-vs-5 question stays open · ADD Club, again, is absent.

**Guards:** `community_members` carries no required FK to `users` · the public directory query filters on `directory_opt_in` (assert a non-opted-in row is invisible, not that a flag exists) · no external host appears in any seeded content.

### Phase 10 — Incubation and acceleration

**Tables:** programs · `cohorts` · `applications` · `interviews` · `cohort_memberships` · `mentors` · `mentor_assignments` · `milestones` · `program_sessions`.

**Placeholders:** `applications.applicant_user_id` **stays nullable** — 7.3 #9 is open, and making it required would silently decide it · `mentors` has no login capability — 7.3 #10 is open.

**Guards:** `applicant_user_id` is nullable and an application submits successfully with it null · `mentors` has no authentication path.

### Phase 11 — Privacy, retention, reporting, hardening

`consents` fully wired to the flows that need them; `data_retention_policies` enforced for `access_events`, `service_tickets` and guest data; financial reporting; the backup/DR strategy (7.3 #3 — an infrastructure document, not a table).

---

## C · Cross-phase technical acceptance criteria

Applied to every phase, and each one has a guard test:

1. No `FLOAT` or `DOUBLE` on any monetary field — `DECIMAL` exclusively (#15).
2. Every spatial table resolves to a `branch_id`, directly or through the hierarchy.
3. Every sensitive action (code issuance, door open, permission change, maintenance assignment) writes to `audit_logs`.
4. Operational status never escalates across hierarchy levels.
5. Every amount-bearing record links to the exchange-rate snapshot taken at execution.
6. No open decision from PRD §7.3 acquires logic that presumes an answer.
7. No external host in code, config, seeds or content (#20).
8. Every enum column has a matching PHP backed enum cast.

---

## D · Gaps and conflicts

D.1–D.6 were the blocking conflicts and are now **resolved** — each links to
its full decision record in [docs/decisions/](../decisions/README.md). The
original framing of each is kept below rather than deleted, so a future
reader can see what was actually in tension, not just the resolution.
D.7–D.15 remain open and are raised, not decided, at the phase where they
first bind.

### D.1 The two ERD documents conflict structurally · **RESOLVED** → [structure-reference.md](../decisions/structure-reference.md)

Document 4 states it was rewritten from scratch from the PRD alone, **without access to ERD v1.0**. Master ERD v2.0 is a diff over the schema the current code was actually built from. They disagree on roughly ten structural points (D.2–D.9 below). A precedence rule is needed before the first migration.

Resolved as recommended: **ERD v2.0 governs structure; Document 4 governs decision traceability**; the PRD governs behaviour.

### D.2 i18n column strategy · **RESOLVED** → [i18n-columns.md](../decisions/i18n-columns.md)

Today's code stores translations as one JSON column cast to array (`{"en": …, "ar": …}`) — documented in `CLAUDE.md` and used by `branches`, `events`, `community_members`, `partners`, `founders`. Both new documents specify twin `name_ar` / `name_en` columns. This affects nearly every table with user-visible text and cannot be deferred.

Resolved: the single JSON column is kept, on every table, old and new alike — the twin-column convention in both new documents is not adopted.

### D.3 OTP channel · **RESOLVED** → [otp-channel.md](../decisions/otp-channel.md)

The PRD (§2.1, §5.1) and ERD v2.0 both specify **MTN and Syriatel**. The current code specifies **WhatsApp**: `otp_verifications.provider` is `enum('whatsapp')`, and `OtpService` / `OtpServiceProvider` / `MockOtpProvider` are built around a WhatsApp driver. Document 4 compounds the confusion by listing the channel as `[mtn_cash|syriatel_cash]` — those are payment methods, and appear to be a copy-paste error.

Resolved, final: WhatsApp only. This is an explicit reversal of the MTN/Syriatel decision both new documents state as settled, not a fix toward them — the PRD is now stale on that one point.

### D.4 Money Model — PRD contradicts ERD v2.0 · **RESOLVED** → [money-model.md](../decisions/money-model.md)

PRD decision #15 and §5.7: the dollar is the base currency. ERD v2.0 §5 declares a newer "Money Model" — pricing currency per price point, not centrally forced to USD, with `usd_equivalent` explicitly **non-authoritative and unusable for refunds** — and claims it was decided *after* PRD v0.7.1. If so, the PRD is stale on its own locked decision. ERD v2.0 also contradicts itself here: `bookings.price_snapshot_usd` survives unchanged from the old model.

Resolved: ERD v2.0's Money Model is adopted. `price_snapshot_usd` and any other single-USD-base column is dropped wherever it would otherwise appear (`bookings` doesn't exist yet, so this binds Phase 5's migration, not a retroactive edit).

### D.5 Access-control table shape · **RESOLVED** → [access-control-tables.md](../decisions/access-control-tables.md)

ERD v2.0 keeps the generic `devices` + `device_capabilities` design (one entity for every device type, so a future device needs no new table) and extends it with `hardware_mac` and `parent_device_id`. Document 4 replaces it with separate `gateways` and `locks` tables. The existing `devices` migration already matches ERD v2.0, including the `gateway` type.

Resolved: ERD v2.0's shape is confirmed — no structural change, since the code already matched it.

### D.6 Subscription and wallet ownership · **RESOLVED** → [wallet-subscription-ownership.md](../decisions/wallet-subscription-ownership.md)

ERD v2.0: `memberships.user_id` and `wallets.user_id` — individuals only. Document 4: `subscriptions` and `wallets` carry `owner_type[user|company]`. A company holding a private-office contract argues for Document 4's shape, but it is a materially different model and also disagrees on table names (`plans`/`memberships` vs `membership_plans`/`subscriptions`).

Resolved: extend ERD v2.0's shape and naming with `owner_type`/`owner_id`, rather than switching to Document 4's alternate table names.

### D.7 Space type naming

ERD v2.0: `co_space | room | business | event_hall` (matches the existing migration, which lacks only `event_hall`). Document 4: `private_office | co_space | meeting_room | event_hall`. Naming only — but it propagates into enums, API contracts and the frontend.

### D.8 RBAC scoping

ERD v2.0 keeps flat spatie roles. Document 4 adds `user_roles.scope_type[global|company]` + `scope_id` for a company-scoped Company Member role. Recommendation: keep spatie flat and express the one genuinely scoped capability through `company_user.door_access_enabled` plus a policy — a scoped-role system for a single capability is a large mechanism for a small need. Confirmation requested.

### D.9 `space_capacity_slots` missing from Document 4

Present in ERD v2.0 and load-bearing: it is what makes the strict no-overbooking ceiling enforceable across both bookings and walk-ins. Absent from Document 4 entirely. Assumed to be an omission, not a removal.

### D.10 Tables in the code with no home in the new ERD

`founders` (with `order` and `published`) exists and is served by both an admin and a public controller. It appears in neither new document. Ecosystem or CMS content? Also: `notification_logs` in code vs `notifications` in ERD v2.0; and `community_members` today has `category` with **four** values while ERD v2.0 lists **five** (adding `strategic_regulators`) and flags the count as still open.

### D.11 `resources` has no operational status

PRD §5.6 and decision #8 both say the operational status applies to "the space **and the resource**". Neither ERD gives `resources` any of `status`, `status_reason`, `status_from`, `status_until`. Either the decision is broader than the model, or the model is right and the decision text is loose.

### D.12 `add_members` has no path to a login account

Decision #18 keeps `community_members` free of any FK to `users`. `add_members` hangs off `community_members`. So a verified ADD Member who *also* books a room exists as two unrelated records. Document 4 adds `community_members.linked_user_id` (nullable); ERD v2.0 does not. A nullable optional link seems compatible with #18 — but the two documents disagree, and the intent should be stated rather than inferred.

### D.13 `Event` vs `Events` naming

7.3 #13, low priority in the PRD, but it lands *now*: it decides a table name, a route segment and a namespace. The frontend rules file already records it as "unresolved and must not be picked silently".

### D.14 Sprint-order deviation

Phase 4 moves the finance primitives ahead of booking, for the reason given there. This needs explicit approval, since PRD §8 orders the sprints by dependency.

### D.15 No backend guard tests exist today · **DONE**

The backend test suite was `ExampleTest` only. PRD §9 — "written prose is a request, not a guarantee; every critical rule must be guarded by an automated test" — had no backend implementation, while the frontend repo already runs `/guards` over four spec files. Every phase above therefore carries its own guard list.

Phase 0 built the harness: `tests/Guards/` (testsuite `Guards`, included in `php artisan test`) with the first seven checks — money is DECIMAL-only (#15), no external host (#20), no access-hours window (#11), the Ecosystem/Experience layer boundary (#17/#18), ADD Club stays unmodeled (§7.2), OTP stays WhatsApp-only, and no new migration reintroduces a MySQL `ENUM` column (§A.4). Each later phase adds to this suite as its tables land.
