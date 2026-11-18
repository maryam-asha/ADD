<?php

namespace Add\Hr\Time\Enums;

/**
 * How a reviewer closed an exception: the absence is excused, it is an
 * absence, it does not matter (dismissed), or punches were corrected.
 */
enum ExceptionResolution: string
{
    case Excused = 'excused';
    case Absent = 'absent';
    case Dismissed = 'dismissed';
    case Corrected = 'corrected';
}
