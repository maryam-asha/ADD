<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * `EnsureUserIsActive` (app/Http/Middleware/EnsureUserIsActive.php) is the
 * guard this proves, deliberately via a *raw* `->update(['status' => ...])`
 * rather than `User::deactivate()`/`block()` (see `AccountDeactivationTest`
 * for those): the read-time status check must hold on its own, independent
 * of whether a token was ever revoked — a defense-in-depth case, since
 * `deactivate()`/`block()` delete tokens immediately, but nothing stops a
 * `status` column from changing by some other path (a direct DB write, a
 * future admin action that forgets to call the model method). Every
 * assertion here uses a real token minted via `$user->createToken()`, not
 * `Sanctum::actingAs()`, so the test actually exercises token authentication
 * succeeding and the guard being the thing that stops the request
 * afterward (403, not 401 — the token itself is never touched here).
 */
class SuspendedAccountAccessTest extends IdentityTestCase
{
    public function test_a_member_deactivated_by_a_raw_status_write_is_blocked_on_an_arbitrary_non_auth_endpoint(): void
    {
        $member = User::factory()->create(['status' => 'active']);
        $member->assignRole('member');
        $token = $member->createToken('member-app')->plainTextToken;

        // The token still works right now, before the status change.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/member/wallet/options?category=general')
            ->assertOk();

        $member->update(['status' => 'deactivated']);

        // The guard instance resolved for the first request above cached the
        // (then-active) user on itself — a real request always gets a fresh
        // application, so this only matters because this test makes two
        // requests against the same in-memory app. Forcing re-resolution is
        // what makes the second request actually re-check the DB.
        Auth::forgetGuards();

        // Same token, same request — Sanctum still accepts the token (this
        // is not a 401), but the account is no longer allowed through.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/member/wallet/options?category=general');

        $response->assertForbidden();
    }

    public function test_an_operations_account_deactivated_by_a_raw_status_write_is_blocked_on_an_admin_endpoint(): void
    {
        $operator = User::factory()->create(['status' => 'active']);
        $operator->assignRole('operations');
        $token = $operator->createToken('ops-dashboard')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/companies')
            ->assertOk();

        $operator->update(['status' => 'deactivated']);
        Auth::forgetGuards();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/companies');

        $response->assertForbidden();
    }

    public function test_an_active_user_is_not_affected_by_the_guard(): void
    {
        $member = User::factory()->create(['status' => 'active']);
        $member->assignRole('member');
        $token = $member->createToken('member-app')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/member/wallet/options?category=general')
            ->assertOk();
    }
}
