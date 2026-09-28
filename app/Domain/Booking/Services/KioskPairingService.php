<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\DataTransferObjects\IssuedPairingCode;
use App\Domain\Booking\DataTransferObjects\KioskPairing;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Kiosk;
use App\Support\Security\Totp;
use Illuminate\Support\Facades\DB;

/**
 * How a kiosk gets its TOTP secret (docs/decisions/coworking-self-service-sessions.md):
 * an admin issues a short single-use code, the kiosk app trades it once for
 * a freshly generated secret. Every pairing rotates the secret, so replacing
 * a device is re-pairing, and nothing is ever pushed to a device that was
 * not just paired.
 */
class KioskPairingService
{
    public const CODE_LENGTH = 8;

    public const CODE_TTL_MINUTES = 10;

    /** No 0/O or 1/I: the code is read off one screen and typed into another. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function issueCode(Kiosk $kiosk): IssuedPairingCode
    {
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $expiresAt = now()->addMinutes(self::CODE_TTL_MINUTES);

        // Replaces any unused previous code: only the latest one can pair.
        $kiosk->forceFill([
            'pairing_code_hash' => $this->hash($code),
            'pairing_code_expires_at' => $expiresAt,
        ])->save();

        return new IssuedPairingCode($code, $expiresAt);
    }

    public function pair(string $code): KioskPairing
    {
        return DB::transaction(function () use ($code) {
            $kiosk = Kiosk::query()
                ->where('pairing_code_hash', $this->hash($code))
                ->lockForUpdate()
                ->first();

            // One answer for wrong, expired, used and inactive alike, so the
            // response never tells a guesser which part was right.
            if ($kiosk === null
                || ! $kiosk->is_active
                || $kiosk->pairing_code_expires_at === null
                || $kiosk->pairing_code_expires_at->isPast()) {
                throw new ReceptionActionException('api.kiosk.invalid_pairing_code');
            }

            $secret = Totp::generateSecret();

            $kiosk->forceFill([
                'totp_secret' => $secret,
                'pairing_code_hash' => null,
                'pairing_code_expires_at' => null,
                'paired_at' => now(),
            ])->save();

            return new KioskPairing($kiosk, $secret);
        });
    }

    /**
     * Separate from is_active on purpose: deactivating and later
     * reactivating a kiosk would otherwise bring back the secret a lost
     * device still holds.
     */
    public function revoke(Kiosk $kiosk): void
    {
        $kiosk->forceFill([
            'totp_secret' => null,
            'pairing_code_hash' => null,
            'pairing_code_expires_at' => null,
            'paired_at' => null,
        ])->save();
    }

    private function hash(string $code): string
    {
        return hash('sha256', strtoupper((string) preg_replace('/[\s-]+/', '', $code)));
    }
}
