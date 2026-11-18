<?php

namespace App\Domain\Kitchen\Exceptions;

use RuntimeException;

/**
 * Every refusal while sending or handling a kitchen order: the translation
 * key, the HTTP status, and the `:placeholder` params the controller passes
 * to __().
 */
class KitchenOrderException extends RuntimeException
{
    /** @param array<string, string> $params */
    public function __construct(
        public readonly string $messageKey,
        public readonly int $status = 422,
        public readonly array $params = [],
    ) {
        parent::__construct($messageKey);
    }
}
