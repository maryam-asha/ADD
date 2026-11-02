<?php

return [

    /*
     * Push notification driver.
     *
     * Supported: 'fcm', 'mock'
     * 'mock' logs notifications instead of sending — suitable for local dev.
     */
    'push_driver' => env('NOTIFICATION_PUSH_DRIVER', 'mock'),

    /*
     * Active notification channels.
     */
    'channels' => ['push', 'database'],

    // No queue: the host runs no worker, so notifications are delivered in
    // the request, after commit (App\Services\Notification\NotificationService).

];
