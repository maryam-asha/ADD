<?php

namespace App\Services\Auth;

use DateTimeInterface;

/**
 * The two credentials a session is made of. Kept as one value so no caller can
 * hand back an access token without the refresh token that renews it.
 */
final readonly class TokenPair
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public DateTimeInterface $accessTokenExpiresAt,
    ) {}

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => max(0, $this->accessTokenExpiresAt->getTimestamp() - now()->getTimestamp()),
        ];
    }
}
