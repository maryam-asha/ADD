<?php

namespace App\Domain\Foundation\Enums;

/**
 * PRD decision #1/D.7 — ERD v2.0 naming (docs/decisions/space-type-and-resource-status.md).
 * String-backed, cast on the model rather than a MySQL ENUM (build plan §A.4).
 */
enum SpaceType: string
{
    case CoSpace = 'co_space';
    case Room = 'room';
    case Business = 'business';
    case EventHall = 'event_hall';

    /**
     * Only these three types are lockable — the main door and Co-Space stay
     * unlocked entirely (PRD decision #13).
     */
    public function isLockable(): bool
    {
        return match ($this) {
            self::Room, self::Business, self::EventHall => true,
            self::CoSpace => false,
        };
    }
}
