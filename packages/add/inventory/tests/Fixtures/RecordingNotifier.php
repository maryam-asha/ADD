<?php

namespace Add\Inventory\Tests\Fixtures;

use Add\Inventory\Contracts\InventoryNotifier;

class RecordingNotifier implements InventoryNotifier
{
    /** @var list<array{ability: string, key: string, placeholders: array<string, scalar>, target: array<string, string>}> */
    public array $sent = [];

    public function notifyHolders(string $ability, string $key, array $placeholders = [], array $target = []): void
    {
        $this->sent[] = ['ability' => $ability, 'key' => $key, 'placeholders' => $placeholders, 'target' => $target];
    }
}
