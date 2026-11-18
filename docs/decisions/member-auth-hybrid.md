# Member auth: hybrid phone + password, OTP demoted to enrolment

**Status:** resolved 2026-08-10. **Owner:** Maryam Asha.

## What changed

Members used to sign in by requesting a WhatsApp code and posting it back:
`POST /auth/otp/verify` created the account on first use and issued a token on
every use. There was no password anywhere in the member flow, and no refresh
token — `verifyOtp` returned a single non-expiring Sanctum token.

Members now register once with a password and sign in with it from then on.
The code proves control of the phone; it is no longer a credential in its own
right.

| | Before | After |
|---|---|---|
| First sign-up | `otp/request` → `otp/verify` | `register` (whole profile) → `register/verify` (`{phone, code}`) |
| Returning member | `otp/request` → `otp/verify` | `POST /auth/login` with `{phone, password}` |
| Forgotten credential | n/a — there was nothing to forget | `password/forgot` → `password/reset` |
| Session | one Sanctum token, no expiry | access token (60 min) + single-use refresh token (30 days) |

The generic `otp/request` / `otp/verify` pair is gone. It existed because one endpoint served every flow; now each flow has its own, and the endpoint decides its own `OtpPurpose` — the client cannot name one.

## Decisions

**1. The sign-up path is enrolment only; an existing account can never use
it.** Leaving the old path open for accounts that already exist would mean the
password set during registration could always be bypassed by requesting
another code — the weakest credential would define the account's real
security, and the switch would buy nothing. `register` refuses a taken number
with 422; `register/verify` still answers 409 if the number was claimed
between the two steps, which is the only way that branch is now reachable.

**2. Codes carry a `purpose`.** One table now serves two flows that grant
different things: enrolment mints an account, reset overwrites the credential
on an existing one. `otp_verifications.purpose` (`registration` |
`password_reset`) is checked on redemption, and a genuine code presented to
the wrong flow is answered `422` with an explicit message rather than a
generic failure — but only after the code itself is confirmed to match, so an
unknown code can never draw out the more specific answer.

**3. Password policy: minimum 8 characters, no composition rules —
provisional.** Character-class requirements push people toward predictable
substitutions without adding meaningful entropy, and this is a launch-stage
baseline rather than a final position. Raising the minimum later is cheap: the
reset flow already exists to carry members across a change, and the rule lives
in exactly two Form Requests (`VerifyOtpRequest`, `ResetPasswordRequest`).
Recorded here so a future tightening is understood as a planned step rather
than a correction.

**4. Refresh tokens were built, not assumed.** The task this came from
described renewing "the same refresh-token mechanism as the previous session".
No such mechanism existed — `verifyOtp` issued one non-expiring Sanctum token
and nothing else. Rather than skip it, the pair was implemented: access tokens
now expire (`config/tokens.php`), and `refresh_tokens` rows are single-use,
hashed at rest, and linked to the access token they were minted with so
logging out on one device leaves the member's other devices alone.

**5. Login answers "wrong password" and "no such account" identically —
including in timing.** Same status, same body. A missing user or a null
password hash still spends a real `Hash::check` against a throwaway hash, so
the response time doesn't leak what the response body withholds. The
suspended/blocked `403` is only reachable *after* the password matches, so
naming the account state there reveals nothing the caller couldn't already
see.

**6. `password/forgot` always answers 200, and swallows its own throttle.**
Returning `429` on a repeat call would mark the numbers that actually received
a code and leave a plain `200` on the rest — the enumeration the neutral
message exists to prevent. The cooldown still applies; the code just isn't
re-sent.

**7. A completed reset revokes every session, on every device.** Whoever
triggered the reset may already hold the old password, so a session opened
under it must not outlive it. The reset issues no token of its own — the
member signs in with the password they just chose, which doubles as
confirmation they typed it as intended.

**8. Accounts predating this change get no data migration.** Confirmed with
the owner: there are no member accounts worth preserving. A `NULL` password
fails closed at login (generic `401`); had any existed, `forgot` → `reset`
was the intended path and still works for them unchanged.

**9. Sign-up validates everything up front, and accepts that this makes phone
numbers enumerable.** The first shape of this change put the whole profile on
the verify call, so "that email is taken" or "this number already has an
account" only surfaced after the member had waited for a WhatsApp code and
typed it in. That was the worst property of the design and the reason sign-up
is now two endpoints: `register` checks the entire profile — including that
the phone and email are free — *before* a code is spent.

