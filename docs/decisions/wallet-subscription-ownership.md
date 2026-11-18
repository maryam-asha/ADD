# Wallet/subscription ownership: `owner_type` extends ERD v2.0, doesn't replace it

**Status:** resolved 2026-08-08. **Owner:** Maryam Asha.

## What each source said

- **Master ERD v2.0**: `memberships.user_id` and `wallets.user_id` —
  individual ownership only.
- **Document 4**: `subscriptions` and `wallets` carry
  `owner_type[user|company]` + `owner_id` — polymorphic ownership,
  individual or company.
- Neither model is wrong on its own, but a company holding a private-office
  contract genuinely needs a shared wallet and/or shared package that its
  `company_members` draw from, alongside any individual member's personal
  wallet/plan — which is exactly today's situation for an individual.

## Decision

**Extend ERD v2.0, do not replace it with Document 4's shape.** `wallets`
and `subscriptions` (ERD v2.0's `memberships`) gain `owner_type` (`user` |
`company`) and `owner_id`, polymorphic. Everything else about ERD v2.0's
shape for these tables — column names, the no-rollover rule, the
`is_subscription` distinction between a true subscription and a one-off
Hot Desk package — is unchanged.

This is additive on top of ERD v2.0's existing table names
(`plans`/`memberships`/`wallets`), not a switch to Document 4's alternate
names (`membership_plans`/`subscriptions`) — per
[structure-reference.md](structure-reference.md), ERD v2.0 governs structure
including naming.

## What this changed in code

Nothing yet — `wallets` and `memberships` don't exist before Phase 3. This
record is the binding constraint that migration must follow: `owner_type` +
`owner_id` instead of a bare `user_id`, with `company_user.door_access_enabled`
(Phase 2) as the precedent for how a company-scoped capability is already
expressed without a scoped-roles system.

## Guard

None yet — added in Phase 3 alongside the migration: every `wallets` and
`memberships` row has a non-null `owner_type`/`owner_id` pair, and
`owner_type = 'company'` requires the referenced company to exist and be
`active`.
