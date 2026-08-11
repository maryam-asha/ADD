<?php

use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every response speaks whichever locale this resolves — prepended so
        // it runs before anything else in the pipeline, including auth:sanctum.
        $middleware->api(prepend: SetLocaleFromHeader::class);

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
    })->create();
