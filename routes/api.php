<?php

use Add\Hr\Hr;
use Add\Inventory\Inventory;
use App\Http\Controllers\Api\V1\Paymera\PaymeraCallbackController;
use Illuminate\Support\Facades\Route;

/*
 * Every endpoint lives under /api/v1 from day one, even though there is only
 * one version right now — introducing versioning after clients already
 * depend on unversioned paths is the expensive time to do it.
 */
Route::prefix('v1')->group(function () {
    require base_path('routes/api/v1/auth.php');
    require base_path('routes/api/v1/public.php');
    require base_path('routes/api/v1/mobile.php');
    require base_path('routes/api/v1/kiosk.php');

    // 'active' is no longer listed here — EnsureUserIsActive is registered
    // globally in bootstrap/app.php, not per route group.
    //
    // 'abilities:dashboard' is the isolation boundary between the two client
    // surfaces, and it is separate from 'role' on purpose. The role says what
    // its holder may do; the ability says which surface issued the credential
    // being presented. A person who is genuinely both a member and an operator
    // passes the role check with an app token — so only the ability keeps that
    // token, minted behind a single factor, out of the operations API. The
    // dashboard's own session satisfies this automatically (Sanctum gives a
    // session-authenticated user a TransientToken that allows every ability).
    Route::middleware(['auth:sanctum', 'abilities:dashboard', 'role:admin|operations'])
        ->prefix('admin')
        ->group(base_path('routes/api/v1/admin.php'));

    Route::middleware(['auth:sanctum', 'role:member'])
        ->prefix('member')
        ->group(base_path('routes/api/v1/member.php'));

    // Service providers (docs/decisions/service-providers.md): dashboard
    // accounts, so the same 'abilities:dashboard' boundary as the admin
    // group, but their own role and their own file — a provider never
    // reaches an admin route, and provider.active cuts off every account of
    // a deactivated provider.
    Route::middleware(['auth:sanctum', 'abilities:dashboard', 'role:provider', 'provider.active'])
        ->prefix('provider')
        ->group(base_path('routes/api/v1/provider.php'));

    /*
     * HR (packages/add/hr). Dashboard accounts, so the same
     * 'abilities:dashboard' boundary — but no 'role:' check: being an
     * employee is an HR record, not a role. Anyone with an active employee
     * record reaches hr/me (operations, sales, the café, ...), and every
     * admin route carries its own can:hr.* permission, so a custom HR role
     * works without admin|operations.
     */
    Route::middleware(['auth:sanctum', 'abilities:dashboard'])->prefix('hr')->group(function () {
        Route::middleware('hr.employee')->prefix('me')->group(fn () => Hr::selfServiceRoutes());
        Hr::adminRoutes();
    });

    // A document file's only credential is its temporary signature.
    Route::prefix('hr')->middleware('throttle:60,1')->group(fn () => Hr::signedRoutes());

    /*
     * Inventory (packages/add/inventory): assets, stock and their catalog.
     * Dashboard accounts behind the same 'abilities:dashboard' boundary, and
     * like HR no 'role:' check — every route carries its own
     * can:inventory.* permission, so a store keeper's custom role works
     * without admin|operations.
     */
    Route::middleware(['auth:sanctum', 'abilities:dashboard'])->prefix('inventory')->group(fn () => Inventory::adminRoutes());

    /*
     * Paymera's two return paths. Unauthenticated by necessity: the gateway
     * presents no credentials, and the member comes back from an external
     * page with no session in the WebView. What stands in for auth is the
     * 64-character random token in the URL — these routes are keyed on
     * `callback_token`, never on a payment id, so they cannot be walked.
     *
     * Neither endpoint is trusted to report an outcome; both just prompt a
     * get-payment-status call. Rate limited because they are public, and
     * the trigger accepts GET and POST since the spec does not fix the verb.
     *
     * CSRF is not a concern here: this is the "api" route file, which
     * Laravel does not put behind VerifyCsrfToken — the statefulApi()
     * middleware only applies CSRF to requests carrying a session cookie
     * from a SANCTUM_STATEFUL_DOMAINS origin, which Paymera's server-side
     * call and the WebView return are not.
     */
    Route::prefix('paymera')->middleware('throttle:60,1')->group(function () {
        Route::get('callback/{token}', [PaymeraCallbackController::class, 'callback'])
            ->name('paymera.callback');

        Route::match(['get', 'post'], 'trigger/{token}', [PaymeraCallbackController::class, 'trigger'])
            ->name('paymera.trigger');
    });
});
