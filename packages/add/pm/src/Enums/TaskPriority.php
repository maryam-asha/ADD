<?php

namespace Add\Pm\Enums;

/**
 * How much a task matters. The rank gives lists a number to sort by, since
 * the stored words do not sort in order of importance.
 */
enum TaskPriority: string
{
    case Urgent = 'urgent';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    public function rank(): int
    {
        return match ($this) {
            self::Urgent => 40,
            self::High => 30,
            self::Normal => 20,
            self::Low => 10,
        };
    }
}