The cost is real and was weighed rather than overlooked. Decisions 5 and 6
above go to some length to keep login and recovery from revealing whether a
number has an account; `register` now reveals exactly that, to anyone, in one
call. It is bounded by the route's 10/min per-IP throttle and nothing else.

Accepted because registration cannot avoid it without lying: any sign-up flow
must eventually refuse a duplicate, so the only question is whether the member
finds out before or after a pointless OTP round trip. Login and recovery —
the flows an attacker actually wants — keep their neutral answers, so what
leaks here is account *existence*, never anything about the credential.

One shape was rejected: keeping the phone check late while validating the rest
early would have preserved the non-enumeration property, but the most common
failure — the number is already registered — is precisely the one it would have
left slow.

**10. The validated profile is parked server-side, so step two carries only
`{phone, code}`.** The first cut of §9 had step one store nothing, which meant
step two had to receive the whole profile a second time. That was rejected at
first as an extra table not worth its keep, and reversed once the flow was
actually used: re-sending name, email and password to complete a sign-up is
friction with nothing to show for it, and it puts the password on the wire
twice instead of once.

`pending_registrations` is keyed by phone, holds the password already hashed,
and expires at the same moment as the code it was issued with — the two are
halves of one intent and worthless apart. Running step one again overwrites the
row, which is how a typo gets corrected. The row is deleted in the same
transaction that creates the account, so "pending" and "registered" can never
both be true. `model:prune` sweeps expired rows hourly
(`routes/console.php`); abandoned sign-ups are the normal case, not the
exception, and without the sweep this table would accumulate unverified names
and email addresses indefinitely.

`email` is deliberately **not** unique on this table. The uniqueness that
matters is on `users.email`, checked at both steps; enforcing it here as well
would let anyone park an address they don't own and lock the real owner out of
ever signing up with it — an easier denial than the one it would prevent.

**11. The member app must not be a way to reach operations, and one check is
not enough.** Found by probing rather than by reading: an `operations` account
with a second factor enabled on the dashboard could sign in at
`POST /auth/login` with nothing but its phone and password, receive a token with
`['*']` abilities, and reach `GET /api/v1/admin/founders`. The member surface
has no second factor, so it had become a 2FA-free side door — the weaker door
deciding how well the stronger one was protected. `password/forgot` was the same
hole in reverse: `password` is one column shared with Fortify, so a reset driven
from the app would rewrite an operator's dashboard credential, reachable by
whoever controls that operator's phone.

Two independent layers now enforce the boundary, because either alone leaves a
gap:

- **The member auth endpoints serve accounts holding the `member` role only.**
  Login refuses anything else with the *same* rejection as a wrong password —
  "you are staff, use the dashboard" would tell an attacker which numbers belong
  to operators, precisely the accounts worth targeting. `password/forgot` keeps
  its neutral 200 and simply sends nothing. This closes the 2FA bypass.
- **Tokens minted for the app carry the ability `member-app`, not `['*']`, and
  the admin group demands `dashboard`.** This is the layer that still holds when
  the same person is *legitimately* both a member and an operator — a case
  confirmed as something to support. Their roles really are theirs, so a role
  check passes; what the app's token must not be is a credential for the other
  surface. The ability answers "which surface issued this?", which is a
  different question from "what may its owner do?", and the two need separate
  answers.

The dashboard is unaffected: it authenticates by session, and Sanctum gives a
session-authenticated user a `TransientToken` that satisfies every ability
check. Verified both ways — an app token gets 403 on `/api/v1/admin/*` while an
operator's session still gets 200.

Rejected: relying on the `role:` middleware alone. It works today only because
every admin route remembers to carry it, which makes the isolation a convention
rather than a property. The ability check sits on the group, so a route added
later inherits it without anyone having to remember.

## What was given up

Signing in stops being possible from a phone alone — a member who forgets
their password now needs a working WhatsApp delivery to get back in, where
before the code *was* the login and there was nothing to forget. That is the
cost of not having the weakest credential define the account's security, and
the reset flow is the mitigation.

Access tokens now expire, so any client holding one indefinitely will start
getting `401`s at the one-hour mark and must call `/auth/refresh`. This is a
breaking change for consumers written against the old single-token response:
the key is `access_token`, not `token`, and `auth/otp/request` /
`auth/otp/verify` no longer exist at all — they are `auth/register` and
`auth/register/verify`.

## What this changed in code

- `database/migrations/2026_08_10_090000_add_purpose_to_otp_verifications_table.php`
  — `string` + backed enum cast, defaulted so pre-existing rows backfill as
  `registration`.
