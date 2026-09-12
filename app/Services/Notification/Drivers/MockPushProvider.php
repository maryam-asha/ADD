<?php

namespace App\Services\Notification\Drivers;

use App\Services\Notification\PushProvider;
use Illuminate\Support\Facades\Log;

class MockPushProvider implements PushProvider
{
    public function sendToDevice(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        Log::info('[MockPush] Single device', [
            'token' => $fcmToken,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        return true;
    }

    public function sendToDevices(array $fcmTokens, string $title, string $body, array $data = []): array
    {
        Log::info('[MockPush] Multi device', [
            'tokens' => $fcmTokens,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        return array_fill_keys($fcmTokens, true);
    }
}
