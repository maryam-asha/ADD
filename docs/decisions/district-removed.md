# District removed from the spatial hierarchy

**Status:** resolved 2026-08-08, after extended discussion. **Owner:** Maryam Asha.
**Type:** deliberate rollback of shipped Phase 1 work — not a correction of a
mistake, and not an oversight left undocumented.

## What shipped in Phase 1

PRD decision #1 names an eight-level hierarchy: `District ← Branch ← Building
← Floor ← Zone ← Space ← Resource ← Seat/Desk`. Phase 1 built exactly that —
a `districts` table, one seeded row, and a nullable `branches.district_id`
meant to become meaningful "once a second branch opens."

## Decision

**`District` is removed entirely.** The hierarchy is now seven levels:
`Branch ← Building ← Floor ← Zone ← Space ← Resource ← Seat/Desk`. `Branch`
is the top level. "Aleppo Digital District" remains the product/brand name
— a config value or static string, never a database table.

## Why

`District` was designed as a permanent single-row umbrella — a
brand/organizational concept, not an operational one. `Branch` already
carries its own name and geography and is itself the unit that scales:
opening in a new city or region is a new `Branch` row, and a branch's name
and city are already sufficient to distinguish it. `District` added a
foreign key with no filtering, scoping, or query capability that `Branch`
didn't already provide on its own — a decorative join, not a structural
one.

## What this changes about PRD decision #1

This is a **conscious departure from decision #1's literal "eight levels,"
not an inference during implementation** (the distinction PRD §9 itself
draws). The product owner made this call after the fact, with full
authority to amend a decision from her own PRD. The other four sub-clauses
of decision #1 — `Branch` keeps its name, `Floor`/`Zone` are classification
only, `Resource` is metadata, `Seat/Desk` is an address — are unaffected.

## What this changed in code

- New migration `2026_08_08_110000_drop_districts_and_branches_district_id.php`
  reverses the two Phase 1 migrations that introduced `District` — written
  as a new migration, not an edit to the original two, since those may
  already have run elsewhere.
- `App\Domain\Foundation\Models\District` and `DistrictFactory` deleted.
- `Branch::district()` relationship, `district_id` fillable, and the
  `District` import removed from `Branch` and `BranchFactory`.
- `SpatialHierarchyTest` — removed the District-specific test and the
  assertion that a branch resolves to a `District` instance; the "full
  hierarchy" test now asserts seven levels, not eight.

## Guard

No dedicated guard test — `District` not existing at all is what
[`SpatialHierarchyTest`](../../tests/Feature/Foundation/SpatialHierarchyTest.php)
now exercises by construction (it builds the hierarchy from `Branch` up and
never references a `District`). Reintroducing the table would show up as a
new migration and a new model, not a silent regression a schema-shape guard
would need to catch.
