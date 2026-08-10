<?php

namespace App\Services\Auth;

use App\Domain\Identity\Models\RefreshToken;
use App\Domain\Identity\Models\User;
use App\Services\Auth\Exceptions\InvalidRefreshTokenException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The single place a member session is minted, renewed, or ended. Registration,
 * login and refresh all issue exactly the same pair through issue() — the
 * alternative, each endpoint assembling its own, is how the three drift apart
 * and one of them quietly stops expiring things.
 */
class TokenPairService
{
    private const TOKEN_NAME = 'member-app';

    /**
     * The one thing a token minted here is allowed to do. Deliberately not
     * `['*']`, which is what this used to issue.
     *
     * The operations dashboard and the member app share a `users` table, a
     * guard and a password column, so the same person can legitimately hold
     * both the `member` and `operations` roles. When they do, a role check
     * alone cannot keep their phone-and-password app session out of the
     * operations API — the roles really are theirs. The token's ability can:
     * it says which *surface* minted this credential, which is a different
     * question from what its owner is permitted to be.
     *
     * The admin route group demands `dashboard` (routes/api.php), which no
     * token from here ever carries. The dashboard itself is unaffected — it
     * authenticates by session, and Sanctum represents a session-authenticated
     * user with a TransientToken that satisfies every ability check.
     */
    public const MEMBER_APP_ABILITY = 'member-app';

    /**
     * Mint a fresh access token and the refresh token that renews it.
     */
    public function issue(User $user, string $name = self::TOKEN_NAME): TokenPair
    {
        return DB::transaction(function () use ($user, $name) {
            $expiresAt = now()->addMinutes(config('tokens.access_ttl_minutes'));

            $accessToken = $user->createToken($name, [self::MEMBER_APP_ABILITY], $expiresAt);

            $plainRefreshToken = bin2hex(random_bytes(32));

            RefreshToken::create([
                'user_id' => $user->id,
                'access_token_id' => $accessToken->accessToken->getKey(),
                'token_hash' => RefreshToken::hash($plainRefreshToken),
                'expires_at' => now()->addDays(config('tokens.refresh_ttl_days')),
            ]);

            return new TokenPair($accessToken->plainTextToken, $plainRefreshToken, $expiresAt);
        });
    }

    /**
     * Spend a refresh token for a new pair. Single-use by design: the token
     * presented here is revoked and its access token deleted before the
     * replacement is issued, so a stolen token works at most once and the theft
     * surfaces as the real holder being logged out.
     *
     * @throws InvalidRefreshTokenException
     */
    public function rotate(string $plainRefreshToken): TokenPair
    {
        return DB::transaction(function () use ($plainRefreshToken) {
            $refreshToken = RefreshToken::findByPlainToken($plainRefreshToken);

            if (! $refreshToken || ! $refreshToken->isUsable()) {
                throw new InvalidRefreshTokenException;
            }

            $user = $refreshToken->user;

            // deactivate()/block() already revoke refresh tokens, so this is a
            // second line rather than the only one — it also covers a status
            // written directly rather than through those methods.
            if (! $user || $user->status !== 'active') {
                throw new InvalidRefreshTokenException;
            }

            $refreshToken->revoke();

            if ($refreshToken->access_token_id) {
                PersonalAccessToken::whereKey($refreshToken->access_token_id)->delete();
            }

            return $this->issue($user);
        });
    }

    /**
     * End one device's session: the refresh token minted alongside this access
     * token, and nothing belonging to the member's other devices. Revoked
     * before the access token is deleted — the FK is nullOnDelete, so doing it
     * the other way round loses the link and orphans a live refresh token.
     */
    public function revokeSession(PersonalAccessToken $accessToken): void
    {
        DB::transaction(function () use ($accessToken) {
            RefreshToken::where('access_token_id', $accessToken->getKey())
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $accessToken->delete();
        });
    }

    /**
     * Cut every session this member holds, on every device. Used wherever the
     * account's credentials can no longer be trusted — a password reset — and
     * paired with the token deletion deactivate()/block() already do.
     */
    public function revokeAll(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $user->tokens()->delete();
        });
    }
}
