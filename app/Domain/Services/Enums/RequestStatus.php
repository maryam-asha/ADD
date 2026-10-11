<?php

namespace App\Domain\Services\Enums;

enum RequestStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Statuses that operations staff can transition to */
    public static function operationsAllowed(): array
    {
        return [self::InProgress, self::Resolved, self::Rejected];
    }

    /** Statuses that the member can transition to (self-cancel) */
    public static function memberAllowed(): array
    {
        return [self::Cancelled];
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Resolved, self::Rejected, self::Cancelled => true,
            default => false,
        };
    }
}
