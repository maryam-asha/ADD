# RBAC scoping (D.8): flat spatie roles, no scope_type/scope_id system

**Status:** resolved 2026-08-08. **Owner:** Maryam Asha.

## What each source said

- **ERD v2.0**: keeps flat spatie roles — no scoping mechanism.
- **Document 4**: adds `user_roles.scope_type[global|company]` + `scope_id`,
  to express a "Company Member" role scoped to one specific company.
- The one real capability that needs scoping: a member's ability to use
  their employer's shared company door code — per-member, per-company, not
  global.

## Decision

**Flat spatie roles stay exactly as they are — `member`, `operations`,
`admin`. No general-purpose scoped-role system is built.** The one scoped
capability is expressed narrowly:

- `company_user` (the pivot between `users` and `companies`) carries
  `door_access_enabled: bool`.
- `CompanyPolicy::useDoorAccess(User $user, Company $company): bool` checks
  two things — is this user an actual member of *this* company (a
  `company_user` row exists), and is the flag on for that row. Nothing else
  reads or writes a "scope."

A company member is still just a `member` role-wise. Their extra
capability (using the shared door code) is a fact about one row in one
pivot table, checked by one Policy method — not a second dimension on the
role system.

## Why

Document 4's `scope_type`/`scope_id` design is a general mechanism built
for a single, narrow need. Every future "is this scoped correctly" question
would have to go through a generic, harder-to-audit path instead of a
one-line pivot check. If a second genuinely scoped capability shows up
later, it gets evaluated on its own — this decision does not pre-approve a
general scoping system for whatever comes next.

## What this changed in code

- `company_user` migration: `user_id`, `company_id`, `door_access_enabled`,
  plus its own `id` (for clean audit-log targeting) and a unique index on
  `(company_id, user_id)`.
- `App\Domain\Identity\Policies\CompanyPolicy` — the only Policy class in
  the app. No `scope_type`/`scope_id` column exists anywhere.

## Guard

[`RbacStaysFlatTest`](../../tests/Guards/RbacStaysFlatTest.php) asserts no
`scope_type`/`scope_id` column exists on `roles`, `permissions`,
`model_has_roles`, or `company_user`, and that `CompanyPolicy` remains the
only class under `app/Domain/**/Policies`.
