<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Http\Controllers\Concerns\ServesNotificationInbox;
use App\Http\Controllers\Controller;

class MemberNotificationController extends Controller
{
    use ServesNotificationInbox;

    protected function inboxAudience(): NotificationAudience
    {
        return NotificationAudience::Member;
    }
}
