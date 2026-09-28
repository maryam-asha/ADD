<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Kiosk;
use App\Support\Security\Totp;

/**
 * Turns a scanned `add-cowork:v1:{kiosk_id}:{code}` into the kiosk that
 * showed it, or refuses (docs/decisions/coworking-self-service-sessions.md).
 * Stateless: the same code serves every member who scans it within its
 * step, and nothing is stored per code.
 */
class KioskCodeVerifier
{
    public function __construct(private readonly Totp $totp) {}

    public function verify(string $qrValue): Kiosk
    {
        $pattern = '/^'.preg_quote(Kiosk::QR_PREFIX, '/').':(\d+):(\d+)$/';

        if (preg_match($pattern, trim($qrValue), $matches) !== 1) {
            throw new ReceptionActionException('api.coworking.invalid_code');
        }

        $kiosk = Kiosk::query()->whereKey((int) $matches[1])->where('is_active', true)->first();

        if ($kiosk === null
            || $kiosk->totp_secret === null
            || ! $this->totp->verify($kiosk->totp_secret, $matches[2], now()->getTimestamp())) {
            throw new ReceptionActionException('api.coworking.invalid_code');
        }

        // The only sign of life the backend gets from a kiosk after pairing.
        $kiosk->forceFill(['last_used_at' => now()])->save();

        return $kiosk;
    }
}
