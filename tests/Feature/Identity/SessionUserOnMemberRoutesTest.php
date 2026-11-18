<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use App\Services\Auth\TokenPairService;
use Illuminate\Support\Facades\Auth;

/**
 * `auth:sanctum` resolves a session cookie as readily as a bearer token — that
 * is how the dashboard authenticates. So every route behind it, including the
 * member app's own, is reachable by a session-authenticated user, whether or
 * not that was the intent.
 *
 * Sanctum represents such a user with a `TransientToken`, which is not a
 * `PersonalAccessToken` and cannot be deleted. Anything that reaches for the
 * current access token has to cope with that rather than assume a real token
 * row is there.
 */
class SessionUserOnMemberRoutesTest extends IdentityTestCase
{
    public function test_logging_out_over_a_session_does_not_error(): void
    {
        $user = User::factory()->create();
        $user->assignRole('member');

        $this->actingAs($user)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();
    }

    /**
     * Answering 200 while leaving the cookie live would be worse than refusing
     * the request — the caller would believe they had signed out.
     */
    public function test_logging_out_over_a_session_actually_ends_it(): void
    {
        $user = User::factory()->create();
        $user->assignRole('member');

        $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertFalse(Auth::guard('web')->check());
    }

    /**
     * A session logout has no token row to reach for, and must not go looking
     * for one belonging to the member's other devices.
     */
    public function test_a_session_logout_leaves_real_token_sessions_alone(): void
    {
        $user = User::factory()->create();
        $user->assignRole('member');

        $pair = app(TokenPairService::class)->issue($user)->toArray();

        $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertOk();

        Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$pair['access_token'])
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_reading_the_current_user_over_a_session_works(): void
    {
        $user = User::factory()->create();
        $user->assignRole('member');

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }
}
