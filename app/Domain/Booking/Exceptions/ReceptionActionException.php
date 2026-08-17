<?php

namespace App\Domain\Booking\Exceptions;

use RuntimeException;

/**
 * One exception type for every reception-operation precondition failure
 * across booking check-in/check-out/cancel/settlement and walk-in
 * start/check-out/settlement. Carries the translation key and HTTP status
 * the controller maps directly to a JSON error response — a proliferation
 * of one-off exception subclasses would buy nothing over this for the
 * ~15 distinct failure modes this plan needs.
 */
class ReceptionActionException extends RuntimeException
{
    public function __construct(public readonly string $messageKey, public readonly int $status = 422)
    {
        parent::__construct($messageKey);
    }
}
