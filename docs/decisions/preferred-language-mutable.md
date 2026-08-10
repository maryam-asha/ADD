# Member-Writable preferred_language

**Status:** resolved 2026-08-09
**Owner:** Maryam Asha

## What shipped

`preferred_language` was set once, hardcoded to `'ar'`, at first-time OTP
signup (`MemberAuthController::verifyOtp()`), and had no member-facing
write path at all — only readable via `GET /auth/me`.

## Decision

**`preferred_language` is now member-writable at any time, via
`PATCH /api/v1/member/preferences/language`.** This is a reversal of the
prior effectively-read-only behavior, not new scope — the column already
existed.

## Why

Landed alongside the new `preferred_currency` preference (display
currency), which needed the same "member can change this whenever they
like" mechanism. Since both preferences are conceptually symmetric,
`preferred_language` gained the same write path rather than staying
inconsistent with the new field next to it.

## What this changed in code

- New route: `PATCH /api/v1/member/preferences/language` →
  `Member\PreferencesController::updateLanguage`.
- New `App\Http\Requests\Member\UpdateLanguagePreferenceRequest`
  (`in:ar,en`).
- `MemberAuthController::verifyOtp()` is unchanged — the hardcoded `'ar'`
  default at signup still applies; only the *subsequent* mutability
  changed.

## Guard

None yet — this is a permissive change (a previously-closed write path is
now open), not a new constraint to enforce. If a future decision wants to
restrict which values are allowed, that's the place for a guard test.
