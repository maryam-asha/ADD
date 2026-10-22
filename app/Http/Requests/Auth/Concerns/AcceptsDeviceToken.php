<?php

namespace App\Http\Requests\Auth\Concerns;

/**
 * The optional FCM token an endpoint that opens a session accepts, so the app
 * can register its device for push in the same call instead of a separate
 * PUT member/device-token right after — with what it knows about the device.
 */
trait AcceptsDeviceToken
{
    /**
     * @return array<string, mixed>
     */
    protected function deviceTokenRules(): array
    {
        return [
            'fcm_token' => ['nullable', 'string', 'max:500'],
            'platform' => ['nullable', 'string', 'in:ios,android,web'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'build_number' => ['nullable', 'string', 'max:50'],
            'os_version' => ['nullable', 'string', 'max:50'],
            'device_model' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deviceDetails(): array
    {
        return $this->safe()->only(['platform', 'device_id', 'app_version', 'build_number', 'os_version', 'device_model']);
    }
}
