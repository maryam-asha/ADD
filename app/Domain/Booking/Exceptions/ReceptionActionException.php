<?php

namespace App\Domain\Booking\Exceptions;

use RuntimeException;

/**
 * One exception type for every reception/booking precondition failure in
 * this domain. Carries the translation key, HTTP status, and (optionally)
 * Laravel-style `:placeholder` params the controller passes straight
 * through to __(). $params defaults to [] — every pre-existing call site
 * that doesn't need it is unaffected.
 */
class ReceptionActionException extends RuntimeException
{
    public function __construct(
        public readonly string $messageKey,
        public readonly int $status = 422,
        public readonly array $params = [],
    ) {
        parent::__construct($messageKey);
    }
}
