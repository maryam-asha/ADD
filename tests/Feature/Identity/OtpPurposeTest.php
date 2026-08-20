<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\OtpVerification;
use App\Services\Otp\OtpProvider;
use App\Services\Otp\OtpResult;
use App\Services\Otp\OtpService;
use Tests\Support\CapturingOtpProvider;

/**
 * A single code table now serves two flows that grant very different things:
 * registration mints an account, password reset overwrites the credential on
 * an existing one. Without a purpose column a code issued for the cheaper
 * flow would be spendable on the more powerful one.
 */
class OtpPurposeTest extends IdentityTestCase
{
    private CapturingOtpProvider $provider;

    private OtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new CapturingOtpProvider;
        $this->app->instance(OtpProvider::class, $this->provider);
        $this->otp = $this->app->make(OtpService::class);
    }

    public function test_the_requested_purpose_is_persisted_on_the_issued_code(): void
    {
        $this->otp->request('+963912345678', OtpPurpose::PasswordReset);

        $this->assertSame(
            OtpPurpose::PasswordReset,
            OtpVerification::where('phone', '+963912345678')->sole()->purpose
        );
    }

    public function test_a_registration_code_is_rejected_when_spent_on_a_password_reset(): void
    {
        $phone = '+963912345678';
        $this->otp->request($phone, OtpPurpose::Registration);

        $result = $this->otp->verify($phone, $this->provider->lastCodeFor($phone), OtpPurpose::PasswordReset);

        $this->assertSame(OtpResult::PurposeMismatch, $result);
    }

    public function test_a_password_reset_code_is_rejected_when_spent_on_a_registration(): void
    {
        $phone = '+963912345678';
        $this->otp->request($phone, OtpPurpose::PasswordReset);

        $result = $this->otp->verify($phone, $this->provider->lastCodeFor($phone), OtpPurpose::Registration);

        $this->assertSame(OtpResult::PurposeMismatch, $result);
    }

    public function test_a_purpose_mismatch_does_not_consume_the_code(): void
    {
        $phone = '+963912345678';
        $this->otp->request($phone, OtpPurpose::PasswordReset);
        $code = $this->provider->lastCodeFor($phone);

        $this->otp->verify($phone, $code, OtpPurpose::Registration);

        $this->assertSame(OtpResult::Verified, $this->otp->verify($phone, $code, OtpPurpose::PasswordReset));
    }

    public function test_a_code_matching_its_own_purpose_verifies(): void
    {
        $phone = '+963912345678';
        $this->otp->request($phone, OtpPurpose::PasswordReset);

        $result = $this->otp->verify($phone, $this->provider->lastCodeFor($phone), OtpPurpose::PasswordReset);

        $this->assertSame(OtpResult::Verified, $result);
    }

    public function test_a_wrong_code_is_invalid_rather_than_a_purpose_mismatch(): void
    {
        $phone = '+963912345678';
        $this->otp->request($phone, OtpPurpose::Registration);

        $result = $this->otp->verify($phone, '000000', OtpPurpose::PasswordReset);

        $this->assertSame(OtpResult::InvalidOrExpired, $result);
    }

    /**
     * The two flows are throttled independently: needing to reset a password
     * must not be blocked by a registration code someone requested a moment
     * earlier, and the newest code of each purpose has to stay reachable.
     */
    public function test_each_purpose_carries_its_own_resend_cooldown(): void
    {
        $phone = '+963912345678';
        $this->otp->request($phone, OtpPurpose::Registration);
        $this->otp->request($phone, OtpPurpose::PasswordReset);

        $this->assertSame(2, OtpVerification::where('phone', $phone)->count());
    }
}
