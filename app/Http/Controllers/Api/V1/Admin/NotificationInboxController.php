<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Http\Controllers\Concerns\ServesNotificationInbox;
use App\Http\Controllers\Controller;

/**
 * A staff account's own notices in the dashboard — what reaches it by the
 * permissions it holds. Every staff account has one, so its routes sit
 * behind `staff` like `me`.
 */
class NotificationInboxController extends Controller
{
    use ServesNotificationInbox;

    protected function inboxAudience(): NotificationAudience
    {
        return NotificationAudience::Staff;
    }
}
