<?php

namespace App\Domain\Access\Exceptions;

use RuntimeException;

/**
 * One exception type for every Access-domain precondition failure,
 * mirroring App\Domain\Booking\Exceptions\ReceptionActionException's
 * shape exactly — caught manually per-controller, not registered globally.
 */
class LockAccessDeniedException extends RuntimeException
{
    public function __construct(
        public readonly string $messageKey,
        public readonly int $status = 422,
    ) {
        parent::__construct($messageKey);
    }
}
