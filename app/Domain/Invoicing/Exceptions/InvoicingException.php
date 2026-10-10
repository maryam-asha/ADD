<?php

namespace App\Domain\Invoicing\Exceptions;

use RuntimeException;

/** An invoicing action refused, with the message to show and its HTTP status. */
class InvoicingException extends RuntimeException
{
    public function __construct(
        public readonly string $messageKey,
        public readonly int $status = 422,
        public readonly array $params = [],
    ) {
        parent::__construct($messageKey);
    }
}
