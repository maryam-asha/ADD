<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `member.optional`: the app's visitor mode. A request with no credential
 * goes through as a guest ($request->user() is null); one that carries a
 * credential is held to exactly what the member group asks — auth:sanctum
 * and role:member — so a signed-in member sees their own version of the
 * screen.
 *
 * A Bearer token that does not resolve (expired, revoked) is a 401, not a
 * guest: the app answers a 401 by refreshing, and treating it as a visitor
 * would quietly show a signed-in member the logged-out screen instead.
 */
class AuthenticateMemberIfPresent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();

        if ($user === null) {
            if ($request->bearerToken() !== null) {
                throw new AuthenticationException(guards: ['sanctum']);
            }

            return $next($request);
        }

        // As auth:sanctum does, so $request->user() answers from this guard.
        Auth::shouldUse('sanctum');

        if (! $user->hasRole('member')) {
            throw UnauthorizedException::forRoles(['member']);
        }

        return $next($request);
    }
}
