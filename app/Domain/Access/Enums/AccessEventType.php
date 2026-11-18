<?php

namespace App\Domain\Access\Enums;

enum AccessEventType: string
{
    case Unlock = 'unlock';
    // Locked on request from the app, as opposed to the lock's own auto-lock.
    case Lock = 'lock';
    case LockAuto = 'lock_auto';
    case FailedAttempt = 'failed_attempt';
}
