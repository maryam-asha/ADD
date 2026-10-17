<?php

namespace App\Domain\Hr\Kiosk;

use App\Domain\Booking\Models\Kiosk;
use Illuminate\Http\Request;

/**
 * Who is calling: a paired, active kiosk proving it holds its secret. The
 * kiosk has no token; it signs each request with the TOTP secret it got at
 * pairing (never shown on screen — the codes it displays are derived from
 * it, not it):
 *
 *   X-Kiosk-Id:        the kiosk's id
 *   X-Kiosk-Timestamp: unix seconds, within MAX_SKEW_SECONDS of the server
 *   X-Kiosk-Signature: hex HMAC-SHA256(secret, "{id}\n{timestamp}\n{raw body}")
 *
 * Re-pairing rotates the secret, so a lost kiosk stops signing the moment
 * it is replaced. A replayed request within the skew changes nothing: every
 * scan in it carries its own idempotency id.
 */
class KioskRequestSignature
{
    public const MAX_SKEW_SECONDS = 300;

    public function verify(Request $request): ?Kiosk
    {
        $id = (string) $request->header('X-Kiosk-Id');
        $timestamp = (string) $request->header('X-Kiosk-Timestamp');
        $signature = strtolower((string) $request->header('X-Kiosk-Signature'));

        if (! ctype_digit($id) || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return null;
        }

        $kiosk = Kiosk::query()->whereKey((int) $id)->where('is_active', true)->first();

        if ($kiosk === null || $kiosk->totp_secret === null) {
            return null;
        }

        $expected = hash_hmac('sha256', "{$id}\n{$timestamp}\n".$request->getContent(), $kiosk->totp_secret);

        return hash_equals($expected, $signature) ? $kiosk : null;
    }

    /**
     * The headers for a body, as a kiosk computes them.
     *
     * @return array<string, string>
     */
    public static function headersFor(Kiosk $kiosk, string $secret, string $body, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            'X-Kiosk-Id' => (string) $kiosk->id,
            'X-Kiosk-Timestamp' => (string) $timestamp,
            'X-Kiosk-Signature' => hash_hmac('sha256', "{$kiosk->id}\n{$timestamp}\n{$body}", $secret),
        ];
    }
}
