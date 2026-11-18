<?php

namespace Add\Hr\Recruitment\Enums;

enum OpeningStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
}
