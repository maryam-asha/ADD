<?php

namespace App\Services\Notification;

interface PushProvider
{
    /**
     * Send a push notification to a single device.
     *
     * @return bool Whether the gateway accepted the request.
     */
    public function sendToDevice(string $fcmToken, string $title, string $body, array $data = []): bool;

    /**
     * Send a push notification to multiple devices.
     *
     * @param  list<string>  $fcmTokens
     * @return array<string, bool> Token => success map.
     */
    public function sendToDevices(array $fcmTokens, string $title, string $body, array $data = []): array;
}
