<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\DataTransferObjects\IssuedScanTicket;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * The two minutes a member gets to read "your plan won't cover this" and
 * accept — longer than the kiosk code itself stays valid. Encrypted and
 * authenticated with APP_KEY; nothing stored.
 */
class ScanTicketService
{
    public const TTL_SECONDS = 120;

    public function issue(User $member, Kiosk $kiosk): IssuedScanTicket
    {
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        return new IssuedScanTicket(
            Crypt::encryptString(json_encode(['u' => $member->id, 'k' => $kiosk->id, 'e' => $expiresAt->getTimestamp()])),
            $expiresAt,
        );
    }

    public function redeem(string $ticket, User $member): Kiosk
    {
        try {
            $payload = json_decode(Crypt::decryptString($ticket), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new ReceptionActionException('api.coworking.ticket_expired');
        }

        if (($payload['u'] ?? null) !== $member->id || ($payload['e'] ?? 0) < now()->getTimestamp()) {
            throw new ReceptionActionException('api.coworking.ticket_expired');
        }

        // Re-read, not trusted from the ticket: a kiosk revoked or
        // deactivated in the last two minutes must stop starting sessions.
        $kiosk = Kiosk::query()
            ->whereKey($payload['k'] ?? 0)
            ->where('is_active', true)
            ->whereNotNull('paired_at')
            ->first();

        return $kiosk ?? throw new ReceptionActionException('api.coworking.invalid_code');
    }
}
