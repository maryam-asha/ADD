<?php

namespace Tests\Unit\Support;

use App\Support\Security\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** Base32 of the ASCII seed "12345678901234567890" used by RFC 6238 Appendix B. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return list<array{int, string}> RFC 6238 Appendix B, SHA1 column (8 digits). */
    public static function rfcVectors(): array
    {
        return [
            [59, '94287082'],
            [1111111109, '07081804'],
            [1111111111, '14050471'],
            [1234567890, '89005924'],
            [2000000000, '69279037'],
            [20000000000, '65353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_it_matches_the_rfc_6238_sha1_vectors(int $time, string $expected): void
    {
        $this->assertSame($expected, (new Totp)->codeAt(self::RFC_SECRET, $time));
    }

    public function test_verify_accepts_one_step_either_side_and_nothing_further(): void
    {
        $totp = new Totp;
        $t = 1_000_000_020; // exactly on a 30 s step boundary
        $code = $totp->codeAt(self::RFC_SECRET, $t);

        $this->assertTrue($totp->verify(self::RFC_SECRET, $code, $t));
        $this->assertTrue($totp->verify(self::RFC_SECRET, $code, $t + 30));
        $this->assertTrue($totp->verify(self::RFC_SECRET, $code, $t - 30));
        $this->assertFalse($totp->verify(self::RFC_SECRET, $code, $t + 60));
        $this->assertFalse($totp->verify(self::RFC_SECRET, $code, $t - 60));
    }

    public function test_verify_rejects_malformed_codes(): void
    {
        $totp = new Totp;

        $this->assertFalse($totp->verify(self::RFC_SECRET, '9428708', 59));   // too short
        $this->assertFalse($totp->verify(self::RFC_SECRET, '942870821', 59)); // too long
        $this->assertFalse($totp->verify(self::RFC_SECRET, '9428708a', 59));  // not digits
    }

    public function test_generated_secrets_are_32_base32_characters_and_distinct(): void
    {
        $a = Totp::generateSecret();
        $b = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $a);
        $this->assertNotSame($a, $b);
    }
}
