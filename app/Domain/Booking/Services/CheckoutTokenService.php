<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\DataTransferObjects\CheckoutQuote;
use App\Domain\Booking\DataTransferObjects\IssuedCheckoutToken;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * Holds the member to what the checkout showed them
 * (docs/decisions/coworking-self-service-sessions.md): the time a session is
 * priced up to and that price, sealed so the end request carries exactly
 * what the member saw and agreed to — not a later minute, not a time
 * reception picks. Encrypted and authenticated with APP_KEY; nothing stored.
 * Like ScanTicketService, but for leaving.
 */
class CheckoutTokenService
{
    /** Long enough to read the summary and choose how to pay; past it, the summary is shown again. */
    public const TTL_SECONDS = 300;

    public function issue(Booking|WalkinSession $session, CarbonInterface $at, int $points): IssuedCheckoutToken
    {
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        return new IssuedCheckoutToken(
            Crypt::encryptString(json_encode([
                'u' => $session->user_id,
                't' => $session->coworkingType(),
                's' => $session->id,
                'a' => $at->getTimestamp(),
                'p' => $points,
                'e' => $expiresAt->getTimestamp(),
            ])),
            $expiresAt,
        );
    }

    /** The quote $member was shown for $session, while it still holds. */
    public function redeem(string $token, User $member, Booking|WalkinSession $session): CheckoutQuote
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new ReceptionActionException('api.coworking.checkout_expired');
        }

        if (($payload['u'] ?? null) !== $member->id
            || ($payload['t'] ?? null) !== $session->coworkingType()
            || ($payload['s'] ?? null) !== $session->id
            || ($payload['e'] ?? 0) < now()->getTimestamp()) {
            throw new ReceptionActionException('api.coworking.checkout_expired');
        }

        return new CheckoutQuote(CarbonImmutable::createFromTimestampUTC((int) $payload['a']), (int) $payload['p']);
    }
}
