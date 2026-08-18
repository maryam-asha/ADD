# Decision register

Two kinds of record live here.

**Conflict resolutions** — where the three source documents (PRD v0.7.1, Master
ERD v2.0, Document 4) disagreed with each other or with the code that existed
before this rebuild. Each file states what each source said, what was
decided, and what enforces it now.

- [structure-reference.md](structure-reference.md) — which document wins on structural disagreement
- [i18n-columns.md](i18n-columns.md) — single JSON translation column, not twin `_ar`/`_en`
- [otp-channel.md](otp-channel.md) — WhatsApp only; MTN/Syriatel reversed
- [money-model.md](money-model.md) — per-price-point currency, `usd_equivalent` non-authoritative
- [access-control-tables.md](access-control-tables.md) — generic `devices`, not split `gateways`/`locks`
- [wallet-subscription-ownership.md](wallet-subscription-ownership.md) — `owner_type` extension on `wallets`/`subscriptions`
- [space-type-and-resource-status.md](space-type-and-resource-status.md) — `space_type` naming (D.7) and operational status on `resources` (D.11)
- [rbac-scoping.md](rbac-scoping.md) — D.8: flat spatie roles kept; company door access via one Policy, not a scope system
- [staff-operations-rename.md](staff-operations-rename.md) — `staff` role renamed to `operations` to match PRD §4 exactly
- [district-removed.md](district-removed.md) — deliberate rollback: `District` dropped from the spatial hierarchy after Phase 1 shipped it
- [phase-3-membership-plan-wallet-mechanics.md](phase-3-membership-plan-wallet-mechanics.md) — base schema for `plans`/`memberships`/`wallets` (unspecified elsewhere), the signature-preserving debit algorithm, and the company-auto-provisioning gap the instructions left unanswered
- [preferred-language-mutable.md](preferred-language-mutable.md) — `preferred_language` reversed from effectively-read-only to member-writable, landed alongside the new `preferred_currency` preference
- [member-auth-hybrid.md](member-auth-hybrid.md) — members sign in with a password; OTP demoted to enrolment and recovery, access+refresh token pair introduced
- [currency-header-conversion-scope.md](currency-header-conversion-scope.md) — `converted_amount`/`converted_currency` are always-present and reach the nested `MembershipResource.data.plan`, not just direct plan-listing responses
- [business-hours-prd-11-partial-reversal.md](business-hours-prd-11-partial-reversal.md) — clarifies that booking-hours (Phase 2) does not reverse PRD #11 (physical access remains 24/7); the guard's docblock scope is stated explicitly, not weakened

**Design docs** — written ahead of the phase that implements them, so the
first migration for that phase is built to the full shape instead of a
minimal version that gets reworked later.

- [wallet-points-categorization.md](wallet-points-categorization.md) — unified category/restriction/expiry model for wallet transactions, for Phase 3
- [settings-key-value-store.md](settings-key-value-store.md) — new `Settings` domain: cached, typed key/value config, the enabler for business hours/booking/guests/profile/module-toggle work
- [business-hours.md](business-hours.md) — per-branch weekly schedule + date exceptions + resolution service; the capability Sprint 3's booking validation will consume
- [reception-operations-scope.md](reception-operations-scope.md) — reception's check-in/check-out/settlement/cancellation/wallet-top-up layer; a deliberate slice of the build plan's Phase 5 (+ a PaymentMethod sliver of Phase 4), not the full thing
- [profile-fields-completion-score-contact-links.md](profile-fields-completion-score-contact-links.md) — gender/social-link profile fields, the derived completion score, `contact_links`, and the confirmed-absent ADD Member tier

**PRD §7.1 decision map** — every locked decision, traced to the guard test
(if one exists yet) or the phase that will add it. "—" means the decision has
no table surface yet and nothing to guard.

| # | PRD §7.1 decision | Guard test | Phase |
|---|---|---|---|
| 1 | Eight-level spatial hierarchy, `Branch` keeps its name | Deviated — seven levels; `District` shipped in Phase 1 then deliberately removed, see [district-removed.md](district-removed.md) | 1 |
| 2 | Floor/Zone are classification only | — (Phase 1) | 1 |
| 3 | Resource is metadata, never booked independently | — (Phase 1) | 1 |
| 4 | Seat/Desk is an address, not a seat map | — (Phase 1) | 1 |
| 5 | Booking = prepaid + cancellable; session without booking = postpaid + not cancellable | `tests/Feature/Booking/*` (reception-ops slice, [reception-operations-scope.md](reception-operations-scope.md)); no dedicated `tests/Guards/` entry yet — full capacity-slot/extension scope still pending | 3 (slice), 5 (full) |
| 6 | Hot Desk package selection creates a real booking, 24h free cancellation | — (Phase 5) | 5 |
| 7 | Meeting rooms auto-confirm; only the event hall is manual | — (Phase 5) | 5 |
| 8 | Operational status at Space/Resource level, no hierarchical escalation; affected-bookings on maintenance conflict | — (Phase 1 status, Phase 5 affected_bookings) | 1, 5 |
| 9 | Guest requests go through the host exclusively | — (Phase 7) | 7 |
| 10 | Every service ticket carries the scanning member's identity | — (Phase 7) | 7 |
| 11 | 24/7 access; no duration cap | [`NoAccessHoursWindowTest`](../../tests/Guards/NoAccessHoursWindowTest.php) | 0 (done) |
| 12 | Gateway + custom codes primary; offline algorithmic is a documented degraded mode | — (Phase 6) | 6 |
| 13 | Locks only on bookable spaces; main door has no lock | — (Phase 6) | 6 |
| 14 | `lock_mac`/`hardware_mac` is the natural key, not the provider's volatile id | — (Phase 6) | 6 |
| 15 | DECIMAL exclusively for money, no FLOAT/DOUBLE | [`MoneyIsDecimalOnlyTest`](../../tests/Guards/MoneyIsDecimalOnlyTest.php) | 0 (done) |
| 16 | Dark mode out of scope | n/a — frontend-only; guarded in ADDOS's `no-runtime-theming.spec.ts` | — |
| 17 | Structural separation of Experience and Ecosystem | [`DomainLayerBoundaryTest`](../../tests/Guards/DomainLayerBoundaryTest.php) | 0 (done), extended per phase |
| 18 | `community_members` carries no FK to `users` | — (Phase 9; today's table already has none) | 9 |
| 19 | No waitlist in v1 | — (nothing to guard; feature does not exist) | — |
| 20 | Network isolation — any external link is a functional defect | [`NetworkIsolationTest`](../../tests/Guards/NetworkIsolationTest.php) | 0 (done) |

§7.2 (deferred by explicit decision) and §7.3 (open, unresolved) items are not
in this table — they get placeholders and, where a table already exists to
misuse, a guard against building on them. See
[`AddClubUnmodeledTest`](../../tests/Guards/AddClubUnmodeledTest.php) for the
one §7.2 item with a guard today.
