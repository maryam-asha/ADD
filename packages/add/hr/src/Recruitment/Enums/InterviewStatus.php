<?php

namespace Add\Hr\Recruitment\Enums;

enum InterviewStatus: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
