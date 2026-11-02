<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Http\Controllers\Concerns\ServesNotificationInbox;
use App\Http\Controllers\Controller;

/**
 * A provider account's own notices in the provider portal.
 */
class ProviderNotificationController extends Controller
{
    use ServesNotificationInbox;

    protected function inboxAudience(): NotificationAudience
    {
        return NotificationAudience::Provider;
    }
}
