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

    /*
     * Queue name for dispatching notifications.
     */
    'queue' => env('NOTIFICATION_QUEUE', 'notifications'),

];
