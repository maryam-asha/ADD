<?php

namespace Add\Inventory\Tests\Fixtures;

use Add\Inventory\Contracts\CustodianResolver;
use Add\Inventory\Support\Place;

/**
 * Custodians the way a host gives them: here, the test users as `person`.
 */
class RecordingCustodians implements CustodianResolver
{
    public function types(): array
    {
        return ['person'];
    }

    public function exists(Place $custodian): bool
    {
        return $custodian->type === 'person' && User::query()->whereKey($custodian->id)->exists();
    }

    public function describe(Place $custodian): ?array
    {
        $user = $custodian->type === 'person' ? User::query()->find($custodian->id) : null;

        return $user === null ? null : [...$custodian->toArray(), 'label' => $user->name];
    }
}
