<?php

namespace App\Domain\Foundation\Enums;

/**
 * Shared by `spaces` and `resources` (PRD decision #8 / §5.6). Never
 * escalates across the hierarchy — a Floor or Zone going into maintenance
 * means setting this on every Space underneath it individually, not a
 * flag anywhere above Space.
 */
enum OperationalStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Retired = 'retired';

    /**
     * A non-active row disappears from search/booking results immediately,
     * regardless of calendar availability (PRD §5.6).
     */
    public function isBookable(): bool
    {
        return $this === self::Active;
    }
}
