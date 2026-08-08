# Structure reference: which document wins

**Status:** resolved 2026-08-08. **Owner:** Maryam Asha.

## What each source said

- **Document 4** states about itself that it was rewritten from the PRD alone,
  explicitly *without* access to ERD v1.0 — the schema the current codebase
  was actually built from.
- **Master ERD v2.0** presents itself as a diff over that same ERD v1.0, so
  it inherits the current code's actual table/column names and only layers
  the v0.7.1 changes on top.
- The two disagree on roughly ten structural points: i18n columns, the OTP
  channel, the money model, the access-control table shape, RBAC scoping,
  and others — see the backend build plan (§D) for the full list surfaced at
  the time.

## Decision

**ERD v2.0 governs structure.** Document 4 is used only to trace a table
back to the PRD §7.1 decision number that justifies it — its per-table
"القرار #N" annotations are a better index into the PRD than anything in
ERD v2.0, but its schema itself is not authoritative where the two disagree.
**PRD v0.7.1 governs behaviour** (business rules, workflows, what a
button does) in all cases — neither ERD document overrides it there.

Any further structural disagreement between the two ERD documents,
discovered later, is resolved the same way by default: ERD v2.0 wins,
without needing to re-raise it.

## What this changed in code

Nothing directly — this is the precedence rule the other five decision
records in this folder apply. Where ERD v2.0 already matched the existing
code (e.g. `devices` + `device_capabilities`, see
[access-control-tables.md](access-control-tables.md)), no change was needed.
