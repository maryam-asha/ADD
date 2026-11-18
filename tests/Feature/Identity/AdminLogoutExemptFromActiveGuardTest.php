<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * The admin-dashboard mirror of LogoutExemptFromActiveGuardTest — Fortify's
 * own `logout` route (confirmed via `php artisan route:list --name=logout`
 * against this project, not assumed from Fortify's generic docs) needs the
 * same exemption as the member API's `auth.logout`, since a deactivated or
 * blocked operations/admin account must still be able to end its own
 * dashboard session.
 *
 * This goes through a *real* Fortify session login (`POST /login`), not
 * `actingAs()` — `actingAs()` calls `SessionGuard::setUser()`, which fires
 * the `Authenticated` event immediately, before any route is bound to the
 * request; the listener's exemption check would evaluate against no route
 * at all. A real login fires the event during an actual dispatched request,
 * the same way production traffic does. `Auth::forgetGuards()` between
 * requests forces re-resolution from the DB — the same guard-caching
 * artifact already documented in AccountDeactivationTest/SuspendedAccountAccessTest,
 * not something specific to this test.
 */
class AdminLogoutExemptFromActiveGuardTest extends IdentityTestCase
{
    private function loginAsOperator(string $password = 'correct-password'): User
    {
        $operator = User::factory()->create(['password' => Hash::make($password)]);
        $operator->assignRole('operations');

        $this->postJson('/login', [
            'email' => $operator->email,
            'password' => $password,
        ])->assertOk();

        return $operator;
    }

    public function test_a_deactivated_operations_account_can_still_log_out_of_the_dashboard_but_nothing_else(): void
    {
        $operator = $this->loginAsOperator();

        // The session is genuinely valid right now, before deactivation.
        $this->getJson('/api/v1/admin/companies')->assertOk();

        $operator->update(['status' => 'deactivated']);
        Auth::forgetGuards();

        // Any other admin action, same session: blocked.
        $this->getJson('/api/v1/admin/companies')->assertForbidden();

        Auth::forgetGuards();

        // Logout itself: still succeeds despite the same deactivated status.
        $this->postJson('/logout')->assertNoContent();
    }

    public function test_a_blocked_operations_account_can_still_log_out_of_the_dashboard_but_nothing_else(): void
    {
        $operator = $this->loginAsOperator();

        $this->getJson('/api/v1/admin/companies')->assertOk();

        $operator->update(['status' => 'blocked']);
        Auth::forgetGuards();

        $this->getJson('/api/v1/admin/companies')->assertForbidden();

        Auth::forgetGuards();

        $this->postJson('/logout')->assertNoContent();
    }
}
