<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `staff`: the account holds at least one permission, through any role or
 * directly. For the two admin routes with no resource of their own — `me`
 * and `media/upload` — which every staff account needs whatever it may do,
 * while a member or provider account (which never holds a permission) is
 * still refused (docs/decisions/rbac-permission-pilot.md, 2026-10-27).
 * The 403 is Spatie's exception, so it renders like every `permission:`
 * denial (bootstrap/app.php).
 */
class EnsureStaffAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->getAllPermissions()->isNotEmpty() !== true) {
            throw new UnauthorizedException(403, __('api.auth.forbidden'));
        }

        return $next($request);
    }
}
