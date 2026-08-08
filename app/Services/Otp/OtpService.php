<?php

namespace App\Services\Otp;

use App\Domain\Identity\Models\OtpVerification;
use App\Services\Otp\Exceptions\OtpThrottledException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class OtpService
{
    private const CHANNEL = 'whatsapp';

    public function __construct(private readonly OtpProvider $provider) {}

    /**
     * Generate, persist, and dispatch a fresh code for the given phone.
     *
     * @throws OtpThrottledException if a code was requested too recently.
     */
    public function request(string $phone): OtpVerification
    {
        $key = $this->throttleKey($phone);

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 1)) {
            throw new OtpThrottledException(RateLimiter::availableIn($key));
        }

        $code = $this->generateCode();

        $otp = OtpVerification::create([
            'phone' => $phone,
            'provider' => self::CHANNEL,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(config('otp.expires_after_minutes')),
        ]);

        $this->provider->send($phone, $code, self::CHANNEL);

        RateLimiter::hit($key, config('otp.resend_cooldown_seconds'));

        return $otp;
    }

    /**
     * Verify a submitted code against the latest unverified code for this phone.
     * Burns the attempt whether or not the code matches, up to the configured max.
     */
    public function verify(string $phone, string $code): bool
    {
        $otp = OtpVerification::where('phone', $phone)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= config('otp.max_verify_attempts')) {
            return false;
        }

        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            return false;
        }

        $otp->update(['verified_at' => now()]);

        return true;
    }

    private function generateCode(): string
    {
        $length = config('otp.code_length');

        return (string) random_int(10 ** ($length - 1), (10 ** $length) - 1);
    }

    private function throttleKey(string $phone): string
    {
        return 'otp-request:'.$phone;
    }
}
