<?php

namespace App\Services\Otp\Drivers;

use App\Services\Otp\OtpProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends OTP codes over WhatsApp through Hala Sender (base URL lives in
 * config/services.php). Auth is an `X-ApiKey` header; the
 * vendor deliberately answers a missing/invalid key with 404, so a 404 here
 * almost always means a bad key rather than a wrong URL.
 *
 * Hala Sender only accepts Syrian numbers as 9639XXXXXXXX, while the app
 * stores them as 09XXXXXXXX (App\Rules\SyrianPhoneNumber) — the conversion
 * happens here and nowhere else.
 */
class HalaSenderOtpProvider implements OtpProvider
{
    public function send(string $phone, string $code, string $provider): bool
    {
        // The WhatsApp body differs from the SMS one ({message, phoneNumbers}):
        // field names per the vendor's Swagger. whatsAppDeviceId is required —
        // the linked device the message goes out from. Needs the WhatsApp API
        // key; an SMS key answers "No Provided Sender".
        $payload = [
            'message' => __('api.auth.otp_message', [
                'code' => $code,
                'minutes' => config('otp.expires_after_minutes'),
            ]),
            'receivers' => [$this->toInternational($phone)],
            'whatsAppDeviceId' => (int) config('services.hala_sender.device_id'),
        ];

        try {
            $response = Http::baseUrl(config('services.hala_sender.base_url'))
                ->withHeaders(['X-ApiKey' => config('services.hala_sender.api_key')])
                ->acceptJson()
                ->connectTimeout(5)
                // The gateway sends synchronously and has been seen taking
                // well over 10s to answer from production. 20s gives it room
                // while staying under a typical 30s PHP execution limit.
                ->timeout(20)
                // It also intermittently answers 400 "Something went wrong ...
                // check your devices" and succeeds moments later, so an error
                // *response* gets one retry. A timeout does not: that would
                // double the wait, and the first send may still land.
                // throw: false so a final failure reaches the check below.
                ->retry(2, 1000, fn ($e) => $e instanceof RequestException, throw: false)
                ->post('/whatsapp/messages/send', $payload);
        } catch (ConnectionException $e) {
            Log::error('[HalaSenderOtpProvider] connection failed', ['error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            // Never log the payload — it carries the live code.
            Log::error('[HalaSenderOtpProvider] send rejected', [
                'status' => $response->status(),
                // Validation errors come back as a bare {field: [...]} map,
                // other failures as {statusCode, message} — keep the body.
                'body' => $response->json() ?? $response->body(),
            ]);

            return false;
        }

        return true;
    }

    private function toInternational(string $phone): string
    {
        $digits = ltrim($phone, '+');

        if (str_starts_with($digits, '963')) {
            return $digits;
        }

        return '963'.ltrim($digits, '0');
    }
}
