<?php

use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every response speaks whichever locale this resolves — prepended
        // globally (not scoped to the "api" group) so it runs before
        // anything else in the pipeline, including auth:sanctum AND route
        // resolution itself. This app is JSON-only with no server-rendered
        // views, so there's no "web" surface this shouldn't apply to.
        // Group middleware only attaches once a route is actually matched,
        // which left a gap: a request to a URL that matches no route at all
        // (NotFoundHttpException) never ran group middleware, so its locale
        // never got set — confirmed empirically while building Task 4's
        // exception-handler translations. Global middleware wraps request
        // handling before routing fails, closing that gap (and the same one
        // for MethodNotAllowedHttpException, right path/wrong verb).
        $middleware->prepend(SetLocaleFromHeader::class);

        $middleware->statefulApi();

        // No "login" view route exists (Fortify's views are disabled) — the
        // framework default tries to redirect guests to route('login') and
        // crashes with a 500. This app has no server-rendered views at all,
        // so guests never get redirected; shouldRenderJsonWhen() below turns
        // the resulting AuthenticationException into a plain 401 JSON body.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,

            // Sanctum ships these but registers no alias under Laravel 11+.
            // 'abilities' gates on which surface minted the credential, which
            // is a separate axis from 'role' (what its owner may do) — see
            // TokenPairService::MEMBER_APP_ABILITY for why both are needed.
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,

            // Overrides the framework default so Fortify's guest-only routes
            // (`/login`, ...) return JSON to an already-authenticated SPA
            // caller instead of an unconditional redirect() — see the class
            // docblock for why `redirectGuestsTo(fn () => null)` below only
            // covers the opposite direction.
            'guest' => RedirectIfAuthenticated::class,
        ]);

        // The "is this account still active" check is NOT registered here —
        // a kernel-global middleware runs before auth:sanctum's route
        // middleware resolves the user, so it would see $request->user() as
        // null even on an otherwise-valid request (confirmed empirically).
        // It's App\Listeners\EnsureAuthenticatedUserIsActive instead,
        // listening for Sanctum's TokenAuthenticated / the framework's own
        // Authenticated event — see that class's docblock for why.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This app has no server-rendered views — every response, including
        // error responses, must be JSON. Without this, an unauthenticated
        // request that doesn't send "Accept: application/json" (most plain
        // fetch/axios calls) crashes with a 500 instead of a 401, because
        // Laravel's default guest-redirect falls back to route('login'),
        // which doesn't exist (Fortify's view routes are disabled).
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // Six specific exception shapes get a translated `message` instead
        // of Laravel's English-only default. Anything else (e.g. an
        // abort(403, __('api.auth.account_inactive')) elsewhere in the app)
        // already carries its own translated message and falls through to
        // Laravel's default HttpExceptionInterface rendering unchanged — see
        // the pass-through guard in the Throwable catch-all at the bottom,
        // which is what keeps that true once APP_DEBUG is off.
        $exceptions->render(fn (ValidationException $e) => response()->json([
            'message' => __('api.validation.failed'),
            'errors' => $e->errors(),
        ], $e->status));

        $exceptions->render(fn (ThrottleRequestsException $e) => response()->json([
            'message' => __('api.auth.too_many_attempts'),
        ], 429, $e->getHeaders()));

        $exceptions->render(fn (AuthenticationException $e) => response()->json([
            'message' => __('api.auth.unauthenticated'),
        ], 401));

        // AccessDeniedHttpException, NOT AuthorizationException: Laravel's
        // handler runs prepareException() *before* any render() callback, and
        // that rewrites a statusless AuthorizationException (what
        // Gate::authorize() throws) into AccessDeniedHttpException. A closure
        // typed against AuthorizationException therefore never matches
        // anything at render time — it was dead code, and 403s from the one
        // policy in the app shipped with Laravel's untranslated English
        // default. Verified with an Arabic assertion in
        // ErrorResponseLocalizationTest, which is what the English-only
        // assertion could not catch: api.auth.forbidden's English wording is
        // byte-identical to Laravel's own default.
        $exceptions->render(fn (AccessDeniedHttpException $e) => response()->json([
            'message' => __('api.auth.forbidden'),
        ], 403));

        // Spatie's PermissionMiddleware (the `permission:`/`role:`/
        // `role_or_permission:` route-middleware aliases registered above)
        // throws this on denial rather than AccessDeniedHttpException — it
        // does carry a real HTTP status (confirmed empirically: it already
        // rendered as 403 before this branch existed), so it never reached
        // the Throwable catch-all below, but its message was the package's
        // untranslated English default ("User does not have the right
        // permissions.") rather than this app's api.auth.forbidden — the
        // same gap AccessDeniedHttpException had above, for a different
        // reason. Verified with the Arabic assertion in
        // BranchPermissionEnforcementTest, same reasoning as the comment on
        // AccessDeniedHttpException above.
        $exceptions->render(fn (UnauthorizedException $e) => response()->json([
            'message' => __('api.auth.forbidden'),
        ], 403));

        $exceptions->render(fn (NotFoundHttpException $e) => response()->json([
            'message' => __('api.system.not_found'),
        ], 404));

        // Registered last: Throwable matches everything, so an earlier match
        // above always wins. Returning null in debug mode defers to Laravel's
        // own rich diagnostic rendering — a local-dev tool this doesn't touch.
        $exceptions->render(function (Throwable $e) {
            // Already carries its own status and message — abort(403, __(...)),
            // a policy denial, a named limiter's own 429 response. Only a
            // genuinely unhandled exception becomes a generic 500.
            //
            // Without this check the closure only looked at app.debug, so with
            // APP_DEBUG=false (i.e. production) every such exception was
            // flattened into an untranslated 500. HttpResponseException needs
            // its own test because it does not implement
            // HttpExceptionInterface, and Laravel's handler runs render()
            // callbacks *before* the match arm that would have unwrapped it —
            // which is how the member-login limiter's ->response() 429 ended
            // up here at all.
            if ($e instanceof HttpExceptionInterface || $e instanceof HttpResponseException) {
                return null;
            }

            if (config('app.debug')) {
                return null;
            }

            return response()->json(['message' => __('api.system.server_error')], 500);
        });
    })->create();
