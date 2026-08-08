# i18n columns: single JSON column, not twin `_ar`/`_en`

**Status:** resolved 2026-08-08. **Owner:** Maryam Asha.

## What each source said

- **Existing code** (`CLAUDE.md`, `HasTranslations`, and every translatable
  column on `branches`, `events`, `community_members`, `partners`,
  `founders`): one JSON column cast to `array`, shaped
  `{"en": "...", "ar": "..."}`. `HasTranslations::translate()` reads
  current-locale-or-fallback out of that array.
- **Both new documents** (ERD v2.0 and Document 4) specify twin columns —
  `name_ar` / `name_en` — on every translatable field, on every table, old
  and new alike.

This affects nearly every table with user-visible text, so it could not be
deferred to whichever phase happened to touch a given table first.

## Decision

**Keep the single JSON column, on every table — old and new, no exceptions.**
`HasTranslations` stays as the one mechanism for reading it. New migrations
follow the same pattern as `create_branches_table.php`
(`$table->json('name');`), not `name_ar`/`name_en`.

## Why

This is the more mature, already-battle-tested pattern already running in
production code, and switching now would mean rewriting every existing
migration, model, resource, and Form Request that touches a translatable
field for no behavioural gain — the two shapes are equivalent in what a
client can request and receive.

## What this changed in code

Nothing — this is a confirmation that the two ERD documents' `name_ar`/
`name_en` convention is not adopted, not a migration away from anything.
Every new table built from Phase 1 onward uses `json` columns for
translatable fields.

## Guard

None yet — this is a convention, not a structural invariant with a single
check. Revisit if drift shows up (e.g. a future migration adding `_ar`/`_en`
columns instead of `json`).
