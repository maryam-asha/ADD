<?php

namespace Add\Hr\Time\Enums;

enum WorkPatternKind: string
{
    case Fixed = 'fixed';
    case Roster = 'roster';
    case Flexible = 'flexible';
}
