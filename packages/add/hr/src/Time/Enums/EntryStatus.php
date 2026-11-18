<?php

namespace Add\Hr\Time\Enums;

enum EntryStatus: string
{
    case Verified = 'verified';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
