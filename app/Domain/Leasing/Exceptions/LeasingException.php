<?php

namespace App\Domain\Leasing\Exceptions;

use RuntimeException;

/** A lease action refused, with the message to show and its HTTP status. */
class LeasingException extends RuntimeException
{
    public function __construct(
        public readonly string $messageKey,
        public readonly int $status = 422,
        public readonly array $params = [],
    ) {
        parent::__construct($messageKey);
    }
}
