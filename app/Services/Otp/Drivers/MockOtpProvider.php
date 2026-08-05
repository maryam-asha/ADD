<?php

namespace App\Services\Otp\Drivers;

use App\Services\Otp\OtpProvider;
use Illuminate\Support\Facades\Log;

class MockOtpProvider implements OtpProvider
{
    public function send(string $phone, string $code, string $provider): bool
    {
        Log::info("[MockOtpProvider] {$provider} -> {$phone}: {$code}");

        return true;
    }
}
