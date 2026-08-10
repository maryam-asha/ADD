<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\RefreshToken;
use App\Domain\Identity\Models\User;
use App\Services\Auth\TokenPairService;

/**
 * Access tokens are short-lived now that a refresh token exists to renew
 * them, which only buys anything if the refresh token is single-use and if
 * every path that ends a session kills both halves of the pair together.
 */
class RefreshTokenTest extends IdentityTestCase
{
    private function member(): User
    {
        $user = User::factory()->create();
        $user->assignRole('member');

        return $user;
    }

    private function issueFor(User $user): array
    {
        return app(TokenPairService::class)->issue($user)->toArray();
    }

    public function test_a_valid_refresh_token_is_exchanged_for_a_new_pair(): void
    {
        $pair = $this->issueFor($this->member());

        $response = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $pair['refresh_token'],
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in']);
        $this->assertNotSame($pair['refresh_token'], $response->json('refresh_token'));
        $this->assertNotSame($pair['access_token'], $response->json('access_token'));
    }

    public function test_the_new_access_token_authenticates(): void
    {
        $pair = $this->issueFor($this->member());

        $refreshed = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']]);

        $this->withHeader('Authorization', 'Bearer '.$refreshed->json('access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_a_refresh_token_cannot_be_spent_twice(): void
    {
        $pair = $this->issueFor($this->member());

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertOk();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])
            ->assertStatus(401);
    }

    public function test_the_superseded_access_token_stops_working_after_a_refresh(): void
    {
        $pair = $this->issueFor($this->member());

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$pair['access_token'])
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_an_unknown_refresh_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => str_repeat('a', 64)])
            ->assertStatus(401);
    }

    public function test_an_expired_refresh_token_is_rejected(): void
    {
        $pair = $this->issueFor($this->member());

        $this->travel(config('tokens.refresh_ttl_days') + 1)->days();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])
            ->assertStatus(401);
    }

    public function test_deactivating_an_account_revokes_its_refresh_tokens(): void
    {
        $user = $this->member();
        $pair = $this->issueFor($user);

        $user->deactivate('testing');

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])
            ->assertStatus(401);
    }

    public function test_blocking_an_account_revokes_its_refresh_tokens(): void
    {
        $user = $this->member();
        $pair = $this->issueFor($user);

        $user->block('testing');

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])
            ->assertStatus(401);
    }

    /**
     * Logging out on one device must not sign the member out everywhere —
     * only the refresh token minted alongside the access token being ended.
     */
    public function test_logging_out_revokes_only_that_sessions_refresh_token(): void
    {
        $user = $this->member();
        $phone = $this->issueFor($user);
        $laptop = $this->issueFor($user);

        $this->withHeader('Authorization', 'Bearer '.$phone['access_token'])
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $phone['refresh_token']])
            ->assertStatus(401);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $laptop['refresh_token']])
            ->assertOk();
    }

    public function test_the_refresh_token_is_never_stored_in_plaintext(): void
    {
        $pair = $this->issueFor($this->member());

        $this->assertDatabaseMissing('refresh_tokens', ['token_hash' => $pair['refresh_token']]);
        $this->assertSame(1, RefreshToken::count());
    }
}
