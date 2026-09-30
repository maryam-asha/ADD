<?php

namespace App\Domain\Membership\Exceptions;

/**
 * Desks a Dedicated Desk subscription cannot be tied to. `$reason` is the
 * `api.membership.*` key the caller shows (docs/decisions/coworking-seat-pool.md).
 */
class DeskAssignmentRefusedException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
