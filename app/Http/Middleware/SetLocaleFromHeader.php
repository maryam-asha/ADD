<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header only. Deliberately does not consult the authenticated user here —
 * this middleware is prepended to the kernel-global stack
 * (`$middleware->prepend()` in bootstrap/app.php), not to the `api` group, so
 * it runs before everything else in the pipeline. Two reasons it has to sit
 * that early: it must run before `auth:sanctum`'s own route middleware
 * resolves $request->user() (see EnsureAuthenticatedUserIsActive's docblock
 * for the same ordering trap, confirmed empirically there), and it must run
 * before route resolution itself — group middleware only attaches once a
 * route is matched, which left requests to unmatched URLs (404) and to a
 * right-path/wrong-verb URL (405) with no locale set at all.
 * SetLocaleFromUserPreference corrects this provisional value once a guard
 * actually resolves a user.
 */
class SetLocaleFromHeader
{
    public const SUPPORTED_LOCALES = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $header = strtolower((string) $request->header('lang'));

        App::setLocale(in_array($header, self::SUPPORTED_LOCALES, true) ? $header : 'ar');

        return $next($request);
    }
}
