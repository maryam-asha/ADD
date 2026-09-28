<?php

namespace App\Support\Security;

use ParagonIE\ConstantTime\Base32;

/**
 * RFC 6238 TOTP with HMAC-SHA1 — what every authenticator app speaks, so
 * the kiosk app can use any stock TOTP library
 * (docs/decisions/coworking-self-service-sessions.md).
 *
 * Written here rather than taken from pragmarx/google2fa because the time
 * has to come from the caller: google2fa reads the system clock, which
 * Carbon::setTestNow() cannot move. Callers pass now()->getTimestamp().
 *
 * Deliberately not replay-protected: a kiosk code is shared by every member
 * who scans it within the same step.
 */
final class Totp
{
    public const ALGORITHM = 'SHA1';

    public function __construct(
        public readonly int $digits = 8,
        public readonly int $period = 30,
    ) {}

    /** 160 random bits — RFC 4226's recommended length — as 32 Base32 chars. */
    public static function generateSecret(): string
    {
        return Base32::encodeUpper(random_bytes(20));
    }

    public function codeAt(string $secret, int $unixTime): string
    {
        $key = Base32::decodeUpper(strtoupper(str_replace(['=', ' '], '', $secret)));
        $hash = hash_hmac('sha1', pack('J', intdiv($unixTime, $this->period)), $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** $this->digits)), $this->digits, '0', STR_PAD_LEFT);
    }

    /** True when $code matches the step containing $unixTime or $window steps either side. */
    public function verify(string $secret, string $code, int $unixTime, int $window = 1): bool
    {
        if (strlen($code) !== $this->digits || ! ctype_digit($code)) {
            return false;
        }

        for ($step = -$window; $step <= $window; $step++) {
            if (hash_equals($this->codeAt($secret, $unixTime + ($step * $this->period)), $code)) {
                return true;
            }
        }

        return false;
    }
}
