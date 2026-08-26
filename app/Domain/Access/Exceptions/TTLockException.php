<?php

namespace App\Domain\Access\Exceptions;

use RuntimeException;

/**
 * Every TTLockClient failure surfaces as one of these named cases so
 * callers — in particular UnlockService, which must tell a member "use
 * the keypad instead" specifically when the gateway is offline — can
 * branch on *why* the vendor call failed, not just that it did.
 */
class TTLockException extends RuntimeException
{
    private function __construct(string $message, public readonly ?int $vendorErrorCode = null)
    {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self('TTLock rejected the configured client/account credentials.');
    }

    public static function gatewayOffline(): self
    {
        return new self("The lock's gateway is not currently connected.", -2012);
    }

    public static function remoteUnlockDisabled(): self
    {
        return new self('Remote unlock is not enabled for this lock in the TTLock app settings.', -4043);
    }

    public static function lockNotFound(): self
    {
        return new self('TTLock does not recognize this lock/key relationship.');
    }

    public static function vendorError(int $code, string $message): self
    {
        return new self("TTLock error {$code}: {$message}", $code);
    }
}
