<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // No "login" view route exists (Fortify's views are disabled) — the
        // framework default tries to redirect guests to route('login') and
        // crashes with a 500. This app has no server-rendered views at all,
        // so guests never get redirected; shouldRenderJsonWhen() below turns
        // the resulting AuthenticationException into a plain 401 JSON body.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
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
