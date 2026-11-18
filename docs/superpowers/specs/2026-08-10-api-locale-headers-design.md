# API-wide locale via `lang` header

## Problem

Every JSON message the API returns — success, validation, business-rule
rejection, rate limiting, generic HTTP errors — is currently a hardcoded
English (occasionally Arabic) literal scattered across controllers, a
service-provider rate limiter, and one Form Request. There is no
`lang/` directory, no exception-message localization, and no
per-request language signal at all. Two client apps (member, dashboard)
need to render every message in the caller's language without the backend
guessing from `Accept-Language` or anything else implicit.

## Goals

- A `lang: ar|en` request header (case-insensitive) decides the language
  of every message in that response.
- Falls back silently (never a 4xx of its own making) to the authenticated
  user's `users.preferred_language`, then to `'ar'`, when the header is
  absent or invalid.
- Every literal message currently in the code moves to a translation key.
- Validation, rate-limit, and generic HTTP error messages are localized
  too, not just the ones controllers construct by hand.
- Extension only: no new auth flow, no new response envelope shape, no
  restructuring of existing controllers beyond swapping literals for
  `__()` calls.

## Non-goals

- `Booking`, `AccessPasscode`, `ServiceTicket`, `CashRegister` domains
  don't exist in this codebase yet (confirmed by grep) — nothing to
  migrate there. Future work in those domains should just follow the
  `__('api.<domain>.<key>')` convention this establishes.
- No third locale. `SyrianPhoneNumber` and other rule classes are
  unaffected — this is about response text, not validation logic.

## 1. Locale resolution — middleware + listener

A single middleware **cannot** implement the full fallback chain
(header → `preferred_language` → `'ar'`) correctly. `EnsureAuthenticatedUserIsActive`'s
own docblock documents — empirically confirmed, not theoretical — that a
middleware prepended to a middleware group runs *before* `auth:sanctum`'s
route middleware resolves `$request->user()`, so any middleware-time
check of "is this request authenticated" sees `null` even on a request
that goes on to authenticate correctly. `SetLocaleFromHeader` would hit
that exact trap if it tried to read the authenticated user itself. The
fix reuses the same event-based escape hatch that class already
established for this codebase:

**`app/Http/Middleware/SetLocaleFromHeader.php`** (new) — handles only
the header, setting a provisional locale immediately:

```php
final class SetLocaleFromHeader
{
    public const SUPPORTED_LOCALES = ['ar', 'en'];

    public function handle(Request $request, Closure $next): mixed
    {
        $header = strtolower((string) $request->header('lang'));

        App::setLocale(in_array($header, self::SUPPORTED_LOCALES, true) ? $header : 'ar');

        return $next($request);
    }
}
```

Registered in `bootstrap/app.php`:

```php
$middleware->api(prepend: SetLocaleFromHeader::class);
```

Scoped to the `api` middleware group (covers `routes/api.php` and
everything it `require`s: `auth.php`, `public.php`, `admin.php`,
`member.php`) rather than the global stack, since this app's `web.php`
is unused (JSON-only API). No route is exempted, including
`auth.login`/`auth.register` — matches the requirement that no endpoint
opts out.

