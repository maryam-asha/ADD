# `staff` role renamed to `operations`

**Status:** resolved 2026-08-08 (discovered while implementing Phase 2). **Owner:** Maryam Asha.

## What was found

The existing `staff` spatie role — seeded by `RoleSeeder`, gating every route in
`routes/api/v1/admin.php` via `role:admin|staff` except the narrower
`role:admin` group for user/role management — has no permission boundary
that differs from PRD §4's "موظف تشغيل (Operations)" role. Both are: every
admin-dashboard capability except creating or promoting accounts.

## Decision

Renamed `staff` → `operations` throughout: `RoleSeeder`, the
`role:admin|staff` middleware in `routes/api.php`, the matching comment in
`admin.php`, and the `Rule::in([...])` lists in `StoreUserRequest` and
`AssignRoleRequest`. Also updated the two agent definitions
(`.claude/agents/code-reviewer.md`, `.claude/agents/laravel-backend.md`) and
`CLAUDE.md` itself, which all stated the old role name as fact.

## Why

Every future PR touching "who can do X" would otherwise have to translate
mentally between `staff` (code) and `Operations` (PRD) forever. This early —
no production data, no frontend built against the role name yet — the
rename is a same-behavior, mechanical change. Later, after AddDashboard
integrates against these role strings, it would not be.

## What this changed in code

Purely a rename; no permission boundary changed. `role:admin|operations` on
the route group, `operations` in the seeder and both Form Requests.
