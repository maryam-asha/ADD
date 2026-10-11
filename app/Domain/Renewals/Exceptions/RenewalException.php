<?php

namespace App\Domain\Renewals\Exceptions;

use RuntimeException;

/** A renewal action refused outright (not renewable, already renewed, …), with its message and status. */
class RenewalException extends RuntimeException
{
    public function __construct(
        public readonly string $messageKey,
        public readonly int $status = 422,
        public readonly array $params = [],
    ) {
        parent::__construct($messageKey);
    }
}
