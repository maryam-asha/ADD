<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * The everyday sign-in. Two things it must never do: tell an unauthenticated
 * caller whether a phone number has an account, and hand a token to an account
 * that has been deactivated or blocked.
 */
class MemberLoginTest extends IdentityTestCase
{
    private const PHONE = '0912345678';

    private const PASSWORD = 'correct-horse';

    private function member(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'phone' => self::PHONE,
            'password' => self::PASSWORD,
        ], $overrides));

        $user->assignRole('member');

        return $user;
    }

    private function login(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', array_merge([
            'phone' => self::PHONE,
            'password' => self::PASSWORD,
        ], $overrides));
    }

    public function test_a_member_logs_in_with_their_password_and_receives_a_pair(): void
    {
        $this->member();

        $response = $this->login();

        $response->assertOk();
        $response->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in', 'user']);
        $response->assertJsonPath('user.phone', self::PHONE);
    }

    public function test_the_issued_pair_is_immediately_usable(): void
    {
        $this->member();

        $response = $this->login();

        $this->withHeader('Authorization', 'Bearer '.$response->json('access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $response->json('refresh_token')])
            ->assertOk();
    }

    public function test_each_login_issues_a_distinct_pair(): void
    {
        $this->member();

        $first = $this->login();
        $second = $this->login();

        $this->assertNotSame($first->json('access_token'), $second->json('access_token'));
        $this->assertNotSame($first->json('refresh_token'), $second->json('refresh_token'));
    }

    /**
     * The two failures a credential-stuffing attempt can distinguish between —
     * "no such account" and "wrong password" — have to be indistinguishable,
     * or the endpoint doubles as a phone-number enumerator.
     */
    public function test_a_wrong_password_and_an_unknown_phone_are_answered_identically(): void
    {
        $this->member();

        $wrongPassword = $this->login(['password' => 'not-the-password']);
        $unknownPhone = $this->login(['phone' => '0999999999']);

        $wrongPassword->assertStatus(401);
        $unknownPhone->assertStatus(401);

        $this->assertSame($wrongPassword->json('message'), $unknownPhone->json('message'));
        $wrongPassword->assertJsonMissingPath('access_token');
        $unknownPhone->assertJsonMissingPath('access_token');
    }

    /**
     * Accounts predating hybrid auth have no password. A null hash must fail
     * closed rather than matching anything.
     */
    public function test_an_account_with_no_password_cannot_log_in(): void
    {
        $this->member(['password' => null]);

        $this->login()->assertStatus(401);
        $this->login(['password' => ''])->assertStatus(422);
    }

    public function test_a_deactivated_account_is_refused_and_gets_no_token(): void
    {
        $this->member()->deactivate('testing');

        $response = $this->login();

        $response->assertStatus(403);
        $response->assertJsonMissingPath('access_token');
        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function test_a_blocked_account_is_refused_and_gets_no_token(): void
    {
        $this->member()->block('spam');

        $response = $this->login();

        $response->assertStatus(403);
        $response->assertJsonMissingPath('access_token');
        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function test_the_sixth_attempt_in_a_minute_is_throttled(): void
    {
        $this->member();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->login(['password' => 'wrong'])->assertStatus(401);
        }

        $response = $this->login(['password' => 'wrong']);

        $response->assertStatus(429);
        $response->assertJsonStructure(['message', 'retry_after']);
        $this->assertNotNull($response->json('retry_after'));
    }

    /**
     * The limit is per phone+IP, so one number being hammered must not lock out
     * a different member coming from the same address.
     */
    public function test_the_throttle_is_scoped_to_the_phone_being_tried(): void
    {
        $this->member();

        $other = User::factory()->create(['phone' => '0911111111', 'password' => self::PASSWORD]);
        $other->assignRole('member');

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->login(['password' => 'wrong']);
        }

        $this->login(['phone' => '0911111111'])->assertOk();
    }

    public function test_the_phone_and_password_are_both_required(): void
    {
        $this->login(['phone' => null])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->login(['password' => null])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
