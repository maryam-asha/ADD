<?php

namespace Tests\Support;

use App\Services\Otp\OtpProvider;

/**
 * Test double standing in for the real send channel. The generated code is
 * never returned by the API — it only reaches the member over WhatsApp — so
 * every test that needs to complete an OTP round trip has to intercept it at
 * the provider boundary, which is the one seam the service exposes.
 */
class CapturingOtpProvider implements OtpProvider
{
    /** @var list<array{phone: string, code: string, provider: string}> */
    public array $sent = [];

    public function send(string $phone, string $code, string $provider): bool
    {
        $this->sent[] = compact('phone', 'code', 'provider');

        return true;
    }

    /**
     * The most recent code dispatched to this phone, or null if none was.
     */
    public function lastCodeFor(string $phone): ?string
    {
        foreach (array_reverse($this->sent) as $message) {
            if ($message['phone'] === $phone) {
                return $message['code'];
            }
        }

        return null;
    }
}
