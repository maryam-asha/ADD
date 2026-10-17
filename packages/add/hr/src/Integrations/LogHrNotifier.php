<?php

namespace Add\Hr\Integrations;

use Add\Hr\Contracts\HrNotifier;
use Illuminate\Support\Facades\Log;

/**
 * The default: the package cannot know who holds an ability or how the host
 * talks to them, so it only logs what it would have sent.
 */
class LogHrNotifier implements HrNotifier
{
    public function notifyHolders(string $ability, string $key, array $placeholders = []): void
    {
        Log::info('[hr] notification', ['ability' => $ability, 'key' => $key, 'placeholders' => $placeholders]);
    }
}
