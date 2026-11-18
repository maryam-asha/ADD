<?php

namespace Add\Hr\Time\Enums;

/**
 * How a day turned out. A working day with no punch is `no_record`
 * (needs review), never `absent`, until someone decides. `open`: today, still
 * running.
 */
enum DayStatus: string
{
    case Present = 'present';
    case Incomplete = 'incomplete';
    case NoRecord = 'no_record';
    case Absent = 'absent';
    case Excused = 'excused';
    case Rest = 'rest';
    case Holiday = 'holiday';
    case Leave = 'leave';
    case Open = 'open';
}
