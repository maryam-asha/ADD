<?php

namespace App\Services\Notification\Drivers;

use App\Services\Notification\PushProvider;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\WebPushConfig;

class FcmPushProvider implements PushProvider
{
    public function __construct(private readonly Messaging $messaging) {}

    public function sendToDevice(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        try {
            $message = CloudMessage::withTarget('token', $fcmToken)
                ->withNotification(Notification::create($title, $body))
                ->withData($data);

            $this->messaging->send($message);

            return true;
        } catch (\Throwable $e) {
            Log::error('[FCM] Failed to send to device', [
                'token' => $fcmToken,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendToDevices(array $fcmTokens, string $title, string $body, array $data = []): array
    {
        $results = array_fill_keys($fcmTokens, false);

        try {
            $message = CloudMessage::new()
                ->withNotification(Notification::create($title, $body))
                ->withData($data);

            $report = $this->messaging->sendMulticast($message, $fcmTokens);

            foreach ($report->successes()->getItems() as $success) {
                $token = $success->target()->value();
                $results[$token] = true;
            }
        } catch (\Throwable $e) {
            Log::error('[FCM] Multicast failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }

    public function sendDataToDevices(array $tokens, array $data): array
    {
        $results = array_fill_keys($tokens, false);

        try {
            // High urgency: a browser delivers it at once rather than when
            // convenient. A day's TTL: a notice older than that is stale.
            $message = CloudMessage::new()
                ->withData($data)
                ->withWebPushConfig(WebPushConfig::fromArray(['headers' => ['Urgency' => 'high', 'TTL' => '86400']]));

            $report = $this->messaging->sendMulticast($message, $tokens);

            foreach ($report->successes()->getItems() as $success) {
                $results[$success->target()->value()] = true;
            }
        } catch (\Throwable $e) {
            Log::error('[FCM] Data multicast failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }
}