- `database/migrations/2026_08_10_090100_create_refresh_tokens_table.php`.
- `app/Services/Otp/OtpService.php` — `request()`/`verify()` take an
  `OtpPurpose`, with no default on `request()`: every caller is a
  purpose-specific endpoint now, so there is no case where guessing would be
  right. `verify()` returns `OtpResult` instead of `bool`. The resend cooldown
  is keyed per purpose, so needing a reset isn't blocked by an enrolment code
  requested moments earlier.
- `app/Http/Requests/Auth/` — `RequestOtpRequest`/`VerifyOtpRequest` are gone.
  `RegisterRequest` carries the whole profile (§9); `RegisterVerifyRequest` is
  `{phone, code}` (§10). Neither accepts a `purpose` field — that was only ever
  needed while one endpoint served both flows.
- `database/migrations/2026_08_10_090200_create_pending_registrations_table.php`
  and `app/Domain/Identity/Models/PendingRegistration.php` — §10, with
  `MassPrunable` and an hourly `model:prune` in `routes/console.php`.
- `app/Services/Auth/TokenPairService.php` — `MEMBER_APP_ABILITY`; tokens are
  minted with it instead of `['*']` (§11).
- `routes/api.php` — the admin group gained `abilities:dashboard`, aliased in
  `bootstrap/app.php` (Sanctum ships `CheckAbilities` but registers no alias
  under Laravel 11+).
- `MemberAuthController::logout()` — fixed a pre-existing 500. `auth:sanctum`
  accepts a session cookie on every route it guards, including this one, and
  Sanctum represents such a caller with a `TransientToken` that is not a token
  row: the old `currentAccessToken()->delete()` raised
  `BadMethodCallException`, and the refactor to `revokeSession()` turned it into
  a `TypeError` without fixing it. Logout now ends whichever credential the
  caller arrived with — answering 200 while leaving a live cookie behind would
  be the more dangerous of the two lies. Covered by
  [`SessionUserOnMemberRoutesTest`](../../tests/Feature/Identity/SessionUserOnMemberRoutesTest.php).
- `app/Services/Auth/TokenPairService.php` — the single place a session is
  minted, rotated or ended. Registration, login and refresh all go through
  `issue()`.
- `app/Domain/Identity/Models/User.php` — `deactivate()`/`block()` revoke
  refresh tokens alongside deleting access tokens. They are spendable without
  an access token, so deleting only the latter would have left a blocked
  account one `/auth/refresh` call from a working session.
- `app/Http/Controllers/Api/V1/Auth/MemberPasswordController.php` — new;
  member recovery, separate from Fortify's email reset for the dashboard.
- `AppServiceProvider` — `member-login` named rate limiter, 5/minute keyed on
  phone+IP.

There is no `password_hash` column and never was: the users table has used
Laravel's `password` (nullable, `hashed` cast) since `0001_01_01_000000`, and
no comment anywhere scoped it to staff or admin. The task's instruction to
correct such a comment had nothing to act on.

## Tests

No dedicated guard test — the behaviour is covered directly, and a guard here
would restate the feature tests rather than protect a decision they can't see.

- [`MemberRegistrationTest`](../../tests/Feature/Identity/MemberRegistrationTest.php)
  — enrolment, the 409, password rules, purpose mismatch.
- [`MemberLoginTest`](../../tests/Feature/Identity/MemberLoginTest.php) —
  indistinguishable failures, `403` for inactive accounts, the 5/min throttle.
- [`PasswordResetTest`](../../tests/Feature/Identity/PasswordResetTest.php) —
  the neutral `forgot`, and revocation proven by replaying a pre-reset token
  and getting `401`.
- [`RefreshTokenTest`](../../tests/Feature/Identity/RefreshTokenTest.php) —
  single-use rotation, per-device logout, revocation on deactivate/block.
- [`OtpPurposeTest`](../../tests/Feature/Identity/OtpPurposeTest.php) — the
  purpose split at the service level.
- [`MemberOperationsIsolationTest`](../../tests/Feature/Identity/MemberOperationsIsolationTest.php)
  — §11: operators refused at the member surface in wording identical to a wrong
  password, a dual-role member's app token refused by `/admin/*`, and the
  dashboard's session path still reaching it.

## Still uncovered

No test exercises the dashboard's **real** credential path. Every admin and
operations test authenticates with `Sanctum::actingAs()` or `$this->actingAs()`,
both of which inject the user directly and skip credential resolution entirely —
so `statefulApi()`, the `sanctum.stateful` domain list, CORS and the XSRF header
are verified by nothing but manual use. Worth closing, and unrelated to this
change: it predates it.
