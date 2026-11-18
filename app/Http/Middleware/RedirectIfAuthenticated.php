<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Overrides the framework's default 'guest' middleware (aliased in
 * bootstrap/app.php), which guards Fortify's guest-only routes (`/login`,
 * `/forgot-password`, `/two-factor-challenge`, ...).
 *
 * The parent class calls `redirect()` unconditionally when the user is
 * already authenticated — it never checks `expectsJson()`, unlike
 * `Authenticate` (the 'auth' middleware), whose null-guarded redirectTo()
 * lets `redirectGuestsTo(fn () => null)` in bootstrap/app.php turn a guest
 * hit into 401 JSON instead. This app has no `home`/`dashboard` route, so
 * that redirect resolves to `/` — a path `config/cors.php` never allow-lists
 * for the AddDashboard SPA's origin, so the SPA's `fetch()` (which follows
 * redirects automatically) saw an opaque CORS error instead of ever finding
 * out it was already logged in. This app is JSON-only with no server-rendered
 * views (see bootstrap/app.php), so there is no good redirect target for a
 * browser to land on anyway — every caller here is expected to want JSON.
 */
class RedirectIfAuthenticated extends Middleware
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => __('api.auth.already_authenticated'),
                    ], 409);
                }

                return redirect($this->redirectTo($request));
            }
        }

        return $next($request);
    }
}
