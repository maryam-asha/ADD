<?php

namespace App\Services\Otp\Drivers;

use App\Services\Otp\OtpProvider;
use Illuminate\Http\Client\ConnectionException;
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
                ->timeout(10)
                // The gateway intermittently times out or answers 400
                // "Something went wrong ... check your devices" and then
                // succeeds moments later — one retry covers that. throw: false
                // so a final failure lands in the check below, not an exception.
                ->retry(2, 1000, throw: false)
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
