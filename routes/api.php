<?php

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
