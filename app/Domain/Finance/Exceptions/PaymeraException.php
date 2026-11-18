<?php

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * Base for every Paymera failure. Callers that only need "the gateway did
 * not cooperate" catch this; callers that must distinguish a bad key from
 * a declined request catch the subclasses.
 *
 * No Paymera exception ever carries the API key in its message — the key
 * is redacted before it can reach an exception, a log line or a response
 * (tests/Feature/Finance/PaymeraSecretsAreNeverLoggedTest.php).
 */
class PaymeraException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $errorCode = null)
    {
        parent::__construct($message);
    }
}
