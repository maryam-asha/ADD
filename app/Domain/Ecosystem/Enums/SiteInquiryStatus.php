<?php

namespace App\Domain\Ecosystem\Enums;

enum SiteInquiryStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Spam = 'spam';
}
