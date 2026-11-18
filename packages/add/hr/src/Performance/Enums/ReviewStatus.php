<?php

namespace Add\Hr\Performance\Enums;

enum ReviewStatus: string
{
    case Open = 'open';
    case SelfSubmitted = 'self_submitted';
    case ManagerSubmitted = 'manager_submitted';
    /** The cycle closed; the result is visible to the employee. */
    case Finalized = 'finalized';
}
