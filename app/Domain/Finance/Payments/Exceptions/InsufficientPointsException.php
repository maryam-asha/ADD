<?php

namespace App\Domain\Finance\Payments\Exceptions;

use RuntimeException;

/**
 * The wallet paying for something does not hold enough points for it.
 *
 * Carries the three numbers a member needs to act on — what they have, what
 * it costs, and the difference — and is rendered from bootstrap/app.php as
 * 422 `{"message", "current", "required", "shortage"}`, the one shape every
 * pay-with-points path answers with.
 */
class InsufficientPointsException extends RuntimeException
{
    public function __construct(
        public readonly int $current,
        public readonly int $required,
        public readonly int $shortage,
    ) {
        parent::__construct(sprintf(
            'Insufficient points: %d required, %d available (short %d).',
            $required,
            $current,
            $shortage,
        ));
    }

    public static function for(int $current, int $required): self
    {
        return new self($current, $required, max(0, $required - $current));
    }
}
