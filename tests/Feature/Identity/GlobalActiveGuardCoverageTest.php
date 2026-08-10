<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * `EnsureAuthenticatedUserIsActive` (app/Listeners/EnsureAuthenticatedUserIsActive.php)
 * fires on every guard's user resolution, with no per-route/group opt-in —
 * proves the two edges of that: an unauthenticated public route is
 * completely unaffected (no guard ever resolves a user for it, so the
 * listener never even fires), and a brand-new route that never explicitly
 * registered this check is still covered simply because it authenticates
 * through the same guard as everything else.
 */
class GlobalActiveGuardCoverageTest extends IdentityTestCase
{
    public function test_an_unauthenticated_public_route_is_unaffected_by_the_active_guard(): void
    {
        // No Authorization header at all — no guard ever resolves a user,
        // so the listener has nothing to fire on. An empty list is a valid
        // 200, not an error; the point is the absence of any status check.
        $this->getJson('/api/v1/founders')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_an_otp_request_and_verify_are_unaffected_since_no_user_is_authenticated_yet(): void
    {
        $response = $this->postJson('/api/v1/auth/otp/request', ['phone' => '0955512345']);

        $response->assertOk();
    }

    public function test_a_route_with_no_explicit_active_check_registered_anywhere_is_still_covered(): void
    {
        // member/guests never mentions EnsureAuthenticatedUserIsActive or
        // any 'active' middleware in its own route/controller definition —
        // it's covered purely because it goes through auth:sanctum, the
        // same as every other authenticated route.
        $member = User::factory()->create();
        $member->assignRole('member');
        $token = $member->createToken('member-app')->plainTextToken;

        $member->update(['status' => 'blocked']);
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/member/guests')
            ->assertForbidden();
    }
}
