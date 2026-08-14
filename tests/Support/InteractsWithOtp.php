<?php

namespace Tests\Support;

use App\Services\Otp\OtpProvider;

/**
 * Drives a real OTP round trip over HTTP. The code is only ever readable at
 * the send boundary, so tests swap in a capturing provider and pull it back
 * out rather than reaching into the table and re-deriving a hash.
 */
trait InteractsWithOtp
{
    protected CapturingOtpProvider $otpProvider;

    protected function fakeOtpProvider(): CapturingOtpProvider
    {
        $this->otpProvider = new CapturingOtpProvider;
        $this->app->instance(OtpProvider::class, $this->otpProvider);

        return $this->otpProvider;
    }

    /**
     * Step one of sign-up: validate the profile and dispatch a code. Returns
     * what was sent.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function startRegistration(array $payload): string
    {
        $this->postJson('/api/v1/auth/register', $payload)->assertOk();

        return $this->otpProvider->lastCodeFor($payload['phone']);
    }

    /**
     * Step one of recovery. Returns the code sent, or null when the number has
     * no account — this endpoint answers 200 either way by design.
     */
    protected function startPasswordReset(string $phone): ?string
    {
        $this->postJson('/api/v1/auth/password/forgot', ['phone' => $phone])->assertOk();

        return $this->otpProvider->lastCodeFor($phone);
    }

    /**
     * Step one of self-service reactivation. Returns the code sent, or null
     * when the number doesn't resolve to a deactivated member account — this
     * endpoint answers 200 either way by design.
     */
    protected function startAccountReactivation(string $phone): ?string
    {
        $this->postJson('/api/v1/auth/account/reactivate', ['phone' => $phone])->assertOk();

        return $this->otpProvider->lastCodeFor($phone);
    }
}
