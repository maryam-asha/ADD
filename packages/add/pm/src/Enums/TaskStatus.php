<?php

namespace Add\Pm\Enums;

/**
 * Where a task stands. Phase 1 has four fixed statuses, listed here in the
 * order the board shows its columns.
 */
enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Done = 'done';
}
