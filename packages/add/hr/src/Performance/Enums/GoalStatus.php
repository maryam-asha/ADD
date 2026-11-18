<?php

namespace Add\Hr\Performance\Enums;

enum GoalStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    /** Left out of the review and of the cycle's weights. */
    case Cancelled = 'cancelled';
}
