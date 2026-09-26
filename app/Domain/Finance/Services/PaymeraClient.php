<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\DataTransferObjects\CreatedPayment;
use App\Domain\Finance\DataTransferObjects\PaymentStatusResult;
use App\Domain\Finance\Exceptions\PaymeraRequestFailedException;
use App\Domain\Finance\Exceptions\PaymeraUnauthorizedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only class in this app that talks to Paymera eGate (API v4.0).
 *
 * Deliberate choices, each of which has bitten integrations before:
 *
 *  - **create-payment never retries.** A timed-out create may already have
 *    created the payment on Paymera's side; retrying it risks a second
 *    hosted page and a second charge. Only get-payment-status — which is
 *    a pure read — retries.
 *  - **cancel-payment sends `payment_id`, not `paymentId`.** That single
 *    field is snake_case while every other endpoint is camelCase. It is
 *    written once, here, with a test asserting the wire format.
 *  - **The API key never leaves this class.** It goes out as a Basic Auth
 *    username with an empty password (the trailing colon is required);
 *    nothing logged from here includes headers, and redact() scrubs the
 *    key from any value that might carry it.
 *
 * Base URL and credentials come from config/paymera.php — not from a
 * literal here, because tests/Guards/NetworkIsolationTest.php fails the
 * build on any external host written under app/.
 */
class PaymeraClient
{
    private const ERROR_SUCCESS = 0;

    private const ERROR_UNAUTHORIZED = 1;

    /**
     * Creates a payment and returns the hosted page to redirect to.
     *
     * @param  array<string, mixed>  $extra  savedCards / appUser / notes
     */
    public function createPayment(
        int $amountMinor,
        string $callbackUrl,
        string $triggerUrl,
        ?string $lang = null,
        array $extra = [],
    ): CreatedPayment {
        $payload = array_merge([
            'lang' => $lang ?? (string) config('paymera.default_lang'),
            'terminalId' => (string) config('paymera.terminal_id'),
            // Paymera requires a whole number with no separators. Casting to
            // int here is the last line of defence; callers already hold an
            // integer minor-unit amount.
            'amount' => $amountMinor,
            'callbackURL' => $callbackUrl,
            'triggerURL' => $triggerUrl,
        ], $extra);

        $this->log('info', 'create-payment request', ['payload' => $this->redact($payload)]);

        $data = $this->call('POST', '/api/create-payment', $payload, retry: false);

        $created = CreatedPayment::fromResponse($data);

        $this->log('info', 'create-payment accepted', [
            'payment_id' => $created->paymentId,
            'amount' => $amountMinor,
        ]);

        return $created;
    }

    /**
     * The single source of truth for whether a payment succeeded. Safe to
     * retry — it is a read with no side effects at the gateway.
     */
    public function getPaymentStatus(string $paymentId): PaymentStatusResult
    {
        $data = $this->call('GET', "/api/get-payment-status/{$paymentId}", null, retry: true);

        $result = PaymentStatusResult::fromResponse($data);

        $this->log('info', 'get-payment-status', [
            'payment_id' => $paymentId,
            'status' => $result->rawStatus,
            'amount' => $result->amountMinor,
            'rrn' => $result->rrn,
        ]);

        return $result;
    }

    /**
     * Reverses an accepted payment.
     *
     * The request field is `payment_id` — snake_case, unlike `paymentId`
     * everywhere else in this API. This is per Paymera's spec, not a typo.
     */
    public function cancelPayment(string $paymentId, ?string $lang = null): void
    {
        $this->log('info', 'cancel-payment request', ['payment_id' => $paymentId]);

        $this->call('POST', '/api/cancel-payment', [
            'lang' => $lang ?? (string) config('paymera.default_lang'),
            'payment_id' => $paymentId,
        ], retry: false);

        $this->log('info', 'cancel-payment accepted', ['payment_id' => $paymentId]);
    }

    /**
     * Issues the request and unwraps Paymera's `{ErrorCode, ErrorMessage,
     * Data}` envelope.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed> the `Data` object (empty for cancel-payment)
     */
    private function call(string $method, string $path, ?array $payload, bool $retry): array
    {
        try {
            $request = $this->request($retry);

            $response = $method === 'GET'
                ? $request->get($path)
                : $request->post($path, $payload ?? []);
        } catch (ConnectionException $e) {
            $this->log('error', 'connection failed', [
                'path' => $path,
                'error' => $this->redactString($e->getMessage()),
            ]);

            throw PaymeraRequestFailedException::networkError($e->getMessage());
        }

        $body = $response->json();

        if (! is_array($body) || ! array_key_exists('ErrorCode', $body)) {
            // A 401/403 with an HTML or empty body is the usual shape of a
            // rejected key, which would otherwise surface as "malformed".
            if ($response->status() === 401 || $response->status() === 403) {
                $this->log('error', 'unauthorized', ['path' => $path, 'http_status' => $response->status()]);

                throw PaymeraUnauthorizedException::make();
            }

            $this->log('error', 'malformed response', [
                'path' => $path,
                'http_status' => $response->status(),
                'body' => $this->redactString((string) $response->body()),
            ]);

            throw PaymeraRequestFailedException::malformedResponse(
                "HTTP {$response->status()} with no ErrorCode field"
            );
        }

        $errorCode = (int) $body['ErrorCode'];
        $errorMessage = (string) ($body['ErrorMessage'] ?? 'unknown Paymera error');

        if ($errorCode === self::ERROR_UNAUTHORIZED) {
            $this->log('error', 'unauthorized', ['path' => $path, 'error_message' => $errorMessage]);

            throw PaymeraUnauthorizedException::make();
        }

        if ($errorCode !== self::ERROR_SUCCESS) {
            $this->log('error', 'vendor error', [
                'path' => $path,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);

            throw PaymeraRequestFailedException::vendorError($errorCode, $errorMessage);
        }

        $data = $body['Data'] ?? [];

        return is_array($data) ? $data : [];
    }

    private function request(bool $retry): PendingRequest
    {
        $request = Http::baseUrl((string) config('paymera.base_url'))
            // Basic auth, API key as the username with an EMPTY password —
            // base64("API_KEY:"). The trailing colon is required; dropping it
            // produces a token Paymera rejects with ErrorCode 1.
            ->withBasicAuth((string) config('paymera.api_key'), '')
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('paymera.timeout'));

        if ($retry) {
            // Reads only. throw: false so a final failure is handled by the
            // ErrorCode inspection above rather than a RequestException.
            $request = $request->retry(3, 500, throw: false);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('paymera')->{$level}("[Paymera] {$message}", $context);
    }

    /**
     * Strips anything key-shaped from a payload before it is logged. The key
     * is never *put* into a payload by this class, so this is belt-and-braces
     * against a future caller passing one through `$extra`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        foreach (['api_key', 'apiKey', 'X-ApiKey', 'Authorization', 'password'] as $sensitive) {
            if (array_key_exists($sensitive, $payload)) {
                $payload[$sensitive] = '[redacted]';
            }
        }

        return $payload;
    }

    /**
     * Removes the configured key from free-form text (an exception message,
     * an error body) before it is logged.
     */
    private function redactString(string $value): string
    {
        $key = (string) config('paymera.api_key');

        if ($key === '') {
            return $value;
        }

        return str_replace($key, '[redacted]', $value);
    }
}
