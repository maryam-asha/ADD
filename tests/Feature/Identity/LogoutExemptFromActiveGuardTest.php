<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * `routes/api/v1/auth.php` deliberately keeps `logout` outside the `active`
 * middleware group — a deactivated/blocked account must still be able to end
 * its own session. This can only be observed with a token that survives the
 * status change, so this test forces the status column directly (as
 * `SuspendedAccountAccessTest` does) rather than going through
 * `User::deactivate()`/`block()`, which already delete every token
 * immediately (`AccountDeactivationTest`) — by the time those run, there is
 * no token left for *any* endpoint, logout included, so they can't exercise
 * this exemption at all. This test isolates the middleware's own
 * route-exemption behavior from that separate token-revocation mechanism.
 */
class LogoutExemptFromActiveGuardTest extends IdentityTestCase
{
    public function test_logout_succeeds_for_a_deactivated_user_while_every_other_endpoint_stays_blocked(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $token = $member->createToken('member-app')->plainTextToken;

        $member->update(['status' => 'deactivated']);
        Auth::forgetGuards();

        // Any other endpoint, same request shape, same token: still blocked.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertForbidden();

        Auth::forgetGuards();

        // Logout itself: succeeds despite the same deactivated status.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // And the token really was revoked by logout, not just ignored.
        $this->assertSame(0, $member->tokens()->count());
    }

    public function test_logout_succeeds_for_a_blocked_user_too(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $token = $member->createToken('member-app')->plainTextToken;

        $member->update(['status' => 'blocked']);
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();
    }
}
