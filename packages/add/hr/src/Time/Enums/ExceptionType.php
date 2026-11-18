<?php

namespace Add\Hr\Time\Enums;

enum ExceptionType: string
{
    case MissingOut = 'missing_out';
    case NoRecord = 'no_record';
    case Late = 'late';
    case EarlyLeave = 'early_leave';
    case UnscheduledWork = 'unscheduled_work';
}
