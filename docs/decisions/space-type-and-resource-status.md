# Space type naming, and operational status on `resources`

**Status:** resolved 2026-08-08 (non-blocking, raised at Phase 1 as planned). **Owner:** Maryam Asha.

## D.7 — `spaces.space_type` naming

ERD v2.0: `co_space | room | business | event_hall` (matches the existing
migration, which was missing only `event_hall`). Document 4:
`private_office | co_space | meeting_room | event_hall`.

**Decision: ERD v2.0's naming.** Per [structure-reference.md](structure-reference.md),
ERD v2.0 is the default on any structural disagreement; confirmed rather
than silently applied, since it propagates into an enum, the API contract,
and (eventually) the frontend.

## D.11 — operational status on `resources`

PRD §5.6 and decision #8 both say the operational status applies to "the
space **and the resource**" ("على مستوى المساحة والمورد فقط"), but neither
ERD document gives `resources` any of `status` / `status_reason` /
`status_from` / `status_until`.

**Decision: add all four columns to `resources` too**, matching the PRD text
literally — a projector under repair should be markable unavailable without
taking its whole space offline. Same enum (`active | maintenance | retired`),
same semantics as `spaces`. Unlike `spaces`, a `resources` row going
`maintenance` does **not** generate `affected_bookings` (decision #3:
resources are never booked or requested independently in v1, so there is no
booking to affect) — it only removes the resource from whatever read-side
listing shows a space's equipment.

## What this changed in the plan

`resources` in Phase 1 gets the same four operational-status columns as
`spaces`, default `active`.
