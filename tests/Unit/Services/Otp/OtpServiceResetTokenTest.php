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
 * authorizeReset()/consumeResetAuthorization() bridge verify() and the eventual
 * password write. The authorization lives server-side — the caller only needs
 * to present the phone number again, not a token.
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

    public function test_authorizing_after_a_real_verify_makes_authorization_consumable(): void
    {
        $this->verifiedPhone();

        $this->otp->authorizeReset(self::PHONE, OtpPurpose::PasswordReset);

        $this->assertTrue($this->otp->consumeResetAuthorization(self::PHONE, OtpPurpose::PasswordReset));
    }

    public function test_reset_authorization_cannot_be_consumed_twice(): void
    {
        $this->verifiedPhone();
        $this->otp->authorizeReset(self::PHONE, OtpPurpose::PasswordReset);

        $this->assertTrue($this->otp->consumeResetAuthorization(self::PHONE, OtpPurpose::PasswordReset));
        $this->assertFalse($this->otp->consumeResetAuthorization(self::PHONE, OtpPurpose::PasswordReset));
    }

    public function test_reset_authorization_cannot_be_consumed_after_its_ttl_expires(): void
    {
        $this->verifiedPhone();
        $this->otp->authorizeReset(self::PHONE, OtpPurpose::PasswordReset);

        $this->travel(config('otp.reset_token_ttl_minutes') + 1)->minutes();

        $this->assertFalse($this->otp->consumeResetAuthorization(self::PHONE, OtpPurpose::PasswordReset));
    }

    /**
     * consumeResetAuthorization() without a prior authorizeReset() returns
     * false — there is no row carrying a valid authorization timestamp.
     */
    public function test_consuming_without_prior_authorize_returns_false(): void
    {
        $this->verifiedPhone();

        $this->assertFalse($this->otp->consumeResetAuthorization(self::PHONE, OtpPurpose::PasswordReset));
    }

    /**
     * authorizeReset() is only ever called right after a Verified result for
     * this exact phone/purpose. Calling it without that having happened is a
     * caller bug — it should surface loudly rather than silently authorize nothing.
     */
    public function test_authorizing_without_a_prior_verify_throws(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->otp->authorizeReset(self::PHONE, OtpPurpose::PasswordReset);
    }
}
