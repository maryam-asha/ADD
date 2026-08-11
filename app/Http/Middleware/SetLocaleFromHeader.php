<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header only. Deliberately does not consult the authenticated user here —
 * this middleware is prepended to the `api` middleware group, which runs
 * before `auth:sanctum`'s own route middleware resolves $request->user()
 * (see EnsureAuthenticatedUserIsActive's docblock for the same ordering
 * trap, confirmed empirically there). SetLocaleFromUserPreference corrects
 * this provisional value once a guard actually resolves a user.
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
