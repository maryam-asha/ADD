<?php

namespace Add\Inventory\Tests\Fixtures;

use Add\Inventory\Contracts\InventoryNotifier;

class RecordingNotifier implements InventoryNotifier
{
    /** @var list<array{ability: string, key: string, placeholders: array<string, scalar>}> */
    public array $sent = [];

    public function notifyHolders(string $ability, string $key, array $placeholders = []): void
    {
        $this->sent[] = ['ability' => $ability, 'key' => $key, 'placeholders' => $placeholders];
    }
}
