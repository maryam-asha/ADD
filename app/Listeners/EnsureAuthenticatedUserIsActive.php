<?php

namespace App\Listeners;

use App\Domain\Identity\Models\User;
use Illuminate\Auth\Events\Authenticated;
use Laravel\Sanctum\Events\TokenAuthenticated;

/**
 * Fires the moment ANY guard resolves a user — Sanctum's `TokenAuthenticated`
 * for the API, the framework's own `Authenticated` for Fortify's web/session
 * guard — rather than a middleware. A middleware appended to the kernel-global
 * stack (`bootstrap/app.php`'s `$middleware->append()`) runs *before*
 * `auth:sanctum`'s own route middleware switches the active guard and
 * resolves the user, so `$request->user()` at that point is null even for a
 * request that goes on to authenticate correctly — confirmed empirically: a
 * global middleware version of this check let every request through
 * regardless of status. Listening to the resolution event itself has no
 * middleware-ordering problem to get right, and — the actual goal — needs no
 * per-route or per-group registration anywhere for this to apply to a future
 * domain's routes.
 */
class EnsureAuthenticatedUserIsActive
{
    /**
     * A deactivated/blocked account must still be able to end its own
     * session, on either side of the app. `auth.logout` is named for
     * exactly this in routes/api/v1/auth.php (the member API). `logout` is
     * Fortify's own admin-dashboard route — confirmed by actually running
     * `php artisan route:list --name=logout` against this project rather
     * than assumed from Fortify's generic docs: `FortifyServiceProvider`
     * doesn't rename it, so it registers under Fortify's own default name.
     *
     * @var list<string>
     */
    private const EXEMPT_ROUTES = [
        'auth.logout',
        'logout',
    ];

    public function handle(TokenAuthenticated|Authenticated $event): void
    {
        $user = $event instanceof TokenAuthenticated
            ? $event->token->tokenable
            : $event->user;

        if (! $user instanceof User) {
            return;
        }

        if ($user->status !== 'active' && ! request()->routeIs(...self::EXEMPT_ROUTES)) {
            abort(403, 'This account has been suspended.');
        }
    }
}
