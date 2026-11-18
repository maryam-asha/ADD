<?php

namespace App\Providers;

use App\Services\Notification\Drivers\FcmPushProvider;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Illuminate\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PushProvider::class, function () {
            return match (config('notifications.push_driver')) {
                'fcm' => new FcmPushProvider(app('firebase.messaging')),
                default => new MockPushProvider,
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
