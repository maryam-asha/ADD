<?php

namespace App\Domain\Access\Enums;

enum AccessEventType: string
{
    case Unlock = 'unlock';
    case LockAuto = 'lock_auto';
    case FailedAttempt = 'failed_attempt';
}
