# Profile fields, completion score, and contact_links

**Status:** resolved 2026-08-17. **Owner:** Maryam Asha.
**Type:** design doc, written against the 2026-08-15 decision session
(decisions #11, #12, #15).

## What this adds

Four optional profile fields (`gender`, `instagram_url`, `behance_url`,
`website_url`), a derived profile-completion score exposed on
`GET /member/profile`, and a new `App\Domain\Ecosystem\ContactLink`
resource (admin-managed, publicly readable).

## Decision

- **`gender` lives on `user_personal_profiles`,** alongside `bio`/`city`/
  `avatar_url` — it's a personal, not professional, attribute. Column is a
  nullable `string` cast to a new `App\Domain\Identity\Enums\Gender` backed
  enum (`Male`, `Female`), matching this codebase's established convention
  for optional categorical fields (`ErrorLogPlatform`/`platform`,
  `private_office_requests.status`) — never a MySQL `ENUM` column
  (`tests/Guards/NoNewMysqlEnumColumnsTest.php`). Only two cases were
  chosen; the source spec left the value set unspecified, so this is an
  assumption, not a locked decision — flagged here for confirmation before
  production, the same way `settings-key-value-store.md` flagged its own
  unspecified numeric defaults.
- **`instagram_url`/`behance_url`/`website_url` live on
  `user_professional_profiles`,** alongside the existing `linkedin_url` —
  "keep all social/web links together" per the spec, and `linkedin_url`
  already set that precedent.
- **Completion score is computed on read, never cached.** Both profile
  relations (`personalProfile`, `professionalProfile`) are already loaded
  on every `GET /member/profile` call regardless — there is no hot path
  this adds cost to, and no-cache-at-all sidesteps the entire invalidation-
  correctness surface the spec warned about ("do not let it go stale
  silently") by having nothing to invalidate.
- **`profile.completion_threshold` needed no seeder change.** It was
  already seeded at `80` in the 2026-08-15 settings phase
  (`database/seeders/SettingSeeder.php`, flagged there as an assumption).
  This phase just reads it via `SettingService::get()` and reconfirms `80`
  as the working default.
- **`contact_links.type` is an open plain `string`, not a PHP backed enum
  and not a MySQL `ENUM`.** The spec's own precedent citation
  ("check `SettingScope`'s reasoning... it was designed with the same
  open-endedness in mind") turned out to be factually wrong on inspection —
  `SettingScope` is itself a closed backed enum with exactly one case
  today (`Global`). The actual open-string precedent in that same domain is
  `Setting::$key`: a freely composed, domain-owned, dot-namespaced string
  with no central enum coordinating every consumer. `contact_links.type`
  needs that same property, for a structural reason: the requirement
  "adding a platform is a row insert, never a code change or migration" is
  incompatible with a backed enum, whose cases are fixed at deploy time.
- **`ContactLink` lives in `App\Domain\Ecosystem`,** mirroring `Founder`/
  `Partner` exactly — public-facing, admin-managed organisational content,
  same permission tier (`role:admin|operations`, no narrower `role:admin`
  group), same `AdminResourceController`/`PublicResourceController`
  two-tier pattern. Unlike `PaymentMethod` (forward-earmarked into a
  not-yet-built `Finance` domain because the backend build plan already
  named that home ahead of time), no forward earmark exists for
  `contact_links` anywhere in the build plan — `Ecosystem` already fully
  fits the shape today, so no new domain is warranted.
- **No ADD Member promotion/tier mechanism is built.** Investigated per the
  spec's §3 instruction: no table, model, enum, or route models an "ADD
  Member" tier anywhere in this codebase today. `add_members` is named
  only as a future table in `docs/architecture/2026-08-08-backend-build-
  plan.md` (Ecosystem domain, Phase 9) and in `tests/Guards/
  CommunityMembersNoUserLinkTest.php`'s docblock, which calls the question
  of whether it optionally links to a `users` account (D.12) "distinct...
  and stays undecided." The three RBAC roles (`member`/`operations`/
  `admin`) are authorization roles, not membership tiers, and are
  unrelated. `App\Domain\Membership` (`Plan`/`Membership`/`Wallet`) is a
  subscription-billing concept, also unrelated. Per the spec's own
  instruction, this phase stops here rather than inventing a tier system:
  the completion score is exposed (`score`/`threshold`/`missing_fields` on
  `GET /member/profile`) as the future eligibility signal, and
  `tests/Guards/AddMemberTierUnbuiltTest.php` (mirroring the existing
  `AddClubUnmodeledTest` for the tier above this one) keeps the concept
  unmodeled until that decision is made.
- **No notification code is added.** Also investigated per the spec's §0
  instruction to reuse "whatever notification mechanism was used for
  reception/approval notifications in prior phases": no such mechanism
  exists anywhere in this codebase — no `Illuminate\Notifications\
  Notification` subclass, no mail, no reception/approval notification was
  ever built. `App\Domain\Identity\Models\NotificationLog` exists as a
  migration/model but nothing writes to it; it's dormant scaffolding kept
  from an earlier ERD. Since no promotion mechanism exists to notify about
  (previous bullet), this phase has nothing to wire a notification to
  either. Flagged here for whoever eventually builds the ADD Member tier —
  `NotificationLog` and `User`'s existing `Notifiable` trait are the
  pieces already in place for that future work.

## Why

See "Decision" above — each bullet states its own reasoning inline.

## What this changed in code

- `database/migrations/2026_08_17_090000_add_gender_to_user_personal_profiles_table.php`
- `database/migrations/2026_08_17_090001_add_social_links_to_user_professional_profiles_table.php`
- `database/migrations/2026_08_17_090002_create_contact_links_table.php`
- `App\Domain\Identity\Enums\Gender`
- `App\Domain\Identity\Models\{UserPersonalProfile,UserProfessionalProfile}` (fillable/casts)
- `App\Domain\Identity\Services\ProfileCompletionService`
- `App\Domain\Ecosystem\Models\ContactLink`
- `App\Http\Resources\{UserPersonalProfileResource,UserProfessionalProfileResource,ContactLinkResource}`
- `App\Http\Requests\Member\UpdateProfileRequest`, `App\Http\Requests\Admin\{Store,Update}ContactLinkRequest`
- `App\Http\Controllers\Api\V1\Member\ProfileController` (score wiring)
- `App\Http\Controllers\Api\V1\{Admin,Public}\ContactLinkController`
- `routes/api/v1/{admin,public}.php`
- `lang/{en,ar}/api.php` (`admin.contact_link_updated`)
- `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` (extended with the `UserPersonalProfile` entry)
- `tests/Guards/AddMemberTierUnbuiltTest.php` (new)
- `postman/ADD-OS.postman_collection.json` (`Content > Contact Links`, `Public (Site) > Get Contact Links`, updated `Profile` examples)

## Known limitations

- **`sort_order`/`is_visible` are `nullable` in validation but `NOT NULL`
  with no DB default is not quite right either — both columns do have
  `NOT NULL` DB defaults (`0`/`true`), but `ContactLinkController::store()`
  papers over that with `array_merge(['sort_order' => 0, 'is_visible' =>
  true], $request->validated())`. `array_merge` lets a later array's key
  override an earlier one, so an explicitly-sent `null` for either field
  (allowed by the `nullable` rule) survives the merge and reaches
  `ContactLink::create()` as `null`, which then throws a DB-level `NOT NULL
  constraint` error instead of falling back to the intended default. This
  is not new to `ContactLink` — it's the same house pattern already present
  in `FounderController`/`PartnerController`/`CommunityMemberController`/
  `PlanController::store()`, all four also unfixed today. Noted here so a
  future cleanup fixes all five call sites together rather than
  `ContactLink` alone.
- **`ProfileCompletionService`'s weight table treats any non-null value as
  "filled" regardless of length** — a one-character `bio` earns the same
  full 10 points as a real one. This is soft today because nothing consumes
  the score yet (see the "No ADD Member promotion/tier mechanism is built"
  bullet above). Whoever eventually gates a real benefit on this score
  should decide whether minimum-length/quality checks belong in this
  service or a later validation layer.
- **`contact_links.value` is not a pre-sanitized, trustworthy `href`.** The
  `not_regex` scheme denylist added against Finding 2 of this phase's
  review blocks the most dangerous schemes (`javascript:`/`data:`/
  `vbscript:`), but the field is still admin/operations-authored (a broader
  trust circle than `admin` alone) free-form content served unauthenticated
  on `GET /api/v1/contact-links`. Downstream consumers (the marketing site,
  the mobile app, a kiosk client) should keep treating it as untrusted
  user-adjacent content rather than assuming the denylist makes it safe to
  interpolate anywhere.

## Guard

[`EnumColumnsHaveBackedEnumCastsTest`](../../tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php)
covers the `gender` enum cast. [`AddMemberTierUnbuiltTest`](../../tests/Guards/AddMemberTierUnbuiltTest.php)
covers the ADD Member tier staying unmodeled. No dedicated guard exists for
the completion-score weights themselves or for `contact_links.type`
staying an open string — both are covered by `tests/Unit/Domain/Identity/
ProfileCompletionServiceTest.php` and `tests/Feature/Ecosystem/
ContactLinkAdminTest.php` respectively, rather than a source-scanning
guard, since neither is a "this must never exist" invariant.
