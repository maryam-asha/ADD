<?php

namespace App\Services\Experience\Exceptions;

use RuntimeException;

/**
 * One exception type for every event registration precondition failure.
 * Carries the translation key, HTTP status, and any Laravel-style
 * `:placeholder` params the controller passes straight to __().
 *
 * Structurally the same as Booking's ReceptionActionException, and
 * deliberately not that class: the Experience surface should not inherit
 * another domain's exception vocabulary.
 */
class EventActionException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly string $messageKey,
        public readonly int $status = 422,
        public readonly array $params = [],
    ) {
        parent::__construct($messageKey);
    }
}