**`app/Listeners/SetLocaleFromUserPreference.php`** (new) — listens to
the same two events `EnsureAuthenticatedUserIsActive` already listens
to (`TokenAuthenticated` for the member API, `Authenticated` for
Fortify's session guard), which fire at the exact moment a guard
resolves a user — after `SetLocaleFromHeader` already ran, since that
resolution happens inside `auth:sanctum`'s own route middleware:

```php
final class SetLocaleFromUserPreference
{
    public function handle(TokenAuthenticated|Authenticated $event): void
    {
        $header = strtolower((string) request()->header('lang'));

        if (in_array($header, SetLocaleFromHeader::SUPPORTED_LOCALES, true)) {
            return; // a valid header always wins — never overridden here
        }

        $user = $event instanceof TokenAuthenticated ? $event->token->tokenable : $event->user;

        if ($user instanceof User) {
            App::setLocale($user->preferred_language);
        }
    }
}
```

Registered in `AppServiceProvider::boot()` right alongside the existing
listener registration:

```php
Event::listen(TokenAuthenticated::class, SetLocaleFromUserPreference::class);
Event::listen(Authenticated::class, SetLocaleFromUserPreference::class);
```

Net effect: `SetLocaleFromHeader` sets `'ar'` as a provisional default
whenever the header is missing/invalid; if the request goes on to
authenticate, this listener immediately corrects that provisional value
to the resolved user's `preferred_language`. An unauthenticated request
keeps the provisional `'ar'`, matching the required fallback chain
exactly. A valid header is never touched by the listener, so it always
wins regardless of authentication.

## 2. Translation files

**`lang/{ar,en}/api.php`** — new, nested by domain:

```
auth.otp_request_throttled
auth.otp_sent
auth.registration_code_invalid
auth.account_already_exists
auth.phone_already_registered
auth.code_purpose_mismatch_reset
auth.code_purpose_mismatch_registration
auth.code_invalid
auth.account_inactive
auth.invalid_credentials
auth.refresh_token_invalid
auth.logged_out
auth.password_reset_code_sent
auth.password_updated
auth.too_many_attempts
auth.unauthenticated
auth.forbidden
wallet.insufficient_balance
system.not_found
system.server_error
validation.failed
```

Wording (simplest/clearest, per instruction — final copy lives in the
file, this is the source of truth once written):

| key | en | ar |
|---|---|---|
| auth.otp_request_throttled | Too many requests. Please wait before requesting a new code. | طلبات كثيرة. الرجاء الانتظار قبل طلب رمز جديد. |
| auth.otp_sent | Verification code sent. | تم إرسال رمز التحقق. |
| auth.registration_code_invalid | Invalid or expired code. Please start signing up again. | الرمز غير صحيح أو منتهي الصلاحية. الرجاء إعادة التسجيل. |
| auth.account_already_exists | An account already exists for this number or email. Please log in instead. | يوجد حساب مسجّل بهذا الرقم أو البريد الإلكتروني مسبقاً. الرجاء تسجيل الدخول. |
| auth.phone_already_registered | This number already has an account. Please log in instead. | هذا الرقم مسجّل بحساب مسبقاً. الرجاء تسجيل الدخول. |
| auth.code_purpose_mismatch_reset | That code was issued to reset a password, not to create an account. | هذا الرمز مخصّص لإعادة تعيين كلمة المرور، لا لإنشاء حساب. |
| auth.code_purpose_mismatch_registration | That code was issued to create an account, not to reset a password. | هذا الرمز مخصّص لإنشاء حساب، لا لإعادة تعيين كلمة المرور. |
| auth.code_invalid | Invalid or expired code. | الرمز غير صحيح أو منتهي الصلاحية. |
| auth.account_inactive | This account has been suspended. Please contact ADD. | هذا الحساب معلّق. الرجاء التواصل مع ADD. |
| auth.invalid_credentials | These credentials do not match our records. | بيانات الدخول غير مطابقة لسجلاتنا. |
| auth.refresh_token_invalid | Invalid or expired refresh token. | رمز التحديث غير صحيح أو منتهي الصلاحية. |
| auth.logged_out | Logged out. | تم تسجيل الخروج. |
| auth.password_reset_code_sent | If that number has an account, a reset code has been sent to it. | إذا كان هذا الرقم مرتبطاً بحساب، فسيتم إرسال رمز إعادة التعيين إليه. |
| auth.password_updated | Password updated. Please log in with your new password. | تم تحديث كلمة المرور. الرجاء تسجيل الدخول بكلمة المرور الجديدة. |
| auth.too_many_attempts | Too many attempts. Please wait before trying again. | محاولات كثيرة جداً. الرجاء الانتظار قبل المحاولة مرة أخرى. |
| auth.unauthenticated | Unauthenticated. | غير مصادَق. |
| auth.forbidden | This action is unauthorized. | غير مخوّل بتنفيذ هذا الإجراء. |
| wallet.insufficient_balance | Insufficient general balance to allocate this amount. | الرصيد العام غير كافٍ لتخصيص هذا المبلغ. |
| system.not_found | The requested resource was not found. | المورد المطلوب غير موجود. |
| system.server_error | An unexpected error occurred. Please try again later. | حدث خطأ غير متوقع. الرجاء المحاولة لاحقاً. |
| validation.failed | The given data is invalid. | البيانات المُرسلة غير صالحة. |

`auth.forbidden` is reachable today via `Gate::authorize('manageMembers',
$company)` in `CompanyWalletAllocationController` — a policy denial
throws `AuthorizationException`, which Laravel renders as 403 with the
default English "This action is unauthorized." unless we intercept it
(rule 4 in §4 below).

**`lang/en/validation.php`** — published via
`php artisan lang:publish` (Laravel 12 ships none by default).

**`lang/ar/validation.php`** — hand-translated line-by-line
from that same published file: same keys, same structure, same
placeholders (`:attribute`, `:min`, `:max`, `:values`, ...), covering
every stock rule Laravel ships (not just the ones this app currently
uses), so the file doesn't need revisiting when a new rule is added
later.

## 3. Literal-string migration (complete audit)

| file | current literal(s) | → key |
|---|---|---|
| `MemberAuthController::register()` | "Too many requests..." | `auth.otp_request_throttled` |
| | "Verification code sent." | `auth.otp_sent` |
| `MemberAuthController::verifyRegistration()` | "Invalid or expired code. Please start signing up again." | `auth.registration_code_invalid` |
| | "An account already exists..." | `auth.account_already_exists` |
| | "That code was issued to reset a password..." | `auth.code_purpose_mismatch_reset` |
| | "Invalid or expired code." | `auth.code_invalid` |
| `MemberAuthController::login()` | "This account has been suspended..." | `auth.account_inactive` |
| | "These credentials do not match our records." | `auth.invalid_credentials` |
| `MemberAuthController::refresh()` | "Invalid or expired refresh token." | `auth.refresh_token_invalid` |
| `MemberAuthController::logout()` | "Logged out." | `auth.logged_out` |
| `MemberPasswordController::forgot()` | "If that number has an account..." | `auth.password_reset_code_sent` |
| `MemberPasswordController::reset()` | "Invalid or expired code." (×2) | `auth.code_invalid` |
| | "That code was issued to create an account..." | `auth.code_purpose_mismatch_registration` |
| | "Password updated..." | `auth.password_updated` |
| `RegisterRequest::messages()` | "This number already has an account..." | `auth.phone_already_registered` |
| `AppServiceProvider::registerLoginRateLimiter()` | "Too many login attempts..." | `auth.too_many_attempts` |
| `EnsureAuthenticatedUserIsActive` | `abort(403, 'This account has been suspended.')` | `abort(403, __('auth.account_inactive'))` — kept as a specific abort rather than routed through the generic 403 handler (decision confirmed with user) |
| `CompanyWalletAllocationController::store()` | "Insufficient general balance..." | `wallet.insufficient_balance` |

After this pass, no `response()->json(['message' => '<literal>'])` or
`throw new ...Exception('<literal>')` remains anywhere under `app/`.

## 4. Exception handling (`bootstrap/app.php` → `withExceptions`)

Extends the existing closure (no new `Handler` class — this Laravel
version has none, and none should be introduced). Order matters; most
specific first:

1. `ValidationException` → `{message: __('api.validation.failed'), errors: $e->errors()}`. Per-field text already comes from `validation.php` via Laravel's own mechanism — untouched.
2. `ThrottleRequestsException` → `{message: __('api.auth.too_many_attempts')}`, 429. The `member-login` named limiter's `->response()` closure in `AppServiceProvider` is updated to call the same key directly (it bypasses the exception entirely, so the handler alone can't reach it).
3. `AuthenticationException` → `{message: __('api.auth.unauthenticated')}`, 401.
4. `AuthorizationException` → `{message: __('api.auth.forbidden')}`, 403.
5. `NotFoundHttpException` (covers no-matching-route and Laravel's auto-converted `ModelNotFoundException`) → `{message: __('api.system.not_found')}`, 404. Prevents Eloquent's default message (which names the model class) from leaking.
6. Any other `HttpExceptionInterface` whose `getMessage()` is non-empty → passed through unchanged (this is what lets `EnsureAuthenticatedUserIsActive`'s `abort(403, __('api.auth.account_inactive'))` keep its specific message instead of being overwritten by rule 4/the generic fallback).
7. Anything else, uncaught → `{message: __('api.system.server_error')}`, 500 — only when `config('app.debug')` is false. Debug-mode's rich trace is untouched.

Response shape stays `{message, errors?}` throughout — no envelope change.

## 5. Tests

New `tests/Feature/Identity/ApiLocalizationTest.php`, driven mostly off
the existing `POST /api/v1/auth/login` (wrong-credentials path already
covered functionally by `MemberLoginTest`, reused here for locale
assertions instead of duplicating login-logic tests):

- (a) `lang: ar` + wrong credentials → `message` equals the Arabic string for `auth.invalid_credentials`.
- (b) `lang: en` + wrong credentials → equals the English string for the same key.
- (c) no header, authenticated member with `preferred_language = 'en'`, `PATCH /api/v1/member/preferences` sent with an invalid `preferred_language` value to force the existing `UpdateLanguagePreferenceRequest` validation to fail (422) → top-level `message` equals the English string for `api.validation.failed`.
- (d) same request, `lang: en` header + `preferred_language = 'ar'` stored on the member → still English (header wins over the stored preference).
- (e) `lang: fr` (invalid) → falls back per rule 1's chain; asserted against whichever of ar/en that resolves to for an unauthenticated request (`ar`, the system default) — not a 4xx.
- (f) 6th login attempt in a minute (429, reusing `MemberLoginTest`'s existing throttle scenario) → `message` equals the translated `auth.too_many_attempts` string, not Laravel's default "Too Many Attempts.".

`composer test` run after each numbered step, confirming the running
total, per your instruction.
