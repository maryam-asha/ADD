<?php

use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

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
        // Laravel's default HttpExceptionInterface rendering unchanged.
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

        $exceptions->render(fn (AuthorizationException $e) => response()->json([
            'message' => __('api.auth.forbidden'),
        ], 403));

        $exceptions->render(fn (NotFoundHttpException $e) => response()->json([
            'message' => __('api.system.not_found'),
        ], 404));

        // Registered last: Throwable matches everything, so an earlier match
        // above always wins. Returning null in debug mode defers to Laravel's
        // own rich diagnostic rendering — a local-dev tool this doesn't touch.
        $exceptions->render(function (Throwable $e) {
            if (config('app.debug')) {
                return null;
            }

            return response()->json(['message' => __('api.system.server_error')], 500);
        });
    })->create();
