# RBAC scoping (D.8): flat spatie roles, no scope_type/scope_id system

**Status:** resolved 2026-08-08. **Owner:** Maryam Asha.

## What each source said

- **ERD v2.0**: keeps flat spatie roles — no scoping mechanism.
- **Document 4**: adds `user_roles.scope_type[global|company]` + `scope_id`,
  to express a "Company Member" role scoped to one specific company.
- The real capabilities that need scoping: a member's ability to use
  their employer's shared company door code, and (added later, same
  mechanism) a company admin's ability to manage other members of that
  same company — both per-member, per-company, not global.

## Decision

**Flat spatie roles stay exactly as they are — `member`, `operations`,
`admin`. No general-purpose scoped-role system is built.** Each scoped
capability is expressed narrowly, as its own boolean column plus its own
Policy method — not a second dimension on the role system:

- `company_user` (the pivot between `users` and `companies`) carries
  `door_access_enabled: bool` and `is_admin: bool`.
- `CompanyPolicy::useDoorAccess(User $user, Company $company): bool` checks
  two things — is this user an actual member of *this* company (a
  `company_user` row exists), and is `door_access_enabled` on for that row.
- `CompanyPolicy::manageMembers(User $user, Company $company): bool` — same
  shape, checking `is_admin` instead. A company admin can change another
  member's `door_access_enabled` or `is_admin`; a regular member cannot,
  not even for themselves. Operations/admin bypass both checks
  unconditionally via the existing `Gate::before`, and remain the only way
  a company gets its first admin (`StoreCompanyMemberRequest` accepts
  `is_admin` at creation) — a company admin can only grant `is_admin` to an
  *existing* member, so nothing can bootstrap the first one.

A company member is still just a `member` role-wise. Both extra
capabilities are a fact about one row in one pivot table, each checked by
one Policy method — not a second dimension on the role system.

## Why

Document 4's `scope_type`/`scope_id` design is a general mechanism built
for a single, narrow need. Every future "is this scoped correctly" question
would have to go through a generic, harder-to-audit path instead of a
one-line pivot check. `is_admin` is exactly the "second genuinely scoped
capability" this decision said would be evaluated on its own — it was, and
it fit the same one-column-plus-one-Policy-method shape without needing a
general scoping system. A third capability gets the same evaluation, not
an assumption that this pattern is now a standing mechanism to reuse
automatically.

## What this changed in code

- `company_user` migration: `user_id`, `company_id`, `door_access_enabled`,
  `is_admin` (added in a later migration), plus its own `id` (for clean
  audit-log targeting) and a unique index on `(company_id, user_id)`.
- `App\Domain\Identity\Policies\CompanyPolicy` — the only Policy class in
  the app, now with two methods. No `scope_type`/`scope_id` column exists
  anywhere.
- `Company::members()` / `User::companies()` — both relationships must list
  every pivot column in `withPivot(...)`; a column added to the migration
  and the `CompanyUser` model but not to `withPivot` silently reads as
  `null` off a re-fetched pivot, even though the underlying `wherePivot()`
  query-builder filters (used by both Policy methods) are unaffected —
  they operate on the SQL column directly, not the hydrated attribute.

## Guard

[`RbacStaysFlatTest`](../../tests/Guards/RbacStaysFlatTest.php) asserts no
`scope_type`/`scope_id` column exists on `roles`, `permissions`,
`model_has_roles`, or `company_user`, and that `CompanyPolicy` remains the
only class under `app/Domain/**/Policies`.
