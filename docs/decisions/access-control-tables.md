# Access-control tables: generic `devices`, not split `gateways`/`locks`

**Status:** resolved 2026-08-08. **Owner:** Maryam Asha.

## What each source said

- **Master ERD v2.0**: keeps the existing `devices` + `device_capabilities`
  design — one entity for every device type, so a future device type needs
  no new table — and extends it with `hardware_mac` (natural key, decision
  #14) and `parent_device_id` (links a lock to its gateway), plus a new
  `gateway` value in the `type` enum.
- **Document 4**: replaces this with two separate tables, `gateways` and
  `locks`.
- **Existing code**: `create_devices_table.php` and `create_device_capabilities_table.php`
  already match ERD v2.0's shape — including a `type` enum that already has
  room for `gateway` conceptually (currently
  `lock|gateway|camera|gate|printer|display|occupancy_sensor|attendance_terminal`
  once `gateway` is added in Phase 6).

## Decision

**ERD v2.0's shape is adopted, per [structure-reference.md](structure-reference.md)
— which in this case means no structural change at all.** `devices` +
`device_capabilities` continue exactly as designed; Phase 6 only adds
`hardware_mac`, `parent_device_id`, and the `gateway` type value, none of
which requires a new table.

## What this changed in code

Nothing. This record exists so the choice is documented rather than
silently assumed — a future reader comparing Document 4 to the running
schema should find the reasoning here instead of re-deriving it.

## Guard

None needed — there is no `gateways` or `locks` table to guard against
reintroducing; Phase 6 is where `hardware_mac` uniqueness and the
lock-deletion-requires-proximity rule (decision #14) get their own guards.
