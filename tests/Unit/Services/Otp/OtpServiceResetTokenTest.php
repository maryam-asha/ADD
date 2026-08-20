<?php

namespace Tests\Unit\Services\Otp;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Services\Otp\OtpProvider;
use App\Services\Otp\OtpService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CapturingOtpProvider;
use Tests\TestCase;

/**
 * issueResetToken()/consumeResetToken() bridge verify() and the eventual
 * password write without letting the raw code (already spent by verify())
 * or the phone number alone stand in as proof for that second step.
 */
class OtpServiceResetTokenTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+963912345678';

    private CapturingOtpProvider $provider;

    private OtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new CapturingOtpProvider;
        $this->app->instance(OtpProvider::class, $this->provider);
        $this->otp = $this->app->make(OtpService::class);
    }

    private function verifiedPhone(): void
    {
        $this->otp->request(self::PHONE, OtpPurpose::PasswordReset);
        $code = $this->provider->lastCodeFor(self::PHONE);

        $this->otp->verify(self::PHONE, $code, OtpPurpose::PasswordReset);
    }

    public function test_issuing_a_reset_token_after_a_real_verify_produces_a_consumable_token(): void
    {
        $this->verifiedPhone();

        $token = $this->otp->issueResetToken(self::PHONE, OtpPurpose::PasswordReset);

        $this->assertNotEmpty($token);
        $this->assertTrue($this->otp->consumeResetToken(self::PHONE, $token, OtpPurpose::PasswordReset));
    }

    public function test_a_reset_token_cannot_be_consumed_twice(): void
    {
        $this->verifiedPhone();
        $token = $this->otp->issueResetToken(self::PHONE, OtpPurpose::PasswordReset);

        $this->assertTrue($this->otp->consumeResetToken(self::PHONE, $token, OtpPurpose::PasswordReset));
        $this->assertFalse($this->otp->consumeResetToken(self::PHONE, $token, OtpPurpose::PasswordReset));
    }

    public function test_a_reset_token_cannot_be_consumed_after_its_ttl_expires(): void
    {
        $this->verifiedPhone();
        $token = $this->otp->issueResetToken(self::PHONE, OtpPurpose::PasswordReset);

        $this->travel(config('otp.reset_token_ttl_minutes') + 1)->minutes();

        $this->assertFalse($this->otp->consumeResetToken(self::PHONE, $token, OtpPurpose::PasswordReset));
    }

    public function test_a_wrong_token_is_not_consumed(): void
    {
        $this->verifiedPhone();
        $this->otp->issueResetToken(self::PHONE, OtpPurpose::PasswordReset);

        $this->assertFalse($this->otp->consumeResetToken(self::PHONE, 'not-the-real-token', OtpPurpose::PasswordReset));
    }

    /**
     * issueResetToken() is only ever called right after a Verified result for
     * this exact phone/purpose. Calling it without that having happened is a
     * caller bug, not a recoverable outcome — it should surface loudly rather
     * than mint a token attached to nothing.
     */
    public function test_issuing_a_reset_token_without_a_prior_verify_throws(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->otp->issueResetToken(self::PHONE, OtpPurpose::PasswordReset);
    }
}
