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

    /**
     * Send a data-only message to multiple devices — nothing the platform
     * shows by itself: a dashboard browser's service worker reads the title
     * and body from $data and decides whether to show them.
     *
     * @param  list<string>  $tokens
     * @param  array<string, string>  $data
     * @return array<string, bool> Token => success map.
     */
    public function sendDataToDevices(array $tokens, array $data): array;
}
