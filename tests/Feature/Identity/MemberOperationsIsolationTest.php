<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithOtp;

/**
 * The member app and the operations dashboard share one `users` table, one
 * auth guard and one password column. That sharing is deliberate, but it means
 * the two surfaces are only as isolated as the checks between them — and the
 * member surface is the weaker one: it has no second factor, where Fortify's
 * dashboard login does.
 *
 * So the member app must not be a way to reach operations. Two independent
 * layers enforce that, because either one alone leaves a hole:
 *
 *  1. The member auth endpoints refuse accounts that hold no `member` role at
 *     all — this is what closes the 2FA bypass.
 *  2. Tokens minted for the app carry a bounded ability, and the admin group
 *     demands one the app never gets — this is what still holds when the same
 *     person is legitimately both a member and an operator.
 */
class MemberOperationsIsolationTest extends IdentityTestCase
{
    use InteractsWithOtp;

    private const PASSWORD = 'shared-password';

    private function userWithRoles(string $phone, string ...$roles): User
    {
        $user = User::factory()->create([
            'phone' => $phone,
            'password' => self::PASSWORD,
        ]);

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function login(string $phone): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'phone' => $phone,
            'password' => self::PASSWORD,
        ]);
    }

    // -----------------------------------------------------------------
    // Layer 1 — the member surface serves members only.
    // -----------------------------------------------------------------

    /**
     * The one that matters most: an operator's dashboard account is protected
     * by a second factor there, and this endpoint has none. Issuing it a token
     * would make the app a 2FA-free side door into the operations API.
     */
    public function test_an_operations_account_cannot_sign_in_through_the_member_app(): void
    {
        $this->userWithRoles('+963955500009', 'operations');

        $response = $this->login('+963955500009');

        $response->assertStatus(401);
        $response->assertJsonMissingPath('access_token');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function test_an_admin_account_cannot_sign_in_through_the_member_app(): void
    {
        $this->userWithRoles('+963955500010', 'admin');

        $this->login('+963955500010')->assertStatus(401);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_account_with_no_role_at_all_cannot_sign_in(): void
    {
        $this->userWithRoles('+963955500011');

        $this->login('+963955500011')->assertStatus(401);
    }

    /**
     * The refusal must not be a new oracle. "You are staff, use the dashboard"
     * would tell an attacker which numbers belong to operators — exactly the
     * accounts worth targeting.
     */
    public function test_refusing_an_operator_is_worded_exactly_like_a_wrong_password(): void
    {
        $this->userWithRoles('+963955500009', 'operations');
        $this->userWithRoles('+963912345678', 'member');

        $operator = $this->login('+963955500009');
        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'phone' => '+963912345678',
            'password' => 'not-the-password',
        ]);

        $this->assertSame($wrongPassword->status(), $operator->status());
        $this->assertSame($wrongPassword->json('message'), $operator->json('message'));
    }

    /**
     * `password` is one column serving both surfaces, so a reset driven from
     * the member app would rewrite an operator's dashboard credential. The
     * neutral 200 has to stay neutral while still sending nothing.
     */
    public function test_the_member_recovery_flow_will_not_reset_an_operations_password(): void
    {
        $this->fakeOtpProvider();
        $this->userWithRoles('+963955500009', 'operations');

        $this->postJson('/api/v1/auth/password/forgot', ['phone' => '+963955500009'])->assertOk();

        $this->assertNull($this->otpProvider->lastCodeFor('+963955500009'));
        $this->assertDatabaseCount('otp_verifications', 0);
    }

    // -----------------------------------------------------------------
    // Layer 2 — bounded token abilities.
    // -----------------------------------------------------------------

    public function test_a_member_can_still_sign_in_and_use_member_routes(): void
    {
        $this->userWithRoles('+963912345678', 'member');

        $token = $this->login('+963912345678')->assertOk()->json('access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/member/wallet/options?category=general')
            ->assertOk();
    }

    /**
     * The dual-role case, which is why layer 1 alone is not enough: this person
     * is genuinely both, so layer 1 lets them in. What stops their *app* token
     * from reaching the operations API is the ability it was minted with.
     */
    public function test_a_dual_role_user_signs_in_but_their_app_token_cannot_reach_the_admin_api(): void
    {
        $this->userWithRoles('+963912345678', 'member', 'operations');

        $token = $this->login('+963912345678')->assertOk()->json('access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/member/wallet/options?category=general')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/founders')
            ->assertStatus(403);
    }

    public function test_a_refreshed_app_token_is_still_bounded(): void
    {
        $this->userWithRoles('+963912345678', 'member', 'operations');

        $refreshToken = $this->login('+963912345678')->json('refresh_token');
        $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$rotated->json('access_token'))
            ->getJson('/api/v1/admin/founders')
            ->assertStatus(403);
    }

    public function test_a_token_minted_at_registration_is_bounded_too(): void
    {
        $this->fakeOtpProvider();

        $profile = [
            'phone' => '+963912345678',
            'name' => 'Maryam Asha',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
        ];

        $code = $this->startRegistration($profile);
        $token = $this->postJson('/api/v1/auth/register/verify', $profile + ['code' => $code])
            ->assertOk()
            ->json('access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/founders')
            ->assertStatus(403);
    }

    /**
     * The other half of layer 2: bounding the app's token must not bound the
     * dashboard, which authenticates by session rather than by token.
     */
    public function test_the_dashboard_session_path_still_reaches_the_admin_api(): void
    {
        $operator = $this->userWithRoles('+963955500009', 'operations');

        $this->actingAs($operator)
            ->getJson('/api/v1/admin/founders')
            ->assertOk();
    }
}
